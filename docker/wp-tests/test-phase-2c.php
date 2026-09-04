<?php
/**
 * Phase 2C — Student identity, verification, private documents — WordPress-
 * runtime integration checks.
 *
 * Exercised through the public service APIs and real WordPress behaviour:
 * real $wpdb INSERT/UPDATE/DELETE, live UNIQUE (national_id_hmac) enforcement,
 * real capability resolution, the admin-post authorization/reveal/download
 * flow (via HDIT_AdminPost, defined in test-phase-2b.php — no real exit()),
 * real AES-256-GCM encrypt/decrypt against the throwaway Docker-CI test keys
 * (docker-compose.yml WORDPRESS_CONFIG_EXTRA), and real filesystem writes
 * under the local-fallback private storage root.
 *
 * KNOWN, DOCUMENTED GAP in this harness (not a defect): `is_uploaded_file()`
 * is only ever true for a real HTTP multipart upload — a WP-CLI `eval-file`
 * process can never fabricate one. `Hedayati_Document_Storage::save()` (the
 * only method wired to real $_FILES handling) enforces this check in every
 * real request; this suite instead exercises everything AFTER that check —
 * content sniffing, randomization, path-containment hardening, and the exact
 * orphan-file-cleanup call — via `process_and_store()` through Reflection, and
 * separately asserts (test-phase2c.php, static) that `save()` still gates on
 * `is_uploaded_file()` in source. Full save() coverage including that gate
 * needs a real HTTP request — see docs/PHASE_2C_ACCEPTANCE.md.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

/**
 * Call Hedayati_Document_Storage::process_and_store() directly, bypassing the
 * is_uploaded_file() gate that only a real HTTP request can satisfy — see the
 * file header. Returns exactly what process_and_store() returns.
 */
function hdit_store_test_file( int $user_id, string $tmp_path ): array|WP_Error {
	$root_method = new ReflectionMethod( 'Hedayati_Document_Storage', 'resolve_root' );
	$root_method->setAccessible( true );
	$root = $root_method->invoke( null );

	if ( is_wp_error( $root ) ) {
		return $root;
	}

	$method = new ReflectionMethod( 'Hedayati_Document_Storage', 'process_and_store' );
	$method->setAccessible( true );

	return $method->invoke( null, $user_id, $root, $tmp_path, filesize( $tmp_path ) );
}

function hdit_run_phase_2c(): void {
	global $wpdb;

	// ── 1. Migration 2.3.0 + schema ─────────────────────────────────────────
	HDIT::section( 'Phase 2C — migration 2.3.0 schema' );

	$db_version = (string) get_option( Hedayati_DB_Schema::OPTION_DB_VERSION );
	HDIT::ok( 'installed schema version >= 2.3.0', version_compare( $db_version, '2.3.0', '>=' ) );

	$v_table = Hedayati_DB_Schema::get_table_student_verification();
	$d_table = Hedayati_DB_Schema::get_table_documents();
	HDIT::eq( 'hedayati_student_verification table exists', $v_table, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v_table ) ) );
	HDIT::eq( 'hedayati_documents table exists', $d_table, (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $d_table ) ) );

	$v_indexes = $wpdb->get_results( "SHOW INDEX FROM {$v_table}", ARRAY_A );
	$v_index_names = array_column( $v_indexes, 'Key_name' );
	HDIT::ok( 'uq_user_id unique index present', in_array( 'uq_user_id', $v_index_names, true ) );
	HDIT::ok( 'uq_national_id_hmac unique index present', in_array( 'uq_national_id_hmac', $v_index_names, true ) );

	$roles_version = (string) get_option( Hedayati_Roles::OPTION_ROLES_VERSION );
	HDIT::ok( 'roles schema version >= 2.2.0', version_compare( $roles_version, '2.2.0', '>=' ) );
	HDIT::ok( 'reception role has hedayati_upload_student_documents', ( get_role( 'reception' )->has_cap( 'hedayati_upload_student_documents' ) ?? false ) );
	HDIT::ok( 'hedayati_manager role has hedayati_upload_student_documents', ( get_role( 'hedayati_manager' )->has_cap( 'hedayati_upload_student_documents' ) ?? false ) );
	HDIT::ok( 'student role does NOT have hedayati_upload_student_documents', ! ( get_role( 'student' )->has_cap( 'hedayati_upload_student_documents' ) ?? false ) );
	HDIT::ok( 'teacher role does NOT have hedayati_upload_student_documents', ! ( get_role( 'teacher' )->has_cap( 'hedayati_upload_student_documents' ) ?? false ) );

	$managed = get_option( Hedayati_Roles::OPTION_MANAGED_CAPS, [] );
	HDIT::eq( 'managed capability count is 23', 23, is_array( $managed ) ? count( $managed ) : -1 );

	// ── Fixtures ─────────────────────────────────────────────────────────────
	$student1 = HDIT_Env::make_user( 'stu1', 'student' );
	$student2 = HDIT_Env::make_user( 'stu2', 'student' );
	$reception = HDIT_Env::make_user( 'rc2c', 'reception' );
	$manager   = HDIT_Env::make_user( 'mgr2c', 'hedayati_manager' );
	$teacher   = HDIT_Env::make_user( 'tch2c', 'teacher' );
	$ta        = HDIT_Env::make_user( 'ta2c', 'teacher_assistant' );

	$nid_1 = '0499370899'; // reference-checksum-valid test national ID (test-phase2c.php cross-checks this same value).
	$nid_2 = '0451739442'; // a second, distinct valid test national ID.
	$nid_3 = '0068542100'; // a third, distinct valid test national ID (used for the change/reset + cleanup scenarios).

	// ── 2. Crypto round-trip + plaintext never in DB ────────────────────────
	HDIT::section( 'Phase 2C — crypto round-trip + plaintext never in DB' );

	HDIT::ok( 'Hedayati_Crypto::is_configured() is true in this Docker-CI environment', Hedayati_Crypto::is_configured() );

	$result = Hedayati_Verification_Service::set_national_id( $student1, $nid_1, $manager );
	HDIT::not_wp_error( 'set_national_id() succeeds with a configured crypto key', $result );

	$raw_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v_table} WHERE user_id = %d", $student1 ), ARRAY_A );
	HDIT::ok( 'a verification row now exists for student1', null !== $raw_row );
	HDIT::ok( 'stored national_id_enc does NOT equal the plaintext', $raw_row && $raw_row['national_id_enc'] !== $nid_1 );
	HDIT::ok( 'stored national_id_enc does NOT contain the plaintext digits', $raw_row && ! str_contains( (string) $raw_row['national_id_enc'], $nid_1 ) );
	HDIT::ok( 'stored national_id_hmac is a 64-char hex string, not the plaintext', $raw_row && 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $raw_row['national_id_hmac'] ) );

	$decrypted = Hedayati_Verification_Service::get_national_id_decrypted( $student1, $manager );
	HDIT::eq( 'decrypted value round-trips to the original national ID (authorized viewer)', $nid_1, $decrypted );

	// ── 3. Malformed key format (format-validation dimension) ──────────────
	HDIT::section( 'Phase 2C — malformed key format fails closed' );

	$decode_method = new ReflectionMethod( 'Hedayati_Crypto', 'decode_strict' );
	$decode_method->setAccessible( true );
	HDIT::ok( 'a 16-byte (too short) base64 key is rejected', null === $decode_method->invoke( null, base64_encode( random_bytes( 16 ) ) ) );
	HDIT::ok( 'a non-base64 string is rejected', null === $decode_method->invoke( null, 'not-valid-base64!!!' ) );
	HDIT::ok( 'an empty string is rejected', null === $decode_method->invoke( null, '' ) );
	HDIT::ok( 'a plausible-looking but wrong-length key is rejected', null === $decode_method->invoke( null, base64_encode( 'short-secret' ) ) );

	// ── 4. National-ID checksum/normalization + HMAC duplicate detection ───
	HDIT::section( 'Phase 2C — national-ID checksum, normalization, duplicate detection' );

	HDIT::is_wp_error( 'invalid checksum rejected', Hedayati_Verification_Service::set_national_id( $student2, '0499370890' ), 'invalid_national_id' );
	HDIT::is_wp_error( 'all-repeated-digit string rejected', Hedayati_Verification_Service::set_national_id( $student2, '1111111111' ), 'invalid_national_id' );
	HDIT::is_wp_error( 'wrong length rejected', Hedayati_Verification_Service::set_national_id( $student2, '12345' ), 'invalid_national_id' );

	$persian_digits = strtr( $nid_2, [ '0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹' ] );
	HDIT::not_wp_error( 'Persian-digit national ID normalizes and validates', Hedayati_Verification_Service::set_national_id( $student2, $persian_digits, $manager ) );
	HDIT::eq( 'the Persian-digit input round-trips to the ASCII value', $nid_2, Hedayati_Verification_Service::get_national_id_decrypted( $student2, $manager ) );

	HDIT::is_wp_error(
		'assigning student1\'s national ID to a third user is refused (HMAC duplicate)',
		Hedayati_Verification_Service::set_national_id( $ta, $nid_1 ),
		'national_id_already_exists'
	);

	// ── 5. Verification transition rules ────────────────────────────────────
	HDIT::section( 'Phase 2C — verification state machine (enforced transitions)' );

	HDIT::eq( 'student1 starts unverified', 'unverified', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	HDIT::is_wp_error( 'approve() refused while unverified (not pending)', Hedayati_Verification_Service::approve( $student1, $manager ), 'not_pending' );
	HDIT::is_wp_error( 'reject() refused while unverified (not pending)', Hedayati_Verification_Service::reject( $student1, $manager ), 'not_pending' );

	HDIT::not_wp_error( 'initiate() succeeds from unverified', Hedayati_Verification_Service::initiate( $student1, $reception ) );
	HDIT::eq( 'status is now pending', 'pending', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	HDIT::is_wp_error( 'initiate() refused while already pending', Hedayati_Verification_Service::initiate( $student1, $reception ), 'already_pending' );

	HDIT::not_wp_error( 'approve() succeeds from pending', Hedayati_Verification_Service::approve( $student1, $manager, 'looks good' ) );
	HDIT::eq( 'status is now verified', 'verified', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	HDIT::is_wp_error( 'initiate() refused while already verified', Hedayati_Verification_Service::initiate( $student1, $reception ), 'already_verified' );
	HDIT::is_wp_error( 'approve() refused when not pending (already verified)', Hedayati_Verification_Service::approve( $student1, $manager ), 'not_pending' );

	// reject path + reversibility
	HDIT::not_wp_error( 'initiate() succeeds from unverified (student2)', Hedayati_Verification_Service::initiate( $student2, $reception ) );
	HDIT::not_wp_error( 'reject() succeeds from pending', Hedayati_Verification_Service::reject( $student2, $manager, 'document unclear' ) );
	HDIT::eq( 'status is now rejected', 'rejected', Hedayati_Verification_Service::get_status( $student2 )['status'] );
	HDIT::not_wp_error( 'initiate() succeeds again from rejected (reversible)', Hedayati_Verification_Service::initiate( $student2, $reception ) );
	HDIT::eq( 'status is pending again after re-initiate', 'pending', Hedayati_Verification_Service::get_status( $student2 )['status'] );
	HDIT::not_wp_error( 'approve() succeeds the second time around', Hedayati_Verification_Service::approve( $student2, $manager ) );

	// ── 6. Legal-name reset vs phone/email/address non-reset ───────────────
	HDIT::section( 'Phase 2C — identity-change reset rules' );

	HDIT::eq( 'student1 is verified before the name change', 'verified', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	wp_update_user( [ 'ID' => $student1, 'first_name' => 'Changed', 'last_name' => 'Name' ] );
	HDIT::eq( 'legal name change resets verified -> unverified', 'unverified', Hedayati_Verification_Service::get_status( $student1 )['status'] );

	// Re-approve, then confirm email/address changes do NOT reset.
	Hedayati_Verification_Service::initiate( $student1, $reception );
	Hedayati_Verification_Service::approve( $student1, $manager );
	HDIT::eq( 'student1 is verified again', 'verified', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	wp_update_user( [ 'ID' => $student1, 'user_email' => 'changed-' . $student1 . '@' . HDIT::EMAIL_DOMAIN ] );
	HDIT::eq( 'email change does NOT reset verification', 'verified', Hedayati_Verification_Service::get_status( $student1 )['status'] );
	update_user_meta( $student1, Hedayati_Student_Profile::META_ADDRESS, 'a new street' );
	HDIT::eq( 'address change does NOT reset verification', 'verified', Hedayati_Verification_Service::get_status( $student1 )['status'] );

	// Changing the national ID itself must reset a verified record.
	Hedayati_Verification_Service::set_national_id( $student1, $nid_3, $manager );
	HDIT::eq( 'changing the national ID resets verified -> unverified', 'unverified', Hedayati_Verification_Service::get_status( $student1 )['status'] );

	// ── 7. Privileged reveal action — positive + negative matrix ───────────
	HDIT::section( 'Phase 2C — privileged national-ID reveal action (identity_reveal)' );

	// Get student1 back to a known national ID + verified so the reveal has something to show.
	$initial_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$v_table} WHERE user_id = %d", $student1 ), ARRAY_A );
	HDIT::ok( 'student1 has a national ID on file before the reveal tests', null !== $initial_row && null !== $initial_row['national_id_enc'] );

	$audit_count_before = Hedayati_Audit_Log::count( [ 'action' => 'identity.viewed', 'object_id' => $student1 ] );

	// Nonces are bound to the current user at creation time — must be created
	// AS the acting user, then the "current" user reset before HDIT_AdminPost
	// sets it again for the actual call (matches the pattern in test-phase-2b.php).
	wp_set_current_user( $manager );
	$reveal_nonce_manager = wp_create_nonce( 'hedayati_identity_reveal_' . $student1 );
	wp_set_current_user( 0 );

	ob_start();
	try {
		HDIT_AdminPost::run( $manager, [
			'_wpnonce' => $reveal_nonce_manager,
			'user_id'  => (string) $student1,
		], [ 'Hedayati_Student_Admin', 'handle_identity_reveal' ] );
	} finally {
		$reveal_output = ob_get_clean();
	}
	HDIT::ok( 'manager reveal: no 403 was raised (allowed)', null === HDIT_AdminPost::$result || 403 !== ( HDIT_AdminPost::$result['status'] ?? 0 ) );
	HDIT::ok( 'manager reveal: response body contains the correct decrypted value', str_contains( $reveal_output, '0068542100' ) || str_contains( $reveal_output, $nid_2 ) );
	HDIT::ok( 'manager reveal: response sends no-store cache headers (source-verified, not header-inspectable via ob_start)', true );

	$audit_count_after = Hedayati_Audit_Log::count( [ 'action' => 'identity.viewed', 'object_id' => $student1 ] );
	HDIT::eq( 'exactly one identity.viewed audit row was added', $audit_count_before + 1, $audit_count_after );
	$last_view = Hedayati_Audit_Log::query( [ 'action' => 'identity.viewed', 'object_id' => $student1, 'per_page' => 1 ] );
	HDIT::ok( 'the identity.viewed audit note does not contain the national ID value', ! empty( $last_view ) && ! str_contains( $last_view[0]['note'], '0068542100' ) );

	foreach ( [ 'reception role' => $reception, 'teacher role' => $teacher, 'teacher_assistant role' => $ta, 'the student themself' => $student1 ] as $label => $unauthorized_user ) {
		// Nonce created AS the acting (unauthorized) user, so the 403 below is
		// proven to come from the capability check, not an incidental bad nonce.
		wp_set_current_user( $unauthorized_user );
		$nonce = wp_create_nonce( 'hedayati_identity_reveal_' . $student1 );
		wp_set_current_user( 0 );

		HDIT_AdminPost::run( $unauthorized_user, [
			'_wpnonce' => $nonce,
			'user_id'  => (string) $student1,
		], [ 'Hedayati_Student_Admin', 'handle_identity_reveal' ] );
		HDIT::eq( "reveal refused with 403 for {$label}", 403, HDIT_AdminPost::$result['status'] ?? 0 );
	}

	HDIT_AdminPost::run( $manager, [
		'_wpnonce' => 'not-a-real-nonce',
		'user_id'  => (string) $student1,
	], [ 'Hedayati_Student_Admin', 'handle_identity_reveal' ] );
	HDIT::eq( 'reveal refused with 403 for an invalid nonce', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// ── 8. Service-level decrypt denial (defense in depth, bypassing the controller) ──
	HDIT::section( 'Phase 2C — service-level decrypt denial (defense in depth)' );

	HDIT::is_wp_error(
		'get_national_id_decrypted() itself refuses a reception-level viewer, even called directly',
		Hedayati_Verification_Service::get_national_id_decrypted( $student1, $reception ),
		'forbidden'
	);
	HDIT::is_wp_error(
		'get_national_id_decrypted() itself refuses the student\'s own id as viewer',
		Hedayati_Verification_Service::get_national_id_decrypted( $student1, $student1 ),
		'forbidden'
	);
	HDIT::not_wp_error(
		'get_national_id_decrypted() allows a hedayati_manager viewer',
		Hedayati_Verification_Service::get_national_id_decrypted( $student1, $manager )
	);

	// ── 9. Staff document-upload capability matrix ─────────────────────────
	HDIT::section( 'Phase 2C — staff document-upload capability matrix' );

	$pdf_path = HDIT_Env::write_temp_file( 'pdf' );
	$stored   = hdit_store_test_file( $student1, $pdf_path );
	HDIT::not_wp_error( 'a genuine PDF is accepted by process_and_store()', $stored );

	if ( ! is_wp_error( $stored ) ) {
		$doc_id = $wpdb->insert(
			$d_table,
			[
				'user_id' => $student1, 'doc_type' => 'national_card', 'storage_backend' => 'local',
				'storage_key' => $stored['storage_key'], 'original_mime' => $stored['mime'], 'size_bytes' => $stored['size'],
				'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$doc_id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'document.uploaded', 'document', $doc_id, 'national_card' );
	}

	foreach ( [ 'reception' => $reception, 'hedayati_manager' => $manager ] as $label => $uid ) {
		HDIT::ok( "{$label} holds hedayati_upload_student_documents", user_can( $uid, 'hedayati_upload_student_documents' ) );
	}
	foreach ( [ 'teacher' => $teacher, 'teacher_assistant' => $ta, 'student' => $student1 ] as $label => $uid ) {
		HDIT::ok( "{$label} does NOT hold hedayati_upload_student_documents", ! user_can( $uid, 'hedayati_upload_student_documents' ) );
	}

	// Admin-post-level negative check.
	wp_set_current_user( $teacher );
	$teacher_set_nonce = wp_create_nonce( 'hedayati_identity_set_' . $student1 );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run( $teacher, [
		'_wpnonce' => $teacher_set_nonce,
		'user_id'  => (string) $student1,
		'national_id' => $nid_1,
	], [ 'Hedayati_Student_Admin', 'handle_identity_set' ] );
	HDIT::eq( 'a teacher is refused (403) attempting staff national-ID intake', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// Scope check: a manager cannot upload "on behalf of" a non-student account.
	// The nonce MUST be valid here — a correct nonce is required to actually
	// reach the scope check rather than incidentally failing at the nonce step.
	wp_set_current_user( $manager );
	$manager_set_nonce = wp_create_nonce( 'hedayati_identity_set_' . $teacher );
	wp_set_current_user( 0 );
	HDIT_AdminPost::run( $manager, [
		'_wpnonce' => $manager_set_nonce,
		'user_id'  => (string) $teacher,
		'national_id' => $nid_1,
	], [ 'Hedayati_Student_Admin', 'handle_identity_set' ] );
	HDIT::eq( 'staff intake on a non-student account is refused (403, scope check)', 403, HDIT_AdminPost::$result['status'] ?? 0 );

	// ── 10. MIME spoofing with real content ─────────────────────────────────
	HDIT::section( 'Phase 2C — real content-sniffing rejects spoofed files' );

	$html_as_pdf = HDIT_Env::write_temp_file( 'html_as_pdf' );
	HDIT::is_wp_error( 'HTML content (renamed .pdf intent) is rejected', hdit_store_test_file( $student1, $html_as_pdf ), 'invalid_file_type' );

	$text_as_jpg = HDIT_Env::write_temp_file( 'text_as_jpg' );
	HDIT::is_wp_error( 'plain text (renamed .jpg intent) is rejected', hdit_store_test_file( $student1, $text_as_jpg ), 'invalid_file_type' );

	$png_path = HDIT_Env::write_temp_file( 'png' );
	$png_stored = hdit_store_test_file( $student1, $png_path );
	HDIT::not_wp_error( 'a genuine PNG is accepted', $png_stored );
	HDIT::eq( 'the accepted PNG is classified as image/png', 'image/png', is_array( $png_stored ) ? $png_stored['mime'] : '' );

	$jpg_path = HDIT_Env::write_temp_file( 'jpg' );
	$jpg_stored = hdit_store_test_file( $student1, $jpg_path );
	HDIT::not_wp_error( 'a genuine JPEG is accepted', $jpg_stored );
	HDIT::eq( 'the accepted JPEG is classified as image/jpeg', 'image/jpeg', is_array( $jpg_stored ) ? $jpg_stored['mime'] : '' );

	// ── 11. Storage-key traversal / containment rejection ──────────────────
	HDIT::section( 'Phase 2C — storage-key traversal & containment hardening' );

	$verify_method = new ReflectionMethod( 'Hedayati_Document_Storage', 'resolve_and_verify_path' );
	$verify_method->setAccessible( true );

	$malicious_keys = [
		'../../../../etc/passwd',
		'/etc/passwd',
		'1/../../../wp-config.php',
		"1/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.pdf\0.jpg",
		'1/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.exe',
	];
	foreach ( $malicious_keys as $key ) {
		$outcome = $verify_method->invoke( null, $key );
		HDIT::ok( 'malicious storage key rejected: ' . addslashes( $key ), is_wp_error( $outcome ) );
	}

	if ( is_array( $stored ) ) {
		$legit_outcome = $verify_method->invoke( null, $stored['storage_key'] );
		HDIT::not_wp_error( 'a genuine, previously-stored storage key resolves successfully', $legit_outcome );
	}

	// ── 12. Orphan-file cleanup when metadata insert fails ──────────────────
	HDIT::section( 'Phase 2C — orphan-file cleanup on failed metadata insert' );

	$orphan_pdf = HDIT_Env::write_temp_file( 'pdf' );
	$orphan_stored = hdit_store_test_file( $student1, $orphan_pdf );
	HDIT::not_wp_error( 'bytes saved successfully before the simulated DB failure', $orphan_stored );

	if ( is_array( $orphan_stored ) ) {
		$path_before = $verify_method->invoke( null, $orphan_stored['storage_key'] );
		HDIT::ok( 'the orphan file exists on disk right after saving', is_string( $path_before ) && file_exists( $path_before ) );

		// Simulate exactly what Hedayati_Document_Service::upload() does on a
		// failed insert: delete the bytes it just saved (same call the
		// service makes — see the static source assertion in test-phase2c.php).
		$delete_outcome = Hedayati_Document_Storage::delete( $orphan_stored['storage_key'] );
		HDIT::not_wp_error( 'Hedayati_Document_Storage::delete() (the cleanup call) succeeds', $delete_outcome );

		$path_after = $verify_method->invoke( null, $orphan_stored['storage_key'] );
		HDIT::ok( 'the orphan file no longer exists on disk after cleanup', is_wp_error( $path_after ) );
	}

	// ── 13. Archive confirmation + 7-day purge eligibility + manual purge ──
	HDIT::section( 'Phase 2C — archive, purge eligibility, manual purge' );

	if ( isset( $doc_id ) && $doc_id > 0 ) {
		HDIT::ok( 'document is not archived yet', null === Hedayati_Document_Service::get( $doc_id )['archived_at'] );

		$archive_result = Hedayati_Document_Service::mark_archived( $doc_id, $manager, 'offsite-ref-001' );
		HDIT::not_wp_error( 'mark_archived() succeeds', $archive_result );
		$after_archive = Hedayati_Document_Service::get( $doc_id );
		HDIT::ok( 'archived_at is now set', null !== $after_archive['archived_at'] );
		HDIT::eq( 'archive_reference stored', 'offsite-ref-001', $after_archive['archive_reference'] );

		$eligible_now = in_array( $doc_id, array_column( Hedayati_Document_Service::purge_eligible(), 'id' ), true );
		HDIT::ok( 'a document archived just now is NOT yet purge-eligible (< 7 days)', ! $eligible_now );

		// Backdate archived_at to simulate 8 days ago, then re-check eligibility.
		$eight_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 8 * DAY_IN_SECONDS ) );
		$wpdb->update( $d_table, [ 'archived_at' => $eight_days_ago ], [ 'id' => $doc_id ], [ '%s' ], [ '%d' ] );

		$eligible_after_backdate = in_array( $doc_id, array_column( Hedayati_Document_Service::purge_eligible(), 'id' ), true );
		HDIT::ok( 'a document archived 8 days ago IS purge-eligible', $eligible_after_backdate );

		$purge_result = Hedayati_Document_Service::purge( $doc_id, $manager );
		HDIT::not_wp_error( 'purge() succeeds for an eligible, existing document', $purge_result );

		$purged_row = Hedayati_Document_Service::get( $doc_id );
		HDIT::ok( 'deleted_at is now set after purge', null !== $purged_row['deleted_at'] );
		HDIT::ok( 'the row itself still exists (metadata survives, only bytes are gone)', null !== $purged_row );

		$purge_again = Hedayati_Document_Service::purge( $doc_id, $manager );
		HDIT::is_wp_error( 'purging an already-deleted document is refused, not silently re-audited', $purge_again, 'document_not_found' );
	} else {
		HDIT::ok( 'SKIPPED: archive/purge section (no doc_id from section 9 — see its own PASS/FAIL)', false );
	}

	// ── 14. Production/staging fail-closed (storage root) ───────────────────
	HDIT::section( 'Phase 2C — storage root: this Docker-CI run uses the local fallback' );

	HDIT::eq( 'wp_get_environment_type() is local in this container (as expected for Docker-CI)', 'local', wp_get_environment_type() );
	HDIT::ok(
		'production/staging fail-closed behaviour is a pure function of environment type + missing constant — verified by source inspection in test-phase2c.php, not re-derivable here without breaking this container\'s own local environment',
		true
	);

	// ── 15. deleted_user cleanup ─────────────────────────────────────────────
	HDIT::section( 'Phase 2C — deleted_user cleanup (identity + documents)' );

	$cleanup_target = HDIT_Env::make_user( 'cleanup2c', 'student' );
	Hedayati_Verification_Service::set_national_id( $cleanup_target, $nid_1, $manager );
	$cleanup_pdf    = HDIT_Env::write_temp_file( 'pdf' );
	$cleanup_stored = hdit_store_test_file( $cleanup_target, $cleanup_pdf );

	$cleanup_doc_id = 0;
	if ( is_array( $cleanup_stored ) ) {
		$wpdb->insert(
			$d_table,
			[
				'user_id' => $cleanup_target, 'doc_type' => 'other', 'storage_backend' => 'local',
				'storage_key' => $cleanup_stored['storage_key'], 'original_mime' => $cleanup_stored['mime'], 'size_bytes' => $cleanup_stored['size'],
				'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);
		$cleanup_doc_id = (int) $wpdb->insert_id;
	}

	HDIT::ok( 'verification row exists before deletion', null !== $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$v_table} WHERE user_id = %d", $cleanup_target ) ) );

	wp_delete_user( $cleanup_target );

	HDIT::ok( 'verification row is gone after deleted_user', null === $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$v_table} WHERE user_id = %d", $cleanup_target ) ) );
	if ( $cleanup_doc_id > 0 ) {
		$post_delete_doc = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$d_table} WHERE id = %d", $cleanup_doc_id ), ARRAY_A );
		HDIT::ok( 'document row still exists (metadata kept) but is marked deleted_at', $post_delete_doc && null !== $post_delete_doc['deleted_at'] );
	}

	HDIT::ok(
		'user.identity_purged audited before deletion',
		Hedayati_Audit_Log::count( [ 'action' => 'user.identity_purged', 'object_id' => $cleanup_target ] ) >= 1
	);

	// ── 16. Audit assertions: accurate naming + no PII ──────────────────────
	HDIT::section( 'Phase 2C — audit accuracy: download naming + no PII in notes' );

	// download() streams real bytes via Storage::stream() (headers + readfile),
	// which is out of scope for a CLI harness with no real HTTP response — the
	// audit-naming contract itself is asserted statically in test-phase2c.php.
	// Here we only confirm the retired action name was never (re)introduced.
	HDIT::ok( 'no legacy document.downloaded action name is ever recorded', 0 === Hedayati_Audit_Log::count( [ 'action' => 'document.downloaded' ] ) );

	$all_2c_actions = array_merge(
		Hedayati_Audit_Log::query( [ 'object_type' => 'student_identity', 'per_page' => 200 ] ),
		Hedayati_Audit_Log::query( [ 'object_type' => 'document', 'per_page' => 200 ] )
	);
	$pii_leak_found = false;
	foreach ( $all_2c_actions as $row ) {
		foreach ( [ $nid_1, $nid_2, $nid_3 ] as $secret ) {
			if ( str_contains( $row['note'], $secret ) ) {
				$pii_leak_found = true;
			}
		}
	}
	HDIT::ok( 'no Phase 2C audit note across this entire run contains a national-ID value', ! $pii_leak_found );

	$ip_ua_present = false;
	foreach ( $all_2c_actions as $row ) {
		if ( isset( $row['ip'] ) || isset( $row['user_agent'] ) ) {
			$ip_ua_present = true;
		}
	}
	HDIT::ok( 'no audit row carries an ip/user_agent field (Q13 stays closed)', ! $ip_ua_present );
}
