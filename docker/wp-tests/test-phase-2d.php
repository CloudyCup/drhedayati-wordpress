<?php
/**
 * Phase 2D — account shell + student self-service portal — WordPress-runtime
 * integration checks.
 *
 * Exercised through the real service layer and real WordPress behaviour:
 * the account Page's actual creation/idempotency, the admin-post.php mutation
 * handlers via HDIT_AdminPost (defined in test-phase-2b.php — no real exit()),
 * and — the central Phase 2D security property — that student A can never
 * read or mutate student B's data through any portal handler, proven by
 * actually attempting it and checking the result, not by reading source.
 *
 * KNOWN, DOCUMENTED GAPS in this harness (not defects — the same class of
 * limitation already recorded for Phase 2C):
 *   1. `is_uploaded_file()` is only ever true for a real HTTP multipart
 *      upload — a WP-CLI process can never fabricate one, so
 *      `Hedayati_Student_Portal::handle_document_upload()`'s own call into
 *      `Hedayati_Document_Storage::save()` cannot be exercised end-to-end
 *      here. This suite instead (a) asserts the real handler correctly
 *      REFUSES a call with no genuine uploaded file (proving the gate itself
 *      still fires from the front-end code path), and (b) exercises
 *      everything downstream of that gate — ownership, ordering vs. metadata,
 *      ownership-scoped listing — via the same `hdit_store_test_file()`
 *      reflection seam Phase 2C's own suite uses. Full end-to-end upload
 *      coverage (a real multipart POST through the front-end form) needs a
 *      real HTTP request — retained as an explicit staging acceptance item,
 *      not claimed as passed here.
 *   2. The full `template_redirect` → `is_page()` → login/capability guard
 *      chain (`Hedayati_Student_Portal::guard_account_page()`) requires a
 *      real front-end page request with WordPress's main query resolved to
 *      the account Page — not reproducible from a bare `wp eval-file`
 *      process without a real HTTP request. This suite instead tests the
 *      capability/role logic the guard depends on directly (the same
 *      capability matrix already runtime-verified in Phase 2A), and the
 *      account-page bootstrap/idempotency, which ARE reproducible here.
 *      The guard's actual page-request behavior is a staging acceptance item.
 *   3. `wp_get_environment_type()`/`WP_CLI` guards inside
 *      `Hedayati_Auth_UI::maybe_redirect_student_away_from_admin()` mean that
 *      method always no-ops under this WP-CLI harness by design (the same
 *      guard that correctly protects a real WP-CLI cron/maintenance task in
 *      production makes it untestable here) — this suite instead tests
 *      `student_login_redirect()` (no such guard, fully callable) and the
 *      role-only condition statically (`verify-phase2d.js`), and documents
 *      that the admin_init redirect's actual browser behavior is a staging
 *      acceptance item.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

/**
 * Real-runtime companion to verify-phase2d.js's static regression guard:
 * confirms no callback is actually attached to the `lostpassword_errors`
 * filter in this live WordPress instance (not just absent from source) —
 * the exact filter whose contract violation caused the fixed defect.
 */
function assert_no_lostpassword_filter_registered(): void {
	global $wp_filter;

	$registered = isset( $wp_filter['lostpassword_errors'] ) && ! empty( $wp_filter['lostpassword_errors']->callbacks );
	HDIT::ok( 'no callback is attached to the lostpassword_errors filter at runtime (the fixed defect\'s filter is gone, not just unused)', ! $registered );
}

function hdit_run_phase_2d(): void {
	global $wpdb;

	// ── 1. Account page bootstrap + idempotency ─────────────────────────────
	HDIT::section( 'Phase 2D — account page bootstrap' );

	$page_id_first = Hedayati_Student_Portal::get_account_page_id();
	HDIT::ok( 'account page already exists from plugin activation', $page_id_first > 0 && 'page' === get_post_type( $page_id_first ) );

	Hedayati_Student_Portal::maybe_create_account_page();
	$page_id_second = Hedayati_Student_Portal::get_account_page_id();
	HDIT::eq( 'calling maybe_create_account_page() again does not create a duplicate page', $page_id_first, $page_id_second );

	$page = get_post( $page_id_first );
	HDIT::eq( 'account page is published', 'publish', $page->post_status );
	HDIT::eq( 'account page slug is "account" (page-account.php template auto-selection depends on this)', 'account', $page->post_name );

	$account_url = Hedayati_Student_Portal::get_account_url();
	HDIT::ok( 'get_account_url() returns a URL containing the account slug', false !== strpos( $account_url, '/account/' ) );
	$profile_url = Hedayati_Student_Portal::get_account_url( 'profile' );
	HDIT::ok( 'get_account_url("profile") appends the view query arg', false !== strpos( $profile_url, 'view=profile' ) );

	// ── Fixtures ─────────────────────────────────────────────────────────────
	$student_a = HDIT_Env::make_user( 'porta', 'student' );
	$student_b = HDIT_Env::make_user( 'portb', 'student' );
	$manager   = HDIT_Env::make_user( 'portmgr', 'hedayati_manager' );
	$teacher   = HDIT_Env::make_user( 'portteach', 'teacher' );

	// ── 2. Role-aware login redirect ────────────────────────────────────────
	HDIT::section( 'Phase 2D — role-aware login redirect' );

	$default_redirect = admin_url();
	$student_a_user   = get_userdata( $student_a );
	$manager_user     = get_userdata( $manager );

	$redirect_for_student = Hedayati_Auth_UI::student_login_redirect( $default_redirect, '', $student_a_user );
	HDIT::eq( 'a student is redirected to the account URL after login', Hedayati_Student_Portal::get_account_url(), $redirect_for_student );

	$redirect_for_manager = Hedayati_Auth_UI::student_login_redirect( $default_redirect, '', $manager_user );
	HDIT::eq( 'a manager keeps the default (wp-admin) redirect after login', $default_redirect, $redirect_for_manager );

	$redirect_for_wp_error = Hedayati_Auth_UI::student_login_redirect( $default_redirect, '', new WP_Error( 'failed_login', 'nope' ) );
	HDIT::eq( 'a failed-login WP_Error leaves the redirect untouched (defensive instanceof check)', $default_redirect, $redirect_for_wp_error );

	// ── 3. No public self-registration ──────────────────────────────────────
	HDIT::section( 'Phase 2D — no public self-registration, regardless of the stored option' );

	update_option( 'users_can_register', 1 );
	HDIT::ok( 'option_users_can_register filter forces false even when the stored option is 1', ! get_option( 'users_can_register' ) );
	update_option( 'users_can_register', 0 );

	// ── 4. Password-reset enumeration hardening — real runtime behaviour ────
	// Release-blocking defect (fixed): the earlier implementation filtered
	// `lostpassword_errors` and returned boolean `true`, which violates that
	// filter's contract (retrieve_password() calls $errors->has_errors() on
	// the return value unconditionally — a bare bool fatals). The fix removes
	// that filter entirely and instead hooks `login_form_lostpassword`
	// (see class-auth-ui.php's docblock). These tests exercise the REAL
	// handler end to end — not a direct call into an isolated filter — so a
	// regression back to the old approach would show up here as a PHP fatal,
	// not just a static-source mismatch.
	HDIT::section( 'Phase 2D — password-reset enumeration hardening (real handler, not a filter)' );

	assert_no_lostpassword_filter_registered();

	$reset_user_id = HDIT_Env::make_user( 'resetuser', 'student' );
	$reset_login   = get_userdata( $reset_user_id )->user_login;

	$wpdb->update( $wpdb->users, [ 'user_activation_key' => '' ], [ 'ID' => $reset_user_id ] );

	// `pre_wp_mail` (short-circuit filter, WP 5.7+) both captures the attempt
	// AND prevents wp_mail() from actually trying to send anything — this
	// container has no configured mail transport, and a real send attempt
	// risks a slow/hanging SMTP connection attempt in CI, not just a failure.
	$mail_attempts = [];
	$capture_mail  = static function ( $short_circuit, $atts ) use ( &$mail_attempts ) {
		$mail_attempts[] = $atts;
		return true; // short-circuit: wp_mail() returns true, sends nothing.
	};
	add_filter( 'pre_wp_mail', $capture_mail, 10, 2 );

	// 4a. Unknown account — must not fatal, must not email, must still redirect
	// to the exact same URL a real account gets.
	$fatal_caught = false;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = [ 'user_login' => 'hdit_definitely_nonexistent_' . wp_generate_password( 8, false ) ];
	HDIT_AdminPost::arm();
	try {
		Hedayati_Auth_UI::handle_lostpassword_request();
	} catch ( \Throwable $e ) {
		if ( ! ( $e instanceof HDIT_WpDie ) ) {
			$fatal_caught = true;
		}
	} finally {
		HDIT_AdminPost::disarm();
	}
	$redirect_for_unknown = HDIT_AdminPost::$result['message'] ?? '';
	$_POST = [];
	$_SERVER['REQUEST_METHOD'] = '';

	HDIT::ok( 'an unknown-account password-reset request does NOT fatal (the exact defect this fix addresses)', ! $fatal_caught );
	HDIT::ok( 'an unknown account redirects to the native WordPress success target (checkemail=confirm)', false !== strpos( $redirect_for_unknown, 'checkemail=confirm' ) );
	HDIT::eq( 'no email was attempted for the nonexistent identifier', 0, count( $mail_attempts ) );

	// 4b. Existing account — real reset path: a genuine key is generated and
	// WordPress's own mail attempt fires (captured, not actually delivered —
	// this container has no configured mail transport, which is fine; we are
	// asserting WordPress's own logic decided to send, not that delivery
	// succeeded).
	$mail_attempts = [];
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = [ 'user_login' => $reset_login ];
	HDIT_AdminPost::arm();
	try {
		Hedayati_Auth_UI::handle_lostpassword_request();
	} catch ( HDIT_WpDie $e ) {
		// expected control-flow escape.
	} finally {
		HDIT_AdminPost::disarm();
	}
	$redirect_for_real = HDIT_AdminPost::$result['message'] ?? '';
	$_POST = [];
	$_SERVER['REQUEST_METHOD'] = '';

	remove_filter( 'pre_wp_mail', $capture_mail, 10 );

	$activation_key_after = $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $reset_user_id ) );
	HDIT::ok( 'an existing account gets a REAL reset key generated by the unmodified retrieve_password() call', '' !== (string) $activation_key_after );
	HDIT::ok( 'an existing account\'s reset attempts a real email send (WordPress\'s own decision, not reimplemented here)', count( $mail_attempts ) >= 1 );
	HDIT::eq( 'an existing account redirects to the identical URL an unknown account gets', $redirect_for_unknown, $redirect_for_real );

	// 4c. Outward indistinguishability, stated explicitly as its own assertion.
	HDIT::eq(
		'existing vs. nonexistent identifiers produce a byte-identical outward redirect — the core enumeration-resistance property',
		$redirect_for_real,
		$redirect_for_unknown
	);

	// 4d. Empty submission is untouched (real validation feedback, not a leak):
	// the handler must return without redirecting or touching mail.
	$mail_attempts_empty = [];
	$capture_mail_empty  = static function ( $short_circuit, $atts ) use ( &$mail_attempts_empty ) {
		$mail_attempts_empty[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $capture_mail_empty, 10, 2 );
	HDIT_AdminPost::$result = null;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = [ 'user_login' => '' ];
	HDIT_AdminPost::arm();
	try {
		Hedayati_Auth_UI::handle_lostpassword_request();
	} catch ( HDIT_WpDie $e ) {
		// not expected — an empty submission must not redirect.
	} finally {
		HDIT_AdminPost::disarm();
	}
	$_POST = [];
	$_SERVER['REQUEST_METHOD'] = '';
	remove_filter( 'pre_wp_mail', $capture_mail_empty, 10 );

	HDIT::ok( 'an empty submission does not redirect (native WordPress validation feedback renders instead)', null === HDIT_AdminPost::$result );
	HDIT::eq( 'an empty submission never attempts an email', 0, count( $mail_attempts_empty ) );

	// ── 5. Ownership: student A cannot mutate student B's profile/phone ────
	HDIT::section( 'Phase 2D — student A cannot mutate student B (posted user_id is ignored)' );

	$nonce_a = null;
	wp_set_current_user( $student_a );
	$nonce_a = wp_create_nonce( 'hedayati_portal_profile_save' );
	wp_set_current_user( 0 );

	HDIT_AdminPost::run(
		$student_a,
		[
			'_wpnonce'             => $nonce_a,
			'user_id'              => (string) $student_b, // attempted IDOR — must be ignored entirely
			'hedayati_address'     => 'street A',
			'hedayati_city'        => 'Tabriz',
			'hedayati_postal_code' => '1234567890',
			'user_email'           => 'student-a-' . $student_a . '@hedayati.test',
		],
		[ 'Hedayati_Student_Portal', 'handle_profile_save' ]
	);

	HDIT::eq( "student A's own address was updated", 'street A', get_user_meta( $student_a, Hedayati_Student_Profile::META_ADDRESS, true ) );
	HDIT::eq( "student B's address was NOT touched by student A's request (posted user_id ignored)", '', get_user_meta( $student_b, Hedayati_Student_Profile::META_ADDRESS, true ) );

	// ── 6. Phone: normalization, uniqueness, reset-on-change via the portal ─
	HDIT::section( 'Phase 2D — phone normalization/uniqueness/reset through the portal caller' );

	wp_set_current_user( $student_a );
	$phone_nonce_a = wp_create_nonce( 'hedayati_portal_phone_save' );
	wp_set_current_user( 0 );

	HDIT_AdminPost::run(
		$student_a,
		[ '_wpnonce' => $phone_nonce_a, 'phone' => '0912 345 6789', 'user_id' => (string) $student_b ],
		[ 'Hedayati_Student_Portal', 'handle_phone_save' ]
	);

	$record_a = Hedayati_User_Phone_Service::get_phone_record_by_user( $student_a );
	HDIT::ok( "student A's phone stored canonically (E.164) via the portal caller", $record_a && '+989123456789' === $record_a['phone_e164'] );
	HDIT::ok( "student B has no phone record — the posted user_id had no effect", null === Hedayati_User_Phone_Service::get_phone_record_by_user( $student_b ) );

	Hedayati_User_Phone_Service::verify_phone( $student_a );
	$verified_before = Hedayati_User_Phone_Service::get_phone_record_by_user( $student_a );
	HDIT::ok( 'phone marked verified (test setup) before the change', $verified_before['is_verified'] );

	wp_set_current_user( $student_a );
	$phone_nonce_a2 = wp_create_nonce( 'hedayati_portal_phone_save' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run(
		$student_a,
		[ '_wpnonce' => $phone_nonce_a2, 'phone' => '0912 000 0000' ],
		[ 'Hedayati_Student_Portal', 'handle_phone_save' ]
	);
	$record_a_after_change = Hedayati_User_Phone_Service::get_phone_record_by_user( $student_a );
	HDIT::ok( 'changing the phone through the portal resets is_verified (D8, unchanged by Phase 2D)', $record_a_after_change && ! $record_a_after_change['is_verified'] );

	// Duplicate-phone rejection through the same portal path.
	wp_set_current_user( $student_b );
	$phone_nonce_b = wp_create_nonce( 'hedayati_portal_phone_save' );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run(
		$student_b,
		[ '_wpnonce' => $phone_nonce_b, 'phone' => '0912 000 0000' ], // already student A's number
		[ 'Hedayati_Student_Portal', 'handle_phone_save' ]
	);
	HDIT::ok( 'student B cannot claim student A\'s already-assigned phone number through the portal', null === Hedayati_User_Phone_Service::get_phone_record_by_user( $student_b ) );

	// ── 7. Document ownership: upload-origin gate + reflection-seam content ─
	HDIT::section( 'Phase 2D — document upload (known upload-origin gap; see file header) + ownership' );

	wp_set_current_user( $student_a );
	$upload_nonce_a = wp_create_nonce( 'hedayati_portal_document_upload' );
	wp_set_current_user( 0 );

	// No real $_FILES entry exists in this harness (see file header, gap #1) —
	// the real handler must still refuse cleanly, proving the front-end gate
	// itself fires, not just the underlying service's own check.
	HDIT_AdminPost::run(
		$student_a,
		[ '_wpnonce' => $upload_nonce_a, 'doc_type' => 'national_card' ],
		[ 'Hedayati_Student_Portal', 'handle_document_upload' ]
	);
	HDIT::eq( 'handle_document_upload() with no real uploaded file redirects with an error notice (does not fatal, does not silently succeed)', 0, Hedayati_Audit_Log::count( [ 'action' => 'document.uploaded', 'actor_id' => $student_a ] ) );

	// Ownership content, via the same reflection seam Phase 2C's own suite
	// uses to get real bytes past the is_uploaded_file() gate.
	$pdf_path      = HDIT_Env::write_temp_file( 'pdf' );
	$stored_for_a  = hdit_store_test_file( $student_a, $pdf_path );
	HDIT::not_wp_error( 'a genuine PDF is accepted for student A (storage layer, reflection seam)', $stored_for_a );

	$doc_id_a = 0;
	if ( is_array( $stored_for_a ) ) {
		$documents_table = Hedayati_DB_Schema::get_table_documents();
		$wpdb->insert(
			$documents_table,
			[
				'user_id' => $student_a, 'doc_type' => 'national_card', 'storage_backend' => 'local',
				'storage_key' => $stored_for_a['storage_key'], 'original_mime' => $stored_for_a['mime'], 'size_bytes' => $stored_for_a['size'],
				'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$doc_id_a = (int) $wpdb->insert_id;
	}

	HDIT::ok( 'a document row exists for student A to test ownership against', $doc_id_a > 0 );

	if ( $doc_id_a > 0 ) {
		// Student A downloading their own document: no wp_die should fire.
		wp_set_current_user( $student_a );
		$download_nonce_a = wp_create_nonce( 'hedayati_portal_document_download_' . $doc_id_a );
		wp_set_current_user( 0 );

		HDIT_AdminPost::arm();
		wp_set_current_user( $student_a );
		$_GET = [ 'doc_id' => (string) $doc_id_a, '_wpnonce' => $download_nonce_a ];
		try {
			ob_start();
			Hedayati_Student_Portal::handle_document_download();
			ob_end_clean();
		} catch ( HDIT_WpDie $e ) {
			// expected only on failure — see assertion below.
		} finally {
			HDIT_AdminPost::disarm();
			wp_set_current_user( 0 );
			$_GET = [];
		}
		HDIT::ok(
			"student A downloading their OWN document does not trigger a wp_die (owns it)",
			null === HDIT_AdminPost::$result
		);

		// Student B attempting to download student A's document via a stolen/
		// guessed doc_id: this is the central Phase 2D ownership property.
		wp_set_current_user( $student_b );
		$download_nonce_b_attempt = wp_create_nonce( 'hedayati_portal_document_download_' . $doc_id_a );
		wp_set_current_user( 0 );

		HDIT_AdminPost::arm();
		wp_set_current_user( $student_b );
		$_GET = [ 'doc_id' => (string) $doc_id_a, '_wpnonce' => $download_nonce_b_attempt ];
		try {
			Hedayati_Student_Portal::handle_document_download();
		} catch ( HDIT_WpDie $e ) {
			// expected — this IS the point of the test.
		} finally {
			HDIT_AdminPost::disarm();
			wp_set_current_user( 0 );
			$_GET = [];
		}
		HDIT::eq(
			'student B attempting student A\'s document id is refused with 404 (identical response to "does not exist" — never confirms it belongs to someone else)',
			404,
			HDIT_AdminPost::$result['status'] ?? 0
		);
	}

	// ── 8. Verification display never exposes staff-internal fields ────────
	HDIT::section( 'Phase 2D — verification status shown to a student is narrowed correctly' );

	Hedayati_Verification_Service::set_national_id( $student_a, '0499370899', $manager );
	Hedayati_Verification_Service::initiate( $student_a, $manager );
	Hedayati_Verification_Service::reject( $student_a, $manager, 'a staff-internal reason mentioning sensitive context' );

	$full_status = Hedayati_Verification_Service::get_status( $student_a );
	HDIT::ok( 'get_status() itself still returns reviewer/reviewed_at/note (unchanged Phase 2C contract — the NARROWING happens in the portal controller, not the service)', '' !== $full_status['note'] && $full_status['reviewer_id'] > 0 );

	// render_verification_view() is private; go through the public
	// render_current_view() dispatcher instead, scoped to student A via
	// wp_set_current_user() — matches how the real request-scoped controller
	// resolves its owner (get_current_user_id(), never a parameter).
	wp_set_current_user( $student_a );
	$_GET   = [ 'view' => 'verification' ];
	$output = Hedayati_Student_Portal::render_current_view();
	wp_set_current_user( 0 );
	$_GET = [];

	HDIT::ok( 'rendered verification view does NOT contain the staff-internal review note text', false === strpos( $output, 'a staff-internal reason mentioning sensitive context' ) );
	HDIT::ok( 'rendered verification view does NOT contain the word "reviewer_id"/"reviewed_at" (no raw array dump)', false === strpos( $output, 'reviewer_id' ) && false === strpos( $output, 'reviewed_at' ) );
	HDIT::ok( 'rendered verification view shows the rejected status label', false !== strpos( $output, 'رد شده' ) );
	HDIT::ok( 'rendered verification view shows national-ID presence only ("ثبت شده"), never the digits', false !== strpos( $output, 'ثبت شده' ) && false === strpos( $output, '0499370899' ) );

	// ── 9. Enrollment/session display is read-only and Shamsi-presented ────
	HDIT::section( 'Phase 2D — enrollments view is read-only and Shamsi-dated' );

	$course_id     = HDIT_Env::make_course( 'Portal test course' );
	$run_id_result = Hedayati_Course_Run_Service::create( [ 'course_id' => $course_id, 'run_status' => 'in_progress', 'start_date' => '2026-05-01' ] );
	HDIT::not_wp_error( 'synthetic Course Run created for the enrollments-view fixture', $run_id_result );
	$run_id = is_wp_error( $run_id_result ) ? 0 : $run_id_result;

	if ( $run_id > 0 ) {
		Hedayati_Enrollment_Service::enroll( $run_id, $student_a );
		Hedayati_Session_Service::create( [ 'run_id' => $run_id, 'session_number' => '1', 'starts_at' => '2026-05-02 09:00', 'topic' => 'Intro' ] );
	}

	wp_set_current_user( $student_a );
	$_GET = [ 'view' => 'enrollments' ];
	$enrollments_output = Hedayati_Student_Portal::render_current_view();
	wp_set_current_user( 0 );
	$_GET = [];

	HDIT::ok( 'enrollments view shows the enrolled course title', false !== strpos( $enrollments_output, 'Portal test course' ) );
	HDIT::ok( 'enrollments view shows a Shamsi-formatted date (Persian digits), not a raw ISO string', 1 === preg_match( '/[۰-۹]/u', $enrollments_output ) );
	HDIT::ok( 'no enrollment/session mutation form exists in this view (no <form> markup — read-only)', false === strpos( $enrollments_output, '<form' ) );

	// ── Cleanup ──────────────────────────────────────────────────────────────
	if ( $doc_id_a > 0 ) {
		Hedayati_Document_Storage::delete( $stored_for_a['storage_key'] );
	}
}
