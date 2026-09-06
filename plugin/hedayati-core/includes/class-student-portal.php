<?php
/**
 * Phase 2D — Front-end student self-service portal.
 *
 * A single real WordPress Page (slug `account`, template `page-account.php` in
 * the theme) with view routing via `?view=` — the same convention already used
 * by the staff-only wp-admin screens (`Hedayati_Academic_Admin`,
 * `Hedayati_Student_Admin`). Every mutation goes through `admin-post.php` with a
 * per-action nonce and a server-side capability check, matching that same
 * proven pattern.
 *
 * SECURITY — the owner of a piece of data is ALWAYS `get_current_user_id()`,
 * NEVER a value read from `$_POST`/`$_GET`. This class does not, and must
 * never, accept a client-submitted `user_id`. This is deliberately different
 * from `Hedayati_Student_Admin::require_student_scope()` (staff-only,
 * intentionally unscoped for reception/manager) — reusing that check here
 * would let one student act on another's data by simply posting a different
 * user_id who also happens to hold the `student` role. See
 * `docs/PHASE_2D_PLANNING.md` §9.
 *
 * `Hedayati_Document_Service` is capability-agnostic by design (its own
 * docblock says so) — it enforces nothing on its own. Every call into it from
 * this controller is preceded by an explicit capability check AND, for
 * document-specific actions, an explicit `$doc['user_id'] ===
 * get_current_user_id()` ownership check performed in THIS file.
 *
 * Verification display is deliberately narrower than
 * `Hedayati_Verification_Service::get_status()`'s full return value: only
 * `status` and national-ID PRESENCE (never the value) reach a student. This
 * class never calls `get_national_id_decrypted()`.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Student_Portal {

	private const PAGE_SLUG       = 'account';
	private const OPTION_PAGE_ID  = 'hedayati_account_page_id';
	private const VIEW_CAPABILITY = 'hedayati_view_own_portal';

	public const VIEWS = [ 'dashboard', 'enrollments', 'schedule', 'verification', 'documents', 'profile' ];

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_create_account_page' ] );
		add_action( 'template_redirect', [ self::class, 'guard_account_page' ] );

		$mutations = [
			'hedayati_portal_profile_save',
			'hedayati_portal_phone_save',
			'hedayati_portal_document_upload',
		];
		foreach ( $mutations as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle_' . substr( $action, 16 ) ] );
		}
		add_action( 'admin_post_hedayati_portal_document_download', [ self::class, 'handle_document_download' ] );
	}

	// ── Page bootstrap ──────────────────────────────────────────────────────

	/**
	 * Ensures the real `account` Page exists. Called on plugin activation AND
	 * (cheaply, option-check only) on every `admin_init` — replacing plugin
	 * files without reactivating never fires the activation hook, the same
	 * caveat already documented for migrations (`docs/DEPLOYMENT.md`).
	 */
	public static function maybe_create_account_page(): void {
		$page_id = (int) get_option( self::OPTION_PAGE_ID, 0 );

		if ( $page_id > 0 && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing instanceof WP_Post ) {
			update_option( self::OPTION_PAGE_ID, $existing->ID );
			return;
		}

		$new_id = wp_insert_post(
			[
				'post_title'   => __( 'حساب کاربری', 'hedayati-core' ),
				'post_name'    => self::PAGE_SLUG,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			],
			true
		);

		if ( ! is_wp_error( $new_id ) ) {
			update_option( self::OPTION_PAGE_ID, $new_id );
		}
	}

	public static function get_account_page_id(): int {
		return (int) get_option( self::OPTION_PAGE_ID, 0 );
	}

	public static function get_account_url( string $view = '' ): string {
		$url = home_url( '/' . self::PAGE_SLUG . '/' );

		return '' !== $view ? add_query_arg( 'view', $view, $url ) : $url;
	}

	// ── Access guard (login + capability + no-cache) ────────────────────────

	/**
	 * Runs on every front-end request; no-ops unless the current request IS the
	 * account page. Order matters: no-cache headers are sent before any login/
	 * capability decision, so even a redirect response is never cached.
	 */
	public static function guard_account_page(): void {
		$page_id = self::get_account_page_id();

		if ( $page_id <= 0 || ! is_page( $page_id ) ) {
			return;
		}

		self::send_no_cache_headers();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::get_account_url( self::current_view() ) ) );
			exit;
		}

		if ( ! current_user_can( self::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این بخش را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Explicit no-store headers on every authenticated portal response, plus
	 * the LiteSpeed Cache plugin's own documented exclusion hook (a no-op,
	 * safely, if that plugin isn't active) — `docs/PHASE_2D_PLANNING.md`'s
	 * mandatory requirement that authenticated portal pages never enter a
	 * public/LiteSpeed cache.
	 */
	public static function send_no_cache_headers(): void {
		nocache_headers();

		if ( function_exists( 'do_action' ) && has_action( 'litespeed_control_set_nocache' ) ) {
			do_action( 'litespeed_control_set_nocache', 'hedayati account page' );
		}
	}

	private static function current_view(): string {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard';

		return in_array( $view, self::VIEWS, true ) ? $view : 'dashboard';
	}

	// ── Rendering (called from theme/hedayati/page-account.php) ─────────────

	/**
	 * Render the current view's body markup. Called by the theme template,
	 * inside the shared shell layout it provides. Output is pre-escaped here;
	 * the template must echo the return value raw (not re-escape it).
	 */
	public static function render_current_view(): string {
		$view    = self::current_view();
		$user_id = get_current_user_id();

		switch ( $view ) {
			case 'profile':
				return self::render_profile_view( $user_id );
			case 'verification':
				return self::render_verification_view( $user_id );
			case 'enrollments':
				return self::render_enrollments_view( $user_id );
			case 'schedule':
				return self::render_schedule_view( $user_id );
			case 'documents':
				return self::render_documents_view( $user_id );
			default:
				return self::render_dashboard_view( $user_id );
		}
	}

	private static function render_dashboard_view( int $user_id ): string {
		$status       = Hedayati_Verification_Service::get_status( $user_id );
		$enrollments  = Hedayati_Enrollment_Service::list_for_user( $user_id );
		$documents    = Hedayati_Document_Service::list_for_user( $user_id );
		$active_count = count( array_filter( $enrollments, static fn( $e ) => 'active' === $e['status'] ) );
		$active       = array_values( array_filter( $enrollments, static fn( $e ) => 'active' === $e['status'] ) );
		$upcoming     = self::upcoming_sessions_for_user( $user_id );

		$display_name = wp_get_current_user()->display_name;

		ob_start();
		?>
		<header class="hd-student-heading">
			<div><span class="hd-manager-eyebrow"><?php esc_html_e( 'میز کار آموزشی', 'hedayati-core' ); ?></span>
			<h1 class="hd-portal-title"><?php esc_html_e( 'داشبورد یادگیری', 'hedayati-core' ); ?></h1>
			<?php if ( '' !== $display_name ) : ?>
			<p class="hd-portal-note">
				<?php
				/* translators: %s: student display name */
				printf( esc_html__( 'خوش آمدید، %s.', 'hedayati-core' ), esc_html( $display_name ) );
				?>
			</p>
			<?php endif; ?></div>
			<a class="hd-student-catalog" href="<?php echo esc_url( home_url( '/courses/' ) ); ?>"><?php esc_html_e( 'مشاهدهٔ دوره‌ها', 'hedayati-core' ); ?></a>
		</header>
		<div class="hd-portal-cards hd-student-kpis">
			<div class="hd-portal-card">
				<span class="hd-portal-card-label"><?php esc_html_e( 'وضعیت احراز هویت', 'hedayati-core' ); ?></span>
				<span class="hd-portal-card-value"><?php echo esc_html( self::verification_status_label( $status['status'] ) ); ?></span>
			</div>
			<div class="hd-portal-card">
				<span class="hd-portal-card-label"><?php esc_html_e( 'دوره‌های فعال', 'hedayati-core' ); ?></span>
				<span class="hd-portal-card-value"><?php echo esc_html( Hedayati_Text::digits_to_persian( (string) $active_count ) ); ?></span>
			</div>
			<div class="hd-portal-card">
				<span class="hd-portal-card-label"><?php esc_html_e( 'مدارک ثبت‌شده', 'hedayati-core' ); ?></span>
				<span class="hd-portal-card-value"><?php echo esc_html( Hedayati_Text::digits_to_persian( (string) count( $documents ) ) ); ?></span>
			</div>
		</div>

		<div class="hd-student-dashboard-grid">
			<section class="hd-student-learning">
				<div class="hd-student-section-heading"><div><span><?php esc_html_e( 'در حال یادگیری', 'hedayati-core' ); ?></span><h2><?php esc_html_e( 'دوره‌های فعال شما', 'hedayati-core' ); ?></h2></div><a href="<?php echo esc_url( self::get_account_url( 'enrollments' ) ); ?>"><?php esc_html_e( 'مشاهدهٔ همه', 'hedayati-core' ); ?></a></div>
				<?php if ( empty( $active ) ) : ?>
					<p class="hd-portal-note"><?php esc_html_e( 'در حال حاضر دورهٔ فعالی برای شما ثبت نشده است.', 'hedayati-core' ); ?></p>
				<?php else : ?>
					<div class="hd-student-course-list">
					<?php foreach ( array_slice( $active, 0, 3 ) as $enrollment ) :
						$run = Hedayati_Course_Run_Service::get( (int) $enrollment['run_id'] );
						if ( null === $run ) { continue; }
						$title = get_the_title( $run['course_id'] ) ?: sprintf( '#%d', $run['course_id'] );
						?>
						<a href="<?php echo esc_url( self::get_account_url( 'enrollments' ) ); ?>"><span class="hd-student-course-mark"><?php echo esc_html( Hedayati_Text::digits_to_persian( (string) mb_substr( $title, 0, 2 ) ) ); ?></span><span><strong><?php echo esc_html( $run['label'] ?: $title ); ?></strong><small><?php echo esc_html( $title ); ?></small></span><b aria-hidden="true">‹</b></a>
					<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<aside class="hd-student-next-class">
				<span><?php esc_html_e( 'جلسهٔ بعدی شما', 'hedayati-core' ); ?></span>
				<?php if ( empty( $upcoming ) ) : ?>
					<h2><?php esc_html_e( 'برنامه‌ای ثبت نشده', 'hedayati-core' ); ?></h2>
					<p><?php esc_html_e( 'جلسهٔ آینده پس از ثبت برنامهٔ کلاس در اینجا نمایش داده می‌شود.', 'hedayati-core' ); ?></p>
				<?php else : $next = $upcoming[0]; ?>
					<h2><?php echo esc_html( $next['course_title'] ); ?></h2>
					<p><?php echo esc_html( $next['topic'] ); ?></p>
					<strong dir="ltr"><?php echo esc_html( substr( $next['starts_at'], 11, 5 ) ); ?></strong>
					<p dir="ltr"><?php echo esc_html( Hedayati_Jalali::format( $next['starts_at'], true, false ) ); ?></p>
				<?php endif; ?>
				<a href="<?php echo esc_url( self::get_account_url( 'schedule' ) ); ?>"><?php esc_html_e( 'مشاهدهٔ برنامهٔ کلاس‌ها', 'hedayati-core' ); ?></a>
			</aside>
		</div>

		<h2 class="hd-portal-subtitle"><?php esc_html_e( 'دسترسی سریع', 'hedayati-core' ); ?></h2>
		<div class="hd-portal-cards">
			<a class="hd-portal-card" href="<?php echo esc_url( self::get_account_url( 'enrollments' ) ); ?>"><?php esc_html_e( 'دوره‌ها و جلسات من', 'hedayati-core' ); ?></a>
			<a class="hd-portal-card" href="<?php echo esc_url( self::get_account_url( 'documents' ) ); ?>"><?php esc_html_e( 'بارگذاری و مشاهدهٔ مدارک', 'hedayati-core' ); ?></a>
			<a class="hd-portal-card" href="<?php echo esc_url( self::get_account_url( 'profile' ) ); ?>"><?php esc_html_e( 'ویرایش پروفایل و شمارهٔ موبایل', 'hedayati-core' ); ?></a>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_schedule_view( int $user_id ): string {
		$items = self::upcoming_sessions_for_user( $user_id );

		ob_start();
		?>
		<div class="hd-student-view-heading"><span class="hd-manager-eyebrow"><?php esc_html_e( 'برنامهٔ آموزشی', 'hedayati-core' ); ?></span><h1 class="hd-portal-title"><?php esc_html_e( 'جلسات آینده', 'hedayati-core' ); ?></h1></div>
		<?php if ( empty( $items ) ) : ?>
			<div class="hd-student-empty"><strong><?php esc_html_e( 'جلسه‌ای در برنامه نیست', 'hedayati-core' ); ?></strong><p><?php esc_html_e( 'پس از ثبت برنامه توسط واحد آموزش، جلسه‌های آینده در این بخش نمایش داده می‌شوند.', 'hedayati-core' ); ?></p><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'ارتباط با واحد آموزش', 'hedayati-core' ); ?></a></div>
		<?php else : ?>
			<div class="hd-student-schedule">
			<?php foreach ( $items as $item ) : ?>
				<article><time dir="ltr"><?php echo esc_html( Hedayati_Jalali::format( $item['starts_at'], true, true ) ); ?></time><div><strong><?php echo esc_html( $item['course_title'] ); ?></strong><p><?php echo esc_html( $item['topic'] ); ?></p><small><?php echo esc_html( $item['run_label'] ); ?></small></div></article>
			<?php endforeach; ?>
			</div>
		<?php endif;
		return (string) ob_get_clean();
	}

	/**
	 * Build the signed-in student's future schedule from authorized enrollments.
	 *
	 * @return array<int, array{starts_at:string,topic:string,course_title:string,run_label:string}>
	 */
	private static function upcoming_sessions_for_user( int $user_id ): array {
		$items = [];
		$now   = current_time( 'mysql' );

		foreach ( Hedayati_Enrollment_Service::list_for_user( $user_id ) as $enrollment ) {
			if ( 'active' !== $enrollment['status'] ) {
				continue;
			}

			$run = Hedayati_Course_Run_Service::get( (int) $enrollment['run_id'] );
			if ( null === $run || in_array( $run['run_status'], [ 'completed', 'cancelled' ], true ) ) {
				continue;
			}

			$course_title = get_the_title( $run['course_id'] ) ?: sprintf( '#%d', $run['course_id'] );
			foreach ( Hedayati_Session_Service::list_for_run( (int) $run['id'] ) as $session ) {
				if ( 'scheduled' !== $session['status'] || $session['starts_at'] < $now ) {
					continue;
				}

				$items[] = [
					'starts_at'    => (string) $session['starts_at'],
					'topic'        => $session['topic'] ?: sprintf( __( 'جلسهٔ %d', 'hedayati-core' ), $session['session_number'] ),
					'course_title' => $course_title,
					'run_label'    => $run['label'] ?: $course_title,
				];
			}
		}

		usort( $items, static fn( array $a, array $b ): int => strcmp( $a['starts_at'], $b['starts_at'] ) );
		return array_slice( $items, 0, 20 );
	}

	private static function render_profile_view( int $user_id ): string {
		$user   = get_userdata( $user_id );
		$fields = Hedayati_Student_Profile::get( $user_id );
		$phone  = Hedayati_User_Phone_Service::get_phone_record_by_user( $user_id );

		ob_start();
		?>
		<h1 class="hd-portal-title"><?php esc_html_e( 'پروفایل من', 'hedayati-core' ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hd-portal-form">
			<?php wp_nonce_field( 'hedayati_portal_profile_save' ); ?>
			<input type="hidden" name="action" value="hedayati_portal_profile_save">

			<label class="hd-portal-field">
				<span><?php esc_html_e( 'ایمیل', 'hedayati-core' ); ?></span>
				<input type="email" name="user_email" value="<?php echo esc_attr( $user ? $user->user_email : '' ); ?>" dir="ltr">
			</label>

			<label class="hd-portal-field">
				<span><?php esc_html_e( 'نشانی پستی', 'hedayati-core' ); ?></span>
				<textarea name="hedayati_address" rows="3"><?php echo esc_textarea( $fields['address'] ?? '' ); ?></textarea>
			</label>

			<label class="hd-portal-field">
				<span><?php esc_html_e( 'شهر', 'hedayati-core' ); ?></span>
				<input type="text" name="hedayati_city" value="<?php echo esc_attr( $fields['city'] ?? '' ); ?>">
			</label>

			<label class="hd-portal-field">
				<span><?php esc_html_e( 'کد پستی (۱۰ رقم)', 'hedayati-core' ); ?></span>
				<input type="text" name="hedayati_postal_code" value="<?php echo esc_attr( $fields['postal_code'] ?? '' ); ?>" dir="ltr" maxlength="10">
			</label>

			<button type="submit" class="hd-portal-btn"><?php esc_html_e( 'ذخیرهٔ پروفایل', 'hedayati-core' ); ?></button>
		</form>

		<h2 class="hd-portal-subtitle"><?php esc_html_e( 'شمارهٔ موبایل', 'hedayati-core' ); ?></h2>
		<p class="hd-portal-note">
			<?php
			echo $phone
				? esc_html( Hedayati_Phone::format_display( $phone['phone_e164'] ) )
				: esc_html__( 'شماره‌ای ثبت نشده است.', 'hedayati-core' );
			?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hd-portal-form">
			<?php wp_nonce_field( 'hedayati_portal_phone_save' ); ?>
			<input type="hidden" name="action" value="hedayati_portal_phone_save">
			<label class="hd-portal-field">
				<span><?php esc_html_e( 'شمارهٔ موبایل جدید', 'hedayati-core' ); ?></span>
				<input type="text" name="phone" dir="ltr" placeholder="09xxxxxxxxx">
			</label>
			<p class="hd-portal-note"><?php esc_html_e( 'تغییر شماره، وضعیت تأیید شماره را بازنشانی می‌کند.', 'hedayati-core' ); ?></p>
			<button type="submit" class="hd-portal-btn"><?php esc_html_e( 'به‌روزرسانی شماره', 'hedayati-core' ); ?></button>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Deliberately narrow: only `status` and national-ID PRESENCE. Never
	 * `reviewer_id`, `reviewed_at`, `note`, or a decrypted value — see the
	 * class docblock and docs/PHASE_2D_PLANNING.md §5/§13.
	 */
	private static function render_verification_view( int $user_id ): string {
		$status = Hedayati_Verification_Service::get_status( $user_id );
		$masked = Hedayati_Verification_Service::get_national_id_masked( $user_id );

		ob_start();
		?>
		<h1 class="hd-portal-title"><?php esc_html_e( 'وضعیت احراز هویت', 'hedayati-core' ); ?></h1>
		<div class="hd-portal-cards">
			<div class="hd-portal-card">
				<span class="hd-portal-card-label"><?php esc_html_e( 'وضعیت', 'hedayati-core' ); ?></span>
				<span class="hd-portal-card-value"><?php echo esc_html( self::verification_status_label( $status['status'] ) ); ?></span>
			</div>
			<div class="hd-portal-card">
				<span class="hd-portal-card-label"><?php esc_html_e( 'کد ملی', 'hedayati-core' ); ?></span>
				<span class="hd-portal-card-value">
					<?php echo 'set' === $masked ? esc_html__( 'ثبت شده', 'hedayati-core' ) : esc_html__( 'ثبت نشده', 'hedayati-core' ); ?>
				</span>
			</div>
		</div>
		<p class="hd-portal-note"><?php esc_html_e( 'ثبت یا مشاهدهٔ کد ملی تنها توسط کارکنان مجتمع در حضور شما انجام می‌شود.', 'hedayati-core' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	private static function render_enrollments_view( int $user_id ): string {
		$enrollments = Hedayati_Enrollment_Service::list_for_user( $user_id );

		ob_start();
		?>
		<h1 class="hd-portal-title"><?php esc_html_e( 'دوره‌های من', 'hedayati-core' ); ?></h1>
		<?php if ( empty( $enrollments ) ) : ?>
			<p class="hd-portal-note"><?php esc_html_e( 'هنوز ثبت‌نامی برای شما ثبت نشده است.', 'hedayati-core' ); ?></p>
			<?php
			return (string) ob_get_clean();
		endif;

		foreach ( $enrollments as $enrollment ) {
			$run = Hedayati_Course_Run_Service::get( (int) $enrollment['run_id'] );
			if ( null === $run ) {
				continue;
			}

			$course_title = get_the_title( $run['course_id'] ) ?: sprintf( '#%d', $run['course_id'] );
			$sessions     = Hedayati_Session_Service::list_for_run( $run['id'] );
			?>
			<div class="hd-portal-run-card">
				<h2 class="hd-portal-subtitle"><?php echo esc_html( $run['label'] ?: $course_title ); ?></h2>
				<p class="hd-portal-note"><?php echo esc_html( $course_title ); ?></p>
				<p class="hd-portal-note">
					<?php echo esc_html( self::enrollment_status_label( $enrollment['status'] ) ); ?>
					<?php if ( $run['start_date'] ) : ?>
						— <span dir="ltr"><?php echo esc_html( Hedayati_Jalali::format( $run['start_date'] ) ); ?></span>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $sessions ) ) : ?>
					<ul class="hd-portal-session-list">
						<?php foreach ( $sessions as $session ) : ?>
							<li>
								<?php echo esc_html( $session['topic'] ?: sprintf( __( 'جلسهٔ %d', 'hedayati-core' ), $session['session_number'] ) ); ?>
								— <span dir="ltr"><?php echo esc_html( Hedayati_Jalali::format( $session['starts_at'], true, true ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<?php
		}

		return (string) ob_get_clean();
	}

	private static function render_documents_view( int $user_id ): string {
		$documents = Hedayati_Document_Service::list_for_user( $user_id );

		ob_start();
		?>
		<h1 class="hd-portal-title"><?php esc_html_e( 'مدارک من', 'hedayati-core' ); ?></h1>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="hd-portal-form">
			<?php wp_nonce_field( 'hedayati_portal_document_upload' ); ?>
			<input type="hidden" name="action" value="hedayati_portal_document_upload">
			<label class="hd-portal-field">
				<span><?php esc_html_e( 'نوع مدرک', 'hedayati-core' ); ?></span>
				<select name="doc_type">
					<?php foreach ( Hedayati_Document_Service::DOC_TYPES as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( self::doc_type_label( $type ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="hd-portal-field">
				<span><?php esc_html_e( 'فایل مدرک (PDF، JPEG یا PNG)', 'hedayati-core' ); ?></span>
				<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png">
			</label>
			<button type="submit" class="hd-portal-btn"><?php esc_html_e( 'بارگذاری مدرک', 'hedayati-core' ); ?></button>
		</form>

		<?php if ( empty( $documents ) ) : ?>
			<p class="hd-portal-note"><?php esc_html_e( 'مدرکی بارگذاری نکرده‌اید.', 'hedayati-core' ); ?></p>
			<?php
			return (string) ob_get_clean();
		endif;
		?>
		<table class="hd-portal-table">
			<thead>
				<tr><th><?php esc_html_e( 'نوع', 'hedayati-core' ); ?></th><th></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $documents as $doc ) :
					$download_url = wp_nonce_url(
						add_query_arg( [ 'action' => 'hedayati_portal_document_download', 'doc_id' => $doc['id'] ], admin_url( 'admin-post.php' ) ),
						'hedayati_portal_document_download_' . $doc['id']
					);
					?>
					<tr>
						<td><?php echo esc_html( self::doc_type_label( $doc['doc_type'] ) ); ?></td>
						<td><a class="hd-portal-btn hd-portal-btn-small" href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'دانلود', 'hedayati-core' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	// ── Mutation handlers (admin-post.php) ───────────────────────────────────

	public static function handle_profile_save(): void {
		$user_id = self::verify_self_service( 'hedayati_portal_profile_save', 'hedayati_edit_own_profile' );

		$postal_raw   = isset( $_POST['hedayati_postal_code'] ) ? (string) wp_unslash( $_POST['hedayati_postal_code'] ) : '';
		$postal_clean = Hedayati_Student_Profile::sanitize_postal( $postal_raw );

		if ( '' !== $postal_clean && ! preg_match( '/^\d{10}$/', $postal_clean ) ) {
			self::redirect( 'profile', esc_html__( 'کد پستی باید دقیقاً ۱۰ رقم باشد یا خالی بماند.', 'hedayati-core' ), 'error' );
		}

		// current_user_can_edit() inside save() re-checks hedayati_edit_own_profile
		// for $user_id === get_current_user_id() — belt and suspenders.
		Hedayati_Student_Profile::save( $user_id );

		$new_email = isset( $_POST['user_email'] ) ? sanitize_email( (string) wp_unslash( $_POST['user_email'] ) ) : '';
		$current   = get_userdata( $user_id );

		if ( '' !== $new_email && $current && $new_email !== $current->user_email ) {
			if ( ! is_email( $new_email ) ) {
				self::redirect( 'profile', esc_html__( 'ایمیل نامعتبر است.', 'hedayati-core' ), 'error' );
			}

			$result = wp_update_user( [ 'ID' => $user_id, 'user_email' => $new_email ] );

			if ( is_wp_error( $result ) ) {
				self::redirect( 'profile', esc_html( $result->get_error_message() ), 'error' );
			}
		}

		self::redirect( 'profile', esc_html__( 'پروفایل ذخیره شد.', 'hedayati-core' ) );
	}

	public static function handle_phone_save(): void {
		$user_id = self::verify_self_service( 'hedayati_portal_phone_save', 'hedayati_edit_own_profile' );

		$raw_phone = isset( $_POST['phone'] ) ? (string) wp_unslash( $_POST['phone'] ) : '';

		if ( '' === trim( $raw_phone ) ) {
			self::redirect( 'profile', esc_html__( 'شماره موبایل وارد نشده است.', 'hedayati-core' ), 'error' );
		}

		$result = Hedayati_User_Phone_Service::assign_phone( $user_id, $raw_phone );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'profile', esc_html( $result->get_error_message() ), 'error' );
		}

		self::redirect( 'profile', esc_html__( 'شماره موبایل به‌روزرسانی شد.', 'hedayati-core' ) );
	}

	public static function handle_document_upload(): void {
		$user_id = self::verify_self_service( 'hedayati_portal_document_upload', 'hedayati_upload_own_documents' );

		$doc_type = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : 'other';
		$file     = $_FILES['document'] ?? null;

		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			self::redirect( 'documents', esc_html__( 'بارگذاری فایل ناموفق بود.', 'hedayati-core' ), 'error' );
		}

		$result = Hedayati_Document_Service::upload( $user_id, $file, $doc_type, $user_id );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'documents', esc_html( $result->get_error_message() ), 'error' );
		}

		self::redirect( 'documents', esc_html__( 'مدرک بارگذاری شد.', 'hedayati-core' ) );
	}

	/**
	 * The explicit ownership check this whole controller exists to guarantee:
	 * the document is loaded first, and its `user_id` is compared against
	 * `get_current_user_id()` BEFORE any byte is streamed. `Hedayati_Document_Service`
	 * itself performs no such check — it is capability-agnostic by design.
	 */
	public static function handle_document_download(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'لطفاً ابتدا وارد شوید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$doc_id = isset( $_GET['doc_id'] ) ? absint( wp_unslash( $_GET['doc_id'] ) ) : 0;

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'hedayati_portal_document_download_' . $doc_id )
		) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( self::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$user_id = get_current_user_id();
		$doc     = Hedayati_Document_Service::get( $doc_id );

		if ( null === $doc || (int) $doc['user_id'] !== $user_id ) {
			// Identical response whether the document doesn't exist or belongs
			// to someone else — never confirm another student's document exists.
			wp_die( esc_html__( 'مدرک یافت نشد.', 'hedayati-core' ), '', [ 'response' => 404 ] );
		}

		$result = Hedayati_Document_Service::download( $doc_id, $user_id );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 404 ] );
		}

		self::maybe_exit();
	}

	/**
	 * exit() after a raw (non wp_die/wp_redirect) streamed response, EXCEPT
	 * inside the Docker/WP-CLI acceptance harness, which defines HDIT_TESTING
	 * (docker/wp-tests/helpers.php) so it can assert on the completed response
	 * instead of having its whole PHP process terminated — same seam already
	 * established for Hedayati_Student_Admin in Phase 2C.
	 */
	private static function maybe_exit(): void {
		if ( ! defined( 'HDIT_TESTING' ) ) {
			exit;
		}
	}

	// ── Plumbing ─────────────────────────────────────────────────────────────

	/**
	 * Shared verify step for every self-service mutation: nonce + capability,
	 * then returns the owner id — ALWAYS `get_current_user_id()`, never a
	 * posted value. There is no `$user_id` parameter to this method on purpose.
	 */
	private static function verify_self_service( string $nonce_action, string $capability ): int {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		return get_current_user_id();
	}

	private static function redirect( string $view, string $notice = '', string $type = 'success' ): void {
		if ( '' !== $notice ) {
			set_transient( self::notice_key(), [ 'type' => $type, 'text' => $notice ], 45 );
		}

		wp_safe_redirect( self::get_account_url( $view ) );
		exit;
	}

	private static function notice_key(): string {
		return 'hedayati_portal_notice_' . get_current_user_id();
	}

	/**
	 * Called by the theme template to render (and consume) a pending notice.
	 */
	public static function render_notice(): string {
		$notice = get_transient( self::notice_key() );

		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return '';
		}

		delete_transient( self::notice_key() );

		$class = 'error' === ( $notice['type'] ?? '' ) ? 'hd-portal-notice-error' : 'hd-portal-notice-success';

		return sprintf( '<div class="hd-portal-notice %s">%s</div>', esc_attr( $class ), wp_kses_post( (string) $notice['text'] ) );
	}

	// ── Label maps ───────────────────────────────────────────────────────────

	private static function verification_status_label( string $status ): string {
		$map = [
			'unverified' => __( 'احراز نشده', 'hedayati-core' ),
			'pending'    => __( 'در حال بررسی', 'hedayati-core' ),
			'verified'   => __( 'احراز شده', 'hedayati-core' ),
			'rejected'   => __( 'رد شده', 'hedayati-core' ),
		];

		return $map[ $status ] ?? $status;
	}

	private static function enrollment_status_label( string $status ): string {
		$map = [
			'active'    => __( 'فعال', 'hedayati-core' ),
			'withdrawn' => __( 'انصراف', 'hedayati-core' ),
			'completed' => __( 'پایان‌یافته', 'hedayati-core' ),
			'cancelled' => __( 'لغوشده', 'hedayati-core' ),
		];

		return $map[ $status ] ?? $status;
	}

	private static function doc_type_label( string $type ): string {
		$map = [
			'national_card'     => __( 'کارت ملی', 'hedayati-core' ),
			'birth_certificate' => __( 'شناسنامه', 'hedayati-core' ),
			'other'             => __( 'سایر', 'hedayati-core' ),
		];

		return $map[ $type ] ?? $type;
	}
}
