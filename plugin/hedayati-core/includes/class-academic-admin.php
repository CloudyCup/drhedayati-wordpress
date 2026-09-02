<?php
/**
 * Phase 2B — Academic Operations admin UI.
 *
 * A single wp-admin screen ("عملیات آموزشی") for institute staff to run cohorts
 * without touching SQL: course runs, staff assignment, class sessions, enrollments
 * and attendance.
 *
 * Security model (every state-changing request):
 *   - routed through admin-post.php with a per-action nonce;
 *   - server-side capability check (never UI-visibility alone);
 *   - per-run access scope for non-managers (teachers/TAs see only their runs);
 *   - all IDs validated against the services; all output escaped by context.
 *
 * Capability map:
 *   - view screen / manage runs / sessions / enrollments : hedayati_manage_course_runs
 *   - assign staff                                       : hedayati_assign_staff
 *   - record attendance (write)                          : hedayati_record_attendance
 *   - manage enrollments (status/remove)                 : hedayati_manage_enrollments
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Academic_Admin {

	private const MENU_SLUG = 'hedayati-academic';
	private const CAP_VIEW  = 'hedayati_manage_course_runs';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_notices', [ self::class, 'render_notices' ] );
		add_action( 'add_meta_boxes_course', [ self::class, 'register_course_runs_box' ] );

		$actions = [
			'hedayati_run_save', 'hedayati_run_delete',
			'hedayati_staff_assign', 'hedayati_staff_remove',
			'hedayati_session_save', 'hedayati_session_delete',
			'hedayati_enroll_add', 'hedayati_enroll_status', 'hedayati_enroll_remove',
			'hedayati_attendance_save',
		];

		foreach ( $actions as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle_' . substr( $action, 9 ) ] );
		}
	}

	// ── Menu / routing ──────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_menu_page(
			'عملیات آموزشی',
			'عملیات آموزشی',
			self::CAP_VIEW,
			self::MENU_SLUG,
			[ self::class, 'render_screen' ],
			'dashicons-welcome-learn-more',
			7
		);

		add_submenu_page(
			self::MENU_SLUG,
			'گزارش رویدادها (Audit Log)',
			'گزارش رویدادها',
			Hedayati_Audit_Log::VIEW_CAPABILITY,
			self::MENU_SLUG . '-audit',
			[ self::class, 'render_audit_log' ]
		);
	}

	public static function render_screen(): void {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این بخش را ندارید.', 'hedayati-core' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		echo '<div class="wrap">';

		switch ( $view ) {
			case 'run':
				self::render_run_detail( isset( $_GET['run_id'] ) ? absint( wp_unslash( $_GET['run_id'] ) ) : 0 );
				break;
			case 'attendance':
				self::render_attendance( isset( $_GET['session_id'] ) ? absint( wp_unslash( $_GET['session_id'] ) ) : 0 );
				break;
			default:
				self::render_run_list();
		}

		echo '</div>';
	}

	// ── Course edit screen: read-only "Course Runs" panel ───────────────────

	public static function register_course_runs_box(): void {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			return;
		}

		add_meta_box(
			'hedayati-course-runs',
			'دوره‌های اجرایی این دوره',
			[ self::class, 'render_course_runs_box' ],
			'course',
			'side',
			'default'
		);
	}

	public static function render_course_runs_box( WP_Post $post ): void {
		$runs = Hedayati_Course_Run_Service::query( [ 'course_id' => $post->ID, 'limit' => 100, 'orderby' => 'start_date', 'order' => 'DESC' ] );

		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'هنوز دورهٔ اجرایی برای این دوره ثبت نشده است.', 'hedayati-core' ) . '</p>';
		} else {
			echo '<ul style="margin:0">';
			foreach ( $runs as $run ) {
				$url = add_query_arg(
					[ 'page' => self::MENU_SLUG, 'view' => 'run', 'run_id' => $run['id'] ],
					admin_url( 'admin.php' )
				);
				printf(
					'<li style="margin-bottom:.4em"><a href="%s">%s</a> — %s%s</li>',
					esc_url( $url ),
					esc_html( $run['label'] ?: sprintf( __( 'دورهٔ اجرایی #%d', 'hedayati-core' ), $run['id'] ) ),
					esc_html( self::run_status_label( $run['run_status'] ) ),
					$run['start_date'] ? ' — <span dir="ltr">' . esc_html( $run['start_date'] ) . '</span>' : ''
				);
			}
			echo '</ul>';
		}

		$new_url = add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
		echo '<p><a class="button button-small" href="' . esc_url( $new_url ) . '">' . esc_html__( 'مدیریت دوره‌های اجرایی', 'hedayati-core' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'دورهٔ اجرایی، منبعِ رسمیِ مدرس، زمان‌بندی، شهریه و ظرفیتِ هر ترم است.', 'hedayati-core' ) . '</p>';
	}

	// ── Access scope ────────────────────────────────────────────────────────

	private static function is_manager(): bool {
		return current_user_can( 'hedayati_manage_course_runs' );
	}

	/**
	 * Managers/admins can reach every run; other staff only runs they are on.
	 */
	private static function can_access_run( int $run_id ): bool {
		if ( self::is_manager() ) {
			return true;
		}

		return Hedayati_Run_Staff_Service::user_is_staff_on_run( get_current_user_id(), $run_id );
	}

	// ── List view ───────────────────────────────────────────────────────────

	private static function render_run_list(): void {
		$runs = Hedayati_Course_Run_Service::query( [ 'limit' => 200, 'orderby' => 'created_at', 'order' => 'DESC' ] );

		echo '<h1>' . esc_html__( 'دوره‌های اجرایی', 'hedayati-core' ) . '</h1>';

		echo '<h2>' . esc_html__( 'افزودن دورهٔ اجرایی جدید', 'hedayati-core' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_run_save' );
		echo '<input type="hidden" name="action" value="hedayati_run_save">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="hd_course_id">' . esc_html__( 'دورهٔ کاتالوگ', 'hedayati-core' ) . '</label></th><td>';
		self::post_select( 'course_id', 'course', 0, esc_html__( '— انتخاب دوره —', 'hedayati-core' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hd_label">' . esc_html__( 'عنوان دورهٔ اجرایی', 'hedayati-core' ) . '</label></th><td>';
		echo '<input type="text" name="label" id="hd_label" class="regular-text" placeholder="' . esc_attr__( 'مثال: پاییز ۱۴۰۴ — تبریز', 'hedayati-core' ) . '"></td></tr>';

		echo '</tbody></table>';
		submit_button( esc_html__( 'ایجاد دورهٔ اجرایی', 'hedayati-core' ) );
		echo '</form>';

		echo '<hr><h2>' . esc_html__( 'همهٔ دوره‌های اجرایی', 'hedayati-core' ) . '</h2>';

		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'هنوز دورهٔ اجرایی ثبت نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'دوره', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'عنوان', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'وضعیت اجرا', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'ثبت‌نام', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'شروع', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'ثبت‌نام‌شده', 'hedayati-core' ) . '</th>';
		echo '<th></th></tr></thead><tbody>';

		foreach ( $runs as $run ) {
			if ( ! self::can_access_run( (int) $run['id'] ) ) {
				continue;
			}

			$course_title = get_the_title( $run['course_id'] );
			$detail_url   = add_query_arg(
				[ 'page' => self::MENU_SLUG, 'view' => 'run', 'run_id' => $run['id'] ],
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $course_title ?: '#' . $run['course_id'] ) . '</td>';
			echo '<td>' . esc_html( $run['label'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( self::run_status_label( $run['run_status'] ) ) . '</td>';
			echo '<td>' . esc_html( self::registration_status_label( $run['registration_status'] ) ) . '</td>';
			echo '<td dir="ltr">' . esc_html( $run['start_date'] ?? '—' ) . '</td>';
			echo '<td>' . esc_html( (string) Hedayati_Enrollment_Service::count_active( (int) $run['id'] ) )
				. ( null !== $run['capacity'] ? ' / ' . esc_html( (string) $run['capacity'] ) : '' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'مدیریت', 'hedayati-core' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	// ── Run detail view ─────────────────────────────────────────────────────

	private static function render_run_detail( int $run_id ): void {
		$run = Hedayati_Course_Run_Service::get( $run_id );

		if ( null === $run || ! self::can_access_run( $run_id ) ) {
			echo '<h1>' . esc_html__( 'دورهٔ اجرایی', 'hedayati-core' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'دورهٔ اجرایی یافت نشد یا دسترسی ندارید.', 'hedayati-core' ) . '</p></div>';
			return;
		}

		$back = add_query_arg( [ 'page' => self::MENU_SLUG ], admin_url( 'admin.php' ) );
		echo '<h1>' . esc_html( get_the_title( $run['course_id'] ) ) . ' — ' . esc_html( $run['label'] ?: __( 'بدون عنوان', 'hedayati-core' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) . '</a></p>';

		self::render_run_form( $run );

		if ( self::is_manager() ) {
			self::render_staff_section( $run );
			self::render_sessions_section( $run );
			self::render_enrollments_section( $run );
		} else {
			self::render_sessions_section( $run, true );
		}
	}

	private static function render_run_form( array $run ): void {
		echo '<h2>' . esc_html__( 'مشخصات دورهٔ اجرایی', 'hedayati-core' ) . '</h2>';

		if ( ! self::is_manager() ) {
			echo '<p class="description">' . esc_html__( 'فقط مدیر آموزش می‌تواند این مشخصات را تغییر دهد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_run_save' );
		echo '<input type="hidden" name="action" value="hedayati_run_save">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::text_row( 'label', __( 'عنوان', 'hedayati-core' ), $run['label'] );
		self::select_row( 'run_status', __( 'وضعیت اجرا', 'hedayati-core' ), self::run_status_choices(), $run['run_status'] );
		self::select_row( 'registration_status', __( 'وضعیت ثبت‌نام', 'hedayati-core' ), self::registration_status_choices(), $run['registration_status'] );
		self::text_row( 'start_date', __( 'تاریخ شروع (میلادی YYYY-MM-DD)', 'hedayati-core' ), $run['start_date'] ?? '', 'ltr' );
		self::text_row( 'end_date', __( 'تاریخ پایان (میلادی YYYY-MM-DD)', 'hedayati-core' ), $run['end_date'] ?? '', 'ltr' );
		self::text_row( 'schedule_text', __( 'برنامهٔ زمانی', 'hedayati-core' ), $run['schedule_text'] );
		self::text_row( 'capacity', __( 'ظرفیت (خالی = نامشخص)', 'hedayati-core' ), null === $run['capacity'] ? '' : (string) $run['capacity'], 'ltr' );
		self::text_row( 'tuition_rial', __( 'شهریه به ریال (خالی = نامشخص)', 'hedayati-core' ), null === $run['tuition_rial'] ? '' : (string) $run['tuition_rial'], 'ltr' );

		echo '<tr><th scope="row"><label for="hd_notes">' . esc_html__( 'یادداشت داخلی', 'hedayati-core' ) . '</label></th><td>';
		echo '<textarea name="notes" id="hd_notes" rows="3" class="large-text">' . esc_textarea( $run['notes'] ) . '</textarea></td></tr>';

		echo '</tbody></table>';
		submit_button( esc_html__( 'ذخیرهٔ مشخصات', 'hedayati-core' ) );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'حذف این دورهٔ اجرایی و همهٔ جلسات، ثبت‌نام‌ها و حضور و غیاب آن؟', 'hedayati-core' ) ) . '\');">';
		wp_nonce_field( 'hedayati_run_delete' );
		echo '<input type="hidden" name="action" value="hedayati_run_delete">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		submit_button( esc_html__( 'حذف دورهٔ اجرایی', 'hedayati-core' ), 'delete', 'submit', false );
		echo '</form>';
	}

	private static function render_staff_section( array $run ): void {
		echo '<hr><h2>' . esc_html__( 'عوامل دوره', 'hedayati-core' ) . '</h2>';

		$staff = Hedayati_Run_Staff_Service::list_for_run( (int) $run['id'] );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'نقش', 'hedayati-core' ) . '</th><th>' . esc_html__( 'فرد', 'hedayati-core' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $staff ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'عاملی اختصاص نیافته است.', 'hedayati-core' ) . '</td></tr>';
		}

		foreach ( $staff as $row ) {
			$who = '—';
			if ( $row['teacher_id'] ) {
				$who = get_the_title( $row['teacher_id'] ) ?: '#' . $row['teacher_id'];
			} elseif ( $row['user_id'] ) {
				$u   = get_user_by( 'id', $row['user_id'] );
				$who = $u ? $u->display_name : '#' . $row['user_id'];
			}

			echo '<tr><td>' . esc_html( self::staff_role_label( $row['staff_role'] ) ) . '</td><td>' . esc_html( $who ) . '</td><td>';

			if ( current_user_can( 'hedayati_assign_staff' ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_staff_remove' );
				echo '<input type="hidden" name="action" value="hedayati_staff_remove">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="assignment_id" value="' . esc_attr( (string) $row['id'] ) . '">';
				submit_button( esc_html__( 'حذف', 'hedayati-core' ), 'small delete', 'submit', false );
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( ! current_user_can( 'hedayati_assign_staff' ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'افزودن عامل', 'hedayati-core' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_staff_assign' );
		echo '<input type="hidden" name="action" value="hedayati_staff_assign">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';

		self::select_row( 'staff_role', __( 'نقش', 'hedayati-core' ), [
			'primary_instructor'    => self::staff_role_label( 'primary_instructor' ),
			'additional_instructor' => self::staff_role_label( 'additional_instructor' ),
			'assistant'             => self::staff_role_label( 'assistant' ),
		], 'primary_instructor' );

		echo '<tr><th scope="row"><label for="hd_teacher_id">' . esc_html__( 'استاد (برای نقش مدرس)', 'hedayati-core' ) . '</label></th><td>';
		self::post_select( 'teacher_id', Hedayati_Teacher::POST_TYPE, 0, esc_html__( '— بدون استاد —', 'hedayati-core' ) );
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="hd_staff_user_id">' . esc_html__( 'حساب کاربری (برای نقش استادیار)', 'hedayati-core' ) . '</label></th><td>';
		wp_dropdown_users( [
			'name'              => 'user_id',
			'id'                => 'hd_staff_user_id',
			'show_option_none'  => esc_html__( '— بدون حساب کاربری —', 'hedayati-core' ),
			'option_none_value' => 0,
		] );
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( esc_html__( 'اختصاص عامل', 'hedayati-core' ) );
		echo '</form>';
	}

	private static function render_sessions_section( array $run, bool $read_only = false ): void {
		echo '<hr><h2>' . esc_html__( 'جلسات', 'hedayati-core' ) . '</h2>';

		$sessions        = Hedayati_Session_Service::list_for_run( (int) $run['id'] );
		$can_take_attend = current_user_can( 'hedayati_record_attendance' );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>#</th><th>' . esc_html__( 'شروع', 'hedayati-core' ) . '</th><th>' . esc_html__( 'موضوع', 'hedayati-core' ) . '</th><th>' . esc_html__( 'وضعیت', 'hedayati-core' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $sessions ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'جلسه‌ای ثبت نشده است.', 'hedayati-core' ) . '</td></tr>';
		}

		foreach ( $sessions as $s ) {
			$attend_url = add_query_arg(
				[ 'page' => self::MENU_SLUG, 'view' => 'attendance', 'session_id' => $s['id'] ],
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( (string) $s['session_number'] ) . '</td>';
			echo '<td dir="ltr">' . esc_html( $s['starts_at'] ) . '</td>';
			echo '<td>' . esc_html( $s['topic'] ?: '—' ) . '</td>';
			echo '<td>' . esc_html( self::session_status_label( $s['status'] ) ) . '</td>';
			echo '<td>';
			if ( $can_take_attend ) {
				echo '<a class="button button-small" href="' . esc_url( $attend_url ) . '">' . esc_html__( 'حضور و غیاب', 'hedayati-core' ) . '</a> ';
			}
			if ( ! $read_only && self::is_manager() ) {
				echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'حذف این جلسه و حضور و غیاب آن؟', 'hedayati-core' ) ) . '\');">';
				wp_nonce_field( 'hedayati_session_delete' );
				echo '<input type="hidden" name="action" value="hedayati_session_delete">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $s['id'] ) . '">';
				submit_button( esc_html__( 'حذف', 'hedayati-core' ), 'small delete', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $read_only || ! self::is_manager() ) {
			return;
		}

		echo '<h3>' . esc_html__( 'افزودن جلسه', 'hedayati-core' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_session_save' );
		echo '<input type="hidden" name="action" value="hedayati_session_save">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'session_number', __( 'شمارهٔ جلسه', 'hedayati-core' ), (string) Hedayati_Session_Service::next_session_number( (int) $run['id'] ), 'ltr' );
		self::text_row( 'starts_at', __( 'زمان شروع (YYYY-MM-DD HH:MM)', 'hedayati-core' ), '', 'ltr' );
		self::text_row( 'ends_at', __( 'زمان پایان (اختیاری)', 'hedayati-core' ), '', 'ltr' );
		self::text_row( 'topic', __( 'موضوع', 'hedayati-core' ), '' );
		self::select_row( 'status', __( 'وضعیت', 'hedayati-core' ), self::session_status_choices(), 'scheduled' );
		echo '</tbody></table>';
		submit_button( esc_html__( 'افزودن جلسه', 'hedayati-core' ) );
		echo '</form>';
	}

	private static function render_enrollments_section( array $run ): void {
		echo '<hr><h2>' . esc_html__( 'ثبت‌نام‌ها', 'hedayati-core' ) . '</h2>';

		$enrollments  = Hedayati_Enrollment_Service::list_for_run( (int) $run['id'] );
		$can_manage   = current_user_can( 'hedayati_manage_enrollments' );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'دانشجو', 'hedayati-core' ) . '</th><th>' . esc_html__( 'وضعیت', 'hedayati-core' ) . '</th><th>' . esc_html__( 'تاریخ ثبت‌نام', 'hedayati-core' ) . '</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( empty( $enrollments ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'ثبت‌نامی وجود ندارد.', 'hedayati-core' ) . '</td></tr>';
		}

		foreach ( $enrollments as $e ) {
			$u = get_user_by( 'id', $e['user_id'] );
			echo '<tr>';
			echo '<td>' . esc_html( $u ? $u->display_name . ' (' . $u->user_login . ')' : '#' . $e['user_id'] ) . '</td>';
			echo '<td>' . esc_html( self::enrollment_status_label( $e['status'] ) ) . '</td>';
			echo '<td dir="ltr">' . esc_html( $e['enrolled_at'] ) . '</td>';
			echo '<td>';

			if ( $can_manage ) {
				echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_enroll_status' );
				echo '<input type="hidden" name="action" value="hedayati_enroll_status">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $e['id'] ) . '">';
				echo '<select name="status">';
				foreach ( self::enrollment_status_choices() as $val => $label ) {
					echo '<option value="' . esc_attr( $val ) . '"' . selected( $e['status'], $val, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select> ';
				submit_button( esc_html__( 'به‌روزرسانی', 'hedayati-core' ), 'small', 'submit', false );
				echo '</form> ';

				echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'حذف کامل این ثبت‌نام و حضور و غیاب آن؟', 'hedayati-core' ) ) . '\');">';
				wp_nonce_field( 'hedayati_enroll_remove' );
				echo '<input type="hidden" name="action" value="hedayati_enroll_remove">';
				echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
				echo '<input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $e['id'] ) . '">';
				submit_button( esc_html__( 'حذف', 'hedayati-core' ), 'small delete', 'submit', false );
				echo '</form>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( ! current_user_can( 'hedayati_create_enrollments' ) && ! $can_manage ) {
			return;
		}

		echo '<h3>' . esc_html__( 'ثبت‌نام دانشجو', 'hedayati-core' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_enroll_add' );
		echo '<input type="hidden" name="action" value="hedayati_enroll_add">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run['id'] ) . '">';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'دانشجو', 'hedayati-core' ) . '</th><td>';
		wp_dropdown_users( [
			'name'              => 'user_id',
			'show_option_none'  => esc_html__( '— انتخاب دانشجو —', 'hedayati-core' ),
			'option_none_value' => 0,
			'role__in'          => [ 'student' ],
		] );
		echo '<p class="description">' . esc_html__( 'فقط کاربران با نقش دانشجو نمایش داده می‌شوند.', 'hedayati-core' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button( esc_html__( 'ثبت‌نام', 'hedayati-core' ) );
		echo '</form>';
	}

	// ── Attendance view ─────────────────────────────────────────────────────

	private static function render_attendance( int $session_id ): void {
		$session = Hedayati_Session_Service::get( $session_id );

		if ( null === $session || ! self::can_access_run( $session['run_id'] ) ) {
			echo '<h1>' . esc_html__( 'حضور و غیاب', 'hedayati-core' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'جلسه یافت نشد یا دسترسی ندارید.', 'hedayati-core' ) . '</p></div>';
			return;
		}

		$run        = Hedayati_Course_Run_Service::get( $session['run_id'] );
		$can_write  = current_user_can( 'hedayati_record_attendance' );
		$enrollments = Hedayati_Enrollment_Service::list_for_run( $session['run_id'], [ 'status' => 'active' ] );
		$marks      = Hedayati_Attendance_Service::list_for_session( $session_id );

		$back = add_query_arg(
			[ 'page' => self::MENU_SLUG, 'view' => 'run', 'run_id' => $session['run_id'] ],
			admin_url( 'admin.php' )
		);

		echo '<h1>' . esc_html__( 'حضور و غیاب', 'hedayati-core' ) . ' — ' . esc_html( sprintf( __( 'جلسهٔ %d', 'hedayati-core' ), $session['session_number'] ) ) . '</h1>';
		echo '<p>' . esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['label'] ?: '' ) ) . ' · <span dir="ltr">' . esc_html( $session['starts_at'] ) . '</span></p>';
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'بازگشت به دورهٔ اجرایی', 'hedayati-core' ) . '</a></p>';

		if ( empty( $enrollments ) ) {
			echo '<p>' . esc_html__( 'دانشجوی فعالی در این دوره ثبت‌نام نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_attendance_save' );
		echo '<input type="hidden" name="action" value="hedayati_attendance_save">';
		echo '<input type="hidden" name="session_id" value="' . esc_attr( (string) $session_id ) . '">';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'دانشجو', 'hedayati-core' ) . '</th><th>' . esc_html__( 'وضعیت', 'hedayati-core' ) . '</th><th>' . esc_html__( 'توضیح', 'hedayati-core' ) . '</th></tr></thead><tbody>';

		foreach ( $enrollments as $e ) {
			$u       = get_user_by( 'id', $e['user_id'] );
			$current = $marks[ $e['id'] ]['status'] ?? '';
			$note    = $marks[ $e['id'] ]['note'] ?? '';

			echo '<tr><td>' . esc_html( $u ? $u->display_name : '#' . $e['user_id'] ) . '</td><td>';
			echo '<select name="mark[' . esc_attr( (string) $e['id'] ) . ']"' . disabled( ! $can_write, true, false ) . '>';
			echo '<option value="">' . esc_html__( '— ثبت نشده —', 'hedayati-core' ) . '</option>';
			foreach ( self::attendance_status_choices() as $val => $label ) {
				echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td><td>';
			echo '<input type="text" name="note[' . esc_attr( (string) $e['id'] ) . ']" value="' . esc_attr( $note ) . '" class="regular-text"' . disabled( ! $can_write, true, false ) . '>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		if ( $can_write ) {
			submit_button( esc_html__( 'ذخیرهٔ حضور و غیاب', 'hedayati-core' ) );
		} else {
			echo '<p class="description">' . esc_html__( 'شما اجازهٔ ثبت حضور و غیاب را ندارید (فقط مشاهده).', 'hedayati-core' ) . '</p>';
		}

		echo '</form>';
	}

	// ── Audit-log viewer (read-only) ───────────────────────────────────────

	/**
	 * Smallest secure read-only viewer for the append-only audit log
	 * (`hedayati_view_audit_logs`). No actions, no state changes, no nonces
	 * (GET only). Filters and pagination via sanitized query args. Richer UX
	 * (export, date range, actor search) is Phase 2D and deliberately not invented
	 * here.
	 */
	public static function render_audit_log(): void {
		if ( ! current_user_can( Hedayati_Audit_Log::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازهٔ مشاهدهٔ گزارش رویدادها را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$per_page  = 50;
		$page      = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$f_type    = isset( $_GET['flt_object_type'] ) ? sanitize_key( wp_unslash( $_GET['flt_object_type'] ) ) : '';
		$f_action  = isset( $_GET['flt_action'] ) ? sanitize_text_field( wp_unslash( $_GET['flt_action'] ) ) : '';
		$f_object  = isset( $_GET['flt_object_id'] ) ? absint( wp_unslash( $_GET['flt_object_id'] ) ) : 0;

		$f_type   = in_array( $f_type, Hedayati_Audit_Log::object_types(), true ) ? $f_type : '';
		$f_action = in_array( $f_action, Hedayati_Audit_Log::actions(), true ) ? $f_action : '';

		$filters = array_filter( [
			'object_type' => $f_type,
			'action'      => $f_action,
			'object_id'   => $f_object,
		] );

		$total   = Hedayati_Audit_Log::count( $filters );
		$entries = Hedayati_Audit_Log::query( $filters + [ 'per_page' => $per_page, 'page' => $page ] );
		$pages   = (int) max( 1, ceil( $total / $per_page ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'گزارش رویدادها (Audit Log)', 'hedayati-core' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'ثبت فقط-افزودنی رویدادهای عملیاتی. بدون IP، بدون user-agent، بدون اطلاعات هویتی دانشجو.', 'hedayati-core' ) . '</p>';

		// Filter form (GET)
		echo '<form method="get" style="margin:1em 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG . '-audit' ) . '">';
		echo '<select name="flt_object_type"><option value="">' . esc_html__( 'همهٔ انواع', 'hedayati-core' ) . '</option>';
		foreach ( Hedayati_Audit_Log::object_types() as $ot ) {
			echo '<option value="' . esc_attr( $ot ) . '"' . selected( $f_type, $ot, false ) . '>' . esc_html( $ot ) . '</option>';
		}
		echo '</select> ';
		echo '<select name="flt_action"><option value="">' . esc_html__( 'همهٔ رویدادها', 'hedayati-core' ) . '</option>';
		foreach ( Hedayati_Audit_Log::actions() as $ac ) {
			echo '<option value="' . esc_attr( $ac ) . '"' . selected( $f_action, $ac, false ) . '>' . esc_html( $ac ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="number" name="flt_object_id" value="' . esc_attr( $f_object ?: '' ) . '" placeholder="' . esc_attr__( 'شناسهٔ شیء', 'hedayati-core' ) . '" style="width:9em" min="0"> ';
		submit_button( esc_html__( 'فیلتر', 'hedayati-core' ), 'secondary', '', false );
		echo '</form>';

		echo '<p>' . esc_html( sprintf( __( '%d رویداد', 'hedayati-core' ), $total ) ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'زمان (UTC)', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'کاربر', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'رویداد', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'شیء', 'hedayati-core' ) . '</th>';
		echo '<th>' . esc_html__( 'توضیح', 'hedayati-core' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $entries ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'رویدادی ثبت نشده است.', 'hedayati-core' ) . '</td></tr>';
		}

		foreach ( $entries as $row ) {
			$actor = '—';
			if ( $row['actor_id'] > 0 ) {
				$u     = get_user_by( 'id', $row['actor_id'] );
				$actor = $u ? $u->user_login : sprintf( '#%d', $row['actor_id'] );
			} else {
				$actor = esc_html__( 'سیستم', 'hedayati-core' );
			}

			echo '<tr>';
			echo '<td dir="ltr">' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $actor ) . '</td>';
			echo '<td dir="ltr"><code>' . esc_html( $row['action'] ) . '</code></td>';
			echo '<td dir="ltr">' . esc_html( $row['object_type'] . ( $row['object_id'] ? ' #' . $row['object_id'] : '' ) ) . '</td>';
			echo '<td dir="ltr">' . esc_html( $row['note'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $pages > 1 ) {
			$base_args         = $filters;
			$base_args['page'] = self::MENU_SLUG . '-audit';
			$base              = admin_url( 'admin.php' ) . '?' . http_build_query( $base_args ) . '&paged=%#%';

			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( (string) paginate_links( [
				'base'      => $base,
				'format'    => '',
				'current'   => $page,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			] ) );
			echo '</div></div>';
		}

		echo '</div>';
	}

	// ── POST handlers ───────────────────────────────────────────────────────

	private static function verify( string $action, string $cap ): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $action ) ) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	private static function redirect( array $args, string $notice = '', string $type = 'success' ): void {
		if ( '' !== $notice ) {
			set_transient( self::notice_key(), [ 'type' => $type, 'text' => $notice ], 45 );
		}

		$args['page'] = self::MENU_SLUG;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function require_run_scope( int $run_id ): void {
		if ( ! self::can_access_run( $run_id ) ) {
			wp_die( esc_html__( 'به این دورهٔ اجرایی دسترسی ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	public static function handle_run_save(): void {
		self::verify( 'hedayati_run_save', 'hedayati_manage_course_runs' );

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;

		$payload = [];
		foreach ( [ 'label', 'run_status', 'registration_status', 'start_date', 'end_date', 'schedule_text', 'capacity', 'tuition_rial', 'notes' ] as $k ) {
			if ( isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ) {
				// The service is the sanitization boundary (allowlist / date / int parsers);
				// here we only unslash and guarantee a scalar reaches it.
				$payload[ $k ] = wp_unslash( $_POST[ $k ] );
			}
		}

		if ( $run_id > 0 ) {
			self::require_run_scope( $run_id );
			$result = Hedayati_Course_Run_Service::update( $run_id, $payload );
			$target = [ 'view' => 'run', 'run_id' => $run_id ];
		} else {
			$payload['course_id'] = isset( $_POST['course_id'] ) ? absint( wp_unslash( $_POST['course_id'] ) ) : 0;
			$result               = Hedayati_Course_Run_Service::create( $payload );
			$target               = is_wp_error( $result ) ? [] : [ 'view' => 'run', 'run_id' => (int) $result ];
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( $run_id > 0 ? [ 'view' => 'run', 'run_id' => $run_id ] : [], $result->get_error_message(), 'error' );
		}

		self::redirect( $target, __( 'دورهٔ اجرایی ذخیره شد.', 'hedayati-core' ) );
	}

	public static function handle_run_delete(): void {
		self::verify( 'hedayati_run_delete', 'hedayati_manage_course_runs' );

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		Hedayati_Course_Run_Service::delete_run( $run_id );
		self::redirect( [], __( 'دورهٔ اجرایی حذف شد.', 'hedayati-core' ) );
	}

	public static function handle_staff_assign(): void {
		self::verify( 'hedayati_staff_assign', 'hedayati_assign_staff' );

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$result = Hedayati_Run_Staff_Service::assign( [
			'run_id'     => $run_id,
			'staff_role' => isset( $_POST['staff_role'] ) ? sanitize_text_field( wp_unslash( $_POST['staff_role'] ) ) : '',
			'teacher_id' => isset( $_POST['teacher_id'] ) ? absint( wp_unslash( $_POST['teacher_id'] ) ) : 0,
			'user_id'    => isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0,
		] );

		if ( is_wp_error( $result ) ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], $result->get_error_message(), 'error' );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'عامل دوره اختصاص یافت.', 'hedayati-core' ) );
	}

	public static function handle_staff_remove(): void {
		self::verify( 'hedayati_staff_remove', 'hedayati_assign_staff' );

		$run_id        = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$assignment_id = isset( $_POST['assignment_id'] ) ? absint( wp_unslash( $_POST['assignment_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$assignment = Hedayati_Run_Staff_Service::get( $assignment_id );
		if ( null === $assignment || $assignment['run_id'] !== $run_id ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'اختصاص یافت نشد.', 'hedayati-core' ), 'error' );
		}

		Hedayati_Run_Staff_Service::remove( $assignment_id );
		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'عامل دوره حذف شد.', 'hedayati-core' ) );
	}

	public static function handle_session_save(): void {
		self::verify( 'hedayati_session_save', 'hedayati_manage_course_runs' );

		$run_id     = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$session_id = isset( $_POST['session_id'] ) ? absint( wp_unslash( $_POST['session_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$payload = [ 'run_id' => $run_id ];
		foreach ( [ 'session_number', 'starts_at', 'ends_at', 'topic', 'status' ] as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$payload[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}

		if ( $session_id > 0 ) {
			$existing = Hedayati_Session_Service::get( $session_id );
			if ( null === $existing || $existing['run_id'] !== $run_id ) {
				self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'جلسه یافت نشد.', 'hedayati-core' ), 'error' );
			}
			$result = Hedayati_Session_Service::update( $session_id, $payload );
		} else {
			$result = Hedayati_Session_Service::create( $payload );
		}

		if ( is_wp_error( $result ) ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], $result->get_error_message(), 'error' );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'جلسه ذخیره شد.', 'hedayati-core' ) );
	}

	public static function handle_session_delete(): void {
		self::verify( 'hedayati_session_delete', 'hedayati_manage_course_runs' );

		$run_id     = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$session_id = isset( $_POST['session_id'] ) ? absint( wp_unslash( $_POST['session_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$existing = Hedayati_Session_Service::get( $session_id );
		if ( null !== $existing && $existing['run_id'] === $run_id ) {
			Hedayati_Session_Service::delete_session( $session_id );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'جلسه حذف شد.', 'hedayati-core' ) );
	}

	public static function handle_enroll_add(): void {
		self::verify( 'hedayati_enroll_add', 'hedayati_create_enrollments' );

		$run_id  = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$result = Hedayati_Enrollment_Service::enroll( $run_id, $user_id );

		if ( is_wp_error( $result ) ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], $result->get_error_message(), 'error' );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'دانشجو ثبت‌نام شد.', 'hedayati-core' ) );
	}

	public static function handle_enroll_status(): void {
		self::verify( 'hedayati_enroll_status', 'hedayati_manage_enrollments' );

		$run_id        = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( wp_unslash( $_POST['enrollment_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$enrollment = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null === $enrollment || $enrollment['run_id'] !== $run_id ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'ثبت‌نام یافت نشد.', 'hedayati-core' ), 'error' );
		}

		$result = Hedayati_Enrollment_Service::set_status(
			$enrollment_id,
			isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : ''
		);

		if ( is_wp_error( $result ) ) {
			self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], $result->get_error_message(), 'error' );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'وضعیت ثبت‌نام به‌روزرسانی شد.', 'hedayati-core' ) );
	}

	public static function handle_enroll_remove(): void {
		self::verify( 'hedayati_enroll_remove', 'hedayati_manage_enrollments' );

		$run_id        = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( wp_unslash( $_POST['enrollment_id'] ) ) : 0;
		self::require_run_scope( $run_id );

		$enrollment = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null !== $enrollment && $enrollment['run_id'] === $run_id ) {
			Hedayati_Enrollment_Service::delete_enrollment( $enrollment_id );
		}

		self::redirect( [ 'view' => 'run', 'run_id' => $run_id ], __( 'ثبت‌نام حذف شد.', 'hedayati-core' ) );
	}

	public static function handle_attendance_save(): void {
		self::verify( 'hedayati_attendance_save', 'hedayati_record_attendance' );

		$session_id = isset( $_POST['session_id'] ) ? absint( wp_unslash( $_POST['session_id'] ) ) : 0;
		$session    = Hedayati_Session_Service::get( $session_id );

		if ( null === $session ) {
			self::redirect( [], __( 'جلسه یافت نشد.', 'hedayati-core' ), 'error' );
		}

		self::require_run_scope( $session['run_id'] );

		$raw_marks = isset( $_POST['mark'] ) && is_array( $_POST['mark'] ) ? wp_unslash( $_POST['mark'] ) : [];
		$raw_notes = isset( $_POST['note'] ) && is_array( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : [];

		$recorded = 0;
		$errors   = 0;

		foreach ( $raw_marks as $enrollment_id => $status ) {
			if ( is_array( $status ) ) {
				continue;
			}

			$enrollment_id = absint( $enrollment_id );
			$status        = sanitize_text_field( (string) $status );

			if ( '' === $status ) {
				continue; // "not recorded" — skip, do not wipe an existing mark implicitly
			}

			$raw_note = $raw_notes[ $enrollment_id ] ?? '';
			$note     = is_array( $raw_note ) ? '' : sanitize_text_field( (string) $raw_note );
			$result = Hedayati_Attendance_Service::record( $session_id, $enrollment_id, $status, [
				'note'        => $note,
				'recorded_by' => get_current_user_id(),
			] );

			is_wp_error( $result ) ? $errors++ : $recorded++;
		}

		self::redirect(
			[ 'view' => 'attendance', 'session_id' => $session_id ],
			sprintf( __( '%d مورد ثبت شد، %d خطا.', 'hedayati-core' ), $recorded, $errors ),
			$errors > 0 ? 'error' : 'success'
		);
	}

	// ── Notices ─────────────────────────────────────────────────────────────

	private static function notice_key(): string {
		return 'hedayati_academic_notice_' . get_current_user_id();
	}

	public static function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return;
		}

		delete_transient( self::notice_key() );

		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['text'] )
		);
	}

	// ── Small render helpers ────────────────────────────────────────────────

	private static function text_row( string $name, string $label, string $value, string $dir = 'rtl' ): void {
		printf(
			'<tr><th scope="row"><label for="hd_%1$s">%2$s</label></th><td><input type="text" name="%1$s" id="hd_%1$s" value="%3$s" class="regular-text" dir="%4$s"></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $dir )
		);
	}

	/**
	 * Flat <select> of published/draft posts of a type, ID as value. Predictable
	 * for non-hierarchical CPTs (unlike wp_dropdown_pages).
	 */
	private static function post_select( string $name, string $post_type, int $selected, string $none_label ): void {
		$posts = get_posts( [
			'post_type'        => $post_type,
			'post_status'      => [ 'publish', 'draft', 'pending', 'private' ],
			'numberposts'      => 500,
			'orderby'          => 'title',
			'order'            => 'ASC',
			'suppress_filters' => false,
		] );

		echo '<select name="' . esc_attr( $name ) . '" id="hd_' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html( $none_label ) . '</option>';

		foreach ( $posts as $p ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $p->ID,
				selected( $selected, $p->ID, false ),
				esc_html( $p->post_title !== '' ? $p->post_title : sprintf( '#%d', $p->ID ) )
			);
		}

		echo '</select>';
	}

	private static function select_row( string $name, string $label, array $choices, string $selected ): void {
		echo '<tr><th scope="row"><label for="hd_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select name="' . esc_attr( $name ) . '" id="hd_' . esc_attr( $name ) . '">';
		foreach ( $choices as $val => $text ) {
			echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( $selected, $val, false ) . '>' . esc_html( (string) $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	// ── Label maps (Persian) ────────────────────────────────────────────────

	private static function run_status_choices(): array {
		return [
			'draft'       => __( 'پیش‌نویس', 'hedayati-core' ),
			'scheduled'   => __( 'زمان‌بندی‌شده', 'hedayati-core' ),
			'in_progress' => __( 'در حال برگزاری', 'hedayati-core' ),
			'completed'   => __( 'پایان‌یافته', 'hedayati-core' ),
			'cancelled'   => __( 'لغوشده', 'hedayati-core' ),
		];
	}

	private static function registration_status_choices(): array {
		return [
			'closed' => __( 'بسته', 'hedayati-core' ),
			'open'   => __( 'باز', 'hedayati-core' ),
			'soon'   => __( 'به‌زودی', 'hedayati-core' ),
		];
	}

	private static function session_status_choices(): array {
		return [
			'scheduled' => __( 'زمان‌بندی‌شده', 'hedayati-core' ),
			'held'      => __( 'برگزارشده', 'hedayati-core' ),
			'cancelled' => __( 'لغوشده', 'hedayati-core' ),
		];
	}

	private static function enrollment_status_choices(): array {
		return [
			'active'    => __( 'فعال', 'hedayati-core' ),
			'withdrawn' => __( 'انصراف', 'hedayati-core' ),
			'completed' => __( 'اتمام', 'hedayati-core' ),
			'cancelled' => __( 'لغوشده', 'hedayati-core' ),
		];
	}

	private static function attendance_status_choices(): array {
		return [
			'present' => __( 'حاضر', 'hedayati-core' ),
			'absent'  => __( 'غایب', 'hedayati-core' ),
			'late'    => __( 'با تأخیر', 'hedayati-core' ),
			'excused' => __( 'غیبت موجه', 'hedayati-core' ),
		];
	}

	private static function staff_role_label( string $role ): string {
		return [
			'primary_instructor'    => __( 'مدرس اصلی', 'hedayati-core' ),
			'additional_instructor' => __( 'مدرس همکار', 'hedayati-core' ),
			'assistant'             => __( 'استادیار', 'hedayati-core' ),
		][ $role ] ?? $role;
	}

	private static function run_status_label( string $s ): string {
		return self::run_status_choices()[ $s ] ?? $s;
	}

	private static function registration_status_label( string $s ): string {
		return self::registration_status_choices()[ $s ] ?? $s;
	}

	private static function session_status_label( string $s ): string {
		return self::session_status_choices()[ $s ] ?? $s;
	}

	private static function enrollment_status_label( string $s ): string {
		return self::enrollment_status_choices()[ $s ] ?? $s;
	}
}
