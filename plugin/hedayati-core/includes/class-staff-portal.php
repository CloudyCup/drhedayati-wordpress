<?php
/** Staff workflows. Every read/write pairs a capability with object scope. */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Hedayati_Staff_Portal {

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'ensure_page' ] );
		add_action( 'template_redirect', [ self::class, 'guard' ] );
		foreach ( [ 'session', 'attendance', 'student', 'enroll', 'identity', 'verify', 'upload' ] as $action ) {
			add_action( 'admin_post_hedayati_staff_' . $action, [ self::class, 'handle_' . $action ] );
		}
		add_filter( 'login_redirect', [ self::class, 'login_redirect' ], 20, 3 );
	}

	public static function ensure_page(): void {
		if ( ! get_page_by_path( 'panel' ) ) {
			wp_insert_post( [ 'post_type' => 'page', 'post_name' => 'panel', 'post_title' => 'پنل آموزش', 'post_status' => 'publish' ] );
		}
	}

	public static function url( array $args = [] ): string { return add_query_arg( $args, home_url( '/panel/' ) ); }
	public static function allowed(): bool {
		return current_user_can( 'hedayati_view_assigned_runs' ) || current_user_can( 'hedayati_lookup_students' ) || current_user_can( 'hedayati_manage_course_runs' );
	}
	public static function login_redirect( string $url, string $requested, $user ): string {
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) &&
			( user_can( $user, 'hedayati_view_assigned_runs' ) || user_can( $user, 'hedayati_lookup_students' ) ) ) {
			return self::url();
		}
		return $url;
	}
	public static function guard(): void {
		if ( ! is_page( 'panel' ) ) { return; }
		Hedayati_Student_Portal::send_no_cache_headers();
		if ( ! is_user_logged_in() ) { auth_redirect(); }
		if ( ! self::allowed() ) { self::deny(); }
		// Resolve object authorization before the theme emits headers or markup.
		$view = self::input( 'view', $_GET );
		if ( 'run' === $view ) {
			$id = absint( self::input( 'run_id', $_GET ) );
			if ( ! self::can_run( $id, 'hedayati_view_assigned_roster' ) && ! self::can_run( $id, 'hedayati_manage_course_runs' ) ) { self::deny(); }
		}
		if ( 'students' === $view ) {
			if ( ! current_user_can( 'hedayati_lookup_students' ) || ! current_user_can( 'hedayati_view_student_profiles_basic' ) ) { self::deny(); }
			$id = absint( self::input( 'student_id', $_GET ) );
			if ( $id ) { self::student( $id ); }
		}
	}
	private static function deny(): void { wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] ); }
	private static function input( string $key, ?array $source = null ): string {
		$source ??= $_POST;
		return isset( $source[ $key ] ) && is_string( $source[ $key ] ) ? sanitize_text_field( wp_unslash( $source[ $key ] ) ) : '';
	}
	private static function verify( string $action, string $cap ): void {
		Hedayati_Student_Portal::send_no_cache_headers();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( $cap ) || ! wp_verify_nonce( self::input( '_wpnonce' ), 'hedayati_staff_' . $action ) ) { self::deny(); }
	}
	public static function can_run( int $id, string $cap ): bool {
		return current_user_can( $cap ) && null !== Hedayati_Course_Run_Service::get( $id ) &&
			( current_user_can( 'hedayati_manage_course_runs' ) || Hedayati_Run_Staff_Service::user_is_staff_on_run( get_current_user_id(), $id ) );
	}
	private static function student( int $id ): WP_User {
		$user = get_user_by( 'id', $id );
		if ( ! $user || ! in_array( 'student', $user->roles, true ) ) { self::deny(); }
		return $user;
	}
	private static function finish( $result, array $args = [] ): void {
		set_transient( 'hedayati_staff_notice_' . get_current_user_id(), [ 'error' => is_wp_error( $result ), 'text' => is_wp_error( $result ) ? $result->get_error_message() : __( 'اطلاعات ذخیره شد.', 'hedayati-core' ) ], 60 );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
	private static function form( string $action, array $hidden = [], bool $upload = false ): void {
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $upload ? ' enctype="multipart/form-data"' : '' ) . '>';
		wp_nonce_field( 'hedayati_staff_' . $action );
		$hidden['action'] = 'hedayati_staff_' . $action;
		foreach ( $hidden as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '">'; }
	}
	private static function field( string $key, string $label, string $type = 'text', string $value = '', bool $required = false ): void {
		echo '<label class="hd-portal-field">' . esc_html( $label ) . '<input name="' . esc_attr( $key ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . '></label>';
	}
	private static function button( string $label ): void { echo '<button class="hd-portal-btn" type="submit">' . esc_html( $label ) . '</button></form>'; }
	public static function render(): void {
		if ( ! self::allowed() ) { self::deny(); }
		$notice = get_transient( 'hedayati_staff_notice_' . get_current_user_id() );
		if ( is_array( $notice ) ) {
			delete_transient( 'hedayati_staff_notice_' . get_current_user_id() );
			echo '<p role="status" class="hd-portal-notice ' . ( $notice['error'] ? 'hd-portal-notice-error' : 'hd-portal-notice-success' ) . '">' . esc_html( $notice['text'] ) . '</p>';
		}
		$view = self::input( 'view', $_GET );
		if ( 'students' === $view && current_user_can( 'hedayati_lookup_students' ) ) { self::render_students(); return; }
		if ( 'run' === $view ) { self::render_run( absint( self::input( 'run_id', $_GET ) ) ); return; }
		echo '<h1 class="hd-portal-title">' . esc_html__( 'پنل آموزش', 'hedayati-core' ) . '</h1><p>' . esc_html( wp_get_current_user()->display_name ) . '</p><div class="hd-portal-cards">';
		foreach ( [ 'hedayati_lookup_students' => [ self::url( [ 'view' => 'students' ] ), 'پذیرش و پرونده دانشجو' ], 'hedayati_manage_courses' => [ admin_url( 'edit.php?post_type=course' ), 'مدیریت دوره‌ها' ], 'hedayati_manage_course_runs' => [ admin_url( 'admin.php?page=hedayati-academic' ), 'عملیات آموزشی' ], 'hedayati_manage_teachers' => [ admin_url( 'edit.php?post_type=teacher' ), 'مدیریت اساتید' ], 'hedayati_manage_settings' => [ admin_url( 'options-general.php?page=hedayati-settings' ), 'اطلاعات تماس مجتمع' ], 'hedayati_verify_students' => [ admin_url( 'admin.php?page=hedayati-students' ), 'بررسی احراز هویت' ] ] as $cap => $link ) {
			if ( current_user_can( $cap ) ) { echo '<a class="hd-portal-card" href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>'; }
		}
		echo '</div>';
		if ( current_user_can( 'hedayati_view_assigned_runs' ) ) {
			echo '<h2 class="hd-portal-subtitle">کلاس‌های من</h2>';
			$ids = Hedayati_Run_Staff_Service::run_ids_for_user( get_current_user_id() );
			if ( ! $ids ) { echo '<p>هنوز کلاسی به شما اختصاص داده نشده است.</p>'; }
			foreach ( $ids as $id ) {
				$run = Hedayati_Course_Run_Service::get( $id );
				if ( $run ) { echo '<p><a href="' . esc_url( self::url( [ 'view' => 'run', 'run_id' => $id ] ) ) . '">' . esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['title'] ?? '#' . $id ) ) . '</a></p>'; }
			}
		}
	}
	private static function render_run( int $id ): void {
		if ( ! self::can_run( $id, 'hedayati_view_assigned_roster' ) && ! self::can_run( $id, 'hedayati_manage_course_runs' ) ) { self::deny(); }
		$run = Hedayati_Course_Run_Service::get( $id );
		echo '<h1 class="hd-portal-title">' . esc_html( get_the_title( $run['course_id'] ) ) . '</h1><h2 class="hd-portal-subtitle">فهرست دانشجویان</h2>';
		$enrollments = Hedayati_Enrollment_Service::list_for_run( $id );
		echo '<ul>';
		foreach ( $enrollments as $enrollment ) { $user = get_user_by( 'id', $enrollment['user_id'] ); if ( $user ) { echo '<li>' . esc_html( $user->display_name ) . '</li>'; } }
		echo '</ul>';
		// TA receives names only: no profile links, email, phone, identity or documents.
		if ( ! current_user_can( 'hedayati_manage_assigned_sessions' ) && ! current_user_can( 'hedayati_manage_course_runs' ) ) { return; }
		echo '<h2 class="hd-portal-subtitle">جلسات و حضور و غیاب</h2>';
		foreach ( Hedayati_Session_Service::list_for_run( $id ) as $session ) {
			echo '<section class="hd-staff-section"><h3>' . esc_html( Hedayati_Jalali::format( substr( $session['starts_at'], 0, 10 ) ) . ' ' . substr( $session['starts_at'], 11, 5 ) . ' — ' . $session['topic'] ) . '</h3>';
			if ( current_user_can( 'hedayati_record_attendance' ) ) {
				$marks = [];
				foreach ( Hedayati_Attendance_Service::list_for_session( $session['id'] ) as $mark ) { $marks[ $mark['enrollment_id'] ] = $mark['status']; }
				self::form( 'attendance', [ 'session_id' => $session['id'] ] );
				foreach ( $enrollments as $e ) {
					if ( 'active' !== $e['status'] ) { continue; }
					$user = get_user_by( 'id', $e['user_id'] );
					if ( ! $user ) { continue; }
					echo '<label class="hd-portal-field">' . esc_html( $user->display_name ) . '<select name="mark[' . esc_attr( (string) $e['id'] ) . ']">';
					foreach ( [ '' => 'ثبت نشده', 'present' => 'حاضر', 'absent' => 'غایب', 'late' => 'تأخیر', 'excused' => 'موجه' ] as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '"' . selected( $marks[ $e['id'] ] ?? '', $value, false ) . '>' . esc_html( $label ) . '</option>'; }
					echo '</select></label>';
				}
				self::button( 'ذخیره حضور و غیاب' );
			}
			echo '</section>';
		}
		self::form( 'session', [ 'run_id' => $id ] );
		echo '<h2 class="hd-portal-subtitle">جلسه جدید</h2>';
		self::field( 'date', 'تاریخ شمسی (۱۴۰۵/۰۶/۱۵) یا میلادی', 'text', '', true );
		self::field( 'time', 'ساعت شروع', 'time', '', true );
		self::field( 'topic', 'موضوع جلسه' );
		self::button( 'افزودن جلسه' );
	}
	public static function handle_session(): void {
		self::verify( 'session', 'hedayati_manage_assigned_sessions' );
		$id = absint( self::input( 'run_id' ) );
		if ( ! self::can_run( $id, 'hedayati_manage_assigned_sessions' ) ) { self::deny(); }
		$date = Hedayati_Academic_Validation::parse_iso_date( self::input( 'date' ) ) ?? Hedayati_Jalali::parse_input( self::input( 'date' ) );
		$result = $date ? Hedayati_Session_Service::create( [ 'run_id' => $id, 'session_number' => Hedayati_Session_Service::next_session_number( $id ), 'starts_at' => $date . ' ' . self::input( 'time' ) . ':00', 'topic' => self::input( 'topic' ) ] ) : new WP_Error( 'date', 'تاریخ نامعتبر است.' );
		self::finish( $result, [ 'view' => 'run', 'run_id' => $id ] );
	}
	public static function handle_attendance(): void {
		self::verify( 'attendance', 'hedayati_record_attendance' );
		$session = Hedayati_Session_Service::get( absint( self::input( 'session_id' ) ) );
		if ( ! $session || ! self::can_run( $session['run_id'], 'hedayati_record_attendance' ) ) { self::deny(); }
		$marks = isset( $_POST['mark'] ) && is_array( $_POST['mark'] ) ? wp_unslash( $_POST['mark'] ) : [];
		// Validate the entire batch before writing any mark, including forged foreign IDs.
		foreach ( $marks as $id => $status ) {
			$e = Hedayati_Enrollment_Service::get( absint( $id ) );
			if ( ! $e || $e['run_id'] !== $session['run_id'] || 'active' !== $e['status'] || ! is_string( $status ) || ( '' !== $status && ! in_array( $status, Hedayati_Academic_Validation::ATTENDANCE_STATUSES, true ) ) ) { self::deny(); }
		}
		$result = true;
		foreach ( $marks as $id => $status ) {
			if ( '' === $status ) { continue; }
			$saved = Hedayati_Attendance_Service::record( $session['id'], (int) $id, $status, [ 'recorded_by' => get_current_user_id() ] );
			if ( is_wp_error( $saved ) ) { $result = $saved; break; }
		}
		self::finish( $result, [ 'view' => 'run', 'run_id' => $session['run_id'] ] );
	}
	private static function render_students(): void {
		if ( ! current_user_can( 'hedayati_view_student_profiles_basic' ) ) { self::deny(); }
		echo '<h1 class="hd-portal-title">پذیرش و پرونده دانشجو</h1>';
		// Search is POST so names, phones and emails do not appear in access-log URLs.
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( self::url( [ 'view' => 'students' ] ) ) . '">';
		wp_nonce_field( 'hedayati_staff_search' );
		self::field( 'search', 'نام، نام کاربری، ایمیل یا شماره همراه' ); self::button( 'جستجو' );
		$search = self::input( 'search' );
		if ( '' !== $search && wp_verify_nonce( self::input( '_wpnonce' ), 'hedayati_staff_search' ) ) {
			$phone_user = Hedayati_User_Phone_Service::find_user_by_phone( $search );
			$users = $phone_user ? [ $phone_user ] : get_users( [ 'role' => 'student', 'search' => '*' . $search . '*', 'search_columns' => [ 'user_login', 'display_name', 'user_email' ], 'number' => 50 ] );
			if ( ! $users ) { echo '<p>دانشجویی یافت نشد.</p>'; }
			foreach ( $users as $user ) { if ( in_array( 'student', $user->roles, true ) ) { echo '<p><a href="' . esc_url( self::url( [ 'view' => 'students', 'student_id' => $user->ID ] ) ) . '">' . esc_html( $user->display_name ) . '</a></p>'; } }
		}
		$id = absint( self::input( 'student_id', $_GET ) );
		if ( $id ) { self::render_student( self::student( $id ) ); return; }
		if ( current_user_can( 'hedayati_create_students' ) ) {
			self::form( 'student' ); echo '<h2 class="hd-portal-subtitle">ایجاد حساب دانشجو</h2>';
			foreach ( [ 'first_name' => 'نام', 'last_name' => 'نام خانوادگی', 'user_login' => 'نام کاربری', 'phone' => 'شماره همراه' ] as $key => $label ) { self::field( $key, $label, 'text', '', true ); }
			self::field( 'email', 'ایمیل برای بازیابی رمز عبور', 'email' );
			self::field( 'password', 'رمز اولیه (حداقل ۱۲ کاراکتر؛ تحویل امن به دانشجو)', 'password', '', true );
			self::button( 'ایجاد حساب' );
		}
	}
	private static function render_student( WP_User $user ): void {
		$id = $user->ID;
		echo '<h2 class="hd-portal-subtitle">' . esc_html( $user->display_name ) . '</h2>';
		$phone = Hedayati_User_Phone_Service::get_phone_record_by_user( $id );
		echo '<p><bdi>' . esc_html( $user->user_login . ' — ' . ( $phone['phone_e164'] ?? '' ) ) . '</bdi></p>';
		$status = Hedayati_Verification_Service::get_status( $id );
		$labels = [ 'unverified' => 'احراز نشده', 'pending' => 'در حال بررسی', 'verified' => 'تأیید شده', 'rejected' => 'رد شده' ];
		echo '<p>احراز هویت: ' . esc_html( $labels[ $status['status'] ] ?? 'احراز نشده' ) . '</p>';
		if ( current_user_can( 'hedayati_create_enrollments' ) ) {
			self::form( 'enroll', [ 'student_id' => $id ] ); echo '<label class="hd-portal-field">ثبت‌نام در کلاس<select name="run_id" required><option value="">انتخاب کلاس</option>';
			foreach ( Hedayati_Course_Run_Service::query( [ 'limit' => 500 ] ) as $run ) { if ( in_array( $run['run_status'], [ 'scheduled', 'in_progress' ], true ) ) { echo '<option value="' . esc_attr( (string) $run['id'] ) . '">' . esc_html( get_the_title( $run['course_id'] ) . ' — #' . $run['id'] ) . '</option>'; } }
			echo '</select></label>'; self::button( 'ثبت‌نام دانشجو' );
		}
		echo '<h3>ثبت‌نام‌ها</h3><ul>';
		foreach ( Hedayati_Enrollment_Service::list_for_user( $id ) as $e ) { $run = Hedayati_Course_Run_Service::get( $e['run_id'] ); if ( $run ) { echo '<li>' . esc_html( get_the_title( $run['course_id'] ) . ' — #' . $run['id'] ) . '</li>'; } }
		echo '</ul>';
		if ( current_user_can( 'hedayati_upload_student_documents' ) ) {
			self::form( 'identity', [ 'student_id' => $id ] ); self::field( 'national_id', 'ثبت یا اصلاح کد ملی', 'text', '', true ); self::button( 'ذخیره کد ملی' );
			self::form( 'upload', [ 'student_id' => $id ], true ); echo '<label class="hd-portal-field">نوع مدرک<select name="doc_type"><option value="national_card">کارت ملی</option><option value="birth_certificate">شناسنامه</option><option value="other">سایر</option></select></label><label class="hd-portal-field">فایل PDF، JPEG یا PNG<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required></label>'; self::button( 'بارگذاری مدرک' );
		}
		if ( current_user_can( 'hedayati_initiate_verification' ) && in_array( $status['status'], [ 'unverified', 'rejected' ], true ) ) { self::form( 'verify', [ 'student_id' => $id ] ); self::button( 'ارسال برای بررسی احراز هویت' ); }
	}
	public static function handle_student(): void {
		self::verify( 'student', 'hedayati_create_students' );
		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';
		$phone = Hedayati_Phone::normalize( self::input( 'phone' ) );
		if ( is_wp_error( $phone ) || ! Hedayati_User_Phone_Service::is_phone_available( self::input( 'phone' ) ) || strlen( $password ) < 12 || '' === self::input( 'first_name' ) || '' === self::input( 'last_name' ) ) { self::finish( new WP_Error( 'invalid', 'نام، شماره همراه یکتا و رمز حداقل ۱۲ کاراکتری را بررسی کنید.' ), [ 'view' => 'students' ] ); }
		$id = wp_insert_user( [ 'user_login' => self::input( 'user_login' ), 'user_pass' => $password, 'user_email' => self::input( 'email' ), 'first_name' => self::input( 'first_name' ), 'last_name' => self::input( 'last_name' ), 'display_name' => self::input( 'first_name' ) . ' ' . self::input( 'last_name' ), 'role' => 'student' ] );
		if ( is_wp_error( $id ) ) { self::finish( new WP_Error( 'create', 'ایجاد حساب ناموفق بود؛ نام کاربری و ایمیل را بررسی کنید.' ), [ 'view' => 'students' ] ); }
		$result = Hedayati_User_Phone_Service::assign_phone( $id, $phone );
		if ( is_wp_error( $result ) ) {
			// Compensate only the account just created by this request (e.g. phone race).
			require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id );
			self::finish( $result, [ 'view' => 'students' ] );
		}
		self::finish( true, [ 'view' => 'students', 'student_id' => $id ] );
	}
	public static function handle_enroll(): void {
		self::verify( 'enroll', 'hedayati_create_enrollments' ); $id = absint( self::input( 'student_id' ) ); self::student( $id );
		self::finish( Hedayati_Enrollment_Service::enroll( absint( self::input( 'run_id' ) ), $id ), [ 'view' => 'students', 'student_id' => $id ] );
	}
	public static function handle_identity(): void {
		self::verify( 'identity', 'hedayati_upload_student_documents' ); $id = absint( self::input( 'student_id' ) ); self::student( $id );
		self::finish( Hedayati_Verification_Service::set_national_id( $id, self::input( 'national_id' ), get_current_user_id() ), [ 'view' => 'students', 'student_id' => $id ] );
	}
	public static function handle_verify(): void {
		self::verify( 'verify', 'hedayati_initiate_verification' ); $id = absint( self::input( 'student_id' ) ); self::student( $id );
		self::finish( Hedayati_Verification_Service::initiate( $id, get_current_user_id() ), [ 'view' => 'students', 'student_id' => $id ] );
	}
	public static function handle_upload(): void {
		self::verify( 'upload', 'hedayati_upload_student_documents' ); $id = absint( self::input( 'student_id' ) ); self::student( $id );
		$file = isset( $_FILES['document'] ) && is_array( $_FILES['document'] ) ? $_FILES['document'] : [];
		self::finish( Hedayati_Document_Service::upload( $id, $file, self::input( 'doc_type' ), get_current_user_id() ), [ 'view' => 'students', 'student_id' => $id ] );
	}
}
