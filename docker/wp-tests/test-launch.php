<?php
/** Runtime regression coverage for launch permissions, scope and public opt-in. */
if ( ! defined( 'ABSPATH' ) ) { exit( 2 ); }
function hdit_run_launch(): void {
	HDIT::section( 'Launch — manager permissions and staff privacy' );
	$users = [];
	foreach ( [ 'student', 'teacher', 'teacher_assistant', 'reception', 'hedayati_manager', 'administrator' ] as $role ) { $users[$role] = HDIT_Env::make_user( 'launch_' . $role, $role ); }
	$course = HDIT_Env::make_course( 'Launch course' );
	foreach ( $users as $role => $uid ) {
		wp_set_current_user( $uid );
		$manager = in_array( $role, [ 'hedayati_manager', 'administrator' ], true );
		foreach ( [ 'publish', 'private', 'draft' ] as $status ) {
			wp_update_post( [ 'ID' => $course, 'post_status' => $status ] );
			HDIT::eq( "$role can edit $status course only if manager", $manager, current_user_can( 'edit_post', $course ) );
			HDIT::eq( "$role can delete $status course only if manager", $manager, current_user_can( 'delete_post', $course ) );
		}
		HDIT::eq( "$role category capability", $manager, current_user_can( get_taxonomy( 'course-category' )->cap->manage_terms ) );
		HDIT::eq( "$role institute settings save capability", $manager, current_user_can( apply_filters( 'option_page_capability_hedayati_institute', 'manage_options' ) ) );
		HDIT::eq( "$role creates students only in intake roles", in_array( $role, [ 'reception', 'hedayati_manager', 'administrator' ], true ), current_user_can( 'hedayati_create_students' ) );
		if ( 'administrator' !== $role ) { HDIT::eq( "$role has no technical settings power", false, current_user_can( 'manage_options' ) ); }
	}
	wp_set_current_user( $users['administrator'] );
	wp_update_post( [ 'ID' => $course, 'post_status' => 'publish' ] );
	$teacher = HDIT_Env::make_teacher( 'Launch public instructor', $users['teacher'] );
	$run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'scheduled', 'registration_status' => 'open', 'start_date' => '2026-10-01', 'tuition_rial' => 123456 ] );
	$other = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'scheduled' ] );
	Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'teacher_id' => $teacher, 'staff_role' => 'primary_instructor' ] );
	Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'user_id' => $users['teacher_assistant'], 'staff_role' => 'assistant' ] );
	foreach ( [ 'teacher', 'teacher_assistant' ] as $role ) {
		wp_set_current_user( $users[$role] );
		HDIT::eq( "$role sees assigned run", true, Hedayati_Staff_Portal::can_run( $run, 'hedayati_view_assigned_roster' ) );
		HDIT::eq( "$role cannot see unassigned run", false, Hedayati_Staff_Portal::can_run( $other, 'hedayati_view_assigned_roster' ) );
		HDIT::eq( "$role attendance permission", 'teacher' === $role, Hedayati_Staff_Portal::can_run( $run, 'hedayati_record_attendance' ) );
	}
	wp_set_current_user( 0 );
	HDIT::eq( 'operational runs are not public by default', [], Hedayati_Public_Content::runs( $course ) );
	HDIT::eq( 'teacher unapproved by default', false, in_array( $teacher, array_column( Hedayati_Public_Content::teachers(), 'id' ), true ) );
	update_post_meta( $teacher, '_hedayati_public_teacher', '1' );
	HDIT::eq( 'approved published teacher appears', true, in_array( $teacher, array_column( Hedayati_Public_Content::teachers(), 'id' ), true ) );
	wp_update_post( [ 'ID' => $teacher, 'post_status' => 'private' ] );
	HDIT::eq( 'private teacher never appears even with approval', false, in_array( $teacher, array_column( Hedayati_Public_Content::teachers(), 'id' ), true ) );
	update_post_meta( $course, '_hedayati_public_run_ids', [ $run ] );
	$public = Hedayati_Public_Content::runs( $course );
	HDIT::eq( 'approved class appears', 1, count( $public ) );
	HDIT::eq( 'public projection contains only permitted fields', [ 'start_date', 'tuition_rial', 'registration_status' ], array_keys( $public[0] ) );
	Hedayati_Course_Run_Service::update( $run, [ 'run_status' => 'cancelled' ] );
	HDIT::eq( 'cancelled class no longer public', [], Hedayati_Public_Content::runs( $course ) );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	HDIT_AdminPost::run( $users['student'], [], static fn() => Hedayati_Staff_Portal::handle_student() );
	HDIT::eq( 'student cannot create accounts', 403, HDIT_AdminPost::$result['status'] );
	HDIT_AdminPost::run( $users['reception'], [], static fn() => Hedayati_Staff_Portal::handle_student() );
	HDIT::eq( 'reception account creation requires nonce', 403, HDIT_AdminPost::$result['status'] );
	wp_set_current_user( 0 );
}
