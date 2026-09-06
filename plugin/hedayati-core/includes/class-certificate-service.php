<?php
/**
 * AI Studio parity — certificates + public verification (owner decision D48).
 *
 * Security model:
 *   - NEVER auto-issued. An authorised manager/administrator
 *     (`hedayati_manage_certificates`) explicitly issues one, bound to exactly
 *     one enrollment (`UNIQUE(enrollment_id)` → duplicate issuance impossible).
 *   - The public identifier is a cryptographically random, non-sequential code
 *     (`DH-<jyear>-<10× base32>`), never the national ID, never derived from PII.
 *   - The public verification page shows ONLY: validity, recipient name (as
 *     recorded on the certificate), course title, issue date, institute, code.
 *     No phone / national ID / address / documents / attendance / enrollment
 *     internals. Revoked / unknown codes return a clear, non-sensitive status.
 *   - Verification lookups are IP rate-limited against code brute force.
 *
 * The certificate itself is a print-friendly HTML view (no new PDF dependency).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Certificate_Service {

	public const MANAGE_CAP = 'hedayati_manage_certificates';
	public const VIEW_OWN_CAP = 'hedayati_view_own_certificates';

	public const STATUSES = [ 'valid', 'revoked' ];

	private const VERIFY_RL_WINDOW = 600;
	private const VERIFY_RL_MAX     = 20;

	public static function init(): void {
		add_action( 'admin_post_hedayati_staff_cert_issue', [ self::class, 'handle_issue' ] );
		add_action( 'admin_post_hedayati_staff_cert_revoke', [ self::class, 'handle_revoke' ] );

		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
		add_action( 'hedayati_run_deleted', [ self::class, 'on_run_deleted' ] );

		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'certificate' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [
			'certificate.issued',
			'certificate.revoked',
		] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views['certificates'] = [
			'capability' => self::MANAGE_CAP,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'گواهینامه‌ها', 'hedayati-core' ),
			'title'      => __( 'صدور و مدیریت گواهینامه‌ها', 'hedayati-core' ),
			'desc'       => __( 'صدور گواهینامه برای دانشجویان، ابطال و استعلام عمومی', 'hedayati-core' ),
			'icon'       => 'award',
		];
		return $views;
	}

	public static function status_label( string $s ): string {
		return [
			'valid'   => __( 'معتبر', 'hedayati-core' ),
			'revoked' => __( 'باطل‌شده', 'hedayati-core' ),
		][ $s ] ?? $s;
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Hedayati_DB_Schema::get_table_certificates();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$code = self::normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$table = Hedayati_DB_Schema::get_table_certificates();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_enrollment( int $enrollment_id ): ?array {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_certificates();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE enrollment_id = %d", $enrollment_id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int, array> */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_certificates();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY issued_on DESC", $user_id ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @param array{status?:string,search?:string} $args
	 * @return array<int, array>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		$table  = Hedayati_DB_Schema::get_table_certificates();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(code LIKE %s OR recipient_name LIKE %s OR title LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY issued_on DESC, id DESC LIMIT 200';
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_valid(): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_certificates();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'valid'" );
	}

	// ── Issue / revoke ──────────────────────────────────────────────────────

	/**
	 * @param array{enrollment_id:int, title?:string, issued_on?:string} $data
	 * @return int|WP_Error certificate id
	 */
	public static function issue( array $data, int $actor_id ): int|WP_Error {
		global $wpdb;

		if ( ! user_can( $actor_id, self::MANAGE_CAP ) ) {
			return new WP_Error( 'cap', __( 'اجازهٔ صدور گواهینامه ندارید.', 'hedayati-core' ) );
		}

		$enrollment_id = absint( $data['enrollment_id'] ?? 0 );
		$enrollment    = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null === $enrollment ) {
			return new WP_Error( 'enrollment', __( 'ثبت‌نام معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		if ( null !== self::get_by_enrollment( $enrollment_id ) ) {
			return new WP_Error( 'duplicate', __( 'برای این ثبت‌نام قبلاً گواهینامه صادر شده است.', 'hedayati-core' ) );
		}

		$run = Hedayati_Course_Run_Service::get( (int) $enrollment['run_id'] );
		if ( null === $run ) {
			return new WP_Error( 'run', __( 'کلاس این ثبت‌نام یافت نشد.', 'hedayati-core' ) );
		}

		$student = get_userdata( (int) $enrollment['user_id'] );
		if ( ! $student ) {
			return new WP_Error( 'student', __( 'دانشجوی این ثبت‌نام یافت نشد.', 'hedayati-core' ) );
		}

		$course_title = get_the_title( (int) $run['course_id'] ) ?: ( '#' . $run['course_id'] );
		$title        = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 190 );
		if ( '' === $title ) {
			$title = (string) $course_title;
		}

		$issued_on = sanitize_text_field( (string) ( $data['issued_on'] ?? '' ) );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issued_on ) ) {
			$issued_on = current_time( 'Y-m-d' );
		}

		$code = self::generate_unique_code();
		if ( '' === $code ) {
			return new WP_Error( 'code', __( 'ساخت کد گواهینامه ناموفق بود؛ دوباره تلاش کنید.', 'hedayati-core' ) );
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_certificates();

		$inserted = $wpdb->insert(
			$table,
			[
				'enrollment_id'  => $enrollment_id,
				'user_id'        => (int) $enrollment['user_id'],
				'run_id'         => (int) $enrollment['run_id'],
				'course_id'      => (int) $run['course_id'],
				'code'           => $code,
				'status'         => 'valid',
				'title'          => $title,
				'recipient_name' => mb_substr( $student->display_name, 0, 190 ),
				'issued_on'      => $issued_on,
				'issued_by'      => $actor_id,
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Unique(enrollment_id) race → treat as duplicate.
			if ( null !== self::get_by_enrollment( $enrollment_id ) ) {
				return new WP_Error( 'duplicate', __( 'برای این ثبت‌نام قبلاً گواهینامه صادر شده است.', 'hedayati-core' ) );
			}
			return new WP_Error( 'db', __( 'صدور گواهینامه ناموفق بود.', 'hedayati-core' ) );
		}

		$id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'certificate.issued', 'certificate', $id, 'run #' . $run['id'] . ' · user #' . $enrollment['user_id'], $actor_id );

		Hedayati_Notification_Service::notify(
			(int) $enrollment['user_id'],
			'certificate_issued',
			__( 'گواهینامهٔ شما صادر شد', 'hedayati-core' ),
			$title,
			home_url( '/account/?view=certificates' ),
			'certificate',
			$id
		);

		return $id;
	}

	public static function revoke( int $id, string $reason, int $actor_id ): true|WP_Error {
		global $wpdb;

		if ( ! user_can( $actor_id, self::MANAGE_CAP ) ) {
			return new WP_Error( 'cap', __( 'اجازهٔ ابطال گواهینامه ندارید.', 'hedayati-core' ) );
		}

		$cert = self::get( $id );
		if ( null === $cert ) {
			return new WP_Error( 'not_found', __( 'گواهینامه یافت نشد.', 'hedayati-core' ) );
		}
		if ( 'revoked' === $cert['status'] ) {
			return true;
		}

		$table = Hedayati_DB_Schema::get_table_certificates();
		$wpdb->update(
			$table,
			[
				'status'        => 'revoked',
				'revoked_at'    => current_time( 'mysql', true ),
				'revoked_by'    => $actor_id,
				'revoke_reason' => mb_substr( sanitize_text_field( $reason ), 0, 255 ),
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => $id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		Hedayati_Audit_Log::record( 'certificate.revoked', 'certificate', $id, 'revoked', $actor_id );

		Hedayati_Notification_Service::notify(
			(int) $cert['user_id'],
			'certificate_revoked',
			__( 'وضعیت گواهینامهٔ شما تغییر کرد', 'hedayati-core' ),
			$cert['title'],
			home_url( '/account/?view=certificates' ),
			'certificate',
			$id
		);

		return true;
	}

	public static function on_run_deleted( int $run_id ): void {
		// Certificates are historical records — a deleted run does NOT delete
		// issued certificates (they still verify). Nothing to do here; kept as a
		// deliberate no-op so the intent is documented.
		unset( $run_id );
	}

	// ── Code generation ─────────────────────────────────────────────────────

	private static function generate_unique_code(): string {
		$jyear = self::jalali_year();

		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			$code = 'DH-' . $jyear . '-' . self::random_base32( 10 );
			if ( null === self::get_by_code( $code ) ) {
				return $code;
			}
		}
		return '';
	}

	private static function random_base32( int $len ): string {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no I,L,O,0,1
		$out      = '';
		$bytes    = random_bytes( $len );
		for ( $i = 0; $i < $len; $i++ ) {
			$out .= $alphabet[ ord( $bytes[ $i ] ) % strlen( $alphabet ) ];
		}
		return $out;
	}

	private static function jalali_year(): string {
		$parts = Hedayati_Jalali::from_gregorian( (int) current_time( 'Y' ), (int) current_time( 'n' ), (int) current_time( 'j' ) );
		if ( isset( $parts[0] ) && (int) $parts[0] > 0 ) {
			return (string) (int) $parts[0];
		}
		return (string) ( (int) current_time( 'Y' ) - 621 );
	}

	public static function normalize_code( string $code ): string {
		$code = strtoupper( trim( $code ) );
		$code = preg_replace( '/[^A-Z0-9\-]/', '', $code );
		return 1 === preg_match( '/^DH-\d{3,4}-[A-Z0-9]{6,16}$/', $code ) ? $code : '';
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_issue(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_cert_issue', self::MANAGE_CAP );

		$result = self::issue(
			[
				'enrollment_id' => isset( $_POST['enrollment_id'] ) ? absint( wp_unslash( $_POST['enrollment_id'] ) ) : 0,
				'title'         => wp_unslash( (string) ( $_POST['title'] ?? '' ) ),
				'issued_on'     => wp_unslash( (string) ( $_POST['issued_on'] ?? '' ) ),
			],
			get_current_user_id()
		);

		Hedayati_Staff_Portal::redirect_notice( is_wp_error( $result ) ? $result : true, [ 'view' => 'certificates' ] );
	}

	public static function handle_revoke(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_cert_revoke', self::MANAGE_CAP );

		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$reason = wp_unslash( (string) ( $_POST['reason'] ?? '' ) );

		Hedayati_Staff_Portal::redirect_notice( self::revoke( $id, $reason, get_current_user_id() ), [ 'view' => 'certificates' ] );
	}

	// ── Staff panel ─────────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'مدارک رسمی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'صدور و مدیریت گواهینامه‌ها', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html( sprintf( __( '%s گواهینامهٔ معتبر صادر شده است.', 'hedayati-core' ), Hedayati_Text::digits_to_persian( (string) self::count_valid() ) ) )
		);
		echo '</div></header>';

		// Issue form.
		echo '<section class="hd-manager-section"><h2>' . esc_html__( 'صدور گواهینامهٔ جدید', 'hedayati-core' ) . '</h2>';
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_staff_cert_issue' );
		echo '<input type="hidden" name="action" value="hedayati_staff_cert_issue">';
		echo '<p class="hd-portal-note">' . esc_html__( 'شناسهٔ ثبت‌نام دانشجو را وارد کنید. هر ثبت‌نام تنها یک گواهینامه می‌پذیرد.', 'hedayati-core' ) . '</p>';
		printf( '<label class="hd-portal-field"><span>%s</span><input type="number" name="enrollment_id" min="1" required></label>', esc_html__( 'شناسهٔ ثبت‌نام', 'hedayati-core' ) );
		printf( '<label class="hd-portal-field"><span>%s</span><input type="text" name="title" maxlength="190" placeholder="%s"></label>', esc_html__( 'عنوان روی گواهینامه (اختیاری)', 'hedayati-core' ), esc_attr__( 'پیش‌فرض: نام دوره', 'hedayati-core' ) );
		printf( '<label class="hd-portal-field"><span>%s</span><input type="date" name="issued_on"></label>', esc_html__( 'تاریخ صدور (پیش‌فرض: امروز)', 'hedayati-core' ) );
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'صدور گواهینامه', 'hedayati-core' ) . '</button>';
		echo '</form></section>';

		// List + filter.
		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="certificates">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجو', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'کد، نام یا عنوان…', 'hedayati-core' )
		);
		echo '<label class="hd-manager-check"><select name="status">';
		printf( '<option value=""%s>%s</option>', selected( '', $status, false ), esc_html__( 'همه', 'hedayati-core' ) );
		foreach ( self::STATUSES as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $s, $status, false ), esc_html( self::status_label( $s ) ) );
		}
		echo '</select></label>';
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال', 'hedayati-core' ) );
		echo '</form>';

		$rows = self::query( [ 'status' => $status, 'search' => $search ] );
		if ( empty( $rows ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'گواهینامه‌ای یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-table" role="table">';
		echo '<div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [ __( 'کد', 'hedayati-core' ), __( 'دریافت‌کننده', 'hedayati-core' ), __( 'دوره', 'hedayati-core' ), __( 'تاریخ', 'hedayati-core' ), __( 'وضعیت', 'hedayati-core' ), __( 'اقدام', 'hedayati-core' ) ] as $h ) {
			echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
		}
		echo '</div>';
		foreach ( $rows as $c ) {
			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell"><code dir="ltr">' . esc_html( $c['code'] ) . '</code></span>';
			echo '<span role="cell">' . esc_html( $c['recipient_name'] ) . '</span>';
			echo '<span role="cell">' . esc_html( $c['title'] ) . '</span>';
			echo '<span role="cell">' . esc_html( Hedayati_Jalali::format( $c['issued_on'] ) ) . '</span>';
			echo '<span role="cell">' . esc_html( self::status_label( $c['status'] ) ) . '</span>';
			echo '<span role="cell">';
			printf(
				'<a class="hd-manager-row-edit" href="%s" target="_blank" rel="noopener">%s</a> ',
				esc_url( self::verify_url( $c['code'] ) ),
				esc_html__( 'استعلام', 'hedayati-core' )
			);
			if ( 'valid' === $c['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'این گواهینامه باطل شود؟', 'hedayati-core' ) ) . '\')">';
				wp_nonce_field( 'hedayati_staff_cert_revoke' );
				echo '<input type="hidden" name="action" value="hedayati_staff_cert_revoke">';
				echo '<input type="hidden" name="id" value="' . esc_attr( (string) $c['id'] ) . '">';
				echo '<input type="text" name="reason" maxlength="255" placeholder="' . esc_attr__( 'دلیل (اختیاری)', 'hedayati-core' ) . '">';
				echo '<button class="hd-manager-toggle-btn" type="submit">' . esc_html__( 'ابطال', 'hedayati-core' ) . '</button>';
				echo '</form>';
			}
			echo '</span></div>';
		}
		echo '</div>';
	}

	// ── Student account view ────────────────────────────────────────────────

	public static function render_student_view( int $user_id ): string {
		$certs = self::list_for_user( $user_id );

		ob_start();
		echo '<div class="hd-student-view-heading"><span class="hd-manager-eyebrow">' . esc_html__( 'مدارک من', 'hedayati-core' ) . '</span><h1 class="hd-portal-title">' . esc_html__( 'گواهینامه‌های من', 'hedayati-core' ) . '</h1></div>';

		if ( empty( $certs ) ) {
			echo '<div class="hd-student-empty"><strong>' . esc_html__( 'گواهینامه‌ای صادر نشده است', 'hedayati-core' ) . '</strong><p>' . esc_html__( 'پس از تکمیل دوره و تأیید واحد آموزش، گواهینامهٔ شما در این بخش نمایش داده می‌شود.', 'hedayati-core' ) . '</p></div>';
			return (string) ob_get_clean();
		}

		echo '<div class="hd-cert-list">';
		foreach ( $certs as $c ) {
			echo '<article class="hd-cert-card hd-cert-' . esc_attr( $c['status'] ) . '">';
			echo '<span class="hd-manager-eyebrow">' . esc_html( self::status_label( $c['status'] ) ) . '</span>';
			echo '<h2>' . esc_html( $c['title'] ) . '</h2>';
			echo '<p dir="ltr"><code>' . esc_html( $c['code'] ) . '</code></p>';
			echo '<p>' . esc_html( __( 'تاریخ صدور: ', 'hedayati-core' ) . Hedayati_Jalali::format( $c['issued_on'] ) ) . '</p>';
			printf(
				'<a class="hd-portal-btn" href="%s" target="_blank" rel="noopener">%s</a> ',
				esc_url( self::verify_url( $c['code'] ) ),
				esc_html__( 'مشاهده و چاپ', 'hedayati-core' )
			);
			echo '</article>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	// ── Public verification ─────────────────────────────────────────────────

	public static function verify_url( string $code ): string {
		return add_query_arg( 'code', rawurlencode( $code ), home_url( '/verify/' ) );
	}

	/** Rendered by page.php on the `/verify/` page. */
	public static function render_public_verification(): void {
		$raw  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$code = self::normalize_code( $raw );

		echo '<section class="hd-verify">';
		echo '<form class="hd-verify-form" method="get" action="' . esc_url( home_url( '/verify/' ) ) . '">';
		printf(
			'<label><span>%s</span><input type="text" name="code" dir="ltr" value="%s" placeholder="DH-1405-XXXXXXXXXX"></label>',
			esc_html__( 'کد گواهینامه', 'hedayati-core' ),
			esc_attr( $raw )
		);
		echo '<button class="solid-btn" type="submit">' . esc_html__( 'استعلام', 'hedayati-core' ) . '</button>';
		echo '</form>';

		if ( '' === $raw ) {
			echo '<p class="hd-verify-hint">' . esc_html__( 'کد چاپ‌شده روی گواهینامه را وارد کنید.', 'hedayati-core' ) . '</p></section>';
			return;
		}

		if ( self::verify_rate_limited() ) {
			echo '<p class="hd-verify-invalid">' . esc_html__( 'تعداد استعلام‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.', 'hedayati-core' ) . '</p></section>';
			return;
		}
		self::verify_rate_bump();

		$cert = '' !== $code ? self::get_by_code( $code ) : null;

		if ( null === $cert ) {
			echo '<div class="hd-verify-result hd-verify-unknown">';
			echo '<strong>' . esc_html__( 'گواهینامه‌ای با این کد یافت نشد.', 'hedayati-core' ) . '</strong>';
			echo '<p>' . esc_html__( 'کد را بررسی کنید یا با واحد آموزش مجتمع تماس بگیرید.', 'hedayati-core' ) . '</p>';
			echo '</div></section>';
			return;
		}

		$is_valid = 'valid' === $cert['status'];
		printf( '<div class="hd-verify-result %s">', $is_valid ? 'hd-verify-valid' : 'hd-verify-revoked' );
		echo '<strong>' . esc_html( $is_valid ? __( '✓ این گواهینامه معتبر است.', 'hedayati-core' ) : __( '✕ این گواهینامه باطل شده است.', 'hedayati-core' ) ) . '</strong>';
		echo '<dl class="hd-verify-fields">';
		self::verify_row( __( 'نام دریافت‌کننده', 'hedayati-core' ), $cert['recipient_name'] );
		self::verify_row( __( 'دوره', 'hedayati-core' ), $cert['title'] );
		self::verify_row( __( 'تاریخ صدور', 'hedayati-core' ), Hedayati_Jalali::format( $cert['issued_on'] ) );
		self::verify_row( __( 'صادرکننده', 'hedayati-core' ), __( 'مجتمع آموزشی دکتر هدایتی', 'hedayati-core' ) );
		self::verify_row( __( 'کد گواهینامه', 'hedayati-core' ), $cert['code'] );
		echo '</dl>';
		echo '</div></section>';
	}

	private static function verify_row( string $label, string $value ): void {
		printf( '<dt>%s</dt><dd dir="auto">%s</dd>', esc_html( $label ), esc_html( $value ) );
	}

	private static function verify_rl_key(): string {
		return 'hd_cert_verify_rl_' . md5( Hedayati_Rate_Limiter::get_client_ip() );
	}

	private static function verify_rate_limited(): bool {
		return (int) get_transient( self::verify_rl_key() ) >= self::VERIFY_RL_MAX;
	}

	private static function verify_rate_bump(): void {
		$key = self::verify_rl_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::VERIFY_RL_WINDOW );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'             => (int) $row['id'],
			'enrollment_id'  => (int) $row['enrollment_id'],
			'user_id'        => (int) $row['user_id'],
			'run_id'         => (int) $row['run_id'],
			'course_id'      => (int) $row['course_id'],
			'code'           => (string) $row['code'],
			'status'         => (string) $row['status'],
			'title'          => (string) $row['title'],
			'recipient_name' => (string) $row['recipient_name'],
			'issued_on'      => (string) $row['issued_on'],
			'issued_by'      => (int) $row['issued_by'],
			'revoked_at'     => null !== $row['revoked_at'] ? (string) $row['revoked_at'] : null,
			'revoked_by'     => null !== $row['revoked_by'] ? (int) $row['revoked_by'] : null,
			'revoke_reason'  => (string) $row['revoke_reason'],
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
		];
	}
}
