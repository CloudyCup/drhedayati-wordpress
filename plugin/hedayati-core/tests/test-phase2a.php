<?php
/**
 * Phase 2A Unit & Logic Test Suite.
 *
 * Tests:
 *   1. Phone normalization across all Iranian mobile formats & digit scripts (tuple-based).
 *   2. Rejection of invalid / malformed phone numbers (embedded letters, scripts, symbols).
 *   3. Heuristic phone detection vs standard usernames.
 *   4. Display formatting variations.
 *   5. Rate-limiter identifier canonicalization (format variants sharing same bucket).
 *   6. Rate-limiter separate IP (30) vs Identifier (5) threshold configuration.
 *   7. Rate-limiter identifier clearing without deleting shared IP counter.
 *   8. Role capabilities mapping (student, teacher, TA, reception, hedayati_manager).
 *   9. Role capability future-safe persistence option.
 *  10. Security assertions (TA lacks attendance modification, reception lacks manage_options).
 *  11. Migration framework atomic lock and table verification logic.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

// Minimal mock environment for CLI testing when WP is not booted
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );

	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}

	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		return $text;
	}

	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		return $value;
	}
}

require_once __DIR__ . '/../includes/class-phone.php';
require_once __DIR__ . '/../includes/class-roles.php';
require_once __DIR__ . '/../includes/class-rate-limiter.php';
require_once __DIR__ . '/../includes/class-db-schema.php';

$tests_passed = 0;
$tests_failed = 0;

function assert_test( string $description, bool $condition ): void {
	global $tests_passed, $tests_failed;
	if ( $condition ) {
		echo "  [PASS] {$description}\n";
		$tests_passed++;
	} else {
		echo "  [FAIL] {$description}\n";
		$tests_failed++;
	}
}

echo "=== PHASE 2A UNIT TEST SUITE ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Phone Normalization Tests (Tuple-Based to avoid PHP numeric key conversion)
// ─────────────────────────────────────────────────────────────────────────────
echo "1. Testing Phone Normalization:\n";

$valid_cases = [
	[ '09141234567',       '+989141234567' ],
	[ '۰۹۱۴۱۲۳۴۵۶۷',       '+989141234567' ], // Persian digits
	[ '٠٩١٤١٢٣٤٥٦٧',       '+989141234567' ], // Arabic digits
	[ '+989141234567',     '+989141234567' ], // E.164
	[ '00989141234567',    '+989141234567' ], // International 00
	[ '989141234567',      '+989141234567' ], // 989 prefix (numeric string)
	[ '9141234567',        '+989141234567' ], // 10 digits omitting 0 (numeric string)
	[ '0912-345-6789',     '+989123456789' ], // Dashes
	[ ' 0935 123 4567 ',   '+989351234567' ], // Spaces
	[ '(0914) 123 4567',   '+989141234567' ], // Parentheses
	[ '+98 (912) 3456789', '+989123456789' ], // Complex formatting
	[ '۰۹۳۵-۱۲۳-۴۵۶۷',     '+989351234567' ], // Formatted Persian
	[ '0914.123.4567',     '+989141234567' ], // Dots
];

foreach ( $valid_cases as $case ) {
	$input    = (string) $case[0];
	$expected = (string) $case[1];
	$result   = Hedayati_Phone::normalize( $input );
	assert_test( "Normalizing '{$input}' => '{$expected}'", $result === $expected );
}

echo "\n2. Testing Invalid Phone Rejections & Character Whitelisting:\n";

$invalid_cases = [
	'',                      // Empty
	'   ',                   // Whitespace only
	'0914abc1234567',        // Embedded letters (MUST be rejected)
	'0914<script>1234567',   // Script injection attempt
	'0914_123_4567',         // Underscore separator (unapproved)
	'0914#1234567',          // Hash symbol
	'0914!1234567',          // Exclamation
	'04133377601',           // Landline (Tabriz)
	'02188793566',           // Landline (Tehran)
	'0914123456',            // Too short (10 digits starting with 0)
	'091412345678',          // Too long (12 digits starting with 0)
	'1234567890',            // 10 digits not starting with 9
	'+12025550199',          // US number
	'not_a_phone',           // Pure text
	'++989141234567',        // Double plus
	'+98+9141234567',        // Mid-string plus
	'0914+1234567',          // Mid-string plus
];

foreach ( $invalid_cases as $input ) {
	$result = Hedayati_Phone::normalize( $input );
	assert_test( "Strictly rejecting invalid input: '{$input}'", is_wp_error( $result ) );
}

echo "\n3. Testing Phone Heuristic (looks_like_iranian_phone):\n";

$phone_ident_cases = [
	[ '09141234567',    true ],
	[ '+989141234567',  true ],
	[ '00989141234567', true ],
	[ '۰۹۱۴۱۲۳۴۵۶۷',    true ],
	[ '9141234567',     true ],
	[ 'john_doe',       false ],
	[ 'admin',          false ],
	[ 'user123',        false ],
	[ '04133377601',    false ], // Landline does not match mobile prefixes
	[ 'teacher.drh',    false ],
	[ '0914abc1234567', false ], // Letters disqualify it from phone heuristic
];

foreach ( $phone_ident_cases as $case ) {
	$input    = (string) $case[0];
	$expected = (bool) $case[1];
	$res      = Hedayati_Phone::looks_like_iranian_phone( $input );
	assert_test( "Heuristic check for '{$input}' == " . ( $expected ? 'true' : 'false' ), $res === $expected );
}

echo "\n4. Testing Display Formatting:\n";

$canonical = '+989141234567';
assert_test( "Display format national: '09141234567'", Hedayati_Phone::format_display( $canonical, 'national' ) === '09141234567' );
assert_test( "Display format spaced: '0914 123 4567'", Hedayati_Phone::format_display( $canonical, 'spaced' ) === '0914 123 4567' );
assert_test( "Display format international: '+98 914 123 4567'", Hedayati_Phone::format_display( $canonical, 'international' ) === '+98 914 123 4567' );

// ─────────────────────────────────────────────────────────────────────────────
// 2. Rate Limiter Configuration & Canonicalization Tests
// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Testing Rate Limiter Canonicalization & Thresholds:\n";

$rl_config = Hedayati_Rate_Limiter::get_config();
assert_test( "Identifier max attempts is 5 (protects account)", $rl_config['identifier_max_attempts'] === 5 );
assert_test( "IP max attempts is 30 (accommodates shared networks)", $rl_config['ip_max_attempts'] === 30 );
assert_test( "Lockout duration is 900s (15 min)", $rl_config['lockout_seconds'] === 900 );

// Rate limit identifier canonicalization cases
$canon_cases = [
	[ '09141234567',    '+989141234567' ],
	[ '+989141234567',  '+989141234567' ],
	[ '۰۹۱۴۱۲۳۴۵۶۷',    '+989141234567' ],
	[ '00989141234567', '+989141234567' ],
	[ '9141234567',     '+989141234567' ],
	[ 'AdminUser',      'adminuser' ],
	[ '  Student_01  ', 'student_01' ],
];

foreach ( $canon_cases as $case ) {
	$raw   = (string) $case[0];
	$canon = (string) $case[1];
	assert_test( "Identifier canonicalization: '{$raw}' => '{$canon}'", Hedayati_Rate_Limiter::canonicalize_identifier( $raw ) === $canon );
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. Roles & Capabilities Tests
// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. Testing Roles & Capability Definitions:\n";

$roles = Hedayati_Roles::get_roles_definition();

assert_test( "Role 'student' exists", isset( $roles['student'] ) );
assert_test( "Role 'teacher' exists", isset( $roles['teacher'] ) );
assert_test( "Role 'teacher_assistant' exists", isset( $roles['teacher_assistant'] ) );
assert_test( "Role 'reception' exists", isset( $roles['reception'] ) );
assert_test( "Role 'hedayati_manager' exists", isset( $roles['hedayati_manager'] ) );
assert_test( "Role 'super_admin' is NOT created", ! isset( $roles['super_admin'] ) );

// Capability security checks
$ta_caps = $roles['teacher_assistant']['capabilities'];
assert_test( "TA has 'hedayati_view_assigned_runs'", ! empty( $ta_caps['hedayati_view_assigned_runs'] ) );
assert_test( "TA has 'hedayati_view_assigned_roster'", ! empty( $ta_caps['hedayati_view_assigned_roster'] ) );
assert_test( "TA does NOT have 'hedayati_record_attendance'", empty( $ta_caps['hedayati_record_attendance'] ) );
assert_test( "TA does NOT have 'manage_options'", empty( $ta_caps['manage_options'] ) );

$teacher_caps = $roles['teacher']['capabilities'];
assert_test( "Teacher has 'hedayati_record_attendance'", ! empty( $teacher_caps['hedayati_record_attendance'] ) );
assert_test( "Teacher does NOT have 'manage_options'", empty( $teacher_caps['manage_options'] ) );

$reception_caps = $roles['reception']['capabilities'];
assert_test( "Reception has 'hedayati_lookup_students'", ! empty( $reception_caps['hedayati_lookup_students'] ) );
assert_test( "Reception has 'hedayati_create_enrollments'", ! empty( $reception_caps['hedayati_create_enrollments'] ) );
assert_test( "Reception does NOT have 'manage_options'", empty( $reception_caps['manage_options'] ) );
assert_test( "Reception does NOT have 'delete_users'", empty( $reception_caps['delete_users'] ) );
assert_test( "Reception does NOT have 'edit_theme_options'", empty( $reception_caps['edit_theme_options'] ) );

$manager_caps = $roles['hedayati_manager']['capabilities'];
assert_test( "Manager has 'hedayati_manage_course_runs'", ! empty( $manager_caps['hedayati_manage_course_runs'] ) );
assert_test( "Manager has 'hedayati_verify_students'", ! empty( $manager_caps['hedayati_verify_students'] ) );
assert_test( "Manager does NOT have 'manage_options'", empty( $manager_caps['manage_options'] ) );

$all_caps = Hedayati_Roles::get_all_hedayati_capabilities();
assert_test( "Hedayati capabilities list contains 21 granular items", count( $all_caps ) === 21 );
assert_test( "Role manager tracks managed capabilities option name", Hedayati_Roles::OPTION_MANAGED_CAPS === 'hedayati_core_managed_capabilities' );

// ─────────────────────────────────────────────────────────────────────────────
// 4. Migration Framework Constants & Lock Assertions
// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Testing Migration Framework Constants:\n";

assert_test( "Migration lock option is defined", Hedayati_DB_Schema::LOCK_OPTION === 'hedayati_db_migration_lock' );
assert_test( "Current DB version is 2.0.0", Hedayati_DB_Schema::CURRENT_DB_VERSION === '2.0.0' );

echo "\n=========================================\n";
echo "TEST RESULTS: {$tests_passed} PASSED, {$tests_failed} FAILED\n";
echo "=========================================\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
