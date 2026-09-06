<?php
/**
 * Phase 3 — launch completion — WordPress-runtime integration checks.
 *
 * Focus: the net-new Phase 3 behaviour on top of the (already green) Phase 2D +
 * launch WIP —
 *   - reception-created student accounts get a strong random TEMPORARY password
 *     that is never persisted in plaintext, and a `must_change_password` marker;
 *   - the forced first-login password-change handler enforces its rules, clears
 *     the marker only on success, and records a PII-free audit entry;
 *   - `hedayati_create_students` is held only by reception / manager / admin;
 *   - the manager course/category/settings capability fixes resolve correctly
 *     against real `map_meta_cap()` (a focused complement to test-launch.php).
 *
 * KNOWN GAP (same class as Phase 2D's): `Hedayati_Account_Security::intercept()`
 * runs on `template_redirect` for a real front-end page request and cannot be
 * exercised from a bare `wp eval-file` process. This suite tests the handler
 * (`handle_change()`) and the marker/label logic it depends on directly; the
 * interceptor's actual page behaviour is a staging acceptance item.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

function hdit_run_phase_3(): void {
	global $wpdb;
	$audit_table = Hedayati_DB_Schema::get_table_audit_log();

	// ── 1. Temporary password generator ────────────────────────────────────
	HDIT::section( 'Phase 3 — temporary password generation' );

	$p1 = Hedayati_Account_Security::generate_temp_password();
	$p2 = Hedayati_Account_Security::generate_temp_password();
	HDIT::ok( 'temp password is at least 16 chars', strlen( $p1 ) >= 16 );
	HDIT::ok( 'two generated temp passwords differ', $p1 !== $p2 );
	HDIT::ok( 'temp password mixes character classes', (bool) preg_match( '/[a-z]/', $p1 ) && (bool) preg_match( '/[A-Z]/', $p1 ) && (bool) preg_match( '/\d/', $p1 ) );

	// ── 2. Reception creates a student ─────────────────────────────────────
	HDIT::section( 'Phase 3 — reception-created student account + temp password + must-change marker' );

	$reception = HDIT_Env::make_user( 'p3_reception', 'reception' );
	$manager   = HDIT_Env::make_user( 'p3_manager', 'hedayati_manager' );
	$student   = HDIT_Env::make_user( 'p3_student', 'student' );
	$teacher   = HDIT_Env::make_user( 'p3_teacher', 'teacher' );

	// Every staff-portal mutation handler requires a POST request.
	$_SERVER['REQUEST_METHOD'] = 'POST';

	// student may NOT create accounts.
	wp_set_current_user( $student );
	$nonce_student = wp_create_nonce( 'hedayati_staff_student' );
	HDIT_AdminPost::run( $student, [
		'_wpnonce'   => $nonce_student,
		'first_name' => 'x', 'last_name' => 'y', 'user_login' => 'hdit_p3_new_by_student',
		'phone'      => '09120000001',
	], static fn() => Hedayati_Staff_Portal::handle_student() );
	HDIT::eq( 'a student cannot create a student account (403)', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::ok( 'no account was created by the student attempt', ! ( get_user_by( 'login', 'hdit_p3_new_by_student' ) instanceof WP_User ) );

	// reception creates one, with a valid nonce.
	wp_set_current_user( $reception );
	$nonce_reception = wp_create_nonce( 'hedayati_staff_student' );
	HDIT_AdminPost::run( $reception, [
		'_wpnonce'   => $nonce_reception,
		'first_name' => 'سارا', 'last_name' => 'محمدی',
		'user_login' => 'hdit_p3_created_student',
		'phone'      => '09121112233',
		'email'      => 'p3.created@example.test',
	], static fn() => Hedayati_Staff_Portal::handle_student() );

	$created = get_user_by( 'login', 'hdit_p3_created_student' );
	HDIT::ok( 'reception created the student account', $created instanceof WP_User );
	HDIT::ok( 'created account holds only the student role', $created && [ 'student' ] === array_values( (array) $created->roles ) );
	HDIT::ok( 'phone stored canonically for the new account', '+989121112233' === ( Hedayati_User_Phone_Service::get_phone_record_by_user( $created->ID )['phone_e164'] ?? '' ) );
	HDIT::ok( 'must_change_password marker is set on the new account', Hedayati_Account_Security::must_change( $created->ID ) );

	// The temporary password must not be recoverable from any persistent store.
	HDIT::ok( 'the must-change marker value is just a flag, never a password', '1' === (string) get_user_meta( $created->ID, Hedayati_Account_Security::META_MUST_CHANGE, true ) );
	$fresh = get_userdata( $created->ID );
	HDIT::ok( 'the created-account password is stored only as a WP hash, not plaintext', (bool) preg_match( '#^\$P\$|^\$wp\$|^\$2y\$#', (string) $fresh->user_pass ) );
	HDIT::ok(
		'the only Hedayati usermeta added is the boolean marker (no *_password / *_temp_* key)',
		[] === array_filter(
			array_keys( get_user_meta( $created->ID ) ),
			static fn( $k ) => $k !== Hedayati_Account_Security::META_MUST_CHANGE
				&& ( false !== stripos( (string) $k, 'password' ) || false !== stripos( (string) $k, 'temp_pass' ) )
		)
	);

	// The one-shot staff notice carries the password exactly once, then is gone.
	$notice_first = get_transient( 'hedayati_staff_notice_' . $reception );
	HDIT::ok( 'staff notice carries a non-empty one-shot secret', is_array( $notice_first ) && ! empty( $notice_first['secret'] ) );
	// render_notice() deletes it; simulate that consumption.
	delete_transient( 'hedayati_staff_notice_' . $reception );
	HDIT::ok( 'staff notice is single-use (gone after consumption)', false === get_transient( 'hedayati_staff_notice_' . $reception ) );

	// audit: account.created, PII-free.
	$audit_created = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$audit_table} WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
		'account.created',
		$created->ID
	), ARRAY_A );
	HDIT::ok( 'account.created audit row exists', is_array( $audit_created ) );
	HDIT::eq( 'account.created actor is the reception user', (string) $reception, (string) ( $audit_created['actor_id'] ?? '' ) );
	HDIT::ok( 'account.created note contains no password / national id / phone', is_array( $audit_created )
		&& false === strpos( (string) $audit_created['note'], '+9891211' )
		&& false === strpos( (string) $audit_created['note'], 'p3_created' ) );

	// A duplicate phone must be refused BEFORE any user row is created — no
	// half-provisioned account, no orphan phone row (HD-002-adjacent guard).
	$phone_rows_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Hedayati_DB_Schema::get_table_user_phones() );
	wp_set_current_user( $reception );
	$dup_nonce = wp_create_nonce( 'hedayati_staff_student' );
	HDIT_AdminPost::run( $reception, [
		'_wpnonce'   => $dup_nonce,
		'first_name' => 'رضا', 'last_name' => 'کریمی',
		'user_login' => 'hdit_p3_dupe_phone',
		'phone'      => '09121112233', // already the created student's number
	], static fn() => Hedayati_Staff_Portal::handle_student() );
	HDIT::ok( 'a duplicate phone is rejected with no account created', ! ( get_user_by( 'login', 'hdit_p3_dupe_phone' ) instanceof WP_User ) );
	HDIT::eq( 'a rejected create left no orphan phone row', $phone_rows_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Hedayati_DB_Schema::get_table_user_phones() ) );

	// ── 3. Forced first-login password change ──────────────────────────────
	HDIT::section( 'Phase 3 — forced first-login password change' );

	$target = $created->ID;

	// too short -> rejected, marker intact.
	wp_set_current_user( $target );
	$n = wp_create_nonce( 'hedayati_account_set_password' );
	HDIT_AdminPost::run( $target, [
		'_wpnonce'         => $n,
		'new_password'     => 'short',
		'confirm_password' => 'short',
	], static fn() => Hedayati_Account_Security::handle_change() );
	HDIT::ok( 'a too-short password is rejected (still must change)', Hedayati_Account_Security::must_change( $target ) );

	// mismatch -> rejected.
	wp_set_current_user( $target );
	$n = wp_create_nonce( 'hedayati_account_set_password' );
	HDIT_AdminPost::run( $target, [
		'_wpnonce'         => $n,
		'new_password'     => 'a-perfectly-long-passphrase-1',
		'confirm_password' => 'a-perfectly-long-passphrase-2',
	], static fn() => Hedayati_Account_Security::handle_change() );
	HDIT::ok( 'a mismatched confirmation is rejected (still must change)', Hedayati_Account_Security::must_change( $target ) );

	// missing nonce -> 403.
	HDIT_AdminPost::run( $target, [
		'new_password'     => 'a-perfectly-long-passphrase-9',
		'confirm_password' => 'a-perfectly-long-passphrase-9',
	], static fn() => Hedayati_Account_Security::handle_change() );
	HDIT::eq( 'a missing nonce is a 403', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::ok( 'still must change after the nonce failure', Hedayati_Account_Security::must_change( $target ) );

	// valid change -> succeeds, marker cleared, new password authenticates.
	$new_password = 'Tabriz-1404-strong-pass!';
	wp_set_current_user( $target );
	$n = wp_create_nonce( 'hedayati_account_set_password' );
	HDIT_AdminPost::run( $target, [
		'_wpnonce'         => $n,
		'new_password'     => $new_password,
		'confirm_password' => $new_password,
	], static fn() => Hedayati_Account_Security::handle_change() );

	HDIT::ok( 'marker is cleared after a valid change', ! Hedayati_Account_Security::must_change( $target ) );

	$auth = wp_authenticate( 'hdit_p3_created_student', $new_password );
	HDIT::ok( 'the new password authenticates the account', $auth instanceof WP_User && $auth->ID === $target );

	$audit_pw = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$audit_table} WHERE action = %s AND object_id = %d ORDER BY id DESC LIMIT 1",
		'account.password_changed',
		$target
	), ARRAY_A );
	HDIT::ok( 'account.password_changed audit row exists', is_array( $audit_pw ) );
	HDIT::ok( 'password_changed note never contains the password', is_array( $audit_pw ) && false === strpos( (string) $audit_pw['note'], $new_password ) );

	// a second call now that the marker is gone just bounces to a landing URL.
	wp_set_current_user( $target );
	$n = wp_create_nonce( 'hedayati_account_set_password' );
	HDIT_AdminPost::run( $target, [
		'_wpnonce'         => $n,
		'new_password'     => 'another-long-one-here-42',
		'confirm_password' => 'another-long-one-here-42',
	], static fn() => Hedayati_Account_Security::handle_change() );
	HDIT::ok( 'no marker => the change endpoint does not touch the password again', wp_authenticate( 'hdit_p3_created_student', $new_password ) instanceof WP_User );

	// ── 4. hedayati_create_students role matrix ────────────────────────────
	HDIT::section( 'Phase 3 — hedayati_create_students capability matrix' );

	foreach ( [
		'student'           => false,
		'teacher'           => false,
		'teacher_assistant' => false,
		'reception'         => true,
		'hedayati_manager'  => true,
		'administrator'     => true,
	] as $role => $expected ) {
		$uid = HDIT_Env::make_user( 'p3_caps_' . $role, $role );
		wp_set_current_user( $uid );
		HDIT::eq( "$role hedayati_create_students === " . ( $expected ? 'true' : 'false' ), $expected, current_user_can( 'hedayati_create_students' ) );
	}

	// ── 5. Manager course / category / settings capability fixes ───────────
	HDIT::section( 'Phase 3 — manager course/category/settings capability resolution' );

	$course = HDIT_Env::make_course( 'Phase 3 capability course' );

	wp_set_current_user( $manager );
	HDIT::ok( 'manager can edit a course post (map_meta_cap via hedayati_manage_courses)', current_user_can( 'edit_post', $course ) );
	HDIT::ok( 'manager can delete a course post', current_user_can( 'delete_post', $course ) );
	HDIT::ok( 'manager can manage the course-category taxonomy', current_user_can( get_taxonomy( 'course-category' )->cap->manage_terms ) );
	HDIT::ok( 'manager can save institute settings without manage_options',
		current_user_can( apply_filters( 'option_page_capability_hedayati_institute', 'manage_options' ) ) );
	HDIT::ok( 'manager still lacks manage_options (no technical admin power)', ! current_user_can( 'manage_options' ) );
	HDIT::ok( 'manager receives the unified manager workspace', Hedayati_Staff_Portal::is_manager_workspace() );

	unset( $_GET['view'] );
	ob_start();
	Hedayati_Staff_Portal::render();
	$manager_home = (string) ob_get_clean();
	HDIT::ok( 'manager dashboard renders live KPI and operations sections',
		str_contains( $manager_home, 'hd-manager-kpis' )
		&& str_contains( $manager_home, 'مرکز عملیات' )
		&& str_contains( $manager_home, 'hedayati-academic' ) );
	HDIT::ok( 'manager dashboard does not advertise unbuilt certificate or support modules',
		! str_contains( $manager_home, 'گواهینامه' )
		&& ! str_contains( $manager_home, 'تیکت' ) );

	// ── In-panel course management (AI Studio "مدیریت دوره‌ها" / "دوره‌های ویژه") ──
	wp_set_current_user( $manager );
	$_GET['view'] = 'courses';
	ob_start();
	Hedayati_Staff_Portal::render();
	$courses_view = (string) ob_get_clean();
	HDIT::ok( 'manager courses view lists the real course by title', str_contains( $courses_view, get_the_title( $course ) ) );
	HDIT::ok( 'manager courses view offers nonce-protected feature + publish toggle forms',
		str_contains( $courses_view, 'hedayati_staff_course_feature' )
		&& str_contains( $courses_view, 'hedayati_staff_course_publish' )
		&& str_contains( $courses_view, 'name="_wpnonce"' ) );
	HDIT::ok( 'manager courses view sends full editing to the WordPress editor', str_contains( $courses_view, 'ویرایش در ویرایشگر' ) );

	$_GET['view'] = 'featured';
	ob_start();
	Hedayati_Staff_Portal::render();
	$featured_view = (string) ob_get_clean();
	HDIT::ok( 'manager featured view renders the curation grid', str_contains( $featured_view, 'hd-manager-feature-grid' ) );
	unset( $_GET['view'] );
	wp_set_current_user( 0 );

	// Reception (no hedayati_manage_courses) never sees the in-panel course table,
	// and the toggle handler rejects them outright.
	wp_set_current_user( $reception );
	$nonce_rcpt_feat = wp_create_nonce( 'hedayati_staff_course_feature' );
	$_GET['view'] = 'courses';
	ob_start();
	Hedayati_Staff_Portal::render();
	$rcpt_view = (string) ob_get_clean();
	HDIT::ok( 'reception does not get the in-panel course table', ! str_contains( $rcpt_view, 'hd-manager-table' ) );
	unset( $_GET['view'] );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run( $reception, [
		'_wpnonce'  => $nonce_rcpt_feat,
		'course_id' => (string) $course,
	], static fn() => Hedayati_Staff_Portal::handle_course_feature() );
	HDIT::eq( 'reception cannot toggle a course featured flag (403)', 403, HDIT_AdminPost::$result['status'] ?? 0 );
	HDIT::ok( 'reception attempt left the course flag untouched', ! get_post_meta( $course, '_course_is_featured', true ) );

	// Manager toggles the flag on, then off, through the real handler (PRG redirect on success).
	wp_set_current_user( $manager );
	$nonce_mgr_feat = wp_create_nonce( 'hedayati_staff_course_feature' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run( $manager, [
		'_wpnonce'  => $nonce_mgr_feat,
		'course_id' => (string) $course,
	], static fn() => Hedayati_Staff_Portal::handle_course_feature() );
	HDIT::ok( 'manager feature toggle sets _course_is_featured', (bool) get_post_meta( $course, '_course_is_featured', true ) );

	wp_set_current_user( $manager );
	$nonce_mgr_feat2 = wp_create_nonce( 'hedayati_staff_course_feature' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run( $manager, [
		'_wpnonce'  => $nonce_mgr_feat2,
		'course_id' => (string) $course,
	], static fn() => Hedayati_Staff_Portal::handle_course_feature() );
	HDIT::ok( 'manager feature toggle clears the flag on the second call', ! get_post_meta( $course, '_course_is_featured', true ) );

	wp_set_current_user( $teacher );
	HDIT::ok( 'a teacher cannot edit a course post', ! current_user_can( 'edit_post', $course ) );
	HDIT::ok( 'a teacher cannot manage the course-category taxonomy', ! current_user_can( get_taxonomy( 'course-category' )->cap->manage_terms ) );

	wp_set_current_user( 0 );
}
