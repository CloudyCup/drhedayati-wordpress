<?php
/** Synthetic local HTTP fixtures. Never invoke on staging or production. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() || ! in_array( wp_parse_url( home_url(), PHP_URL_HOST ), [ '127.0.0.1', 'localhost' ], true ) ) { throw new RuntimeException( 'Localhost fixture only' ); }
require_once ABSPATH . 'wp-admin/includes/user.php';
$ids = [];
foreach ( [ 'student', 'student_b', 'teacher', 'teacher_assistant', 'reception', 'hedayati_manager' ] as $role ) {
	$login = 'hdit_browser_' . $role;
	$existing = get_user_by( 'login', $login );
	$data = [ 'user_login' => $login, 'user_pass' => 'Local-QA-Only-9x24', 'user_email' => $login . '@example.test', 'role' => 'student_b' === $role ? 'student' : $role, 'display_name' => 'آزمایش ' . $role ];
	if ( $existing ) { $data['ID'] = $existing->ID; }
	$id = wp_insert_user( $data );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$ids[$role] = $id;
}
Hedayati_Roles::register_roles();
Hedayati_Staff_Portal::ensure_page();
wp_set_current_user( $ids['hedayati_manager'] );
$course = wp_insert_post( [ 'post_type' => 'course', 'post_title' => 'دوره آزمایشی شبکه', 'post_name' => 'local-qa-network', 'post_status' => 'publish', 'post_content' => 'محتوای آزمایشی برای بررسی رابط کاربری؛ این اطلاعات متعلق به دوره واقعی نیست.' ] );
update_post_meta( $course, '_hdit_synthetic', 1 );
update_post_meta( $course, '_course_is_featured', '1' );
$teacher = wp_insert_post( [ 'post_type' => 'teacher', 'post_title' => 'مدرس آزمایشی', 'post_content' => 'معرفی آزمایشی برای بررسی صفحه مدرسان.', 'post_status' => 'publish' ] );
update_post_meta( $teacher, '_hdit_synthetic', 1 );
update_post_meta( $teacher, Hedayati_Teacher::META_USER_ID, $ids['teacher'] );
update_post_meta( $teacher, '_hedayati_public_teacher', '1' );
$run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'scheduled', 'registration_status' => 'open', 'start_date' => '2026-10-01', 'tuition_rial' => 12000000, 'capacity' => 20 ] );
$other_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'scheduled' ] );
Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'teacher_id' => $teacher, 'staff_role' => 'primary_instructor' ] );
Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'user_id' => $ids['teacher_assistant'], 'staff_role' => 'assistant' ] );
$enrollment = Hedayati_Enrollment_Service::enroll( $run, $ids['student'] );
$other_enrollment = Hedayati_Enrollment_Service::enroll( $other_run, $ids['student_b'] );
$session = Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => 1, 'starts_at' => '2026-10-01 10:00:00', 'topic' => 'مبانی شبکه' ] );
update_post_meta( $course, '_hedayati_public_run_ids', [ $run ] );
$output = [ 'users' => $ids, 'course' => $course, 'course_url' => get_permalink( $course ), 'run' => $run, 'other_run' => $other_run, 'enrollment' => $enrollment, 'other_enrollment' => $other_enrollment, 'session' => $session ];
echo wp_json_encode( $output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
