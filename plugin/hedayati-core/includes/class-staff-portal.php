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
		'session'    => 'hedayati_manage_assigned_sessions',
		'attendance' => 'hedayati_record_attendance',
		'student'    => 'hedayati_create_students',
		'enroll'     => 'hedayati_create_enrollments',
		'identity'   => 'hedayati_upload_student_documents',
		'verify'     => 'hedayati_initiate_verification',
		'upload'     => 'hedayati_upload_student_documents',
	];

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

		if ( 'run' === $view ) {
			self::render_run( absint( self::get( 'run_id' ) ) );
			return;
		}

		self::render_home();
	}

	private static function render_home(): void {
		$user = wp_get_current_user();

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

		$can_sessions = current_user_can( 'hedayati_manage_assigned_sessions' ) || current_user_can( 'hedayati_manage_course_runs' );
		if ( ! $can_sessions ) {
			return;
		}

		self::render_run_sessions( $run_id, $enrollments );
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
}
