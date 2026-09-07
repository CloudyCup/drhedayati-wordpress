<?php
/**
 * Manager Experience — Phase E (owner decision D53): academic operations in `/panel/`.
 *
 * A faithful front-end port of `Hedayati_Academic_Admin`'s daily workflows —
 * course runs, staff assignment, sessions, enrollments, attendance — for the
 * manager (`hedayati_manage_course_runs`). **No new data model:** every mutation
 * calls the exact same Phase 2B services (`Hedayati_Course_Run_Service`,
 * `Hedayati_Run_Staff_Service`, `Hedayati_Session_Service`,
 * `Hedayati_Enrollment_Service`, `Hedayati_Attendance_Service`) with the exact
 * same capability map:
 *
 *   view / runs / sessions / enrollments : hedayati_manage_course_runs
 *   assign staff                         : hedayati_assign_staff
 *   record attendance                    : hedayati_record_attendance
 *   manage enrollments (status/remove)   : hedayati_manage_enrollments
 *   create enrollments                   : hedayati_create_enrollments
 *
 * Teachers/TAs keep their own scoped run view (`/panel/?view=run&run_id=N`,
 * `Hedayati_Staff_Portal`) — this screen is the manager's operations centre.
 * `require_run_scope()` is still applied on every write (a manager passes it
 * unconditionally; the check is defence in depth, matching the wp-admin class).
 *
 * The wp-admin screen (`admin.php?page=hedayati-academic`) is untouched and
 * remains available to the real administrator.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Academic_Panel {

	public const VIEW = 'academic';
	public const CAP  = 'hedayati_manage_course_runs';

	/** admin-post action => capability required. */
	private const ACTIONS = [
		'run_save'        => 'hedayati_manage_course_runs',
		'run_delete'      => 'hedayati_manage_course_runs',
		'run_public'      => 'hedayati_manage_course_runs',
		'staff_assign'    => 'hedayati_assign_staff',
		'staff_remove'    => 'hedayati_assign_staff',
		'session_save'    => 'hedayati_manage_course_runs',
		'session_delete'  => 'hedayati_manage_course_runs',
		'enroll_add'      => 'hedayati_create_enrollments',
		'enroll_status'   => 'hedayati_manage_enrollments',
		'enroll_remove'   => 'hedayati_manage_enrollments',
		'attendance_save' => 'hedayati_record_attendance',
	];

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_view' ] );

		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'admin_post_hedayati_apanel_' . $action, [ self::class, 'handle_' . $action ] );
		}
	}

	/**
	 * @param array<string,array> $views
	 * @return array<string,array>
	 */
	public static function register_view( array $views ): array {
		$views[ self::VIEW ] = [
			'capability' => self::CAP,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'عملیات آموزشی', 'hedayati-core' ),
			'title'      => __( 'عملیات آموزشی', 'hedayati-core' ),
			'desc'       => __( 'دوره‌های اجرایی، استادها، جلسات، ثبت‌نام و حضور و غیاب', 'hedayati-core' ),
			'icon'       => 'calendar',
		];

		return $views;
	}

	// ── URL / scope helpers ─────────────────────────────────────────────────

	private static function url( array $args = [] ): string {
		return Hedayati_Staff_Portal::url( array_merge( [ 'view' => self::VIEW ], $args ) );
	}

	private static function get_int( string $key ): int {
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	private static function post_str( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function is_manager(): bool {
		return current_user_can( self::CAP );
	}

	private static function can_access_run( int $run_id ): bool {
		if ( $run_id <= 0 || null === Hedayati_Course_Run_Service::get( $run_id ) ) {
			return false;
		}
		return self::is_manager() || Hedayati_Run_Staff_Service::user_is_staff_on_run( get_current_user_id(), $run_id );
	}

	private static function require_run_scope( int $run_id ): void {
		if ( ! self::can_access_run( $run_id ) ) {
			wp_die( esc_html__( 'به این دورهٔ اجرایی دسترسی ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	/** @param true|int|WP_Error $result */
	private static function finish( $result, array $target ): void {
		Hedayati_Staff_Portal::redirect_notice(
			is_wp_error( $result ) ? $result : true,
			array_merge( [ 'view' => self::VIEW ], $target )
		);
	}

	private static function date( ?string $iso, bool $with_time = false ): string {
		if ( null === $iso || '' === $iso ) {
			return '—';
		}
		$sh = Hedayati_Jalali::format( $iso, true, $with_time );
		return $sh !== '' ? $sh : $iso;
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$run_id     = self::get_int( 'run' );
		$session_id = self::get_int( 'asession' );

		if ( $session_id > 0 ) {
			self::render_attendance( $session_id );
			return;
		}
		if ( $run_id > 0 ) {
			self::render_run_detail( $run_id );
			return;
		}
		self::render_run_list();
	}

	private static function render_run_list(): void {
		$search = isset( $_GET['q'] ) && is_string( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$status = isset( $_GET['rs'] ) && is_string( $_GET['rs'] ) ? sanitize_key( wp_unslash( $_GET['rs'] ) ) : '';
		$status = in_array( $status, Hedayati_Academic_Validation::RUN_STATUSES, true ) ? $status : '';

		$runs = Hedayati_Course_Run_Service::query( [ 'limit' => 300, 'orderby' => 'created_at', 'order' => 'DESC' ] );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'مرکز عملیات', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'دوره‌های اجرایی', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'هر دورهٔ اجرایی، منبع رسمی مدرس، زمان‌بندی، شهریه و ظرفیت یک ترم است.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		// New run
		echo '<details class="hd-course-fieldset" open><summary><strong>' . esc_html__( 'افزودن دورهٔ اجرایی جدید', 'hedayati-core' ) . '</strong></summary>';
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_apanel_run_save' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_run_save">';
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'دورهٔ کاتالوگ', 'hedayati-core' ) . '</span>';
		self::course_select( 'course_id', 0 );
		echo '</label>';
		self::text_field( 'label', __( 'عنوان دورهٔ اجرایی', 'hedayati-core' ), '', 'rtl', 'مثال: پاییز ۱۴۰۵ — تبریز' );
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ایجاد دورهٔ اجرایی', 'hedayati-core' ) . '</button>';
		echo '</form></details>';

		// Filter
		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="' . esc_attr( self::VIEW ) . '">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجو', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'جستجو در عنوان دوره یا کلاس…', 'hedayati-core' )
		);
		echo '<label class="hd-portal-field"><span class="screen-reader-text">' . esc_html__( 'وضعیت', 'hedayati-core' ) . '</span><select name="rs">';
		echo '<option value="">' . esc_html__( 'همهٔ وضعیت‌ها', 'hedayati-core' ) . '</option>';
		foreach ( Hedayati_Academic_Admin::run_status_choices() as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $status, $val, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال', 'hedayati-core' ) );
		echo '</form>';

		$rows = 0;
		echo '<div class="hd-manager-table" role="table">';
		echo '<div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [ __( 'دوره', 'hedayati-core' ), __( 'عنوان کلاس', 'hedayati-core' ), __( 'وضعیت اجرا', 'hedayati-core' ), __( 'ثبت‌نام', 'hedayati-core' ), __( 'شروع', 'hedayati-core' ), __( 'ظرفیت', 'hedayati-core' ), '' ] as $h ) {
			echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
		}
		echo '</div>';

		foreach ( $runs as $run ) {
			if ( '' !== $status && $run['run_status'] !== $status ) {
				continue;
			}
			$course_title = get_the_title( $run['course_id'] );
			if ( '' !== $search
				&& stripos( $course_title, $search ) === false
				&& stripos( (string) $run['label'], $search ) === false ) {
				continue;
			}
			$rows++;
			$active = Hedayati_Enrollment_Service::count_active( (int) $run['id'] );

			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell"><strong>' . esc_html( $course_title ?: '#' . $run['course_id'] ) . '</strong></span>';
			echo '<span role="cell">' . esc_html( $run['label'] ?: '—' ) . '</span>';
			echo '<span role="cell">' . esc_html( Hedayati_Academic_Admin::run_status_label( $run['run_status'] ) ) . '</span>';
			echo '<span role="cell">' . esc_html( Hedayati_Academic_Admin::registration_status_label( $run['registration_status'] ) ) . '</span>';
			echo '<span role="cell"><bdi>' . esc_html( self::date( $run['start_date'] ) ) . '</bdi></span>';
			echo '<span role="cell">' . esc_html( Hedayati_Text::digits_to_persian( (string) $active . ( null !== $run['capacity'] ? ' / ' . $run['capacity'] : '' ) ) ) . '</span>';
			printf(
				'<span role="cell"><a class="hd-manager-row-edit" href="%s">%s</a></span>',
				esc_url( self::url( [ 'run' => $run['id'] ] ) ),
				esc_html__( 'مدیریت', 'hedayati-core' )
			);
			echo '</div>';
		}
		echo '</div>';

		if ( 0 === $rows ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'دورهٔ اجرایی مطابق فیلتر یافت نشد.', 'hedayati-core' ) . '</p>';
		}
	}

	private static function render_run_detail( int $run_id ): void {
		$run = Hedayati_Course_Run_Service::get( $run_id );
		if ( null === $run || ! self::can_access_run( $run_id ) ) {
			echo '<p class="hd-portal-notice hd-portal-notice-error">' . esc_html__( 'دورهٔ اجرایی یافت نشد یا دسترسی ندارید.', 'hedayati-core' ) . '</p>';
			printf( '<p><a class="hd-portal-nav-link" href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) );
			return;
		}

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'دورهٔ اجرایی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['label'] ?: __( 'بدون عنوان', 'hedayati-core' ) ) ) . '</h1>';
		echo '</div>';
		printf( '<a class="hd-portal-nav-link" href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) );
		echo '</header>';

		self::render_run_form( $run );
		self::render_public_toggle( $run );
		self::render_staff_section( $run );
		self::render_sessions_section( $run );
		self::render_enrollments_section( $run );
	}

	private static function render_run_form( array $run ): void {
		echo '<details class="hd-course-fieldset" open><summary><strong>' . esc_html__( 'مشخصات دورهٔ اجرایی', 'hedayati-core' ) . '</strong></summary>';
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_apanel_run_save' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_run_save">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';

		self::text_field( 'label', __( 'عنوان', 'hedayati-core' ), (string) $run['label'] );
		self::select_field( 'run_status', __( 'وضعیت اجرا', 'hedayati-core' ), Hedayati_Academic_Admin::run_status_choices(), $run['run_status'] );
		self::select_field( 'registration_status', __( 'وضعیت ثبت‌نام', 'hedayati-core' ), Hedayati_Academic_Admin::registration_status_choices(), $run['registration_status'] );
		self::text_field( 'start_date', __( 'تاریخ شروع (شمسی ۱۴۰۵/۰۶/۱۵ یا میلادی)', 'hedayati-core' ), self::date_value( $run['start_date'] ?? null ), 'ltr' );
		self::text_field( 'end_date', __( 'تاریخ پایان', 'hedayati-core' ), self::date_value( $run['end_date'] ?? null ), 'ltr' );
		self::text_field( 'schedule_text', __( 'برنامهٔ زمانی', 'hedayati-core' ), (string) $run['schedule_text'] );
		self::text_field( 'capacity', __( 'ظرفیت (خالی = نامشخص)', 'hedayati-core' ), null === $run['capacity'] ? '' : (string) $run['capacity'], 'ltr' );
		self::text_field( 'tuition_rial', __( 'شهریه به ریال (خالی = نامشخص)', 'hedayati-core' ), null === $run['tuition_rial'] ? '' : (string) $run['tuition_rial'], 'ltr' );
		printf(
			'<label class="hd-portal-field"><span>%s</span><textarea name="notes" rows="3">%s</textarea></label>',
			esc_html__( 'یادداشت داخلی', 'hedayati-core' ),
			esc_textarea( (string) $run['notes'] )
		);
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ مشخصات', 'hedayati-core' ) . '</button>';
		echo '</form>';

		printf(
			'<form class="hd-manager-inline-form" method="post" action="%s" onsubmit="return confirm(%s);">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( "'" . esc_js( __( 'حذف این دورهٔ اجرایی و همهٔ جلسات، ثبت‌نام‌ها و حضور و غیاب آن؟', 'hedayati-core' ) ) . "'" )
		);
		wp_nonce_field( 'hedayati_apanel_run_delete' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_run_delete">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف دورهٔ اجرایی', 'hedayati-core' ) . '</button>';
		echo '</form></details>';
	}

	private static function render_public_toggle( array $run ): void {
		$course_id = (int) $run['course_id'];
		$approved  = array_map( 'intval', (array) get_post_meta( $course_id, Hedayati_Public_Content::META_PUBLIC_RUN_IDS, true ) );
		$is_public = in_array( (int) $run['id'], $approved, true );

		echo '<details class="hd-course-fieldset"><summary><strong>' . esc_html__( 'نمایش عمومی این کلاس', 'hedayati-core' ) . '</strong></summary>';
		echo '<p class="hd-portal-note">' . esc_html__( 'در صورت فعال بودن، تاریخ و شهریهٔ این کلاس در صفحهٔ عمومی دوره نمایش داده می‌شود (مشروط به فعال بودن انتشار جزئیات در تنظیمات دوره).', 'hedayati-core' ) . '</p>';
		echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_apanel_run_public' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_run_public">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<input type="hidden" name="make_public" value="' . ( $is_public ? '0' : '1' ) . '">';
		printf(
			'<button type="submit" class="hd-manager-toggle-btn%s">%s</button>',
			$is_public ? ' is-on' : '',
			esc_html( $is_public ? __( 'عمومی — کلیک برای خصوصی‌کردن', 'hedayati-core' ) : __( 'خصوصی — کلیک برای عمومی‌کردن', 'hedayati-core' ) )
		);
		echo '</form></details>';
	}

	private static function render_staff_section( array $run ): void {
		$staff   = Hedayati_Run_Staff_Service::list_for_run( (int) $run['id'] );
		$can_mod = current_user_can( 'hedayati_assign_staff' );

		echo '<details class="hd-course-fieldset" open><summary><strong>' . esc_html__( 'عوامل دوره', 'hedayati-core' ) . '</strong></summary>';
		echo '<div class="hd-manager-table" role="table"><div class="hd-manager-tr hd-manager-th" role="row">';
		echo '<span role="columnheader">' . esc_html__( 'نقش', 'hedayati-core' ) . '</span><span role="columnheader">' . esc_html__( 'فرد', 'hedayati-core' ) . '</span><span role="columnheader"></span></div>';

		if ( empty( $staff ) ) {
			echo '<div class="hd-manager-tr" role="row"><span role="cell" class="hd-portal-note">' . esc_html__( 'عاملی اختصاص نیافته است.', 'hedayati-core' ) . '</span><span role="cell"></span><span role="cell"></span></div>';
		}
		foreach ( $staff as $row ) {
			$who = '—';
			if ( $row['teacher_id'] ) {
				$who = get_the_title( $row['teacher_id'] ) ?: '#' . $row['teacher_id'];
			} elseif ( $row['user_id'] ) {
				$u   = get_user_by( 'id', $row['user_id'] );
				$who = $u ? $u->display_name : '#' . $row['user_id'];
			}
			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell">' . esc_html( Hedayati_Academic_Admin::staff_role_label( $row['staff_role'] ) ) . '</span>';
			echo '<span role="cell">' . esc_html( $who ) . '</span><span role="cell">';
			if ( $can_mod ) {
				echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_apanel_staff_remove' );
				echo '<input type="hidden" name="action" value="hedayati_apanel_staff_remove">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="assignment_id" value="' . esc_attr( (string) $row['id'] ) . '">';
				echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button></form>';
			}
			echo '</span></div>';
		}
		echo '</div>';

		if ( $can_mod ) {
			echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_apanel_staff_assign' );
			echo '<input type="hidden" name="action" value="hedayati_apanel_staff_assign">';
			echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
			self::select_field( 'staff_role', __( 'نقش', 'hedayati-core' ), [
				'primary_instructor'    => Hedayati_Academic_Admin::staff_role_label( 'primary_instructor' ),
				'additional_instructor' => Hedayati_Academic_Admin::staff_role_label( 'additional_instructor' ),
				'assistant'             => Hedayati_Academic_Admin::staff_role_label( 'assistant' ),
			], 'primary_instructor' );
			echo '<label class="hd-portal-field"><span>' . esc_html__( 'استاد (برای نقش مدرس)', 'hedayati-core' ) . '</span>';
			self::teacher_select( 'teacher_id' );
			echo '</label>';
			echo '<label class="hd-portal-field"><span>' . esc_html__( 'حساب کاربری (برای نقش استادیار)', 'hedayati-core' ) . '</span>';
			wp_dropdown_users( [ 'name' => 'user_id', 'show_option_none' => esc_html__( '— بدون حساب کاربری —', 'hedayati-core' ), 'option_none_value' => 0, 'role__in' => [ 'teacher', 'teacher_assistant' ] ] );
			echo '</label>';
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'اختصاص عامل', 'hedayati-core' ) . '</button></form>';
		}
		echo '</details>';
	}

	private static function render_sessions_section( array $run ): void {
		$sessions = Hedayati_Session_Service::list_for_run( (int) $run['id'] );
		$rp       = Hedayati_Progress_Service::run_progress( (int) $run['id'] );

		echo '<details class="hd-course-fieldset" open><summary><strong>' . esc_html__( 'جلسات', 'hedayati-core' ) . '</strong></summary>';
		if ( $rp['total'] > 0 ) {
			echo '<p class="hd-portal-note">' . esc_html( sprintf(
				/* translators: 1: held, 2: total, 3: percent */
				__( 'پیشرفت دوره: %1$s از %2$s جلسه (%3$s٪)', 'hedayati-core' ),
				Hedayati_Text::digits_to_persian( (string) $rp['held'] ),
				Hedayati_Text::digits_to_persian( (string) $rp['total'] ),
				Hedayati_Text::digits_to_persian( (string) Hedayati_Progress_Service::percent( $rp['ratio'] ) )
			) ) . '</p>';
		}

		echo '<div class="hd-manager-table" role="table"><div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [ '#', __( 'شروع', 'hedayati-core' ), __( 'موضوع', 'hedayati-core' ), __( 'وضعیت', 'hedayati-core' ), '' ] as $h ) {
			echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
		}
		echo '</div>';
		if ( empty( $sessions ) ) {
			echo '<div class="hd-manager-tr" role="row"><span role="cell" class="hd-portal-note">' . esc_html__( 'جلسه‌ای ثبت نشده است.', 'hedayati-core' ) . '</span><span role="cell"></span><span role="cell"></span><span role="cell"></span><span role="cell"></span></div>';
		}
		foreach ( $sessions as $s ) {
			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell">' . esc_html( Hedayati_Text::digits_to_persian( (string) $s['session_number'] ) ) . '</span>';
			echo '<span role="cell"><bdi>' . esc_html( self::date( $s['starts_at'], true ) ) . '</bdi></span>';
			echo '<span role="cell">' . esc_html( $s['topic'] ?: '—' ) . '</span>';
			echo '<span role="cell">' . esc_html( Hedayati_Academic_Admin::session_status_label( $s['status'] ) ) . '</span>';
			echo '<span role="cell" class="hd-manager-row-actions">';
			printf(
				'<a class="hd-manager-row-edit" href="%s">%s</a>',
				esc_url( self::url( [ 'asession' => $s['id'] ] ) ),
				esc_html__( 'حضور و غیاب', 'hedayati-core' )
			);
			echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( "'" . esc_js( __( 'حذف این جلسه و حضور و غیاب آن؟', 'hedayati-core' ) ) . "'" ) . ');">';
			wp_nonce_field( 'hedayati_apanel_session_delete' );
			echo '<input type="hidden" name="action" value="hedayati_apanel_session_delete">';
			echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
			echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $s['id'] ) . '">';
			echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button></form>';
			echo '</span></div>';
		}
		echo '</div>';

		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_apanel_session_save' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_session_save">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<h3 class="hd-portal-subtitle">' . esc_html__( 'افزودن جلسه', 'hedayati-core' ) . '</h3>';
		self::text_field( 'session_number', __( 'شمارهٔ جلسه', 'hedayati-core' ), (string) Hedayati_Session_Service::next_session_number( (int) $run['id'] ), 'ltr' );
		self::text_field( 'date', __( 'تاریخ (شمسی ۱۴۰۵/۰۶/۱۵ یا میلادی)', 'hedayati-core' ), '', 'ltr' );
		self::text_field( 'time', __( 'ساعت شروع (HH:MM)', 'hedayati-core' ), '', 'ltr' );
		self::text_field( 'topic', __( 'موضوع', 'hedayati-core' ), '' );
		self::select_field( 'status', __( 'وضعیت', 'hedayati-core' ), Hedayati_Academic_Admin::session_status_choices(), 'scheduled' );
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'افزودن جلسه', 'hedayati-core' ) . '</button></form>';
		echo '</details>';
	}

	private static function render_enrollments_section( array $run ): void {
		$enrollments = Hedayati_Enrollment_Service::list_for_run( (int) $run['id'] );
		$can_manage  = current_user_can( 'hedayati_manage_enrollments' );
		$can_add     = current_user_can( 'hedayati_create_enrollments' );

		echo '<details class="hd-course-fieldset" open><summary><strong>' . esc_html__( 'ثبت‌نام‌ها', 'hedayati-core' ) . '</strong></summary>';
		echo '<div class="hd-manager-table" role="table"><div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [ __( 'دانشجو', 'hedayati-core' ), __( 'وضعیت', 'hedayati-core' ), __( 'تاریخ ثبت‌نام', 'hedayati-core' ), '' ] as $h ) {
			echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
		}
		echo '</div>';
		if ( empty( $enrollments ) ) {
			echo '<div class="hd-manager-tr" role="row"><span role="cell" class="hd-portal-note">' . esc_html__( 'ثبت‌نامی وجود ندارد.', 'hedayati-core' ) . '</span><span role="cell"></span><span role="cell"></span><span role="cell"></span></div>';
		}
		foreach ( $enrollments as $e ) {
			$u = get_user_by( 'id', $e['user_id'] );
			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell">' . esc_html( $u ? $u->display_name : '#' . $e['user_id'] ) . '</span>';
			echo '<span role="cell">';
			if ( $can_manage ) {
				echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_apanel_enroll_status' );
				echo '<input type="hidden" name="action" value="hedayati_apanel_enroll_status">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $e['id'] ) . '">';
				echo '<select name="status" onchange="this.form.submit()">';
				foreach ( Hedayati_Academic_Admin::enrollment_status_choices() as $val => $label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $e['status'], $val, false ), esc_html( $label ) );
				}
				echo '</select></form>';
			} else {
				echo esc_html( Hedayati_Academic_Admin::enrollment_status_label( $e['status'] ) );
			}
			echo '</span>';
			echo '<span role="cell"><bdi>' . esc_html( self::date( $e['enrolled_at'], true ) ) . '</bdi></span>';
			echo '<span role="cell">';
			if ( $can_manage ) {
				echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( "'" . esc_js( __( 'حذف کامل این ثبت‌نام و حضور و غیاب آن؟', 'hedayati-core' ) ) . "'" ) . ');">';
				wp_nonce_field( 'hedayati_apanel_enroll_remove' );
				echo '<input type="hidden" name="action" value="hedayati_apanel_enroll_remove">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $e['id'] ) . '">';
				echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button></form>';
			}
			echo '</span></div>';
		}
		echo '</div>';

		if ( $can_add || $can_manage ) {
			echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_apanel_enroll_add' );
			echo '<input type="hidden" name="action" value="hedayati_apanel_enroll_add">';
			echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
			echo '<label class="hd-portal-field"><span>' . esc_html__( 'ثبت‌نام دانشجو', 'hedayati-core' ) . '</span>';
			wp_dropdown_users( [ 'name' => 'user_id', 'show_option_none' => esc_html__( '— انتخاب دانشجو —', 'hedayati-core' ), 'option_none_value' => 0, 'role__in' => [ 'student' ] ] );
			echo '</label>';
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ثبت‌نام', 'hedayati-core' ) . '</button></form>';
		}
		echo '</details>';
	}

	private static function render_attendance( int $session_id ): void {
		$session = Hedayati_Session_Service::get( $session_id );
		if ( null === $session || ! self::can_access_run( (int) $session['run_id'] ) ) {
			echo '<p class="hd-portal-notice hd-portal-notice-error">' . esc_html__( 'جلسه یافت نشد یا دسترسی ندارید.', 'hedayati-core' ) . '</p>';
			printf( '<p><a class="hd-portal-nav-link" href="%s">%s</a></p>', esc_url( self::url() ), esc_html__( 'بازگشت', 'hedayati-core' ) );
			return;
		}

		$run         = Hedayati_Course_Run_Service::get( (int) $session['run_id'] );
		$can_write   = current_user_can( 'hedayati_record_attendance' );
		$enrollments = Hedayati_Enrollment_Service::list_for_run( (int) $session['run_id'], [ 'status' => 'active' ] );
		$marks       = Hedayati_Attendance_Service::list_for_session( $session_id );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'حضور و غیاب', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html( sprintf( __( 'جلسهٔ %s', 'hedayati-core' ), Hedayati_Text::digits_to_persian( (string) $session['session_number'] ) ) ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['label'] ?: '' ) . ' · ' . self::date( $session['starts_at'], true ) ) . '</p>';
		echo '</div>';
		printf( '<a class="hd-portal-nav-link" href="%s">%s</a>', esc_url( self::url( [ 'run' => $session['run_id'] ] ) ), esc_html__( 'بازگشت به دورهٔ اجرایی', 'hedayati-core' ) );
		echo '</header>';

		if ( empty( $enrollments ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'دانشجوی فعالی در این دوره ثبت‌نام نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_apanel_attendance_save' );
		echo '<input type="hidden" name="action" value="hedayati_apanel_attendance_save">';
		echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $session_id ) . '">';

		foreach ( $enrollments as $e ) {
			$u       = get_user_by( 'id', $e['user_id'] );
			$current = $marks[ $e['id'] ]['status'] ?? '';
			$note    = $marks[ $e['id'] ]['note'] ?? '';

			echo '<div class="hd-attendance-row">';
			echo '<span>' . esc_html( $u ? $u->display_name : '#' . $e['user_id'] ) . '</span>';
			echo '<select name="mark[' . esc_attr( (string) $e['id'] ) . ']"' . disabled( ! $can_write, true, false ) . '>';
			echo '<option value="">' . esc_html__( '— ثبت نشده —', 'hedayati-core' ) . '</option>';
			foreach ( Hedayati_Academic_Admin::attendance_status_choices() as $val => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $current, $val, false ), esc_html( $label ) );
			}
			echo '</select>';
			printf(
				'<input type="text" name="note[%s]" value="%s" placeholder="%s"%s>',
				esc_attr( (string) $e['id'] ),
				esc_attr( (string) $note ),
				esc_attr__( 'توضیح (اختیاری)', 'hedayati-core' ),
				disabled( ! $can_write, true, false )
			);
			echo '</div>';
		}

		if ( $can_write ) {
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ حضور و غیاب', 'hedayati-core' ) . '</button>';
		} else {
			echo '<p class="hd-portal-note">' . esc_html__( 'شما اجازهٔ ثبت حضور و غیاب را ندارید (فقط مشاهده).', 'hedayati-core' ) . '</p>';
		}
		echo '</form>';
	}

	// ── Small field / select helpers ────────────────────────────────────────

	private static function text_field( string $name, string $label, string $value, string $dir = 'rtl', string $placeholder = '' ): void {
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="%s" value="%s" dir="%s"%s></label>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $dir ),
			'' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''
		);
	}

	private static function select_field( string $name, string $label, array $choices, string $selected ): void {
		echo '<label class="hd-portal-field"><span>' . esc_html( $label ) . '</span><select name="' . esc_attr( $name ) . '">';
		foreach ( $choices as $val => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $val ), selected( $selected, $val, false ), esc_html( (string) $text ) );
		}
		echo '</select></label>';
	}

	private static function course_select( string $name, int $selected ): void {
		$posts = get_posts( [ 'post_type' => 'course', 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<select name="' . esc_attr( $name ) . '"><option value="0">' . esc_html__( '— انتخاب دوره —', 'hedayati-core' ) . '</option>';
		foreach ( $posts as $p ) {
			printf( '<option value="%d"%s>%s</option>', (int) $p->ID, selected( $selected, $p->ID, false ), esc_html( $p->post_title ?: '#' . $p->ID ) );
		}
		echo '</select>';
	}

	private static function teacher_select( string $name ): void {
		$posts = get_posts( [ 'post_type' => Hedayati_Teacher::POST_TYPE, 'post_status' => [ 'publish', 'draft', 'pending', 'private' ], 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC' ] );
		echo '<select name="' . esc_attr( $name ) . '"><option value="0">' . esc_html__( '— بدون استاد —', 'hedayati-core' ) . '</option>';
		foreach ( $posts as $p ) {
			printf( '<option value="%d">%s</option>', (int) $p->ID, esc_html( $p->post_title ?: '#' . $p->ID ) );
		}
		echo '</select>';
	}

	/** Stored ISO -> Shamsi for a text input default (empty stays empty). */
	private static function date_value( ?string $iso ): string {
		return ( null === $iso || '' === $iso ) ? '' : Hedayati_Jalali::format( $iso, false );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	private static function guard( string $action ): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_apanel_' . $action, self::ACTIONS[ $action ] );
	}

	public static function handle_run_save(): void {
		self::guard( 'run_save' );
		$run_id = absint( self::post_str( 'run_id' ) );

		$payload = [];
		foreach ( [ 'label', 'run_status', 'registration_status', 'start_date', 'end_date', 'schedule_text', 'capacity', 'tuition_rial', 'notes' ] as $k ) {
			if ( isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) ) {
				$payload[ $k ] = wp_unslash( $_POST[ $k ] );
			}
		}

		if ( $run_id > 0 ) {
			self::require_run_scope( $run_id );
			$result = Hedayati_Course_Run_Service::update( $run_id, $payload );
			self::finish( $result, [ 'run' => $run_id ] );
		}

		$payload['course_id'] = absint( self::post_str( 'course_id' ) );
		$result               = Hedayati_Course_Run_Service::create( $payload );
		self::finish( $result, is_wp_error( $result ) ? [] : [ 'run' => (int) $result ] );
	}

	public static function handle_run_delete(): void {
		self::guard( 'run_delete' );
		$run_id = absint( self::post_str( 'run_id' ) );
		self::require_run_scope( $run_id );
		Hedayati_Course_Run_Service::delete_run( $run_id );
		self::finish( true, [] );
	}

	public static function handle_run_public(): void {
		self::guard( 'run_public' );
		$run_id = absint( self::post_str( 'run_id' ) );
		self::require_run_scope( $run_id );

		$run = Hedayati_Course_Run_Service::get( $run_id );
		if ( null === $run ) {
			self::finish( new WP_Error( 'run', __( 'دورهٔ اجرایی یافت نشد.', 'hedayati-core' ) ), [] );
		}

		$course_id = (int) $run['course_id'];
		$approved  = array_map( 'intval', (array) get_post_meta( $course_id, Hedayati_Public_Content::META_PUBLIC_RUN_IDS, true ) );
		$make      = '1' === self::post_str( 'make_public' );

		$approved = $make
			? array_values( array_unique( array_merge( $approved, [ $run_id ] ) ) )
			: array_values( array_diff( $approved, [ $run_id ] ) );

		update_post_meta( $course_id, Hedayati_Public_Content::META_PUBLIC_RUN_IDS, $approved );
		Hedayati_Audit_Log::record( 'course_run.updated', 'course_run', $run_id, $make ? 'public: on' : 'public: off', get_current_user_id() );
		self::finish( true, [ 'run' => $run_id ] );
	}

	public static function handle_staff_assign(): void {
		self::guard( 'staff_assign' );
		$run_id = absint( self::post_str( 'run_id' ) );
		self::require_run_scope( $run_id );

		$result = Hedayati_Run_Staff_Service::assign( [
			'run_id'     => $run_id,
			'staff_role' => sanitize_text_field( self::post_str( 'staff_role' ) ),
			'teacher_id' => absint( self::post_str( 'teacher_id' ) ),
			'user_id'    => absint( self::post_str( 'user_id' ) ),
		] );
		self::finish( $result, [ 'run' => $run_id ] );
	}

	public static function handle_staff_remove(): void {
		self::guard( 'staff_remove' );
		$run_id        = absint( self::post_str( 'run_id' ) );
		$assignment_id = absint( self::post_str( 'assignment_id' ) );
		self::require_run_scope( $run_id );

		$assignment = Hedayati_Run_Staff_Service::get( $assignment_id );
		if ( null === $assignment || (int) $assignment['run_id'] !== $run_id ) {
			self::finish( new WP_Error( 'not_found', __( 'اختصاص یافت نشد.', 'hedayati-core' ) ), [ 'run' => $run_id ] );
		}
		Hedayati_Run_Staff_Service::remove( $assignment_id );
		self::finish( true, [ 'run' => $run_id ] );
	}

	public static function handle_session_save(): void {
		self::guard( 'session_save' );
		$run_id     = absint( self::post_str( 'run_id' ) );
		$session_id = absint( self::post_str( 'session_id' ) );
		self::require_run_scope( $run_id );

		$date = Hedayati_Academic_Validation::parse_iso_date( self::post_str( 'date' ) )
			?: (string) Hedayati_Jalali::parse_input( self::post_str( 'date' ) );
		$time = sanitize_text_field( self::post_str( 'time' ) );

		$payload = [
			'run_id'         => $run_id,
			'session_number' => sanitize_text_field( self::post_str( 'session_number' ) ),
			'starts_at'      => '' !== $date ? trim( $date . ' ' . ( '' !== $time ? $time . ':00' : '00:00:00' ) ) : '',
			'topic'          => sanitize_text_field( self::post_str( 'topic' ) ),
			'status'         => sanitize_text_field( self::post_str( 'status' ) ),
		];

		if ( $session_id > 0 ) {
			$existing = Hedayati_Session_Service::get( $session_id );
			if ( null === $existing || (int) $existing['run_id'] !== $run_id ) {
				self::finish( new WP_Error( 'not_found', __( 'جلسه یافت نشد.', 'hedayati-core' ) ), [ 'run' => $run_id ] );
			}
			$result = Hedayati_Session_Service::update( $session_id, $payload );
		} else {
			$result = Hedayati_Session_Service::create( $payload );
		}
		self::finish( $result, [ 'run' => $run_id ] );
	}

	public static function handle_session_delete(): void {
		self::guard( 'session_delete' );
		$run_id     = absint( self::post_str( 'run_id' ) );
		$session_id = absint( self::post_str( 'session_id' ) );
		self::require_run_scope( $run_id );

		$existing = Hedayati_Session_Service::get( $session_id );
		if ( null !== $existing && (int) $existing['run_id'] === $run_id ) {
			Hedayati_Session_Service::delete_session( $session_id );
		}
		self::finish( true, [ 'run' => $run_id ] );
	}

	public static function handle_enroll_add(): void {
		self::guard( 'enroll_add' );
		$run_id = absint( self::post_str( 'run_id' ) );
		self::require_run_scope( $run_id );
		$result = Hedayati_Enrollment_Service::enroll( $run_id, absint( self::post_str( 'user_id' ) ) );
		self::finish( $result, [ 'run' => $run_id ] );
	}

	public static function handle_enroll_status(): void {
		self::guard( 'enroll_status' );
		$run_id        = absint( self::post_str( 'run_id' ) );
		$enrollment_id = absint( self::post_str( 'enrollment_id' ) );
		self::require_run_scope( $run_id );

		$enrollment = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null === $enrollment || (int) $enrollment['run_id'] !== $run_id ) {
			self::finish( new WP_Error( 'not_found', __( 'ثبت‌نام یافت نشد.', 'hedayati-core' ) ), [ 'run' => $run_id ] );
		}
		$result = Hedayati_Enrollment_Service::set_status( $enrollment_id, sanitize_text_field( self::post_str( 'status' ) ) );
		self::finish( $result, [ 'run' => $run_id ] );
	}

	public static function handle_enroll_remove(): void {
		self::guard( 'enroll_remove' );
		$run_id        = absint( self::post_str( 'run_id' ) );
		$enrollment_id = absint( self::post_str( 'enrollment_id' ) );
		self::require_run_scope( $run_id );

		$enrollment = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null !== $enrollment && (int) $enrollment['run_id'] === $run_id ) {
			Hedayati_Enrollment_Service::delete_enrollment( $enrollment_id );
		}
		self::finish( true, [ 'run' => $run_id ] );
	}

	public static function handle_attendance_save(): void {
		self::guard( 'attendance_save' );
		$session_id = absint( self::post_str( 'session_id' ) );
		$session    = Hedayati_Session_Service::get( $session_id );
		if ( null === $session ) {
			self::finish( new WP_Error( 'not_found', __( 'جلسه یافت نشد.', 'hedayati-core' ) ), [] );
		}
		self::require_run_scope( (int) $session['run_id'] );

		$raw_marks = isset( $_POST['mark'] ) && is_array( $_POST['mark'] ) ? wp_unslash( $_POST['mark'] ) : [];
		$raw_notes = isset( $_POST['note'] ) && is_array( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : [];

		// Validate the batch (including forged foreign enrollment IDs) before any write.
		foreach ( $raw_marks as $enrollment_id => $status ) {
			if ( is_array( $status ) ) {
				continue;
			}
			$status = (string) $status;
			if ( '' === $status ) {
				continue;
			}
			$enrollment = Hedayati_Enrollment_Service::get( absint( $enrollment_id ) );
			if ( ! $enrollment
				|| (int) $enrollment['run_id'] !== (int) $session['run_id']
				|| 'active' !== $enrollment['status']
				|| ! in_array( $status, Hedayati_Academic_Validation::ATTENDANCE_STATUSES, true )
			) {
				wp_die( esc_html__( 'دادهٔ حضور و غیاب نامعتبر است.', 'hedayati-core' ), '', [ 'response' => 400 ] );
			}
		}

		$recorded = 0;
		$errors   = 0;
		foreach ( $raw_marks as $enrollment_id => $status ) {
			if ( is_array( $status ) || '' === (string) $status ) {
				continue;
			}
			$note   = $raw_notes[ $enrollment_id ] ?? '';
			$result = Hedayati_Attendance_Service::record( $session_id, absint( $enrollment_id ), (string) $status, [
				'note'        => is_array( $note ) ? '' : sanitize_text_field( (string) $note ),
				'recorded_by' => get_current_user_id(),
			] );
			is_wp_error( $result ) ? $errors++ : $recorded++;
		}

		self::finish(
			$errors > 0 ? new WP_Error( 'partial', sprintf( __( '%s مورد ثبت شد، %s خطا.', 'hedayati-core' ), Hedayati_Text::digits_to_persian( (string) $recorded ), Hedayati_Text::digits_to_persian( (string) $errors ) ) ) : true,
			[ 'asession' => $session_id ]
		);
	}
}
