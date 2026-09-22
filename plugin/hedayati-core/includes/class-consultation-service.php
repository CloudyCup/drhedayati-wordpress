<?php
/**
 * AI Studio parity — public consultation requests + staff queue (owner decision D46).
 *
 * Supersedes the earlier "phone CTA only" position. A visitor submits a short
 * consultation request from `/consult/`; authorised staff
 * (`hedayati_manage_consultations` — reception + manager) work it through a small
 * status queue (`new` → `contacted` → `closed`) inside `/panel/`.
 *
 * Storage is the dedicated `hedayati_consultations` table (migration 2.4.0), never
 * mock data. No automatic SMS/email is sent (owner). State-changing staff actions
 * are audited with PII-free notes; the request name/phone/message are never copied
 * into the audit log.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Consultation_Service {

	public const CAPABILITY = 'hedayati_manage_consultations';

	public const STATUSES = [ 'new', 'contacted', 'closed' ];

	private const SUBMIT_ACTION      = 'hedayati_consultation_submit';
	private const NONCE_ACTION       = 'hedayati_consultation_public';
	private const RATE_WINDOW        = 3600; // 1 hour
	private const RATE_MAX_PER_IP    = 5;

	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::SUBMIT_ACTION, [ self::class, 'handle_submit' ] );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, [ self::class, 'handle_submit' ] );

		add_action( 'admin_post_hedayati_staff_consult_status', [ self::class, 'handle_status' ] );
		add_action( 'admin_post_hedayati_staff_consult_note', [ self::class, 'handle_note' ] );

		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );

		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'consultation' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [
			'consultation.created',
			'consultation.status_changed',
			'consultation.note_updated',
		] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views['consultations'] = [
			'capability' => self::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'درخواست‌های مشاوره', 'hedayati-core' ),
			'title'      => __( 'درخواست‌های مشاوره', 'hedayati-core' ),
			'desc'       => __( 'صف درخواست‌های مشاوره از فرم عمومی سایت و پیگیری تماس‌ها', 'hedayati-core' ),
			'icon'       => 'chat',
		];
		return $views;
	}

	// ── Status vocabulary ───────────────────────────────────────────────────

	public static function status_label( string $status ): string {
		return [
			'new'       => __( 'جدید', 'hedayati-core' ),
			'contacted' => __( 'تماس گرفته شد', 'hedayati-core' ),
			'closed'    => __( 'بسته‌شده', 'hedayati-core' ),
		][ $status ] ?? $status;
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_consultations();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array{status?:string,search?:string,per_page?:int,page?:int} $args
	 * @return array<int, array>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$table  = Hedayati_DB_Schema::get_table_consultations();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR phone_e164 LIKE %s OR topic LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 40;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY FIELD(status,'new','contacted','closed'), created_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_new(): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_consultations();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
	}

	// ── Create (public) ─────────────────────────────────────────────────────

	/**
	 * @return int|WP_Error new consultation id
	 */
	public static function create( array $input ): int|WP_Error {
		global $wpdb;

		$name    = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$topic   = sanitize_text_field( (string) ( $input['topic'] ?? '' ) );
		$message = sanitize_textarea_field( (string) ( $input['message'] ?? '' ) );
		$phone   = Hedayati_Phone::normalize( (string) ( $input['phone'] ?? '' ) );

		if ( mb_strlen( $name ) < 2 ) {
			return new WP_Error( 'name', __( 'نام و نام خانوادگی را وارد کنید.', 'hedayati-core' ) );
		}
		if ( is_wp_error( $phone ) ) {
			return new WP_Error( 'phone', __( 'شمارهٔ همراه معتبر ایران را وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹).', 'hedayati-core' ) );
		}
		if ( mb_strlen( $message ) > 2000 ) {
			$message = mb_substr( $message, 0, 2000 );
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_consultations();

		$inserted = $wpdb->insert(
			$table,
			[
				'name'       => mb_substr( $name, 0, 190 ),
				'phone_e164' => $phone,
				'topic'      => mb_substr( $topic, 0, 190 ),
				'message'    => $message,
				'status'     => 'new',
				'source'     => 'public_form',
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db', __( 'ثبت درخواست ناموفق بود؛ لطفاً دوباره تلاش کنید.', 'hedayati-core' ) );
		}

		$id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'consultation.created', 'consultation', $id, 'public form' );

		/**
		 * Fires after a consultation request is stored. Used to notify staff.
		 *
		 * @param int $id consultation id
		 */
		do_action( 'hedayati_consultation_created', $id );

		return $id;
	}

	// ── Staff mutations ─────────────────────────────────────────────────────

	public static function set_status( int $id, string $status, int $actor_id ): true|WP_Error {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'وضعیت نامعتبر است.', 'hedayati-core' ) );
		}

		$existing = self::get( $id );
		if ( null === $existing ) {
			return new WP_Error( 'not_found', __( 'درخواست یافت نشد.', 'hedayati-core' ) );
		}
		if ( $existing['status'] === $status ) {
			return true;
		}

		$table   = Hedayati_DB_Schema::get_table_consultations();
		$updated = $wpdb->update(
			$table,
			[
				'status'     => $status,
				'handled_by' => $actor_id ?: null,
				'updated_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( 'db', __( 'به‌روزرسانی وضعیت ناموفق بود.', 'hedayati-core' ) );
		}

		Hedayati_Audit_Log::record(
			'consultation.status_changed',
			'consultation',
			$id,
			$existing['status'] . ' -> ' . $status,
			$actor_id
		);

		return true;
	}

	public static function set_note( int $id, string $note, int $actor_id ): true|WP_Error {
		global $wpdb;

		$existing = self::get( $id );
		if ( null === $existing ) {
			return new WP_Error( 'not_found', __( 'درخواست یافت نشد.', 'hedayati-core' ) );
		}

		$note  = mb_substr( sanitize_textarea_field( $note ), 0, 500 );
		$table = Hedayati_DB_Schema::get_table_consultations();

		$updated = $wpdb->update(
			$table,
			[ 'staff_note' => $note, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( 'db', __( 'ذخیرهٔ یادداشت ناموفق بود.', 'hedayati-core' ) );
		}

		// Audit the fact of an edit only — never the note text (may contain PII).
		Hedayati_Audit_Log::record( 'consultation.note_updated', 'consultation', $id, 'internal note edited', $actor_id );

		return true;
	}

	// ── Public form ─────────────────────────────────────────────────────────

	/** Rendered by page.php on the `/consult/` page. */
	public static function render_public_form(): void {
		$sent  = isset( $_GET['consult'] ) && 'sent' === sanitize_key( wp_unslash( (string) $_GET['consult'] ) );
		$error = isset( $_GET['consult_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['consult_error'] ) ) : '';

		echo '<section class="hd-consult-form-wrap" id="consult-form">';
		echo '<h2>' . esc_html__( 'درخواست مشاورهٔ رایگان', 'hedayati-core' ) . '</h2>';

		if ( $sent ) {
			echo '<p class="hd-consult-ok" role="status">' . esc_html__( 'درخواست شما ثبت شد. کارشناسان مجتمع در ساعات کاری با شما تماس می‌گیرند.', 'hedayati-core' ) . '</p>';
		}
		if ( '' !== $error ) {
			echo '<p class="hd-consult-error" role="alert">' . esc_html( $error ) . '</p>';
		}

		echo '<form class="hd-consult-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SUBMIT_ACTION ) . '">';
		// Honeypot — real users never fill this; bots do.
		echo '<div class="hd-consult-hp" aria-hidden="true"><label>وب‌سایت<input type="text" name="hd_website" tabindex="-1" autocomplete="off"></label></div>';

		printf(
			'<label class="hd-consult-field"><span>%s</span><input type="text" name="name" required maxlength="190" value="%s"></label>',
			esc_html__( 'نام و نام خانوادگی', 'hedayati-core' ),
			esc_attr( isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['name'] ) ) : '' )
		);
		printf(
			'<label class="hd-consult-field"><span>%s</span><input type="tel" name="phone" required inputmode="tel" dir="ltr" placeholder="09xxxxxxxxx"></label>',
			esc_html__( 'شمارهٔ همراه', 'hedayati-core' )
		);
		printf(
			'<label class="hd-consult-field"><span>%s</span><input type="text" name="topic" maxlength="190" placeholder="%s"></label>',
			esc_html__( 'دوره یا زمینهٔ موردنظر (اختیاری)', 'hedayati-core' ),
			esc_attr__( 'مثال: شبکه، برنامه‌نویسی، امنیت…', 'hedayati-core' )
		);
		printf(
			'<label class="hd-consult-field"><span>%s</span><textarea name="message" rows="4" maxlength="2000" placeholder="%s"></textarea></label>',
			esc_html__( 'توضیح کوتاه (اختیاری)', 'hedayati-core' ),
			esc_attr__( 'هدف شما از دوره، سابقهٔ قبلی، زمان مناسب تماس…', 'hedayati-core' )
		);

		echo '<p class="hd-consult-consent">' . esc_html__( 'با ثبت این فرم موافقت می‌کنید کارشناسان مجتمع برای مشاوره با شمارهٔ واردشده تماس بگیرند.', 'hedayati-core' ) . '</p>';
		echo '<button class="solid-btn" type="submit">' . esc_html__( 'ثبت درخواست مشاوره', 'hedayati-core' ) . '</button>';
		echo '</form></section>';
	}

	public static function handle_submit(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		$back = home_url( '/consult/' );

		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| ! wp_verify_nonce( $nonce, self::NONCE_ACTION )
		) {
			wp_safe_redirect( add_query_arg( 'consult_error', rawurlencode( __( 'نشست شما منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.', 'hedayati-core' ) ), $back ) );
			exit;
		}

		// Honeypot: silently accept-and-drop so bots get no signal.
		if ( '' !== trim( (string) ( $_POST['hd_website'] ?? '' ) ) ) {
			wp_safe_redirect( add_query_arg( 'consult', 'sent', $back ) );
			exit;
		}

		if ( self::is_rate_limited() ) {
			wp_safe_redirect( add_query_arg( 'consult_error', rawurlencode( __( 'تعداد درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید یا مستقیماً تماس بگیرید.', 'hedayati-core' ) ), $back ) );
			exit;
		}

		$result = self::create( [
			'name'    => wp_unslash( (string) ( $_POST['name'] ?? '' ) ),
			'phone'   => wp_unslash( (string) ( $_POST['phone'] ?? '' ) ),
			'topic'   => wp_unslash( (string) ( $_POST['topic'] ?? '' ) ),
			'message' => wp_unslash( (string) ( $_POST['message'] ?? '' ) ),
		] );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'consult_error', rawurlencode( $result->get_error_message() ), $back ) );
			exit;
		}

		self::bump_rate();
		wp_safe_redirect( add_query_arg( 'consult', 'sent', $back ) . '#consult-form' );
		exit;
	}

	// ── Staff panel view ────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';

		$rows = self::query( [ 'status' => $status, 'search' => $search, 'per_page' => 60 ] );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'پذیرش و مشاوره', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'درخواست‌های مشاوره', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html( sprintf(
				/* translators: %s new-request count (Persian digits) */
				__( '%s درخواست جدید در انتظار پیگیری است.', 'hedayati-core' ),
				Hedayati_Text::digits_to_persian( (string) self::count_new() )
			) )
		);
		echo '</div></header>';

		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="consultations">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجو', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'نام، شماره یا موضوع…', 'hedayati-core' )
		);
		echo '<label class="hd-manager-check"><select name="status">';
		printf( '<option value=""%s>%s</option>', selected( '', $status, false ), esc_html__( 'همهٔ وضعیت‌ها', 'hedayati-core' ) );
		foreach ( self::STATUSES as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $s, $status, false ), esc_html( self::status_label( $s ) ) );
		}
		echo '</select></label>';
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال', 'hedayati-core' ) );
		echo '</form>';

		if ( empty( $rows ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'درخواستی یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-consult-queue">';
		foreach ( $rows as $row ) {
			$tel = hedayati_phone_to_tel_uri( $row['phone_e164'] );
			echo '<article class="hd-consult-card hd-consult-' . esc_attr( $row['status'] ) . '">';
			echo '<div class="hd-consult-card-head">';
			echo '<strong>' . esc_html( $row['name'] ) . '</strong>';
			echo '<span class="hd-consult-badge">' . esc_html( self::status_label( $row['status'] ) ) . '</span>';
			echo '</div>';
			if ( '' !== $row['topic'] ) {
				echo '<p class="hd-consult-topic">' . esc_html( $row['topic'] ) . '</p>';
			}
			if ( '' !== $row['message'] ) {
				echo '<p class="hd-consult-message">' . esc_html( $row['message'] ) . '</p>';
			}
			printf(
				'<a class="hd-consult-tel" href="tel:%s" dir="ltr">%s</a>',
				esc_attr( $tel ),
				esc_html( Hedayati_Phone::format_display( $row['phone_e164'], 'national' ) ?: $row['phone_e164'] )
			);
			echo '<p class="hd-consult-time">' . esc_html( Hedayati_Jalali::format( substr( $row['created_at'], 0, 10 ) ) ) . '</p>';

			// Status buttons.
			echo '<div class="hd-consult-actions">';
			foreach ( self::STATUSES as $s ) {
				if ( $s === $row['status'] ) {
					continue;
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_staff_consult_status' );
				echo '<input type="hidden" name="action" value="hedayati_staff_consult_status">';
				echo '<input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '">';
				echo '<input type="hidden" name="status" value="' . esc_attr( $s ) . '">';
				echo '<button class="hd-manager-toggle-btn" type="submit">' . esc_html( self::status_label( $s ) ) . '</button>';
				echo '</form>';
			}
			echo '</div>';

			// Internal note.
			echo '<form class="hd-consult-note-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_staff_consult_note' );
			echo '<input type="hidden" name="action" value="hedayati_staff_consult_note">';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $row['id'] ) . '">';
			printf(
				'<label class="hd-portal-field"><span>%s</span><textarea name="note" rows="2" maxlength="500">%s</textarea></label>',
				esc_html__( 'یادداشت داخلی', 'hedayati-core' ),
				esc_textarea( $row['staff_note'] )
			);
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ یادداشت', 'hedayati-core' ) . '</button>';
			echo '</form>';

			echo '</article>';
		}
		echo '</div>';
	}

	public static function handle_status(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_consult_status', self::CAPABILITY );

		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';

		Hedayati_Staff_Portal::redirect_notice(
			self::set_status( $id, $status, get_current_user_id() ),
			[ 'view' => 'consultations' ]
		);
	}

	public static function handle_note(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_consult_note', self::CAPABILITY );

		$id   = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$note = isset( $_POST['note'] ) ? (string) wp_unslash( $_POST['note'] ) : '';

		Hedayati_Staff_Portal::redirect_notice(
			self::set_note( $id, $note, get_current_user_id() ),
			[ 'view' => 'consultations' ]
		);
	}

	// ── Rate limiting (per client IP, transient-based) ──────────────────────

	private static function rate_key(): string {
		$ip = Hedayati_Rate_Limiter::get_client_ip();
		return 'hd_consult_rl_' . md5( $ip );
	}

	private static function is_rate_limited(): bool {
		return (int) get_transient( self::rate_key() ) >= self::RATE_MAX_PER_IP;
	}

	private static function bump_rate(): void {
		$key   = self::rate_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::RATE_WINDOW );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'name'       => (string) $row['name'],
			'phone_e164' => (string) $row['phone_e164'],
			'topic'      => (string) $row['topic'],
			'message'    => (string) $row['message'],
			'status'     => (string) $row['status'],
			'staff_note' => (string) $row['staff_note'],
			'handled_by' => null !== $row['handled_by'] ? (int) $row['handled_by'] : null,
			'source'     => (string) $row['source'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}
}
