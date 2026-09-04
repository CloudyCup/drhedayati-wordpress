<?php
/**
 * Phase 2B — Academic Operations — WordPress-runtime integration checks.
 *
 * Automates a large part of the docs/PHASE_2B_ACCEPTANCE.md staging matrix
 * (sections A–K) that the static suites explicitly cannot prove: real
 * INSERT/UPDATE/DELETE, live UNIQUE enforcement, capability mapping, REST
 * exposure, cascade deletes, capacity/closed-run guards, cross-run (IDOR)
 * protection, Shamsi persistence, admin-post authorization gating (via a
 * wp_die/wp_redirect interceptor, never a real exit()), and audit-log
 * append-only behaviour.
 *
 * Exercised through the public service APIs and real WordPress behaviour.
 *
 * Known remaining gaps (docs/agent/DEFECTS.md HD-003) not covered here:
 * R5 (every one of the 22 capabilities across all 6 roles — only a
 * representative subset is asserted, in test-phase-2a.php), and B5/J9
 * (re-running a migration whose version is already current only exercises
 * the no-op early-return, not a second dbDelta pass). Do not read a PASS on
 * this suite as proof of those specific rows.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

/**
 * Thrown by HDIT_AdminPost's wp_die/redirect interceptors instead of letting
 * the real handler call exit() — keeps a negative admin-post test from ever
 * being able to abort the whole suite if a capability check unexpectedly
 * passes.
 */
class HDIT_WpDie extends \Exception {}

/**
 * Captures the outcome of an admin-post handler (class-academic-admin.php)
 * without ever reaching its exit() calls, so HD-003's A2/A3/A5 negative
 * authorization checks can run safely inside this single PHP process.
 */
final class HDIT_AdminPost {
	/** @var array{status:int, message:string}|null */
	public static ?array $result = null;

	public static function arm(): void {
		self::$result = null;
		add_filter( 'wp_die_handler', [ self::class, 'die_handler' ], 999999 );
		add_filter( 'wp_redirect', [ self::class, 'redirect_handler' ], 1 );
	}

	public static function disarm(): void {
		remove_filter( 'wp_die_handler', [ self::class, 'die_handler' ], 999999 );
		remove_filter( 'wp_redirect', [ self::class, 'redirect_handler' ], 1 );
	}

	public static function die_handler(): callable {
		return static function ( $message, $title = '', $args = [] ): void {
			self::$result = [
				'status'  => (int) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ),
				'message' => is_wp_error( $message ) ? $message->get_error_message() : (string) $message,
			];
			throw new HDIT_WpDie( 'wp_die intercepted' );
		};
	}

	/** A handler that would otherwise redirect+exit() on the SUCCESS path — treated as "not a 403". */
	public static function redirect_handler( $location ) {
		self::$result = [ 'status' => 0, 'message' => 'unexpected redirect to ' . (string) $location ];
		throw new HDIT_WpDie( 'redirect intercepted' );
	}

	/** Run $fn with $_POST set, current user set, interceptors armed; always restores state. */
	public static function run( int $user_id, array $post, callable $fn ): void {
		$prev_post = $_POST;
		self::arm();
		wp_set_current_user( $user_id );
		$_POST = $post;
		try {
			$fn();
		} catch ( HDIT_WpDie $e ) {
			// expected control-flow escape — see class docblock.
		} finally {
			self::disarm();
			$_POST = $prev_post;
			wp_set_current_user( 0 );
		}
	}
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

	// 1.5.3 regression guard: map_meta_cap('edit_post'|'delete_post', ...) on a
	// `publish`-status post not authored by the acting user ALSO requires
	// edit_published_posts / delete_published_posts (WordPress core, not this
	// plugin) — omitted keys auto-derive from capability_type into an
	// ungranted `..._hedayati_teachers` capability nobody holds, which is
	// exactly what made "manager/administrator: edit_post" false even after
	// the 1.5.2 bare-primitive fix. $probe is `publish` (see make_teacher()),
	// which is what exposed this.
	HDIT::eq( 'status-conditional edit_published_posts requires hedayati_manage_teachers (1.5.3)', 'hedayati_manage_teachers', $cap->edit_published_posts );
	HDIT::eq( 'status-conditional edit_private_posts requires hedayati_manage_teachers (1.5.3)', 'hedayati_manage_teachers', $cap->edit_private_posts );
	HDIT::eq( 'status-conditional delete_published_posts requires hedayati_manage_teachers (1.5.3)', 'hedayati_manage_teachers', $cap->delete_published_posts );
	HDIT::eq( 'status-conditional delete_private_posts requires hedayati_manage_teachers (1.5.3)', 'hedayati_manage_teachers', $cap->delete_private_posts );

	wp_set_current_user( $mgr );
	HDIT::ok( 'manager: current_user_can("hedayati_manage_teachers") [bare, no object]', current_user_can( 'hedayati_manage_teachers' ) );
	HDIT::ok( 'manager: current_user_can("edit_post", <published teacher>) [meta cap maps down]', current_user_can( 'edit_post', $probe ) );
	HDIT::ok( 'manager: current_user_can("delete_post", <published teacher>) (1.5.3)', current_user_can( 'delete_post', $probe ) );
	HDIT::ok( 'manager: current_user_can(edit_posts collection cap)', current_user_can( $cap->edit_posts ) );

	wp_set_current_user( 1 );
	HDIT::ok( 'administrator: current_user_can("hedayati_manage_teachers")', current_user_can( 'hedayati_manage_teachers' ) );
	HDIT::ok( 'administrator: current_user_can("edit_post", <published teacher>)', current_user_can( 'edit_post', $probe ) );
	HDIT::ok( 'administrator: current_user_can("delete_post", <published teacher>) (1.5.3)', current_user_can( 'delete_post', $probe ) );

	// Same checks against a `private`-status profile — exercises
	// edit_private_posts / delete_private_posts specifically (a `publish`
	// post never reaches that branch of map_meta_cap()).
	wp_set_current_user( $mgr );
	$private_probe = HDIT_Env::make_teacher( 'Private probe teacher' );
	wp_update_post( [ 'ID' => $private_probe, 'post_status' => 'private' ] );
	HDIT::ok( 'manager: current_user_can("edit_post", <private teacher>) (1.5.3)', current_user_can( 'edit_post', $private_probe ) );
	HDIT::ok( 'manager: current_user_can("delete_post", <private teacher>) (1.5.3)', current_user_can( 'delete_post', $private_probe ) );
	wp_set_current_user( 1 );
	HDIT::ok( 'administrator: current_user_can("edit_post", <private teacher>) (1.5.3)', current_user_can( 'edit_post', $private_probe ) );
	HDIT::ok( 'administrator: current_user_can("delete_post", <private teacher>) (1.5.3)', current_user_can( 'delete_post', $private_probe ) );

	foreach ( [ 'reception' => $rcpt, 'teacher' => $tchr, 'teacher_assistant' => $ta, 'student' => $stu ] as $label => $uid ) {
		wp_set_current_user( $uid );
		HDIT::ok( "{$label}: CANNOT hedayati_manage_teachers", ! current_user_can( 'hedayati_manage_teachers' ) );
		HDIT::ok( "{$label}: CANNOT edit_post on a published teacher profile", ! current_user_can( 'edit_post', $probe ) );
		HDIT::ok( "{$label}: CANNOT delete_post on a published teacher profile (1.5.3)", ! current_user_can( 'delete_post', $probe ) );
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
		HDIT::eq( "GET {$route} -> 404 (anonymous)", 404, $status );
	}

	// T5: authenticated, low-privilege requests must also see nothing (not just anonymous ones).
	wp_set_current_user( $stu );
	foreach ( [ '/wp/v2/hedayati_teacher', '/wp/v2/teacher' ] as $route ) {
		$status = rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
		HDIT::eq( "T5: GET {$route} -> 404 (authenticated as student)", 404, $status );
	}
	wp_set_current_user( 0 );

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

	// T2: the 1:1 link is enforced by the REAL save() handler, not just direct meta writes.
	$shared_user = HDIT_Env::make_user( 'shared_link', 'teacher' );
	$tp_first    = HDIT_Env::make_teacher( 'Profile one', $shared_user );
	$tp_second   = HDIT_Env::make_teacher( 'Profile two', 0 );

	wp_set_current_user( 1 ); // administrator: passes the edit_post capability check in save()
	$prev_post = $_POST;
	// NONCE_ACTION / NONCE_FIELD are private constants of Hedayati_Teacher; their
	// literal values are part of the handler's stable request contract (form field names).
	$_POST = [
		'hedayati_teacher_meta_nonce' => wp_create_nonce( 'hedayati_teacher_meta_save' ),
		'hd_teacher_headline'         => 'x',
		'hd_teacher_user_id'          => (string) $shared_user,
	];
	Hedayati_Teacher::save( $tp_second, get_post( $tp_second ) );
	$_POST = $prev_post;
	wp_set_current_user( 0 );

	HDIT::eq( 'T2: linking an already-claimed user to a second profile is refused by save()', 0, (int) get_post_meta( $tp_second, Hedayati_Teacher::META_USER_ID, true ) );
	HDIT::eq( 'T2: the original profile keeps the link', $tp_first, Hedayati_Teacher::find_by_user_id( $shared_user ) );

	// ── A2/A3/A5. Admin-post authorization gate ──────────────────────────
	HDIT::section( 'Phase 2B — admin-post authorization gate (A2/A3/A5)' );

	$gate_course = HDIT_Env::make_course( 'Gate course' );
	$gate_mgr    = HDIT_Env::make_user( 'gate_mgr', 'hedayati_manager' );
	$gate_stu    = HDIT_Env::make_user( 'gate_stu', 'student' );

	HDIT_AdminPost::run( $gate_mgr, [ 'course_id' => $gate_course ], [ 'Hedayati_Academic_Admin', 'handle_run_save' ] );
	HDIT::eq( 'A2: handle_run_save without a nonce -> wp_die 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// The nonce is bound to the current user at creation time, so it must be
	// created as $gate_stu — creating it before switching users would make the
	// nonce check itself fail and mask whether the capability check works.
	wp_set_current_user( $gate_stu );
	$run_save_nonce = wp_create_nonce( 'hedayati_run_save' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run(
		$gate_stu,
		[ '_wpnonce' => $run_save_nonce, 'course_id' => $gate_course ],
		[ 'Hedayati_Academic_Admin', 'handle_run_save' ]
	);
	HDIT::eq( 'A3: handle_run_save with a valid nonce but no capability (student) -> wp_die 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::eq( 'A3: the rejected request created no run', 0, Hedayati_Course_Run_Service::count_for_course( $gate_course ) );

	$gate_run     = Hedayati_Course_Run_Service::create( [ 'course_id' => $gate_course, 'run_status' => 'in_progress' ] );
	$gate_session = Hedayati_Session_Service::create( [ 'run_id' => $gate_run, 'session_number' => '1', 'starts_at' => '2026-05-01 09:00' ] );

	wp_set_current_user( $gate_mgr );
	$attendance_nonce = wp_create_nonce( 'hedayati_attendance_save' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run(
		$gate_mgr, // hedayati_manager lacks hedayati_record_attendance (asserted in the Phase 2A role matrix)
		[ '_wpnonce' => $attendance_nonce, 'session_id' => $gate_session, 'mark' => [] ],
		[ 'Hedayati_Academic_Admin', 'handle_attendance_save' ]
	);
	HDIT::eq( 'A5: hedayati_manager cannot POST attendance (lacks hedayati_record_attendance) -> 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// A4 (per-run scope): a user staffed on one run is not treated as staff on another.
	$scope_teacher_user = HDIT_Env::make_user( 'scope_instr', 'teacher' );
	$scope_teacher      = HDIT_Env::make_teacher( 'Scope instructor', $scope_teacher_user );
	Hedayati_Run_Staff_Service::assign( [ 'run_id' => $gate_run, 'staff_role' => 'primary_instructor', 'teacher_id' => $scope_teacher ] );
	$other_gate_run = Hedayati_Course_Run_Service::create( [ 'course_id' => $gate_course, 'run_status' => 'in_progress' ] );
	HDIT::ok( 'A4: staffed on run X -> user_is_staff_on_run(X) true', Hedayati_Run_Staff_Service::user_is_staff_on_run( $scope_teacher_user, $gate_run ) );
	HDIT::ok( 'A4: staffed on run X -> user_is_staff_on_run(Y) false (per-run scope, not global)', ! Hedayati_Run_Staff_Service::user_is_staff_on_run( $scope_teacher_user, $other_gate_run ) );

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

	$cancelled = Hedayati_Course_Run_Service::create( [ 'course_id' => $course, 'run_status' => 'cancelled' ] );
	HDIT::is_wp_error( 'G2: enrolling into a cancelled run refused', Hedayati_Enrollment_Service::enroll( $cancelled, $st1 ), 'run_closed' );

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

	// S3: deleting a session directly (not via delete_run) cascades its attendance.
	HDIT::ok( 'S3: delete_session() returns true', Hedayati_Session_Service::delete_session( $att_session ) );
	HDIT::eq( 'S3: attendance row for the deleted session is gone', null, Hedayati_Attendance_Service::get( $m1 ) );

	// G5: deleting an enrollment directly (not via delete_run/user-delete) cascades its attendance.
	$g5_session = Hedayati_Session_Service::create( [ 'run_id' => $other_run, 'session_number' => '1', 'starts_at' => '2026-04-11 09:00' ] );
	$g5_mark    = Hedayati_Attendance_Service::record( $g5_session, $other_enr, 'present' );
	HDIT::ok( 'G5: attendance recorded ahead of the direct-delete check', is_int( $g5_mark ) && $g5_mark > 0 );
	HDIT::ok( 'G5: delete_enrollment() returns true', Hedayati_Enrollment_Service::delete_enrollment( $other_enr ) );
	HDIT::eq( 'G5: attendance row for the deleted enrollment is gone', null, Hedayati_Attendance_Service::get( $g5_mark ) );

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
	$ud_session = Hedayati_Session_Service::create( [ 'run_id' => $ud_run, 'session_number' => '1', 'starts_at' => '2026-04-25 09:00' ] );
	$ud_student = HDIT_Env::make_user( 'ud_stu', 'student' );
	$ud_enr     = Hedayati_Enrollment_Service::enroll( $ud_run, $ud_student );
	$ud_mark    = Hedayati_Attendance_Service::record( $ud_session, $ud_enr, 'present' );
	HDIT::ok( 'G6: attendance recorded ahead of the user-delete check', is_int( $ud_mark ) && $ud_mark > 0 );

	wp_delete_user( $ud_student );
	HDIT::eq( 'deleting the student deletes their enrollment (G6)', null, Hedayati_Enrollment_Service::get( $ud_enr ) );
	HDIT::eq( 'G6: the student\'s attendance row is cascade-deleted with the enrollment', null, Hedayati_Attendance_Service::get( $ud_mark ) );

	// ── J6/J8. Audit viewer authorization & filter sanitization ─────────
	HDIT::section( 'Phase 2B — audit viewer authorization & filter sanitization (J6/J8)' );

	$j6_mgr  = HDIT_Env::make_user( 'j6_mgr', 'hedayati_manager' );
	$j6_rcpt = HDIT_Env::make_user( 'j6_rcpt', 'reception' );

	wp_set_current_user( $j6_mgr );
	HDIT::ok( 'J6: hedayati_manager can view the audit log (has hedayati_view_audit_logs)', Hedayati_Audit_Log::current_user_can_view() );
	wp_set_current_user( $j6_rcpt );
	HDIT::ok( 'J6: reception cannot view the audit log', ! Hedayati_Audit_Log::current_user_can_view() );
	wp_set_current_user( 0 );

	$bogus = Hedayati_Audit_Log::query( [ 'action' => "junk'; DROP TABLE x; --" ] );
	HDIT::eq( 'J8: an out-of-vocabulary action filter yields 0 rows (sanitised, not passed raw to SQL)', [], $bogus );
	HDIT::eq(
		'J8: the same out-of-vocabulary filter counts 0, not an SQL error',
		0,
		Hedayati_Audit_Log::count( [ 'action' => "junk'; DROP TABLE x; --" ] )
	);
}
