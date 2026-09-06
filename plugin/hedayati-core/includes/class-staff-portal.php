<?php
/**
 * Phase 2D/3 — front-end staff panel (`/panel/`).
 *
 * A single real WordPress Page (slug `panel`, theme template `page-panel.php`)
 * with `?view=` routing — the same convention the student portal and the
 * wp-admin screens use. Gives teachers, teaching assistants and reception a
 * scoped, RTL, task-focused journey without exposing the full wp-admin.
 *
 * Authorisation model (unchanged from Phase 2B/2C): a capability is never
 * sufficient on its own for a screen that touches a specific run or student —
 * every such screen pairs the capability with an object-scope check
 * (`Hedayati_Run_Staff_Service::user_is_staff_on_run()` for runs; an explicit
 * `student` role check for student records, matching reception's intentionally
 * unscoped mandate). Managers/administrators bypass the run-scope check the same
 * way `Hedayati_Academic_Admin` already lets them.
 *
 * Every mutation is an `admin-post.php` action with its own nonce + a
 * server-side capability check + the object-scope check, performed again in the
 * handler (never trusting the guard alone).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Staff_Portal {

	private const PAGE_SLUG = 'panel';

	/** Mutation actions => the capability each one requires. */
	private const ACTIONS = [
		'session'        => 'hedayati_manage_assigned_sessions',
		'attendance'     => 'hedayati_record_attendance',
		'student'        => 'hedayati_create_students',
		'enroll'         => 'hedayati_create_enrollments',
		'identity'       => 'hedayati_upload_student_documents',
		'verify'         => 'hedayati_initiate_verification',
		'upload'         => 'hedayati_upload_student_documents',
		'course_feature' => 'hedayati_manage_courses',
		'course_publish' => 'hedayati_manage_courses',
	];

	/** Homepage featured-course slots (mirrors Hedayati_Query::get_featured_courses()). */
	private const FEATURED_LIMIT = 8;

	private const ATTENDANCE_LABELS = [
		''        => 'ثبت نشده',
		'present' => 'حاضر',
		'absent'  => 'غایب',
		'late'    => 'تأخیر',
		'excused' => 'موجه',
	];

	private const VERIFICATION_LABELS = [
		'unverified' => 'احراز نشده',
		'pending'    => 'در حال بررسی',
		'verified'   => 'تأیید شده',
		'rejected'   => 'رد شده',
	];

	// ── Bootstrap ───────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_ensure_page' ] );
		add_action( 'template_redirect', [ self::class, 'guard' ] );
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 20, 3 );
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_on_panel' ] );

		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'admin_post_hedayati_staff_' . $action, [ self::class, 'handle_' . $action ] );
		}
	}

	/** Keep the front-end staff workspace visually separate from wp-admin. */
	public static function hide_admin_bar_on_panel( bool $show ): bool {
		return is_page( self::PAGE_SLUG ) ? false : $show;
	}

	/** Ensures the `panel` Page exists. Safe to call with no user (activation). */
	public static function ensure_page(): void {
		if ( get_page_by_path( self::PAGE_SLUG ) instanceof WP_Post ) {
			return;
		}

		wp_insert_post( [
			'post_type'   => 'page',
			'post_name'   => self::PAGE_SLUG,
			'post_title'  => __( 'پنل آموزش', 'hedayati-core' ),
			'post_status' => 'publish',
			'post_content' => '',
		] );
	}

	/** admin_init safety net — only an admin needs to be able to recreate it. */
	public static function maybe_ensure_page(): void {
		if ( current_user_can( 'manage_options' ) ) {
			self::ensure_page();
		}
	}

	// ── URLs / capability gate ──────────────────────────────────────────────

	public static function url( array $args = [] ): string {
		return add_query_arg( $args, home_url( '/' . self::PAGE_SLUG . '/' ) );
	}

	/** True if the current user has any reason to see the panel at all. */
	public static function allowed(): bool {
		return current_user_can( 'hedayati_view_assigned_runs' )
			|| current_user_can( 'hedayati_lookup_students' )
			|| current_user_can( 'hedayati_manage_course_runs' );
	}

	/** Managers and technical administrators receive the unified operations home. */
	public static function is_manager_workspace(): bool {
		return current_user_can( 'hedayati_manage_course_runs' )
			&& current_user_can( 'hedayati_manage_courses' );
	}

	/**
	 * Send staff to the panel after login instead of wherever WordPress would.
	 * Administrators (who hold `manage_options`) keep their normal destination.
	 */
	public static function login_redirect( string $url, string $requested, $user ): string {
		if (
			$user instanceof WP_User
			&& ! user_can( $user, 'manage_options' )
			&& ( user_can( $user, 'hedayati_view_assigned_runs' ) || user_can( $user, 'hedayati_lookup_students' ) )
		) {
			return self::url();
		}

		return $url;
	}

	/**
	 * template_redirect guard: no-ops off the panel page; otherwise enforces
	 * login + capability + per-object scope BEFORE the theme emits any markup,
	 * and sends no-cache headers even on the redirect/deny path.
	 */
	public static function guard(): void {
		if ( ! is_page( self::PAGE_SLUG ) ) {
			return;
		}

		Hedayati_Student_Portal::send_no_cache_headers();

		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		if ( ! self::allowed() ) {
			self::deny();
		}

		$view = self::get( 'view' );

		if ( 'run' === $view ) {
			$run_id = absint( self::get( 'run_id' ) );
			if (
				! self::can_run( $run_id, 'hedayati_view_assigned_roster' )
				&& ! self::can_run( $run_id, 'hedayati_manage_course_runs' )
			) {
				self::deny();
			}
		}

		if ( in_array( $view, [ 'courses', 'featured' ], true ) && ! current_user_can( 'hedayati_manage_courses' ) ) {
			self::deny();
		}

		$modules = self::module_views();
		if ( '' !== $view && isset( $modules[ $view ] ) && ! current_user_can( (string) $modules[ $view ]['capability'] ) ) {
			self::deny();
		}

		if ( 'students' === $view ) {
			if (
				! current_user_can( 'hedayati_lookup_students' )
				|| ! current_user_can( 'hedayati_view_student_profiles_basic' )
			) {
				self::deny();
			}

			$student_id = absint( self::get( 'student_id' ) );
			if ( $student_id > 0 ) {
				self::require_student( $student_id );
			}
		}
	}

	private static function deny(): void {
		wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
	}

	// ── Request helpers ─────────────────────────────────────────────────────

	private static function post( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] )
			? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
			: '';
	}

	private static function get( string $key ): string {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] )
			? sanitize_text_field( wp_unslash( $_GET[ $key ] ) )
			: '';
	}

	/**
	 * Shared verify step for every mutation handler: POST method + capability +
	 * nonce. Dies 403 on any failure.
	 */
	private static function verify( string $action ): void {
		Hedayati_Student_Portal::send_no_cache_headers();

		$capability = self::ACTIONS[ $action ] ?? 'do_not_allow';
		$nonce      = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| ! current_user_can( $capability )
			|| ! wp_verify_nonce( $nonce, 'hedayati_staff_' . $action )
		) {
			self::deny();
		}
	}

	/**
	 * True only if the current user may act on this run: holds $cap AND is
	 * either a manager or assigned staff on that specific run, AND the run
	 * exists.
	 */
	public static function can_run( int $run_id, string $cap ): bool {
		if ( $run_id <= 0 || ! current_user_can( $cap ) ) {
			return false;
		}

		if ( null === Hedayati_Course_Run_Service::get( $run_id ) ) {
			return false;
		}

		return current_user_can( 'hedayati_manage_course_runs' )
			|| Hedayati_Run_Staff_Service::user_is_staff_on_run( get_current_user_id(), $run_id );
	}

	/** Load a user, or die, requiring that they currently hold the `student` role. */
	private static function require_student( int $user_id ): WP_User {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! in_array( 'student', (array) $user->roles, true ) ) {
			self::deny();
		}

		return $user;
	}

	// ── Redirect + one-shot notice ──────────────────────────────────────────

	/**
	 * @param true|WP_Error $result
	 * @param array         $args     query args for the return URL
	 * @param string        $secret   optional value shown to staff exactly once
	 *                                 (e.g. a temporary password) — stored in a
	 *                                 45s transient, deleted on first render
	 */
	private static function finish( $result, array $args = [], string $secret = '' ): void {
		$notice = [
			'error'  => is_wp_error( $result ),
			'text'   => is_wp_error( $result )
				? $result->get_error_message()
				: __( 'اطلاعات ذخیره شد.', 'hedayati-core' ),
			'secret' => is_wp_error( $result ) ? '' : $secret,
		];

		set_transient( self::notice_key(), $notice, 45 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	private static function notice_key(): string {
		return 'hedayati_staff_notice_' . get_current_user_id();
	}

	private static function render_notice(): void {
		$notice = get_transient( self::notice_key() );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( self::notice_key() );

		$class = ! empty( $notice['error'] ) ? 'hd-portal-notice-error' : 'hd-portal-notice-success';
		printf(
			'<p role="status" class="hd-portal-notice %s">%s</p>',
			esc_attr( $class ),
			esc_html( (string) ( $notice['text'] ?? '' ) )
		);

		if ( ! empty( $notice['secret'] ) ) {
			echo '<div class="hd-portal-notice hd-portal-secret">';
			echo '<strong>' . esc_html__( 'رمز عبور موقت (فقط همین یک‌بار نمایش داده می‌شود):', 'hedayati-core' ) . '</strong> ';
			echo '<code dir="ltr">' . esc_html( (string) $notice['secret'] ) . '</code>';
			echo '<p>' . esc_html__( 'این رمز را به‌صورت حضوری به دانشجو بدهید. دانشجو در نخستین ورود، رمز عبور شخصی خود را تعیین می‌کند.', 'hedayati-core' ) . '</p>';
			echo '</div>';
		}
	}

	// ── Small form-markup helpers ───────────────────────────────────────────

	private static function form_open( string $action, array $hidden = [], bool $multipart = false, string $extra_class = '' ): void {
		printf(
			'<form class="hd-portal-form%s" method="post" action="%s"%s>',
			'' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '',
			esc_url( admin_url( 'admin-post.php' ) ),
			$multipart ? ' enctype="multipart/form-data"' : ''
		);
		wp_nonce_field( 'hedayati_staff_' . $action );
		$hidden['action'] = 'hedayati_staff_' . $action;

		foreach ( $hidden as $key => $value ) {
			printf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $key ),
				esc_attr( (string) $value )
			);
		}
	}

	private static function field( string $key, string $label, string $type = 'text', bool $required = false ): void {
		printf(
			'<label class="hd-portal-field"><span>%s</span><input name="%s" type="%s"%s></label>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $type ),
			$required ? ' required' : ''
		);
	}

	private static function submit( string $label ): void {
		printf( '<button class="hd-portal-btn" type="submit">%s</button></form>', esc_html( $label ) );
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	/**
	 * Panel view registry for the AI-Studio-parity modules (D46–D52).
	 *
	 * Each module (consultations, certificates, materials, support, notifications,
	 * settings) registers one entry rather than bloating this class:
	 *   'slug' => [ 'capability' => 'hedayati_…', 'render' => callable, 'nav' => 'Label'|null ]
	 * `render` is invoked only after the capability is re-checked here AND in guard().
	 *
	 * @return array<string, array{capability:string, render:callable, nav?:?string}>
	 */
	public static function module_views(): array {
		return (array) apply_filters( 'hedayati_panel_module_views', [] );
	}

	/**
	 * Shared verify step for a module mutation handler: POST + capability + nonce.
	 * Modules call this instead of re-implementing the check. Dies 403 on failure.
	 */
	public static function guard_action( string $nonce_action, string $capability ): void {
		Hedayati_Student_Portal::send_no_cache_headers();

		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| ! current_user_can( $capability )
			|| ! wp_verify_nonce( $nonce, $nonce_action )
		) {
			self::deny();
		}
	}

	/**
	 * PRG helper for module handlers: store a one-shot notice, redirect to a panel
	 * view, exit. Mirrors self::finish() for the core actions.
	 *
	 * @param true|WP_Error $result
	 */
	public static function redirect_notice( $result, array $args = [] ): void {
		set_transient(
			self::notice_key(),
			[
				'error'  => is_wp_error( $result ),
				'text'   => is_wp_error( $result ) ? $result->get_error_message() : __( 'اطلاعات ذخیره شد.', 'hedayati-core' ),
				'secret' => '',
			],
			45
		);
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/** True if the current user may open $slug (a registered module view). */
	public static function can_view_module( string $slug ): bool {
		$views = self::module_views();
		return isset( $views[ $slug ] ) && current_user_can( (string) $views[ $slug ]['capability'] );
	}

	/** Entry point, called by theme/hedayati/page-panel.php. */
	public static function render(): void {
		if ( ! self::allowed() ) {
			self::deny();
		}

		self::render_notice();

		$view = self::get( 'view' );

		if ( 'students' === $view && current_user_can( 'hedayati_lookup_students' ) ) {
			self::render_students();
			return;
		}

		if ( 'courses' === $view && current_user_can( 'hedayati_manage_courses' ) ) {
			self::render_courses();
			return;
		}

		if ( 'featured' === $view && current_user_can( 'hedayati_manage_courses' ) ) {
			self::render_featured();
			return;
		}

		if ( 'run' === $view ) {
			self::render_run( absint( self::get( 'run_id' ) ) );
			return;
		}

		$modules = self::module_views();
		if ( isset( $modules[ $view ] ) && current_user_can( (string) $modules[ $view ]['capability'] ) ) {
			call_user_func( $modules[ $view ]['render'] );
			return;
		}

		self::render_home();
	}

	private static function render_home(): void {
		$user = wp_get_current_user();

		if ( self::is_manager_workspace() ) {
			self::render_manager_home( $user );
			return;
		}

		echo '<h1 class="hd-portal-title">' . esc_html__( 'پنل آموزش', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html( $user->display_name ) . '</p>';

		$cards = [
			'hedayati_lookup_students'    => [ self::url( [ 'view' => 'students' ] ), __( 'پذیرش و پروندهٔ دانشجو', 'hedayati-core' ) ],
			'hedayati_manage_courses'     => [ admin_url( 'edit.php?post_type=course' ), __( 'مدیریت دوره‌ها', 'hedayati-core' ) ],
			'hedayati_manage_course_runs' => [ admin_url( 'admin.php?page=hedayati-academic' ), __( 'عملیات آموزشی', 'hedayati-core' ) ],
			'hedayati_manage_teachers'    => [ admin_url( 'edit.php?post_type=teacher' ), __( 'مدیریت اساتید', 'hedayati-core' ) ],
			'hedayati_manage_settings'    => [ admin_url( 'options-general.php?page=hedayati-settings' ), __( 'اطلاعات تماس مجتمع', 'hedayati-core' ) ],
			'hedayati_verify_students'    => [ admin_url( 'admin.php?page=hedayati-students' ), __( 'بررسی احراز هویت', 'hedayati-core' ) ],
		];

		echo '<div class="hd-portal-cards">';
		foreach ( $cards as $cap => $card ) {
			if ( current_user_can( $cap ) ) {
				printf(
					'<a class="hd-portal-card" href="%s">%s</a>',
					esc_url( $card[0] ),
					esc_html( $card[1] )
				);
			}
		}
		echo '</div>';

		if ( current_user_can( 'hedayati_view_assigned_runs' ) ) {
			self::render_my_runs();
		}
	}

	/**
	 * A task-focused manager landing page backed by real WordPress data.
	 *
	 * The linked operational screens remain the existing capability-gated
	 * controllers. This page only summarizes non-sensitive counts and routes the
	 * manager to them; it does not duplicate their mutation logic.
	 */
	private static function render_manager_home( WP_User $user ): void {
		$metrics = self::manager_metrics();

		echo '<header class="hd-manager-heading">';
		echo '<div><span class="hd-manager-eyebrow">' . esc_html__( 'گزارش و دسترسی سریع', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'داشبورد مدیریت', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s، خوش آمدید. وضعیت امروز مجتمع را ببینید و کار خود را ادامه دهید.</p>',
			esc_html( $user->display_name )
		);
		echo '</div>';
		if ( current_user_can( 'hedayati_manage_courses' ) ) {
			printf(
				'<a class="hd-manager-primary" href="%s">%s</a>',
				esc_url( admin_url( 'post-new.php?post_type=course' ) ),
				esc_html__( 'تعریف دورهٔ جدید', 'hedayati-core' )
			);
		}
		echo '</header>';

		echo '<section class="hd-manager-kpis" aria-label="' . esc_attr__( 'خلاصهٔ وضعیت مجتمع', 'hedayati-core' ) . '">';
		foreach ( $metrics as $metric ) {
			printf(
				'<a class="hd-manager-kpi" href="%1$s"><span>%2$s</span><strong>%3$s</strong><small>%4$s</small></a>',
				esc_url( $metric['url'] ),
				esc_html( $metric['label'] ),
				esc_html( Hedayati_Text::digits_to_persian( (string) $metric['value'] ) ),
				esc_html( $metric['hint'] )
			);
		}
		echo '</section>';

		$actions = [
			'hedayati_manage_courses' => [
				self::url( [ 'view' => 'courses' ] ),
				__( 'دوره‌ها و محتوای آموزشی', 'hedayati-core' ),
				__( 'فهرست دوره‌ها، انتشار و انتخاب دوره‌های ویژهٔ صفحه نخست', 'hedayati-core' ),
				'book',
			],
			'hedayati_manage_course_runs' => [
				admin_url( 'admin.php?page=hedayati-academic' ),
				__( 'عملیات آموزشی', 'hedayati-core' ),
				__( 'دوره‌های اجرایی، استادها، جلسات، ثبت‌نام و حضور و غیاب', 'hedayati-core' ),
				'calendar',
			],
			'hedayati_lookup_students' => [
				self::url( [ 'view' => 'students' ] ),
				__( 'پذیرش و پروندهٔ دانشجو', 'hedayati-core' ),
				__( 'جستجو، ایجاد حساب، ثبت‌نام و دریافت امن مدارک', 'hedayati-core' ),
				'users',
			],
			'hedayati_verify_students' => [
				admin_url( 'admin.php?page=hedayati-students' ),
				__( 'احراز هویت دانشجویان', 'hedayati-core' ),
				__( 'بررسی درخواست‌ها و مدیریت وضعیت تأیید هویت', 'hedayati-core' ),
				'shield',
			],
			'hedayati_manage_teachers' => [
				admin_url( 'edit.php?post_type=teacher' ),
				__( 'اساتید', 'hedayati-core' ),
				__( 'پروفایل استادها و وضعیت انتشار عمومی اطلاعات', 'hedayati-core' ),
				'teacher',
			],
			'hedayati_manage_settings' => [
				admin_url( 'options-general.php?page=hedayati-settings' ),
				__( 'تنظیمات مجتمع', 'hedayati-core' ),
				__( 'شماره‌های تماس و نشانی‌های نمایش‌داده‌شده در سایت', 'hedayati-core' ),
				'settings',
			],
		];

		echo '<section class="hd-manager-section">';
		echo '<div class="hd-manager-section-title"><div><span class="hd-manager-eyebrow">' . esc_html__( 'مرکز عملیات', 'hedayati-core' ) . '</span>';
		echo '<h2>' . esc_html__( 'مدیریت بخش‌های مجتمع', 'hedayati-core' ) . '</h2></div>';
		echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'مشاهدهٔ وب‌سایت', 'hedayati-core' ) . '</a></div>';
		// AI-Studio-parity modules register their own manager card (D46–D52).
		foreach ( self::module_views() as $slug => $module ) {
			if ( empty( $module['title'] ) ) {
				continue;
			}
			$actions[ (string) $module['capability'] . '__' . $slug ] = [
				'url'  => 'settings' === $slug ? self::url( [ 'view' => 'settings' ] ) : self::url( [ 'view' => $slug ] ),
				'cap'  => (string) $module['capability'],
				'name' => (string) $module['title'],
				'desc' => (string) ( $module['desc'] ?? '' ),
				'icon' => (string) ( $module['icon'] ?? 'book' ),
			];
		}

		echo '<div class="hd-manager-actions">';
		foreach ( $actions as $key => $action ) {
			$capability = $action['cap'] ?? $key;
			$url        = $action['url'] ?? $action[0];
			$name       = $action['name'] ?? $action[1];
			$desc       = $action['desc'] ?? $action[2];
			$icon       = $action['icon'] ?? $action[3];

			if ( ! current_user_can( $capability ) ) {
				continue;
			}

			printf(
				'<a class="hd-manager-action" href="%1$s"><span class="hd-manager-action-icon" aria-hidden="true">%2$s</span><span><strong>%3$s</strong><small>%4$s</small></span><b aria-hidden="true">‹</b></a>',
				esc_url( $url ),
				self::manager_icon( $icon ),
				esc_html( $name ),
				esc_html( $desc )
			);
		}
		echo '</div></section>';

		if ( current_user_can( Hedayati_Audit_Log::VIEW_CAPABILITY ) ) {
			printf(
				'<aside class="hd-manager-audit"><div><strong>%1$s</strong><p>%2$s</p></div><a href="%3$s">%4$s</a></aside>',
				esc_html__( 'گزارش فعالیت‌های مدیریتی', 'hedayati-core' ),
				esc_html__( 'رویدادهای حساس سامانه بدون نمایش اطلاعات خصوصی ثبت می‌شوند.', 'hedayati-core' ),
				esc_url( admin_url( 'admin.php?page=hedayati-academic-audit' ) ),
				esc_html__( 'مشاهدهٔ گزارش', 'hedayati-core' )
			);
		}
	}

	/** @return array<int, array{label:string,value:int,hint:string,url:string}> */
	private static function manager_metrics(): array {
		$course_counts = wp_count_posts( 'course' );
		$published     = isset( $course_counts->publish ) ? (int) $course_counts->publish : 0;

		$featured_query = new WP_Query( [
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_course_is_featured',
					'value'   => '1',
					'compare' => '=',
				],
			],
		] );

		$active_runs     = Hedayati_Course_Run_Service::count_active();
		$active_students = Hedayati_Enrollment_Service::count_active_students();

		$metrics = [
			[
				'label' => __( 'دوره‌های منتشرشده', 'hedayati-core' ),
				'value' => $published,
				'hint'  => __( 'مدیریت دوره‌ها', 'hedayati-core' ),
				'url'   => self::url( [ 'view' => 'courses' ] ),
			],
			[
				'label' => __( 'دوره‌های ویژه', 'hedayati-core' ),
				'value' => (int) $featured_query->found_posts,
				'hint'  => __( 'نمایش در صفحه نخست', 'hedayati-core' ),
				'url'   => self::url( [ 'view' => 'featured' ] ),
			],
			[
				'label' => __( 'کلاس‌های فعال', 'hedayati-core' ),
				'value' => $active_runs,
				'hint'  => __( 'برنامه‌ریزی و اجرا', 'hedayati-core' ),
				'url'   => admin_url( 'admin.php?page=hedayati-academic' ),
			],
			[
				'label' => __( 'دانشجویان فعال', 'hedayati-core' ),
				'value' => $active_students,
				'hint'  => __( 'پرونده و ثبت‌نام', 'hedayati-core' ),
				'url'   => self::url( [ 'view' => 'students' ] ),
			],
		];

		if ( current_user_can( Hedayati_Consultation_Service::CAPABILITY ) ) {
			$metrics[] = [
				'label' => __( 'درخواست مشاورهٔ جدید', 'hedayati-core' ),
				'value' => Hedayati_Consultation_Service::count_new(),
				'hint'  => __( 'پیگیری تماس', 'hedayati-core' ),
				'url'   => self::url( [ 'view' => 'consultations' ] ),
			];
		}

		if ( current_user_can( Hedayati_Support_Service::STAFF_CAP ) ) {
			$metrics[] = [
				'label' => __( 'تیکت در انتظار پاسخ', 'hedayati-core' ),
				'value' => Hedayati_Support_Service::count_waiting_staff(),
				'hint'  => __( 'پشتیبانی دانشجو', 'hedayati-core' ),
				'url'   => self::url( [ 'view' => 'support' ] ),
			];
		}

		return $metrics;
	}

	/** Small dependency-free icons for the manager action cards. */
	private static function manager_icon( string $name ): string {
		$paths = [
			'book'     => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H11v15H6.5A2.5 2.5 0 0 0 4 20.5zM20 5.5A2.5 2.5 0 0 0 17.5 3H13v15h4.5a2.5 2.5 0 0 1 2.5 2.5z"/>',
			'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
			'users'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
			'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10zM9 12l2 2 4-4"/>',
			'teacher'  => '<path d="M22 10 12 5 2 10l10 5 10-5zM6 12.5V17c3 2.5 9 2.5 12 0v-4.5M22 10v6"/>',
			'settings' => '<circle cx="12" cy="12" r="3"/>'
				. '<path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1A8 8 0 0 0 15 6.2L14.7 4h-4L10.4 6.2A8 8 0 0 0 8.8 7l-2.3-1-2 3.5 2 1.5a7 7 0 0 0 0 2l-2 1.5 2 3.5 2.3-1a8 8 0 0 0 1.6.8l.3 2.2h4l.3-2.2a8 8 0 0 0 1.5-.8l2.4 1 2-3.5-2-1.5a7 7 0 0 0 .1-1z"/>',
			'chat'     => '<path d="M21 11.5a8.4 8.4 0 0 1-8.5 8.5 8.6 8.6 0 0 1-4-1L3 20l1-4.5a8.4 8.4 0 0 1-1-4A8.4 8.4 0 0 1 11.5 3h.5a8.4 8.4 0 0 1 9 8z"/>',
			'award'    => '<circle cx="12" cy="8" r="5"/><path d="M8.2 12.3 7 22l5-3 5 3-1.2-9.7"/>',
			'folder'   => '<path d="M4 5h5l2 3h9v11H4z"/>',
			'lifebuoy' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="m6.5 6.5 3 3M14.5 14.5l3 3M17.5 6.5l-3 3M9.5 14.5l-3 3"/>',
			'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
		];

		$path = $paths[ $name ] ?? $paths['book'];
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $path . '</svg>';
	}

	// ── Courses (in-panel, adapted from the AI Studio "مدیریت دوره‌ها" tab) ──

	/** Published + non-published `course` posts, newest first, optionally filtered. */
	private static function course_query( string $search, bool $featured_only ): WP_Query {
		$args = [
			'post_type'      => 'course',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
		];

		if ( $featured_only ) {
			$args['meta_query'] = [
				[
					'key'     => '_course_is_featured',
					'value'   => '1',
					'compare' => '=',
				],
			];
		}

		return new WP_Query( $args );
	}

	/** How many `course` posts currently carry the homepage-featured flag. */
	private static function featured_count(): int {
		$q = new WP_Query( [
			'post_type'      => 'course',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_course_is_featured',
					'value'   => '1',
					'compare' => '=',
				],
			],
		] );

		return (int) $q->found_posts;
	}

	private static function is_featured( int $course_id ): bool {
		return (bool) get_post_meta( $course_id, '_course_is_featured', true );
	}

	/** One nonce-protected toggle button (feature / publish) for a course row. */
	private static function toggle_button( string $action, int $course_id, string $label, bool $on ): void {
		self::form_open( $action, [ 'course_id' => $course_id ], false, 'hd-manager-toggle' );
		printf(
			'<button class="hd-manager-toggle-btn%s" type="submit">%s</button></form>',
			$on ? ' is-on' : '',
			esc_html( $label )
		);
	}

	private static function render_courses(): void {
		$search        = self::get( 'q' );
		$featured_only = '1' === self::get( 'featured' );
		$query         = self::course_query( $search, $featured_only );
		$featured_now  = self::featured_count();

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'مدیریت محتوا', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'فهرست دوره‌های آموزشی', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: featured course count, 2: featured slot limit */
					__( 'ویرایش کامل هر دوره در ویرایشگر وردپرس انجام می‌شود. %1$s دوره از %2$s جایگاه ویژهٔ صفحهٔ نخست انتخاب شده است.', 'hedayati-core' ),
					Hedayati_Text::digits_to_persian( (string) $featured_now ),
					Hedayati_Text::digits_to_persian( (string) self::FEATURED_LIMIT )
				)
			)
		);
		echo '</div>';
		printf(
			'<a class="hd-manager-primary" href="%s">%s</a>',
			esc_url( admin_url( 'post-new.php?post_type=course' ) ),
			esc_html__( 'دورهٔ جدید', 'hedayati-core' )
		);
		echo '</header>';

		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( self::url() ) . '">';
		echo '<input type="hidden" name="view" value="courses">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجوی دوره', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'جستجو در عنوان دوره…', 'hedayati-core' )
		);
		printf(
			'<label class="hd-manager-check"><input type="checkbox" name="featured" value="1"%s onchange="this.form.submit()"> %s</label>',
			checked( $featured_only, true, false ),
			esc_html__( 'فقط دوره‌های ویژه', 'hedayati-core' )
		);
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال', 'hedayati-core' ) );
		echo '</form>';

		if ( ! $query->have_posts() ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'دوره‌ای یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-table" role="table">';
		echo '<div class="hd-manager-tr hd-manager-th" role="row">';
		$headings = [
			__( 'عنوان دوره', 'hedayati-core' ),
			__( 'دپارتمان', 'hedayati-core' ),
			__( 'مدت', 'hedayati-core' ),
			__( 'وضعیت انتشار', 'hedayati-core' ),
			__( 'ویژهٔ صفحهٔ نخست', 'hedayati-core' ),
			__( 'ویرایش', 'hedayati-core' ),
		];
		foreach ( $headings as $heading ) {
			echo '<span role="columnheader">' . esc_html( $heading ) . '</span>';
		}
		echo '</div>';

		foreach ( $query->posts as $course_post ) {
			$course_id = (int) $course_post->ID;
			$english   = (string) get_post_meta( $course_id, '_course_english_name', true );
			$duration  = (string) get_post_meta( $course_id, '_course_duration', true );
			$terms     = get_the_term_list( $course_id, 'course-category', '', '، ' );
			$featured  = self::is_featured( $course_id );
			$published = 'publish' === $course_post->post_status;

			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell" class="hd-manager-course-cell"><strong>' . esc_html( get_the_title( $course_post ) ?: __( '(بدون عنوان)', 'hedayati-core' ) ) . '</strong>';
			if ( '' !== $english ) {
				echo '<small dir="ltr">' . esc_html( $english ) . '</small>';
			}
			echo '</span>';
			echo '<span role="cell">' . ( $terms && ! is_wp_error( $terms ) ? wp_kses_post( $terms ) : '<span class="hd-portal-note">—</span>' ) . '</span>';
			echo '<span role="cell">' . ( '' !== $duration ? esc_html( $duration ) : '—' ) . '</span>';
			echo '<span role="cell">';
			self::toggle_button( 'course_publish', $course_id, $published ? __( 'منتشر شده', 'hedayati-core' ) : __( 'پیش‌نویس', 'hedayati-core' ), $published );
			echo '</span>';
			echo '<span role="cell">';
			self::toggle_button( 'course_feature', $course_id, $featured ? __( 'ویژه', 'hedayati-core' ) : __( 'عادی', 'hedayati-core' ), $featured );
			echo '</span>';
			printf(
				'<span role="cell"><a class="hd-manager-row-edit" href="%s">%s</a></span>',
				esc_url( get_edit_post_link( $course_id ) ?: admin_url( 'edit.php?post_type=course' ) ),
				esc_html__( 'ویرایش در ویرایشگر', 'hedayati-core' )
			);
			echo '</div>';
		}
		echo '</div>';
	}

	private static function render_featured(): void {
		$featured_now = self::featured_count();
		$query        = self::course_query( '', false );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'مدیریت صفحهٔ نخست', 'hedayati-core' ) . '</span>';
		printf(
			'<h1 class="hd-portal-title">%s</h1>',
			esc_html(
				sprintf(
					/* translators: 1: current featured count, 2: limit */
					__( 'دوره‌های ویژهٔ صفحهٔ نخست (%1$s از %2$s)', 'hedayati-core' ),
					Hedayati_Text::digits_to_persian( (string) $featured_now ),
					Hedayati_Text::digits_to_persian( (string) self::FEATURED_LIMIT )
				)
			)
		);
		echo '<p class="hd-portal-note">' . esc_html__( 'حداکثر ۸ دوره در صفحهٔ نخست نمایش داده می‌شود. برای بهترین چیدمان دقیقاً ۸ دوره را انتخاب کنید.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		if ( ! $query->have_posts() ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'هنوز دوره‌ای تعریف نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-feature-grid">';
		foreach ( $query->posts as $course_post ) {
			$course_id = (int) $course_post->ID;
			$featured  = self::is_featured( $course_id );
			$english   = (string) get_post_meta( $course_id, '_course_english_name', true );

			self::form_open( 'course_feature', [ 'course_id' => $course_id ], false, 'hd-manager-feature-item' );
			printf(
				'<button class="hd-manager-feature-btn%s" type="submit"><span class="hd-manager-feature-star" aria-hidden="true">%s</span><span><strong>%s</strong><small dir="ltr">%s</small></span></button></form>',
				$featured ? ' is-on' : '',
				$featured ? '★' : '☆',
				esc_html( get_the_title( $course_post ) ?: __( '(بدون عنوان)', 'hedayati-core' ) ),
				esc_html( $english )
			);
		}
		echo '</div>';
	}

	private static function render_my_runs(): void {
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'کلاس‌های من', 'hedayati-core' ) . '</h2>';

		$run_ids = Hedayati_Run_Staff_Service::run_ids_for_user( get_current_user_id() );

		if ( empty( $run_ids ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'هنوز کلاسی به شما اختصاص داده نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<ul class="hd-portal-run-list">';
		foreach ( $run_ids as $run_id ) {
			$run = Hedayati_Course_Run_Service::get( (int) $run_id );
			if ( null === $run ) {
				continue;
			}

			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( self::url( [ 'view' => 'run', 'run_id' => $run_id ] ) ),
				esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['label'] ?: '#' . $run_id ) )
			);
		}
		echo '</ul>';
	}

	private static function render_run( int $run_id ): void {
		if (
			! self::can_run( $run_id, 'hedayati_view_assigned_roster' )
			&& ! self::can_run( $run_id, 'hedayati_manage_course_runs' )
		) {
			self::deny();
		}

		$run         = Hedayati_Course_Run_Service::get( $run_id );
		$enrollments = Hedayati_Enrollment_Service::list_for_run( $run_id );

		echo '<h1 class="hd-portal-title">' . esc_html( get_the_title( $run['course_id'] ) ) . '</h1>';
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'فهرست دانشجویان', 'hedayati-core' ) . '</h2>';

		$roster = [];
		foreach ( $enrollments as $enrollment ) {
			$user = get_user_by( 'id', $enrollment['user_id'] );
			if ( $user ) {
				// TA and teacher get names only — no email, phone, identity or documents.
				$roster[] = $user->display_name;
			}
		}

		if ( empty( $roster ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'هنوز دانشجویی در این کلاس ثبت‌نام نکرده است.', 'hedayati-core' ) . '</p>';
		} else {
			echo '<ul class="hd-portal-roster">';
			foreach ( $roster as $name ) {
				echo '<li>' . esc_html( $name ) . '</li>';
			}
			echo '</ul>';
		}

		// Run progress (objective, from sessions) — visible to any staff on the run.
		$rp = Hedayati_Progress_Service::run_progress( $run_id );
		if ( $rp['total'] > 0 ) {
			printf(
				'<p class="hd-portal-note">%s</p>',
				esc_html( sprintf(
					/* translators: 1: held, 2: total, 3: percent */
					__( 'پیشرفت دوره: %1$s از %2$s جلسه (%3$s٪)', 'hedayati-core' ),
					Hedayati_Text::digits_to_persian( (string) $rp['held'] ),
					Hedayati_Text::digits_to_persian( (string) $rp['total'] ),
					Hedayati_Text::digits_to_persian( (string) Hedayati_Progress_Service::percent( $rp['ratio'] ) )
				) )
			);
		}

		$can_sessions = current_user_can( 'hedayati_manage_assigned_sessions' ) || current_user_can( 'hedayati_manage_course_runs' );
		if ( $can_sessions ) {
			self::render_run_sessions( $run_id, $enrollments );
		}

		// Course/session materials — self-gates on hedayati_manage_session_materials
		// + staff-on-run, so a TA (who lacks the cap) sees nothing here.
		Hedayati_Material_Service::render_run_section( $run_id );
	}

	private static function render_run_sessions( int $run_id, array $enrollments ): void {
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'جلسات و حضور و غیاب', 'hedayati-core' ) . '</h2>';

		foreach ( Hedayati_Session_Service::list_for_run( $run_id ) as $session ) {
			$date = Hedayati_Jalali::format( substr( (string) $session['starts_at'], 0, 10 ) );
			$time = substr( (string) $session['starts_at'], 11, 5 );

			echo '<section class="hd-staff-section">';
			echo '<h3>' . esc_html( trim( $date . ' ' . $time . ' — ' . (string) $session['topic'] ) ) . '</h3>';

			if ( current_user_can( 'hedayati_record_attendance' ) ) {
				self::render_attendance_form( $session, $enrollments );
			}

			echo '</section>';
		}

		self::form_open( 'session', [ 'run_id' => $run_id ] );
		echo '<h3>' . esc_html__( 'جلسهٔ جدید', 'hedayati-core' ) . '</h3>';
		self::field( 'date', __( 'تاریخ شمسی (۱۴۰۵/۰۶/۱۵) یا میلادی', 'hedayati-core' ), 'text', true );
		self::field( 'time', __( 'ساعت شروع', 'hedayati-core' ), 'time', true );
		self::field( 'topic', __( 'موضوع جلسه', 'hedayati-core' ) );
		self::submit( __( 'افزودن جلسه', 'hedayati-core' ) );
	}

	private static function render_attendance_form( array $session, array $enrollments ): void {
		$marks = [];
		foreach ( Hedayati_Attendance_Service::list_for_session( (int) $session['id'] ) as $mark ) {
			$marks[ (int) $mark['enrollment_id'] ] = $mark['status'];
		}

		self::form_open( 'attendance', [ 'session_id' => $session['id'] ], false, 'hd-portal-attendance' );

		$rendered = 0;
		foreach ( $enrollments as $enrollment ) {
			if ( 'active' !== $enrollment['status'] ) {
				continue;
			}

			$user = get_user_by( 'id', $enrollment['user_id'] );
			if ( ! $user ) {
				continue;
			}

			$current = $marks[ (int) $enrollment['id'] ] ?? '';

			echo '<label class="hd-portal-field"><span>' . esc_html( $user->display_name ) . '</span>';
			echo '<select name="mark[' . esc_attr( (string) $enrollment['id'] ) . ']">';
			foreach ( self::ATTENDANCE_LABELS as $value => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $current, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select></label>';
			$rendered++;
		}

		if ( 0 === $rendered ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'دانشجوی فعالی برای ثبت حضور در این کلاس وجود ندارد.', 'hedayati-core' ) . '</p></form>';
			return;
		}

		self::submit( __( 'ذخیرهٔ حضور و غیاب', 'hedayati-core' ) );
	}

	private static function render_students(): void {
		if ( ! current_user_can( 'hedayati_view_student_profiles_basic' ) ) {
			self::deny();
		}

		echo '<h1 class="hd-portal-title">' . esc_html__( 'پذیرش و پروندهٔ دانشجو', 'hedayati-core' ) . '</h1>';

		// Search posts (not GET) so names / phones / emails never land in an access log URL.
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( self::url( [ 'view' => 'students' ] ) ) . '">';
		wp_nonce_field( 'hedayati_staff_search' );
		self::field( 'search', __( 'نام، نام کاربری، ایمیل یا شمارهٔ همراه', 'hedayati-core' ) );
		self::submit( __( 'جستجو', 'hedayati-core' ) );

		$search = self::post( 'search' );
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( '' !== $search && wp_verify_nonce( $nonce, 'hedayati_staff_search' ) ) {
			self::render_search_results( $search );
		}

		$student_id = absint( self::get( 'student_id' ) );
		if ( $student_id > 0 ) {
			self::render_student( self::require_student( $student_id ) );
			return;
		}

		if ( current_user_can( 'hedayati_create_students' ) ) {
			self::render_create_student_form();
		}
	}

	private static function render_search_results( string $search ): void {
		$phone_match = Hedayati_User_Phone_Service::find_user_by_phone( $search );

		$users = $phone_match
			? [ $phone_match ]
			: get_users( [
				'role'           => 'student',
				'search'         => '*' . $search . '*',
				'search_columns' => [ 'user_login', 'display_name', 'user_email' ],
				'number'         => 50,
			] );

		$rows = [];
		foreach ( $users as $user ) {
			if ( ! in_array( 'student', (array) $user->roles, true ) ) {
				continue;
			}
			$rows[] = sprintf(
				'<li><a href="%s">%s</a></li>',
				esc_url( self::url( [ 'view' => 'students', 'student_id' => $user->ID ] ) ),
				esc_html( $user->display_name )
			);
		}

		if ( empty( $rows ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'دانشجویی یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<ul class="hd-portal-result-list">' . implode( '', $rows ) . '</ul>';
	}

	private static function render_create_student_form(): void {
		self::form_open( 'student' );
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'ایجاد حساب دانشجو', 'hedayati-core' ) . '</h2>';
		self::field( 'first_name', __( 'نام', 'hedayati-core' ), 'text', true );
		self::field( 'last_name', __( 'نام خانوادگی', 'hedayati-core' ), 'text', true );
		self::field( 'user_login', __( 'نام کاربری', 'hedayati-core' ), 'text', true );
		self::field( 'phone', __( 'شمارهٔ همراه', 'hedayati-core' ), 'text', true );
		self::field( 'email', __( 'ایمیل برای بازیابی رمز عبور (اختیاری)', 'hedayati-core' ), 'email' );
		echo '<p class="hd-portal-note">' . esc_html__( 'یک رمز عبور موقت به‌صورت خودکار ساخته و یک‌بار به شما نمایش داده می‌شود. دانشجو در نخستین ورود آن را تغییر می‌دهد.', 'hedayati-core' ) . '</p>';
		self::submit( __( 'ایجاد حساب', 'hedayati-core' ) );
	}

	private static function render_student( WP_User $user ): void {
		$user_id = $user->ID;
		$phone   = Hedayati_User_Phone_Service::get_phone_record_by_user( $user_id );
		$status  = Hedayati_Verification_Service::get_status( $user_id );

		echo '<h2 class="hd-portal-subtitle">' . esc_html( $user->display_name ) . '</h2>';
		echo '<p><bdi>' . esc_html( trim( $user->user_login . ' — ' . ( $phone['phone_e164'] ?? '' ) ) ) . '</bdi></p>';
		echo '<p>' . esc_html__( 'احراز هویت:', 'hedayati-core' ) . ' '
			. esc_html( self::VERIFICATION_LABELS[ $status['status'] ] ?? self::VERIFICATION_LABELS['unverified'] ) . '</p>';

		if ( current_user_can( 'hedayati_create_enrollments' ) ) {
			self::render_enroll_form( $user_id );
		}

		echo '<h3>' . esc_html__( 'ثبت‌نام‌ها', 'hedayati-core' ) . '</h3>';
		$rows = [];
		foreach ( Hedayati_Enrollment_Service::list_for_user( $user_id ) as $enrollment ) {
			$run = Hedayati_Course_Run_Service::get( (int) $enrollment['run_id'] );
			if ( $run ) {
				$rows[] = '<li>' . esc_html( get_the_title( $run['course_id'] ) . ' — #' . $run['id'] ) . '</li>';
			}
		}
		echo $rows
			? '<ul class="hd-portal-result-list">' . implode( '', $rows ) . '</ul>'
			: '<p class="hd-portal-note">' . esc_html__( 'ثبت‌نامی ندارد.', 'hedayati-core' ) . '</p>';

		if ( current_user_can( 'hedayati_upload_student_documents' ) ) {
			self::render_identity_forms( $user_id );
		}

		if (
			current_user_can( 'hedayati_initiate_verification' )
			&& in_array( $status['status'], [ 'unverified', 'rejected' ], true )
		) {
			self::form_open( 'verify', [ 'student_id' => $user_id ] );
			self::submit( __( 'ارسال برای بررسی احراز هویت', 'hedayati-core' ) );
		}
	}

	private static function render_enroll_form( int $user_id ): void {
		self::form_open( 'enroll', [ 'student_id' => $user_id ] );
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'ثبت‌نام در کلاس', 'hedayati-core' ) . '</span>';
		echo '<select name="run_id" required><option value="">' . esc_html__( 'انتخاب کلاس', 'hedayati-core' ) . '</option>';

		foreach ( Hedayati_Course_Run_Service::query( [ 'limit' => 500 ] ) as $run ) {
			if ( ! in_array( $run['run_status'], [ 'scheduled', 'in_progress' ], true ) ) {
				continue;
			}
			printf(
				'<option value="%s">%s</option>',
				esc_attr( (string) $run['id'] ),
				esc_html( get_the_title( $run['course_id'] ) . ' — #' . $run['id'] )
			);
		}

		echo '</select></label>';
		self::submit( __( 'ثبت‌نام دانشجو', 'hedayati-core' ) );
	}

	private static function render_identity_forms( int $user_id ): void {
		self::form_open( 'identity', [ 'student_id' => $user_id ] );
		self::field( 'national_id', __( 'ثبت یا اصلاح کد ملی', 'hedayati-core' ), 'text', true );
		self::submit( __( 'ذخیرهٔ کد ملی', 'hedayati-core' ) );

		self::form_open( 'upload', [ 'student_id' => $user_id ], true );
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'نوع مدرک', 'hedayati-core' ) . '</span>';
		echo '<select name="doc_type">';
		echo '<option value="national_card">' . esc_html__( 'کارت ملی', 'hedayati-core' ) . '</option>';
		echo '<option value="birth_certificate">' . esc_html__( 'شناسنامه', 'hedayati-core' ) . '</option>';
		echo '<option value="other">' . esc_html__( 'سایر', 'hedayati-core' ) . '</option>';
		echo '</select></label>';
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'فایل PDF، JPEG یا PNG', 'hedayati-core' ) . '</span>';
		echo '<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required></label>';
		self::submit( __( 'بارگذاری مدرک', 'hedayati-core' ) );
	}

	// ── Mutation handlers ───────────────────────────────────────────────────

	public static function handle_session(): void {
		self::verify( 'session' );

		$run_id = absint( self::post( 'run_id' ) );
		if ( ! self::can_run( $run_id, 'hedayati_manage_assigned_sessions' ) ) {
			self::deny();
		}

		$date = Hedayati_Academic_Validation::parse_iso_date( self::post( 'date' ) )
			?: Hedayati_Jalali::parse_input( self::post( 'date' ) );

		if ( ! $date ) {
			self::finish( new WP_Error( 'date', __( 'تاریخ نامعتبر است.', 'hedayati-core' ) ), [ 'view' => 'run', 'run_id' => $run_id ] );
		}

		$result = Hedayati_Session_Service::create( [
			'run_id'         => $run_id,
			'session_number' => Hedayati_Session_Service::next_session_number( $run_id ),
			'starts_at'      => $date . ' ' . self::post( 'time' ) . ':00',
			'topic'          => self::post( 'topic' ),
		] );

		self::finish( $result, [ 'view' => 'run', 'run_id' => $run_id ] );
	}

	public static function handle_attendance(): void {
		self::verify( 'attendance' );

		$session = Hedayati_Session_Service::get( absint( self::post( 'session_id' ) ) );
		if ( ! $session || ! self::can_run( (int) $session['run_id'], 'hedayati_record_attendance' ) ) {
			self::deny();
		}

		$marks = isset( $_POST['mark'] ) && is_array( $_POST['mark'] ) ? wp_unslash( $_POST['mark'] ) : [];

		// Validate the whole batch — including forged foreign enrollment IDs —
		// before writing any single mark.
		foreach ( $marks as $enrollment_id => $status ) {
			$enrollment = Hedayati_Enrollment_Service::get( absint( $enrollment_id ) );
			$valid_status = is_string( $status )
				&& ( '' === $status || in_array( $status, Hedayati_Academic_Validation::ATTENDANCE_STATUSES, true ) );

			if (
				! $enrollment
				|| (int) $enrollment['run_id'] !== (int) $session['run_id']
				|| 'active' !== $enrollment['status']
				|| ! $valid_status
			) {
				self::deny();
			}
		}

		$result = true;
		foreach ( $marks as $enrollment_id => $status ) {
			if ( '' === $status ) {
				continue;
			}
			$saved = Hedayati_Attendance_Service::record(
				(int) $session['id'],
				absint( $enrollment_id ),
				(string) $status,
				[ 'recorded_by' => get_current_user_id() ]
			);
			if ( is_wp_error( $saved ) ) {
				$result = $saved;
				break;
			}
		}

		self::finish( $result, [ 'view' => 'run', 'run_id' => $session['run_id'] ] );
	}

	public static function handle_student(): void {
		self::verify( 'student' );

		$first = self::post( 'first_name' );
		$last  = self::post( 'last_name' );
		$login = self::post( 'user_login' );
		$email = sanitize_email( self::post( 'email' ) );
		$phone_raw = self::post( 'phone' );

		$normalized = Hedayati_Phone::normalize( $phone_raw );

		if (
			'' === $first
			|| '' === $last
			|| '' === $login
			|| is_wp_error( $normalized )
			|| ! Hedayati_User_Phone_Service::is_phone_available( $phone_raw )
		) {
			self::finish(
				new WP_Error( 'invalid', __( 'نام، نام خانوادگی، نام کاربری و یک شمارهٔ همراه معتبر و یکتا لازم است.', 'hedayati-core' ) ),
				[ 'view' => 'students' ]
			);
		}

		$temp_password = Hedayati_Account_Security::generate_temp_password();

		$user_id = wp_insert_user( [
			'user_login'   => $login,
			'user_pass'    => $temp_password,
			'user_email'   => $email,
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => trim( $first . ' ' . $last ),
			'role'         => 'student',
		] );

		if ( is_wp_error( $user_id ) ) {
			self::finish(
				new WP_Error( 'create', __( 'ایجاد حساب ناموفق بود؛ نام کاربری و ایمیل را بررسی کنید.', 'hedayati-core' ) ),
				[ 'view' => 'students' ]
			);
		}

		$assigned = Hedayati_User_Phone_Service::assign_phone( $user_id, $phone_raw );

		if ( is_wp_error( $assigned ) ) {
			// Compensate only the account this request just created (e.g. a phone race).
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
			self::finish( $assigned, [ 'view' => 'students' ] );
		}

		Hedayati_Account_Security::require_change( $user_id );
		Hedayati_Audit_Log::record( 'account.created', 'account', $user_id, 'reception-created student', get_current_user_id() );

		self::finish( true, [ 'view' => 'students', 'student_id' => $user_id ], $temp_password );
	}

	public static function handle_enroll(): void {
		self::verify( 'enroll' );
		$user_id = absint( self::post( 'student_id' ) );
		self::require_student( $user_id );

		self::finish(
			Hedayati_Enrollment_Service::enroll( absint( self::post( 'run_id' ) ), $user_id ),
			[ 'view' => 'students', 'student_id' => $user_id ]
		);
	}

	public static function handle_identity(): void {
		self::verify( 'identity' );
		$user_id = absint( self::post( 'student_id' ) );
		self::require_student( $user_id );

		self::finish(
			Hedayati_Verification_Service::set_national_id( $user_id, self::post( 'national_id' ), get_current_user_id() ),
			[ 'view' => 'students', 'student_id' => $user_id ]
		);
	}

	public static function handle_verify(): void {
		self::verify( 'verify' );
		$user_id = absint( self::post( 'student_id' ) );
		self::require_student( $user_id );

		self::finish(
			Hedayati_Verification_Service::initiate( $user_id, get_current_user_id() ),
			[ 'view' => 'students', 'student_id' => $user_id ]
		);
	}

	public static function handle_upload(): void {
		self::verify( 'upload' );
		$user_id = absint( self::post( 'student_id' ) );
		self::require_student( $user_id );

		$file = isset( $_FILES['document'] ) && is_array( $_FILES['document'] ) ? $_FILES['document'] : [];

		self::finish(
			Hedayati_Document_Service::upload( $user_id, $file, self::post( 'doc_type' ), get_current_user_id() ),
			[ 'view' => 'students', 'student_id' => $user_id ]
		);
	}

	/** Toggle a course's homepage-featured flag; enforce the 8-slot cap server-side. */
	public static function handle_course_feature(): void {
		self::verify( 'course_feature' );

		$course_id = absint( self::post( 'course_id' ) );
		$post      = get_post( $course_id );

		if ( ! $post || 'course' !== $post->post_type || ! current_user_can( 'edit_post', $course_id ) ) {
			self::deny();
		}

		$currently = self::is_featured( $course_id );

		if ( ! $currently && self::featured_count() >= self::FEATURED_LIMIT ) {
			self::finish(
				new WP_Error( 'featured_full', __( 'حداکثر ۸ دوره می‌تواند در صفحهٔ نخست ویژه باشد. ابتدا یک دوره را از حالت ویژه خارج کنید.', 'hedayati-core' ) ),
				[ 'view' => 'featured' ]
			);
		}

		// Store a real boolean, mirroring Hedayati_Meta_Box::save() exactly
		// (WordPress serialises true → '1', false → '' — what the featured query matches).
		update_post_meta( $course_id, '_course_is_featured', ! $currently );

		self::finish( true, [ 'view' => 'featured' ] );
	}

	/** Toggle a course between published and draft. */
	public static function handle_course_publish(): void {
		self::verify( 'course_publish' );

		$course_id = absint( self::post( 'course_id' ) );
		$post      = get_post( $course_id );

		if ( ! $post || 'course' !== $post->post_type || ! current_user_can( 'edit_post', $course_id ) ) {
			self::deny();
		}

		// verify() already confirmed hedayati_manage_courses, which the course CPT
		// maps to publish_posts / edit_published_posts; edit_post is re-checked above.
		$next   = 'publish' === $post->post_status ? 'draft' : 'publish';
		$result = wp_update_post( [ 'ID' => $course_id, 'post_status' => $next ], true );

		self::finish(
			is_wp_error( $result ) ? $result : true,
			[ 'view' => 'courses' ]
		);
	}
}
