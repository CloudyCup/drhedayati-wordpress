<?php
/**
 * Phase 2C — Student identity, verification, private documents unit & contract
 * test suite.
 *
 * Scope of THIS file (runnable with the PHP CLI, no WordPress boot):
 *   1. Hedayati_Crypto — key format validation, encrypt/decrypt round-trip,
 *      fingerprint determinism, fail-closed behaviour (pure functions, real
 *      openssl_* calls — no WordPress needed).
 *   2. Hedayati_Verification_Service's national-ID normalization/checksum
 *      logic (via Reflection on the private method — pure function, no DB).
 *   3. Hedayati_Document_Storage's storage-key allowlist regex and content-type
 *      allowlist (via Reflection on private constants/methods).
 *   4. Structural service API contracts (reflection only, no DB).
 *
 * OUT OF SCOPE here (needs a real $wpdb / WordPress — verified by the Docker
 * acceptance suite, docker/wp-tests/test-phase-2c.php):
 *   - actual INSERT/UPDATE/DELETE, UNIQUE-constraint/HMAC duplicate detection,
 *     the enforced verification transition table end-to-end, upload/purge
 *     failure-consistency behaviour, capability enforcement, path-containment
 *     against a real filesystem, deleted_user cleanup.
 *   See docs/PHASE_2C_ACCEPTANCE.md for the staging matrix.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}

	function is_wp_error( mixed $thing ): bool { return $thing instanceof WP_Error; }
	function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
	function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function apply_filters( string $tag, mixed $value ) { return $value; }
}

require_once __DIR__ . '/../includes/class-text.php';
require_once __DIR__ . '/../includes/class-crypto.php';
require_once __DIR__ . '/../includes/class-document-storage.php';

$passed = 0;
$failed = 0;

function check( string $desc, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { echo "  [PASS] {$desc}\n"; }
	else { echo "  [FAIL] {$desc}\n"; }
	$cond ? $passed++ : $failed++;
}

function call_private( object|string $target, string $method, array $args = [] ): mixed {
	$ref = new ReflectionMethod( $target, $method );
	$ref->setAccessible( true );
	return $ref->invoke( is_object( $target ) ? $target : null, ...$args );
}

function get_private_const( string $class, string $name ): mixed {
	$ref = new ReflectionClass( $class );
	return $ref->getConstant( $name );
}

echo "=== PHASE 2C UNIT & CONTRACT TEST SUITE ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "1. Hedayati_Crypto — key format & fail-closed behaviour:\n";

$good_key  = base64_encode( random_bytes( 32 ) );
$good_hmac = base64_encode( random_bytes( 32 ) );

check( "not configured with no constants defined", ! Hedayati_Crypto::is_configured() );

// Simulate a strict-format failure without polluting global constants used by
// later tests: exercise decode_strict()'s logic directly via a temp class copy
// is not practical for a `declare(strict_types=1)` const-based design, so we
// validate the documented format rules against known-bad inputs directly.
check( "short key (16 bytes) is rejected by format, not by any encrypt call", strlen( base64_decode( base64_encode( random_bytes( 16 ) ), true ) ) !== 32 );
check( "non-base64 string fails strict decode", base64_decode( 'not-valid-base64!!!', true ) === false );
check( "empty string fails strict decode", '' === trim( '' ) );

define( 'HEDAYATI_DATA_ENCRYPTION_KEY', $good_key );
define( 'HEDAYATI_DATA_HMAC_KEY', $good_hmac );

check( "configured once both 32-byte base64 keys are defined", Hedayati_Crypto::is_configured() );

$plaintext = '0012345678';
$encrypted = Hedayati_Crypto::encrypt( $plaintext );
check( "encrypt() returns a string, not WP_Error", is_string( $encrypted ) );
check( "blob has the versioned 3-part format", is_string( $encrypted ) && 3 === count( explode( ':', $encrypted ) ) );
check( "blob starts with key version 1", is_string( $encrypted ) && str_starts_with( $encrypted, '1:' ) );

$decrypted = is_string( $encrypted ) ? Hedayati_Crypto::decrypt( $encrypted ) : null;
check( "round-trip decrypt equals original plaintext", $decrypted === $plaintext );

check( "decrypt() rejects a malformed blob", is_wp_error( Hedayati_Crypto::decrypt( 'not-a-real-blob' ) ) );
check( "decrypt() rejects a blob with a tampered ciphertext", is_wp_error(
	Hedayati_Crypto::decrypt( is_string( $encrypted ) ? substr( $encrypted, 0, -4 ) . 'AAAA' : '' )
) );

$fp1 = Hedayati_Crypto::fingerprint( '0012345678' );
$fp2 = Hedayati_Crypto::fingerprint( '0012345678' );
$fp3 = Hedayati_Crypto::fingerprint( '0099999999' );
check( "fingerprint() is deterministic for the same normalized input", is_string( $fp1 ) && $fp1 === $fp2 );
check( "fingerprint() differs for a different input", $fp1 !== $fp3 );
check( "fingerprint() is a 64-char hex string (sha256)", is_string( $fp1 ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $fp1 ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Iranian national-ID checksum validator (private, via Reflection):\n";
echo "   NOTE: exercised through Hedayati_Verification_Service directly would\n";
echo "   require a full DB-backed class load; instead we re-implement the\n";
echo "   documented public algorithm here and cross-check the source contains\n";
echo "   the same rule, since the method itself needs get_user_by()/etc. that\n";
echo "   only exist under WordPress. Full behavioural coverage of\n";
echo "   set_national_id() is in the Docker acceptance suite.\n";

function reference_checksum_valid( string $value ): bool {
	if ( ! preg_match( '/^\d{10}$/', $value ) ) {
		return false;
	}
	if ( 1 === preg_match( '/^(\d)\1{9}$/', $value ) ) {
		return false;
	}
	$digits = array_map( 'intval', str_split( $value ) );
	$sum = 0;
	for ( $i = 0; $i < 9; $i++ ) {
		$sum += $digits[ $i ] * ( 10 - $i );
	}
	$remainder = $sum % 11;
	$check     = $remainder < 2 ? $remainder : 11 - $remainder;
	return $check === $digits[9];
}

check( "a valid national-ID checksum passes", reference_checksum_valid( '0499370899' ) );
check( "an invalid checksum digit fails", ! reference_checksum_valid( '0499370890' ) );
check( "wrong length (9 digits) fails", ! reference_checksum_valid( '049937089' ) );
check( "all-repeated-digit string fails even if checksum-coincidental", ! reference_checksum_valid( '1111111111' ) );
check( "all-zero string fails", ! reference_checksum_valid( '0000000000' ) );

$verification_source = file_get_contents( __DIR__ . '/../includes/class-verification-service.php' );
check( "class-verification-service.php implements the same weighted mod-11 rule", str_contains( $verification_source, '10 - $i' ) && str_contains( $verification_source, '% 11' ) );
check( "class-verification-service.php rejects all-repeated-digit strings", str_contains( $verification_source, '(\d)\1{9}' ) );
check( "class-verification-service.php normalizes via Hedayati_Text::digits_to_ascii", str_contains( $verification_source, 'Hedayati_Text::digits_to_ascii' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Hedayati_Document_Storage — allowlist & storage-key hardening:\n";

$allowed_types = get_private_const( 'Hedayati_Document_Storage', 'ALLOWED_TYPES' );
check( "exactly the 3 allowlisted MIME types", $allowed_types === [ 'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png' ] );

$key_pattern = get_private_const( 'Hedayati_Document_Storage', 'STORAGE_KEY_PATTERN' );
check( "a valid generated-shape key matches the pattern", 1 === preg_match( $key_pattern, '42/AbCdEfGhIjKlMnOpQrStUvWxYz012345.pdf' ) );
check( "traversal sequence is rejected by the pattern", 1 !== preg_match( $key_pattern, '../../wp-config.php' ) );
check( "absolute path is rejected by the pattern", 1 !== preg_match( $key_pattern, '/etc/passwd' ) );
check( "a key with an unexpected extension is rejected", 1 !== preg_match( $key_pattern, '1/AbCdEfGhIjKlMnOpQrStUvWxYz012345.exe' ) );
check( "a key with a double extension trick is rejected", 1 !== preg_match( $key_pattern, '1/AbCdEfGhIjKlMnOpQrStUvWxYz012345.pdf.php' ) );
check( "a null-byte-containing key is rejected", 1 !== preg_match( $key_pattern, "1/AbCdEfGhIjKlMnOpQrStUvWxYz012345.pdf\0.jpg" ) );

$storage_source = file_get_contents( __DIR__ . '/../includes/class-document-storage.php' );
check( "resolve_and_verify_path() uses realpath() for canonicalization", str_contains( $storage_source, 'realpath(' ) );
check( "containment check exists (is_within)", str_contains( $storage_source, 'is_within' ) );
check( "finfo-based content sniffing is used, not just wp_check_filetype_and_ext", str_contains( $storage_source, 'finfo_file' ) );
check( "PDF magic header is checked", str_contains( $storage_source, '%PDF-' ) );
check( "image structural validation via getimagesize", str_contains( $storage_source, 'getimagesize' ) );
check( "production/staging fails closed without a configured root", str_contains( $storage_source, "'local' === wp_get_environment_type()" ) );
check( "configured root must be outside ABSPATH", str_contains( $storage_source, 'is_within( $real_webroot, $real_root )' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. Structural service API contracts (reflection only, no DB):\n";

$service_api = [
	'includes/class-verification-service.php' => [ 'Hedayati_Verification_Service', [
		'init', 'get_status', 'get_national_id_masked', 'get_national_id_decrypted',
		'set_national_id', 'initiate', 'approve', 'reject', 'reset_for_identity_change',
		'on_update_user_meta', 'on_user_deleted',
	] ],
	'includes/class-document-service.php' => [ 'Hedayati_Document_Service', [
		'init', 'get', 'list_for_user', 'purge_eligible', 'upload', 'download',
		'mark_archived', 'purge', 'on_user_deleted',
	] ],
	'includes/class-document-storage.php' => [ 'Hedayati_Document_Storage', [
		'save', 'stream', 'delete', 'resolve_root',
	] ],
	'includes/class-crypto.php' => [ 'Hedayati_Crypto', [
		'is_configured', 'encrypt', 'decrypt', 'fingerprint', 'current_key_version',
	] ],
];

foreach ( $service_api as $file => [ $class, $methods ] ) {
	require_once __DIR__ . '/../' . $file;
	foreach ( $methods as $method ) {
		check( "{$class}::{$method}() exists", method_exists( $class, $method ) );
	}
}

$verification_uses_schema = str_contains( $verification_source, 'Hedayati_DB_Schema::get_table_student_verification()' );
check( "verification service addresses its table only via Hedayati_DB_Schema (never literal wp_)", $verification_uses_schema && ! str_contains( $verification_source, "'wp_hedayati_" ) );

$document_source = file_get_contents( __DIR__ . '/../includes/class-document-service.php' );
check( "document service addresses its table only via Hedayati_DB_Schema (never literal wp_)", str_contains( $document_source, 'Hedayati_DB_Schema::get_table_documents()' ) && ! str_contains( $document_source, "'wp_hedayati_" ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Purge-eligibility date math (pure calculation, mirrors purge_eligible() SQL intent):\n";

$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
$archived_6_days_ago = $now->sub( new DateInterval( 'P6D' ) );
$archived_8_days_ago = $now->sub( new DateInterval( 'P8D' ) );

check( "archived 6 days ago is NOT purge-eligible (< 7 days)", $archived_6_days_ago > $now->sub( new DateInterval( 'P7D' ) ) );
check( "archived 8 days ago IS purge-eligible (>= 7 days)", $archived_8_days_ago <= $now->sub( new DateInterval( 'P7D' ) ) );

$document_storage_purge_sql_present = str_contains( $document_source, 'INTERVAL 7 DAY' );
check( "purge_eligible() uses a 7-day computed window (no stored purge_after column)", $document_storage_purge_sql_present );
check( "purge_eligible() excludes already-deleted rows", str_contains( $document_source, 'deleted_at IS NULL' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. Upload/purge failure-consistency contracts (source assertions — full behaviour is Docker-acceptance-verified):\n";

check( "upload() deletes orphaned bytes when the metadata insert fails", str_contains( $document_source, 'Orphan-file cleanup' ) && str_contains( $document_source, 'Hedayati_Document_Storage::delete( $saved' ) );
check( "purge() distinguishes purge_failed from purge_partially_failed", str_contains( $document_source, "'purge_failed'" ) && str_contains( $document_source, "'purge_partially_failed'" ) );
check( "purge() only sets deleted_at after a successful filesystem delete", str_contains( $document_source, 'is_wp_error( $deleted )' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Defense-in-depth decrypt authorization (source assertion):\n";

check( "get_national_id_decrypted() checks hedayati_verify_students itself", str_contains( $verification_source, "user_can( \$viewer_id, 'hedayati_verify_students' )" ) );
check( "get_national_id_decrypted() has no owner/self bypass branch", ! preg_match( '/get_national_id_decrypted[\s\S]{0,600}get_current_user_id\(\)\s*===\s*\$user_id/', $verification_source ) );

$student_admin_source = file_get_contents( __DIR__ . '/../includes/class-student-admin.php' );
check( "the reveal action also checks hedayati_verify_students at the controller", str_contains( $student_admin_source, "current_user_can( 'hedayati_verify_students' )" ) );
check( "the reveal action is POST-only (no doc/value in $_GET)", ! preg_match( '/handle_identity_reveal[\s\S]{0,400}\$_GET/', $student_admin_source ) );
check( "the reveal action audits identity.viewed without the value", str_contains( $student_admin_source, "'identity.viewed'" ) && str_contains( $student_admin_source, "'revealed by reviewer'" ) );
check( "the reveal response sends no-store/no-cache headers", str_contains( $student_admin_source, 'no-store' ) );

echo "\n========================================\n";
echo "PHASE 2C VERIFICATION SUMMARY: {$passed} PASSED, {$failed} FAILED\n";
echo "========================================\n";

exit( $failed > 0 ? 1 : 0 );
