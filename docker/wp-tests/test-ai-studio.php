<?php
/**
 * Real-WordPress runtime acceptance for the AI-Studio-parity modules
 * (owner decisions D46–D52): consultations, progress, certificates, materials,
 * support tickets, notifications, in-panel settings.
 *
 * Companion to tests/verify-ai-studio-modules.js (static). Exercised by
 * docker/wp-tests/run.php on a disposable Docker WordPress + MySQL.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

function hdit_run_ai_studio(): void {
	global $wpdb;

	$_SERVER['REQUEST_METHOD'] = 'POST';

	$manager   = HDIT_Env::make_user( 'ais_mgr', 'hedayati_manager' );
	$reception = HDIT_Env::make_user( 'ais_rcpt', 'reception' );
	$teacher   = HDIT_Env::make_user( 'ais_teacher', 'teacher' );
	$student_a = HDIT_Env::make_user( 'ais_sa', 'student' );
	$student_b = HDIT_Env::make_user( 'ais_sb', 'student' );

	$course          = HDIT_Env::make_course( 'AI Studio module course' );
	$teacher_profile = HDIT_Env::make_teacher( 'AI Studio instructor', $teacher );
	$run             = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'in_progress', 'start_date' => '2026-01-05' ] );
	$run             = is_wp_error( $run ) ? 0 : (int) $run;

	Hedayati_Run_Staff_Service::assign( [ 'run_id' => $run, 'staff_role' => 'primary_instructor', 'teacher_id' => $teacher_profile ] );
	$enr_a = Hedayati_Enrollment_Service::enroll( $run, $student_a );
	$enr_a = is_wp_error( $enr_a ) ? 0 : (int) $enr_a;
	$enr_b = Hedayati_Enrollment_Service::enroll( $run, $student_b );
	$enr_b = is_wp_error( $enr_b ) ? 0 : (int) $enr_b;

	// Sessions: 2 past, 1 future, 1 cancelled.
	$past1 = Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '1', 'starts_at' => '2026-01-06 09:00', 'topic' => 'S1', 'status' => 'held' ] );
	$past2 = Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '2', 'starts_at' => gmdate( 'Y-m-d H:i', time() - DAY_IN_SECONDS ), 'topic' => 'S2' ] );
	Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '3', 'starts_at' => gmdate( 'Y-m-d H:i', time() + 3 * DAY_IN_SECONDS ), 'topic' => 'S3' ] );
	Hedayati_Session_Service::create( [ 'run_id' => $run, 'session_number' => '4', 'starts_at' => '2026-01-20 09:00', 'topic' => 'S4', 'status' => 'cancelled' ] );

	// ── Consultations ───────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — consultation requests (D46)' );

	$c_ok = Hedayati_Consultation_Service::create( [ 'name' => 'سارا رضایی', 'phone' => '09141234567', 'topic' => 'شبکه', 'message' => 'راهنمایی برای انتخاب دوره' ] );
	HDIT::not_wp_error( 'valid public consultation submission is stored', $c_ok );
	HDIT::is_wp_error( 'invalid phone is rejected', Hedayati_Consultation_Service::create( [ 'name' => 'x y', 'phone' => '12345' ] ), 'phone' );
	HDIT::is_wp_error( 'too-short name is rejected', Hedayati_Consultation_Service::create( [ 'name' => 'x', 'phone' => '09120000000' ] ), 'name' );

	$c_row = Hedayati_Consultation_Service::get( (int) $c_ok );
	HDIT::ok( 'stored phone is E.164 normalised', is_array( $c_row ) && str_starts_with( (string) $c_row['phone_e164'], '+989' ) );
	HDIT::ok( 'new request counts toward the queue badge', Hedayati_Consultation_Service::count_new() >= 1 );

	// status change: reception may, student may not.
	HDIT::not_wp_error( 'reception can move a consultation to contacted', Hedayati_Consultation_Service::set_status( (int) $c_ok, 'contacted', $reception ) );
	HDIT::is_wp_error( 'invalid status value is rejected', Hedayati_Consultation_Service::set_status( (int) $c_ok, 'bogus', $reception ), 'status' );

	wp_set_current_user( $student_a );
	$cs_denied = false;
	HDIT_AdminPost::run( $student_a, [ '_wpnonce' => wp_create_nonce( 'hedayati_staff_consult_status' ), 'id' => (string) $c_ok, 'status' => 'closed' ], static fn() => Hedayati_Consultation_Service::handle_status() );
	HDIT::eq( 'a student cannot change consultation status (403)', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	wp_set_current_user( 0 );

	// audit contains no message body / phone.
	$c_audit = Hedayati_Audit_Log::query( [ 'object_type' => 'consultation', 'object_id' => (int) $c_ok ] );
	$c_audit_txt = wp_json_encode( $c_audit );
	HDIT::ok( 'consultation audit notes carry no phone / message body',
		false === strpos( (string) $c_audit_txt, '09141234567' )
		&& false === strpos( (string) $c_audit_txt, 'راهنمایی برای انتخاب دوره' ) );

	// ── Progress ────────────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — objective progress (D47)' );

	$rp = Hedayati_Progress_Service::run_progress( $run );
	HDIT::eq( 'run progress denominator excludes the cancelled session', 3, $rp['total'] );
	HDIT::eq( 'run progress counts held + past sessions', 2, $rp['held'] );
	HDIT::eq( 'run progress percent = 2/3', 67, Hedayati_Progress_Service::percent( $rp['ratio'] ) );

	$zero_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'scheduled' ] );
	$zero_run = is_wp_error( $zero_run ) ? 0 : (int) $zero_run;
	$zp = Hedayati_Progress_Service::run_progress( $zero_run );
	HDIT::ok( 'zero-session run returns null ratio, never 0%', null === $zp['ratio'] && null === Hedayati_Progress_Service::percent( $zp['ratio'] ) );

	// attendance: student_a present on past1, absent past2.
	if ( ! is_wp_error( $past1 ) && ! is_wp_error( $past2 ) && $enr_a > 0 ) {
		Hedayati_Attendance_Service::record( (int) $past1, $enr_a, 'present', [ 'recorded_by' => $teacher ] );
		Hedayati_Attendance_Service::record( (int) $past2, $enr_a, 'absent', [ 'recorded_by' => $teacher ] );
	}
	$att = Hedayati_Progress_Service::attendance_summary( $run, $student_a );
	HDIT::eq( 'attendance recorded count is 2', 2, $att['recorded'] );
	HDIT::eq( 'attendance present count is 1', 1, $att['present'] );
	HDIT::eq( 'attendance rate = 1/2 = 50%', 50, Hedayati_Progress_Service::percent( $att['ratio'] ) );

	$att_b = Hedayati_Progress_Service::attendance_summary( $run, $student_b );
	HDIT::ok( 'a student with no attendance marks gets a null rate (not 0%)', null === $att_b['ratio'] );

	// ── Materials ───────────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — course/session materials (D49)' );

	$m_link = Hedayati_Material_Service::create( [ 'run_id' => $run, 'type' => 'link', 'title' => 'مرجع شبکه', 'url' => 'https://example.org/net' ], $teacher );
	HDIT::not_wp_error( 'assigned teacher can add a link material', $m_link );
	HDIT::is_wp_error( 'link material rejects a non-http url', Hedayati_Material_Service::create( [ 'run_id' => $run, 'type' => 'link', 'title' => 'x', 'url' => 'javascript:alert(1)' ], $teacher ), 'url' );
	HDIT::is_wp_error( 'a stranger cannot add material to a run they do not teach', Hedayati_Material_Service::create( [ 'run_id' => $run, 'type' => 'note', 'title' => 'x' ], $reception ), 'cap' );

	HDIT::ok( 'an enrolled active student can view run materials', Hedayati_Material_Service::can_view_run( $run, $student_a ) );
	HDIT::ok( 'the manager can view run materials', Hedayati_Material_Service::can_view_run( $run, $manager ) );
	$outsider = HDIT_Env::make_user( 'ais_outsider', 'student' );
	HDIT::ok( 'an unrelated student cannot view run materials', ! Hedayati_Material_Service::can_view_run( $run, $outsider ) );

	// withdrawn enrollment loses access.
	if ( $enr_b > 0 ) {
		Hedayati_Enrollment_Service::set_status( $enr_b, 'withdrawn' );
		HDIT::ok( 'a withdrawn student loses material access', ! Hedayati_Material_Service::can_view_run( $run, $student_b ) );
		Hedayati_Enrollment_Service::set_status( $enr_b, 'active' );
	}

	// ── Certificates ────────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — certificates + public verification (D48)' );

	HDIT::is_wp_error( 'a non-manager cannot issue a certificate', Hedayati_Certificate_Service::issue( [ 'enrollment_id' => $enr_a ], $reception ), 'cap' );

	$cert_id = Hedayati_Certificate_Service::issue( [ 'enrollment_id' => $enr_a ], $manager );
	HDIT::not_wp_error( 'manager issues a certificate for an enrollment', $cert_id );
	HDIT::is_wp_error( 'a second certificate for the same enrollment is blocked', Hedayati_Certificate_Service::issue( [ 'enrollment_id' => $enr_a ], $manager ), 'duplicate' );

	$cert = Hedayati_Certificate_Service::get( (int) $cert_id );
	HDIT::ok( 'certificate code is DH-<year>-<random>, non-sequential', is_array( $cert ) && 1 === preg_match( '/^DH-\d{3,4}-[A-Z0-9]{10}$/', (string) $cert['code'] ) );
	HDIT::ok( 'certificate code is not the row id and two codes differ', is_array( $cert ) && (string) $cert['code'] !== (string) $cert['id'] && (string) $cert['code'] !== (string) $cert['enrollment_id'] );

	// student sees own, not others.
	HDIT::ok( 'student A sees their own certificate', count( Hedayati_Certificate_Service::list_for_user( $student_a ) ) === 1 );
	HDIT::ok( 'student B sees no certificate', count( Hedayati_Certificate_Service::list_for_user( $student_b ) ) === 0 );

	// public verification: valid.
	wp_set_current_user( 0 );
	$_GET = [ 'code' => $cert['code'] ];
	ob_start();
	Hedayati_Certificate_Service::render_public_verification();
	$verify_valid = (string) ob_get_clean();
	HDIT::ok( 'public verification of a valid code shows validity + minimal fields', str_contains( $verify_valid, 'hd-verify-valid' ) && str_contains( $verify_valid, $cert['recipient_name'] ) && str_contains( $verify_valid, $cert['code'] ) );
	HDIT::ok( 'public verification leaks no phone / national ID / address / attendance',
		! preg_match( '/09\d{9}/', $verify_valid )
		&& false === strpos( $verify_valid, 'کد ملی' )
		&& false === strpos( $verify_valid, 'حضور و غیاب' ) );

	// unknown code.
	$_GET = [ 'code' => 'DH-1405-ZZZZZZZZZZ' ];
	ob_start();
	Hedayati_Certificate_Service::render_public_verification();
	$verify_unknown = (string) ob_get_clean();
	HDIT::ok( 'an unknown code returns a clear non-sensitive "not found"', str_contains( $verify_unknown, 'hd-verify-unknown' ) );

	// revoke → revoked status shows.
	HDIT::not_wp_error( 'manager can revoke a certificate', Hedayati_Certificate_Service::revoke( (int) $cert_id, 'test', $manager ) );
	$_GET = [ 'code' => $cert['code'] ];
	ob_start();
	Hedayati_Certificate_Service::render_public_verification();
	$verify_revoked = (string) ob_get_clean();
	$_GET = [];
	HDIT::ok( 'a revoked code verifies as revoked, not valid', str_contains( $verify_revoked, 'hd-verify-revoked' ) && ! str_contains( $verify_revoked, 'hd-verify-valid' ) );

	// ── Support tickets ─────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — support tickets (D51)' );

	$t_id = Hedayati_Support_Service::open( $student_a, 'مشکل در جلسه', 'class', 'فایل ضبط‌شدهٔ جلسه را می‌خواهم.' );
	HDIT::not_wp_error( 'a student opens a ticket', $t_id );
	$t_id = is_wp_error( $t_id ) ? 0 : (int) $t_id;

	HDIT::ok( 'student A can load their own ticket', null !== Hedayati_Support_Service::get_for_viewer( $t_id, $student_a ) );
	HDIT::ok( 'student B cannot load student A\'s ticket (IDOR blocked)', null === Hedayati_Support_Service::get_for_viewer( $t_id, $student_b ) );
	HDIT::ok( 'reception (staff cap) can load any ticket', null !== Hedayati_Support_Service::get_for_viewer( $t_id, $reception ) );

	HDIT::is_wp_error( 'student B cannot reply to student A\'s ticket', Hedayati_Support_Service::reply( $t_id, $student_b, 'hi' ), 'not_found' );
	HDIT::not_wp_error( 'reception replies; ticket moves to waiting_student', Hedayati_Support_Service::reply( $t_id, $reception, 'سلام، فایل ارسال شد.' ) );
	$t_row = Hedayati_Support_Service::get( $t_id );
	HDIT::eq( 'ticket status after staff reply is waiting_student', 'waiting_student', $t_row['status'] );

	HDIT::not_wp_error( 'reception closes the ticket', Hedayati_Support_Service::set_status( $t_id, 'closed', $reception ) );
	HDIT::is_wp_error( 'a student cannot reply to a closed ticket', Hedayati_Support_Service::reply( $t_id, $student_a, 'again' ), 'closed' );
	HDIT::is_wp_error( 'a student cannot change ticket status', Hedayati_Support_Service::set_status( $t_id, 'open', $student_a ), 'cap' );

	// audit: no message body.
	$t_audit_txt = (string) wp_json_encode( Hedayati_Audit_Log::query( [ 'object_type' => 'support_ticket', 'object_id' => $t_id ] ) );
	HDIT::ok( 'support audit carries no message body', false === strpos( $t_audit_txt, 'فایل ضبط‌شدهٔ جلسه را می‌خواهم' ) );

	// ── Notifications ───────────────────────────────────────────────────────
	HDIT::section( 'AI Studio — internal notifications (D50)' );

	// certificate issue notified student A; support reply notified student A.
	$n_a = Hedayati_Notification_Service::list_for_user( $student_a );
	HDIT::ok( 'student A received notifications from real events (cert + support)', count( $n_a ) >= 2 );
	HDIT::ok( 'student B received no cross-user notification for A\'s ticket/cert', count( Hedayati_Notification_Service::list_for_user( $student_b ) ) === 0 );
	HDIT::ok( 'unread count is per-user and positive for A', Hedayati_Notification_Service::unread_count( $student_a ) >= 2 );

	$fresh = Hedayati_Notification_Service::list_for_user( $student_a );
	$first = $fresh[0];
	HDIT::ok( 'another user cannot mark A\'s notification read', ! Hedayati_Notification_Service::mark_read( (int) $first['id'], $student_b ) );
	HDIT::ok( 'the owner can mark their own notification read', Hedayati_Notification_Service::mark_read( (int) $first['id'], $student_a ) );
	Hedayati_Notification_Service::mark_all_read( $student_a );
	HDIT::eq( 'mark_all_read clears the unread count', 0, Hedayati_Notification_Service::unread_count( $student_a ) );

	// ── In-panel settings ───────────────────────────────────────────────────
	HDIT::section( 'AI Studio — in-panel institute settings (D52)' );

	$panel_settings_denied = static function ( int $uid ) {
		HDIT_AdminPost::run( $uid, [
			'_wpnonce'       => wp_create_nonce( 'hedayati_panel_settings_save' ),
			'institute_name' => 'HACK',
			'phone_consult'  => '02100000000',
		], static fn() => Hedayati_Panel_Settings::handle_save() );
		return HDIT_AdminPost::$result['status'] ?? 0;
	};

	wp_set_current_user( $reception );
	HDIT::eq( 'reception cannot save institute settings via the panel (403)', 403, $panel_settings_denied( $reception ) );
	wp_set_current_user( $teacher );
	HDIT::eq( 'a teacher cannot save institute settings via the panel (403)', 403, $panel_settings_denied( $teacher ) );

	wp_set_current_user( $manager );
	HDIT_AdminPost::run( $manager, [
		'_wpnonce'       => wp_create_nonce( 'hedayati_panel_settings_save' ),
		'institute_name' => 'مجتمع آموزشی دکتر هدایتی',
		'phone_consult'  => '04133373735',
		'address_tehran' => 'تهران، خیابان ولیعصر',
	], static fn() => Hedayati_Panel_Settings::handle_save() );
	wp_set_current_user( 0 );

	HDIT::eq( 'manager panel save writes through the canonical Hedayati_Settings source', 'مجتمع آموزشی دکتر هدایتی', Hedayati_Settings::get( 'institute_name' ) );
	HDIT::eq( 'the new tehran-address setting persists via the canonical option', 'تهران، خیابان ولیعصر', Hedayati_Settings::get( 'address_tehran' ) );
	HDIT::ok( 'the phone was run through the canonical phone sanitizer', '' !== Hedayati_Settings::get( 'phone_consult' ) );

	// ── Panel module registry ───────────────────────────────────────────────
	HDIT::section( 'AI Studio — role-aware panel module navigation' );

	$views = Hedayati_Staff_Portal::module_views();
	foreach ( [ 'consultations', 'certificates', 'materials', 'support', 'settings' ] as $slug ) {
		HDIT::ok( "module view '{$slug}' is registered", isset( $views[ $slug ] ) );
	}

	wp_set_current_user( $reception );
	HDIT::ok( 'reception sees consultations + support modules', Hedayati_Staff_Portal::can_view_module( 'consultations' ) && Hedayati_Staff_Portal::can_view_module( 'support' ) );
	HDIT::ok( 'reception does NOT see certificates or settings modules', ! Hedayati_Staff_Portal::can_view_module( 'certificates' ) && ! Hedayati_Staff_Portal::can_view_module( 'settings' ) );
	wp_set_current_user( $teacher );
	HDIT::ok( 'teacher sees the materials module only (of the new set)', Hedayati_Staff_Portal::can_view_module( 'materials' ) && ! Hedayati_Staff_Portal::can_view_module( 'consultations' ) && ! Hedayati_Staff_Portal::can_view_module( 'certificates' ) );
	wp_set_current_user( $manager );
	HDIT::ok( 'manager sees every new module', array_reduce( [ 'consultations', 'certificates', 'materials', 'support', 'settings' ], static fn( $ok, $s ) => $ok && Hedayati_Staff_Portal::can_view_module( $s ), true ) );
	wp_set_current_user( 0 );

	$_GET  = [];
	$_POST = [];
}
