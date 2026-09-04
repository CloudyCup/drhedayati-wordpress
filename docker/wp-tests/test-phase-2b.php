<?php
/**
 * Phase 2B — Academic Operations — WordPress-runtime integration checks.
 *
 * Automates the docs/PHASE_2B_ACCEPTANCE.md staging matrix (sections C–K/J) that
 * the static suites explicitly cannot prove: real INSERT/UPDATE/DELETE, live
 * UNIQUE enforcement, capability mapping, REST exposure, cascade deletes,
 * capacity/closed-run guards, cross-run (IDOR) protection, Shamsi persistence,
 * and audit-log append-only behaviour.
 *
 * Exercised through the public service APIs and real WordPress behaviour.
 *
 * @package Hedayati_Core\LocalTest
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

function hdit_run_phase_2b(): void {
	global $wpdb;

	// ── C. Teacher CPT authorization ───────────────────────────────────────
	HDIT::section( 'Phase 2B — Teacher CPT authorization (T1, 1.5.2 meta-cap fix)' );

	$mgr   = HDIT_Env::make_user( 'mgr', 'hedayati_manager' );
	$rcpt  = HDIT_Env::make_user( 'rcpt', 'reception' );
	$tchr  = HDIT_Env::make_user( 'tchr', 'teacher' );
	$ta    = HDIT_Env::make_user( 'ta', 'teacher_assistant' );
	$stu   = HDIT_Env::make_user( 'stu', 'student' );
	$probe = HDIT_Env::make_teacher( 'Auth probe teacher' );

	$cap = get_post_type_object( Hedayati_Teacher::POST_TYPE )->cap;
	HDIT::ok( "meta cap edit_post != the bare primitive (was the 1.5.1 collision)", 'hedayati_manage_teachers' !== $cap->edit_post );
	HDIT::eq( 'collection cap edit_posts requires hedayati_manage_teachers', 'hedayati_manage_teachers', $cap->edit_posts );

	wp_set_current_user( $mgr );
	HDIT::ok( 'manager: current_user_can("hedayati_manage_teachers") [bare, no object]', current_user_can( 'hedayati_manage_teachers' ) );
	HDIT::ok( 'manager: current_user_can("edit_post", <teacher>) [meta cap maps down]', current_user_can( 'edit_post', $probe ) );
	HDIT::ok( 'manager: current_user_can(edit_posts collection cap)', current_user_can( $cap->edit_posts ) );

	wp_set_current_user( 1 );
	HDIT::ok( 'administrator: current_user_can("hedayati_manage_teachers")', current_user_can( 'hedayati_manage_teachers' ) );
	HDIT::ok( 'administrator: current_user_can("edit_post", <teacher>)', current_user_can( 'edit_post', $probe ) );

	foreach ( [ 'reception' => $rcpt, 'teacher' => $tchr, 'teacher_assistant' => $ta, 'student' => $stu ] as $label => $uid ) {
		wp_set_current_user( $uid );
		HDIT::ok( "{$label}: CANNOT hedayati_manage_teachers", ! current_user_can( 'hedayati_manage_teachers' ) );
		HDIT::ok( "{$label}: CANNOT edit_post on a teacher profile", ! current_user_can( 'edit_post', $probe ) );
	}
	wp_set_current_user( 0 );

	// ── T5. Teacher REST route privacy ────────────────────────────────────
	HDIT::section( 'Phase 2B — Teacher REST route privacy (T4/T5, D30/D34)' );

	$server = rest_get_server();
	$routes = $server->get_routes();
	HDIT::ok( 'no /wp/v2/hedayati_teacher route is registered', ! isset( $routes['/wp/v2/hedayati_teacher'] ) );
	HDIT::ok( 'no /wp/v2/teacher route is registered', ! isset( $routes['/wp/v2/teacher'] ) );

	foreach ( [ '/wp/v2/hedayati_teacher', '/wp/v2/teacher' ] as $route ) {
		$status = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
		HDIT::eq( "GET {$route} -> 404", 404, $status );
	}
	$types = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types' ) )->get_data();
	HDIT::ok( 'teacher CPT is absent from /wp/v2/types', ! isset( $types['teacher'] ) && ! isset( $types['hedayati_teacher'] ) );
	HDIT::ok( 'teacher CPT is not publicly_queryable', false === get_post_type_object( Hedayati_Teacher::POST_TYPE )->publicly_queryable );

	// ── T2/T3. Teacher <-> user 1:1 link + unlink on delete ──────────────
	HDIT::section( 'Phase 2B — Teacher <-> user link (1:1) and unlink-on-delete' );

	$link_user = HDIT_Env::make_user( 'linkme', 'teacher' );
	$t_a       = HDIT_Env::make_teacher( 'Linked profile A', $link_user );
	HDIT::eq( 'find_by_user_id resolves to profile A', $t_a, Hedayati_Teacher::find_by_user_id( $link_user ) );
	HDIT::ok( 'Teacher::exists(A) is true', Hedayati_Teacher::exists( $t_a ) );

	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $link_user );
	HDIT::ok( 'profile A survives deletion of the linked WP user', Hedayati_Teacher::exists( $t_a ) );
	HDIT::eq( 'profile A user link is cleared to 0', 0, (int) get_post_meta( $t_a, Hedayati_Teacher::META_USER_ID, true ) );

	// ── D. Course Runs ──────────────────────────────────────────────────
	HDIT::section( 'Phase 2B — Course Run creation & validation' );

	$course = HDIT_Env::make_course( 'CCNA (synthetic)' );
	HDIT::is_wp_error( 'create run with an invalid course_id refused', Hedayati_Course_Run_Service::create( [ 'course_id' => 999999 ] ), 'invalid_course' );

	$run = Hedayati_Course_Run_Service::create(
		[
			'course_id'           => $course,
			'label'               => 'Spring cohort',
			'run_status'          => 'totally-not-valid',
			'registration_status' => 'open',
			'capacity'            => "\u{06F2}\u{06F5}", // Persian "25"
			'tuition_rial'        => '250000000',
			'start_date'          => '1405/01/15',        // Shamsi
		]
	);
	HDIT::ok( 'create() returns a positive int run id', is_int( $run ) && $run > 0 );
	$row = Hedayati_Course_Run_Service::get( $run );
	HDIT::eq( 'invalid run_status fell back to "draft"', 'draft', $row['run_status'] );
	HDIT::eq( 'registration_status stored as "open"', 'open', $row['registration_status'] );
	HDIT::eq( 'Persian-digit capacity normalised to int 25', 25, $row['capacity'] );
	HDIT::eq( 'Shamsi start_date 1405/01/15 persisted as Gregorian 2026-04-04', '2026-04-04', $row['start_date'] );

	HDIT::is_wp_error( 'negative capacity refused', Hedayati_Course_Run_Service::update( $run, [ 'capacity' => '-3' ] ), 'invalid_capacity' );
	HDIT::is_wp_error( 'non-numeric capacity refused', Hedayati_Course_Run_Service::update( $run, [ 'capacity' => '20 seats' ] ), 'invalid_capacity' );
	HDIT::is_wp_error( 'end date before start date refused', Hedayati_Course_Run_Service::update( $run, [ 'start_date' => '2026-05-01', 'end_date' => '2026-04-01' ] ), 'date_range' );
	HDIT::not_wp_error( 'empty capacity clears the value', Hedayati_Course_Run_Service::update( $run, [ 'capacity' => '' ] ) );
	HDIT::eq( 'capacity is now NULL ("unknown", never a fabricated 0)', null, Hedayati_Course_Run_Service::get( $run )['capacity'] );

	// ── E. Sessions ─────────────────────────────────────────────────────
	HDIT::section( 'Phase 2B — Sessions: uniqueness & time validation' );

	$s1 = Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '1', 'starts_at' => '2026-04-05 09:00' ] );
	HDIT::ok( 'session #1 created', is_int( $s1 ) && $s1 > 0 );
	HDIT::eq( 'starts_at canonicalised to Y-m-d H:i:s', '2026-04-05 09:00:00', Hedayati_Session_Service::get( $s1 )['starts_at'] );
	HDIT::is_wp_error( 'duplicate session_number refused by the service', Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '1', 'starts_at' => '2026-04-06 09:00' ] ), 'session_number_exists' );
	HDIT::is_wp_error( 'session_number 0 refused', Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '0', 'starts_at' => '2026-04-06 09:00' ] ), 'invalid_session_number' );
	HDIT::is_wp_error( 'ends_at <= starts_at refused', Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '2', 'starts_at' => '2026-04-06 09:00', 'ends_at' => '2026-04-06 08:00' ] ), 'time_range' );

	$now = current_time( 'mysql', true );
	$wpdb->hide_errors();
	$dup = $wpdb->insert(
		Hedayati_DB_Schema::get_table_sessions(),
		[ 'run_id' => $run, 'session_number' => 1, 'starts_at' => $now, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now ],
		[ '%d', '%d', '%s', '%s', '%s', '%s' ]
	);
	$wpdb->show_errors();
	HDIT::eq( 'direct duplicate (run_id, session_number) rejected by uq_run_session', false, $dup );

	// ── F. Staff assignment ─────────────────────────────────────────────
	HDIT::section( 'Phase 2B — Staff assignment rules' );

	$instr_user = HDIT_Env::make_user( 'instr', 'teacher' );
	$instructor = HDIT_Env::make_teacher( 'Primary instructor', $instr_user );
	$ta_user    = HDIT_Env::make_user( 'ta_staff', 'teacher_assistant' );

	HDIT::is_wp_error( 'primary_instructor without a Teacher profile refused', Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'primary_instructor', 'teacher_id' => 0 ] ), 'instructor_needs_profile' );
	$a1 = Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'primary_instructor', 'teacher_id' => $instructor ] );
	HDIT::ok( 'primary_instructor assigned', is_int( $a1 ) && $a1 > 0 );
	HDIT::is_wp_error( 'a second primary_instructor on the run refused', Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'primary_instructor', 'teacher_id' => $instructor ] ), 'primary_instructor_exists' );
	HDIT::is_wp_error( 'assistant without a WP user refused', Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'assistant', 'user_id' => 0 ] ), 'assistant_needs_user' );
	$a2 = Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'assistant', 'user_id' => $ta_user ] );
	HDIT::ok( 'assistant assigned', is_int( $a2 ) && $a2 > 0 );
	HDIT::is_wp_error( 'duplicate assistant (same user, role, run) refused', Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'assistant', 'user_id' => $ta_user ] ), 'assignment_exists' );

	HDIT::ok( 'user_is_staff_on_run() true for an instructor via their profile', Hedayati_Run_Staff_Service::user_is_staff_on_run( $instr_user, $run ) );
	HDIT::ok( 'user_is_staff_on_run() true for a TA user', Hedayati_Run_Staff_Service::user_is_staff_on_run( $ta_user, $run ) );
	HDIT::ok( 'user_is_staff_on_run() false for an unrelated user (per-run scope)', ! Hedayati_Run_Staff_Service::user_is_staff_on_run( $stu, $run ) );

	wp_delete_post( $instructor, true );
	HDIT::eq( 'deleting the Teacher profile purges its instructor assignment', null, Hedayati_Run_Staff_Service::get( $a1 ) );
	wp_delete_user( $ta_user );
	HDIT::eq( 'deleting the WP user purges their assistant assignment', null, Hedayati_Run_Staff_Service::get( $a2 ) );

	// ── G. Enrollments ─────────────────────────────────────────────────
	HDIT::section( 'Phase 2B — Enrollment: duplicate, capacity & closed-run guards' );

	$cap_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'capacity' => '1', 'run_status' => 'scheduled' ] );
	$st1     = HDIT_Env::make_user( 'enr1', 'student' );
	$st2     = HDIT_Env::make_user( 'enr2', 'student' );

	$e1 = Hedayati_Enrollment_Service::enroll( $cap_run, $st1 );
	HDIT::ok( 'first enrollment succeeds', is_int( $e1 ) && $e1 > 0 );
	HDIT::is_wp_error( 'duplicate (run, student) refused by the service', Hedayati_Enrollment_Service::enroll( $cap_run, $st1 ), 'already_enrolled' );
	HDIT::is_wp_error( 'capacity full -> run_full', Hedayati_Enrollment_Service::enroll( $cap_run, $st2 ), 'run_full' );
	$e2 = Hedayati_Enrollment_Service::enroll( $cap_run, $st2, true );
	HDIT::ok( '$allow_overfill bypasses capacity', is_int( $e2 ) && $e2 > 0 );

	$now = current_time( 'mysql', true );
	$wpdb->hide_errors();
	$dup = $wpdb->insert(
		Hedayati_DB_Schema::get_table_enrollments(),
		[ 'run_id' => $cap_run, 'user_id' => $st1, 'status' => 'active', 'enrolled_at' => $now, 'created_at' => $now, 'updated_at' => $now ],
		[ '%d', '%d', '%s', '%s', '%s', '%s' ]
	);
	$wpdb->show_errors();
	HDIT::eq( 'direct duplicate (run_id, user_id) rejected by uq_run_user', false, $dup );

	$closed = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'completed' ] );
	HDIT::is_wp_error( 'enrolling into a completed run refused', Hedayati_Enrollment_Service::enroll( $closed, $st1 ), 'run_closed' );

	HDIT::not_wp_error( 'status transition active -> withdrawn', Hedayati_Enrollment_Service::set_status( $e1, 'withdrawn' ) );
	HDIT::is_wp_error( 'invalid enrollment status refused', Hedayati_Enrollment_Service::set_status( $e1, 'banished' ), 'invalid_status' );
	HDIT::eq( 'withdrawn no longer counts toward the active total', 1, Hedayati_Enrollment_Service::count_active( $cap_run ) );

	// ── H. Attendance ─────────────────────────────────────────────────
	HDIT::section( 'Phase 2B — Attendance: upsert, cross-run IDOR guard, bulk' );

	$att_run     = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'in_progress' ] );
	$att_session = Hedayati_Session_Service::create( [ 'run_id' => $att_run, 'session_number' => '1', 'starts_at' => '2026-04-10 09:00' ] );
	$att_student = HDIT_Env::make_user( 'att_stu', 'student' );
	$att_enr     = Hedayati_Enrollment_Service::enroll( $att_run, $att_student );
	$recorder    = HDIT_Env::make_user( 'recorder', 'teacher' );

	$m1 = Hedayati_Attendance_Service::record( $att_session, $att_enr, 'present', [ 'recorded_by' => $recorder ] );
	HDIT::ok( 'attendance recorded', is_int( $m1 ) && $m1 > 0 );
	HDIT::is_wp_error( 'invalid attendance status refused', Hedayati_Attendance_Service::record( $att_session, $att_enr, 'maybe' ), 'invalid_attendance_status' );

	$m2 = Hedayati_Attendance_Service::record( $att_session, $att_enr, 'late', [ 'recorded_by' => $recorder ] );
	HDIT::eq( 'a second record() UPDATES the same row (upsert)', $m1, $m2 );
	HDIT::eq( 'status is now "late"', 'late', Hedayati_Attendance_Service::get( $m1 )['status'] );
	HDIT::eq( 'still exactly one attendance row for (session, enrollment)', 1, count( Hedayati_Attendance_Service::list_for_session( $att_session ) ) );

	$other_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'in_progress' ] );
	$other_enr = Hedayati_Enrollment_Service::enroll( $other_run, $att_student );
	HDIT::is_wp_error(
		'recording attendance for an enrollment in a DIFFERENT run refused (IDOR guard)',
		Hedayati_Attendance_Service::record( $att_session, $other_enr, 'present' ),
		'run_mismatch'
	);

	wp_delete_user( $recorder );
	$mark = Hedayati_Attendance_Service::get( $m1 );
	HDIT::ok( 'attendance row survives deletion of the recording user', null !== $mark );
	HDIT::eq( 'recorded_by is nulled after that user is deleted', null, $mark['recorded_by'] );

	$bulk = Hedayati_Attendance_Service::record_bulk( $att_session, [ $att_enr => 'absent', $other_enr => 'present', 999999 => 'present' ] );
	HDIT::eq( 'bulk record: 1 valid mark applied', 1, $bulk['recorded'] );
	HDIT::eq( 'bulk record: 2 per-row errors reported without aborting', 2, count( $bulk['errors'] ) );

	// ── K. Shamsi input -> canonical Gregorian storage ───────────────
	HDIT::section( 'Phase 2B — Shamsi input conversion & canonical persistence (D9)' );

	HDIT::eq( 'Jalali parse 1405/01/01 -> 2026-03-21', '2026-03-21', Hedayati_Jalali::parse_input( '1405/01/01' ) );
	HDIT::eq( 'Jalali parse Persian-digit form -> 2026-03-21', '2026-03-21', Hedayati_Jalali::parse_input( "\u{06F1}\u{06F4}\u{06F0}\u{06F5}/\u{06F0}\u{06F1}/\u{06F0}\u{06F1}" ) );
	HDIT::eq( 'Nowruz boundary 2026-03-20 formats as 1404/12/29', "\u{06F1}\u{06F4}\u{06F0}\u{06F4}/\u{06F1}\u{06F2}/\u{06F2}\u{06F9}", Hedayati_Jalali::format( '2026-03-20', true ) );

	$shamsi_run = Hedayati_Course_Run_Service::create(
		[ 'course_id' => $course, 'start_date' => "\u{06F1}\u{06F4}\u{06F0}\u{06F5}/\u{06F0}\u{06F1}/\u{06F0}\u{06F1}", 'end_date' => '1405/03/31' ]
	);
	$stored = $wpdb->get_row(
		$wpdb->prepare( 'SELECT start_date, end_date FROM ' . Hedayati_DB_Schema::get_table_course_runs() . ' WHERE id = %d', $shamsi_run ),
		ARRAY_A
	);
	HDIT::eq( 'DB start_date is ASCII Gregorian ISO', '2026-03-21', $stored['start_date'] );
	HDIT::ok( 'DB end_date is a plain YYYY-MM-DD string (ASCII digits)', (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored['end_date'] ) );

	// ── J. Audit log: creation, failure-silence, append-only, no PII ──
	HDIT::section( 'Phase 2B — Audit log: creation, failure-silence, append-only, no PII' );

	HDIT_Env::reset();
	$ac_course = HDIT_Env::make_course( 'Audit course' );

	$base   = Hedayati_Audit_Log::count();
	$ac_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $ac_course ] );
	HDIT::eq( 'a successful run create writes exactly one audit row', $base + 1, Hedayati_Audit_Log::count() );
	HDIT::eq( 'that row is action=course_run.created for the new run', 1, Hedayati_Audit_Log::count( [ 'action' => 'course_run.created', 'object_id' => $ac_run ] ) );

	$after_ok = Hedayati_Audit_Log::count();
	Hedayati_Course_Run_Service::create( [ 'course_id' => 999999 ] );
	Hedayati_Enrollment_Service::enroll( $ac_run, 999999 );
	Hedayati_Session_Service::create( [ 'run_id' => $ac_run, 'session_number' => '0', 'starts_at' => 'nonsense' ] );
	HDIT::eq( 'FAILED mutations write NO audit rows', $after_ok, Hedayati_Audit_Log::count() );

	$methods = array_map( 'strtolower', get_class_methods( 'Hedayati_Audit_Log' ) );
	HDIT::ok( 'audit log exposes no update* method (append-only API)', [] === array_filter( $methods, static fn( $m ) => str_starts_with( $m, 'update' ) ) );
	HDIT::ok( 'audit log exposes no delete* method (append-only API)', [] === array_filter( $methods, static fn( $m ) => str_starts_with( $m, 'delete' ) ) );

	$ac_student = HDIT_Env::make_user( 'audit_stu', 'student' );
	Hedayati_User_Phone_Service::assign_phone( $ac_student, '09124445566' );
	Hedayati_Enrollment_Service::enroll( $ac_run, $ac_student );
	$notes = $wpdb->get_col( 'SELECT note FROM ' . Hedayati_DB_Schema::get_table_audit_log() );
	$leak  = false;
	foreach ( $notes as $n ) {
		if ( str_contains( (string) $n, '9124445566' ) || str_contains( (string) $n, 'audit_stu@' ) ) {
			$leak = true;
		}
	}
	HDIT::ok( 'no audit note contains a phone number or email address', ! $leak );

	// ── D6/G5/G6/J3. Deletion & cascade ─────────────────────────────
	HDIT::section( 'Phase 2B — Deletion & cascade behaviour' );

	HDIT_Env::reset();
	$cx_course  = HDIT_Env::make_course( 'Cascade course' );
	$cx_run     = Hedayati_Course_Run_Service::create( [ 'course_id' => $cx_course, 'run_status' => 'in_progress' ] );
	$cx_session = Hedayati_Session_Service::create( [ 'run_id' => $cx_run, 'session_number' => '1', 'starts_at' => '2026-04-20 09:00' ] );
	$cx_teacher = HDIT_Env::make_teacher( 'Cascade instructor' );
	Hedayati_Run_Staff_Service::assign( [ 'run_id' => $cx_run, 'staff_role' => 'primary_instructor', 'teacher_id' => $cx_teacher ] );
	$cx_student = HDIT_Env::make_user( 'cascade_stu', 'student' );
	$cx_enr     = Hedayati_Enrollment_Service::enroll( $cx_run, $cx_student );
	Hedayati_Attendance_Service::record( $cx_session, $cx_enr, 'present' );

	$audit_before   = Hedayati_Audit_Log::count();
	$enr_created_rows = Hedayati_Audit_Log::count( [ 'action' => 'enrollment.created' ] );

	HDIT::ok( 'delete_run() returns true', Hedayati_Course_Run_Service::delete_run( $cx_run ) );
	HDIT::eq( 'run row is gone', null, Hedayati_Course_Run_Service::get( $cx_run ) );
	HDIT::eq( 'sessions cascade-deleted', [], Hedayati_Session_Service::list_for_run( $cx_run ) );
	HDIT::eq( 'enrollment cascade-deleted', null, Hedayati_Enrollment_Service::get( $cx_enr ) );
	HDIT::eq( 'staff assignments cascade-deleted', [], Hedayati_Run_Staff_Service::list_for_run( $cx_run ) );
	HDIT::eq(
		'attendance rows cascade-deleted',
		'0',
		$wpdb->get_var( 'SELECT COUNT(*) FROM ' . Hedayati_DB_Schema::get_table_attendance() )
	);
	HDIT::ok( 'audit history is NOT cascaded — total grew (a delete row was appended)', Hedayati_Audit_Log::count() > $audit_before );
	HDIT::eq( 'the prior enrollment.created audit row is still present (D31)', $enr_created_rows, Hedayati_Audit_Log::count( [ 'action' => 'enrollment.created' ] ) );

	$cd_course = HDIT_Env::make_course( 'Course to delete' );
	$cd_run    = Hedayati_Course_Run_Service::create( [ 'course_id' => $cd_course ] );
	wp_delete_post( $cd_course, true );
	HDIT::eq( 'permanently deleting the catalog course cascade-deletes its runs', null, Hedayati_Course_Run_Service::get( $cd_run ) );

	$ud_course  = HDIT_Env::make_course( 'User-delete course' );
	$ud_run     = Hedayati_Course_Run_Service::create( [ 'course_id' => $ud_course, 'run_status' => 'in_progress' ] );
	$ud_student = HDIT_Env::make_user( 'ud_stu', 'student' );
	$ud_enr     = Hedayati_Enrollment_Service::enroll( $ud_run, $ud_student );
	wp_delete_user( $ud_student );
	HDIT::eq( 'deleting the student deletes their enrollment (G6)', null, Hedayati_Enrollment_Service::get( $ud_enr ) );
}
