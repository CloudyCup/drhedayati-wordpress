<?php
/**
 * Phase 2B — Academic Operations unit & contract test suite.
 *
 * Scope of THIS file (runnable with the PHP CLI, no WordPress boot):
 *   1. Hedayati_Academic_Validation — the complete business-state vocabularies and
 *      the date / datetime / integer parsing rules (pure functions).
 *   2. Structural contracts — service classes expose the expected static API and
 *      address every table through Hedayati_DB_Schema (never a literal `wp_`).
 *
 * OUT OF SCOPE here (needs a real $wpdb / WordPress — verified on staging, the same
 * way Phase 2A behavioural acceptance is a pre-deployment gate):
 *   - actual INSERT/UPDATE/DELETE, UNIQUE-constraint enforcement, cascade deletes,
 *     capacity enforcement, per-run scope, deletion-cleanup hooks.
 *   See docs/PHASE_2B_ACCEPTANCE.md for the staging matrix.
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
}

require_once __DIR__ . '/../includes/class-text.php';
require_once __DIR__ . '/../includes/class-academic-validation.php';

$passed = 0;
$failed = 0;

function check( string $desc, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { echo "  [PASS] {$desc}\n"; $passed++; }
	else { echo "  [FAIL] {$desc}\n"; $failed++; }
}

echo "=== PHASE 2B UNIT & CONTRACT TEST SUITE ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "1. Digit normalization (Hedayati_Text):\n";
check( "Persian digits -> ASCII", Hedayati_Text::digits_to_ascii( '۱۲۳۴۵۶۷۸۹۰' ) === '1234567890' );
check( "Arabic-Indic digits -> ASCII", Hedayati_Text::digits_to_ascii( '٠١٢٣٤٥٦٧٨٩' ) === '0123456789' );
check( "non-digits preserved", Hedayati_Text::digits_to_ascii( 'دوره ۳ (CCNA)' ) === 'دوره 3 (CCNA)' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Business-state vocabularies:\n";
check( "5 run statuses", Hedayati_Academic_Validation::RUN_STATUSES === [ 'draft', 'scheduled', 'in_progress', 'completed', 'cancelled' ] );
check( "3 registration statuses", Hedayati_Academic_Validation::REGISTRATION_STATUSES === [ 'closed', 'open', 'soon' ] );
check( "3 session statuses", Hedayati_Academic_Validation::SESSION_STATUSES === [ 'scheduled', 'held', 'cancelled' ] );
check( "4 enrollment statuses", Hedayati_Academic_Validation::ENROLLMENT_STATUSES === [ 'active', 'withdrawn', 'completed', 'cancelled' ] );
check( "4 attendance statuses", Hedayati_Academic_Validation::ATTENDANCE_STATUSES === [ 'present', 'absent', 'late', 'excused' ] );
check( "3 staff roles", Hedayati_Academic_Validation::STAFF_ROLES === [ 'primary_instructor', 'additional_instructor', 'assistant' ] );
check( "instructor roles = the two instructor slugs", Hedayati_Academic_Validation::INSTRUCTOR_ROLES === [ 'primary_instructor', 'additional_instructor' ] );
check( "is_instructor_role(assistant) is false", ! Hedayati_Academic_Validation::is_instructor_role( 'assistant' ) );
check( "is_instructor_role(primary_instructor) is true", Hedayati_Academic_Validation::is_instructor_role( 'primary_instructor' ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Status sanitizers (safe fallback on invalid):\n";
check( "run_status valid passes", Hedayati_Academic_Validation::sanitize_run_status( 'in_progress' ) === 'in_progress' );
check( "run_status invalid -> draft", Hedayati_Academic_Validation::sanitize_run_status( 'nope' ) === 'draft' );
check( "run_status trims + lowercases", Hedayati_Academic_Validation::sanitize_run_status( '  COMPLETED ' ) === 'completed' );
check( "registration_status invalid -> closed (safe)", Hedayati_Academic_Validation::sanitize_registration_status( '' ) === 'closed' );
check( "session_status invalid -> scheduled", Hedayati_Academic_Validation::sanitize_session_status( 'x' ) === 'scheduled' );
check( "enrollment_status invalid -> active", Hedayati_Academic_Validation::sanitize_enrollment_status( 'x' ) === 'active' );
check( "attendance invalid -> null (no implicit default)", Hedayati_Academic_Validation::parse_attendance_status( 'x' ) === null );
check( "attendance 'LATE' -> 'late'", Hedayati_Academic_Validation::parse_attendance_status( 'LATE' ) === 'late' );
check( "staff role invalid -> null", Hedayati_Academic_Validation::parse_staff_role( 'boss' ) === null );
check( "staff role 'assistant' -> 'assistant'", Hedayati_Academic_Validation::parse_staff_role( 'assistant' ) === 'assistant' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. ISO date parsing:\n";
check( "valid date", Hedayati_Academic_Validation::parse_iso_date( '2026-03-21' ) === '2026-03-21' );
check( "Persian-digit date normalized", Hedayati_Academic_Validation::parse_iso_date( '۲۰۲۶-۰۳-۲۱' ) === '2026-03-21' );
check( "Feb 31 rejected", Hedayati_Academic_Validation::parse_iso_date( '2026-02-31' ) === null );
check( "month 13 rejected", Hedayati_Academic_Validation::parse_iso_date( '2026-13-01' ) === null );
check( "slashes rejected", Hedayati_Academic_Validation::parse_iso_date( '2026/03/21' ) === null );
check( "empty -> null", Hedayati_Academic_Validation::parse_iso_date( '' ) === null );
check( "leap day 2028-02-29 valid", Hedayati_Academic_Validation::parse_iso_date( '2028-02-29' ) === '2028-02-29' );
check( "non-leap 2027-02-29 rejected", Hedayati_Academic_Validation::parse_iso_date( '2027-02-29' ) === null );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Datetime parsing (canonical Y-m-d H:i:s):\n";
check( "space form gets :00 seconds", Hedayati_Academic_Validation::parse_datetime( '2026-03-21 09:30' ) === '2026-03-21 09:30:00' );
check( "datetime-local T form accepted", Hedayati_Academic_Validation::parse_datetime( '2026-03-21T09:30' ) === '2026-03-21 09:30:00' );
check( "explicit seconds preserved", Hedayati_Academic_Validation::parse_datetime( '2026-03-21 09:30:45' ) === '2026-03-21 09:30:45' );
check( "hour 24 rejected", Hedayati_Academic_Validation::parse_datetime( '2026-03-21 24:00' ) === null );
check( "minute 60 rejected", Hedayati_Academic_Validation::parse_datetime( '2026-03-21 09:60' ) === null );
check( "invalid calendar day rejected", Hedayati_Academic_Validation::parse_datetime( '2026-02-30 09:00' ) === null );
check( "date-only rejected", Hedayati_Academic_Validation::parse_datetime( '2026-03-21' ) === null );
check( "Persian-digit datetime normalized", Hedayati_Academic_Validation::parse_datetime( '۲۰۲۶-۰۳-۲۱ ۰۹:۳۰' ) === '2026-03-21 09:30:00' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n6. Integer parsing — nullable 'unknown' vs invalid vs required:\n";
$cap_empty = Hedayati_Academic_Validation::parse_optional_nonneg_int( '' );
check( "empty capacity -> null (unknown, NOT fabricated 0)", $cap_empty === null );
check( "'20' -> int 20", Hedayati_Academic_Validation::parse_optional_nonneg_int( '20' ) === 20 );
check( "'0' -> int 0 (explicit zero allowed)", Hedayati_Academic_Validation::parse_optional_nonneg_int( '0' ) === 0 );
check( "Persian '۲۵۰۰۰۰۰' -> 2500000", Hedayati_Academic_Validation::parse_optional_nonneg_int( '۲۵۰۰۰۰۰' ) === 2500000 );
check( "'-5' -> WP_Error", is_wp_error( Hedayati_Academic_Validation::parse_optional_nonneg_int( '-5' ) ) );
check( "'12x' -> WP_Error", is_wp_error( Hedayati_Academic_Validation::parse_optional_nonneg_int( '12x' ) ) );
check( "session number '0' -> null (must be positive)", Hedayati_Academic_Validation::parse_positive_int( '0' ) === null );
check( "session number '3' -> 3", Hedayati_Academic_Validation::parse_positive_int( '3' ) === 3 );
check( "session number 'abc' -> null", Hedayati_Academic_Validation::parse_positive_int( 'abc' ) === null );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n7. Service API contracts (no DB — reflection only):\n";

$service_api = [
	'includes/class-course-run-service.php' => [ 'Hedayati_Course_Run_Service', [ 'init', 'get', 'query', 'create', 'update', 'delete_run', 'on_course_deleted', 'count_for_course' ] ],
	'includes/class-run-staff-service.php'  => [ 'Hedayati_Run_Staff_Service', [ 'init', 'get', 'list_for_run', 'assign', 'remove', 'on_user_deleted', 'on_post_deleted', 'user_is_staff_on_run', 'run_ids_for_user' ] ],
	'includes/class-session-service.php'    => [ 'Hedayati_Session_Service', [ 'init', 'get', 'list_for_run', 'create', 'update', 'delete_session', 'next_session_number' ] ],
	'includes/class-enrollment-service.php' => [ 'Hedayati_Enrollment_Service', [ 'init', 'get', 'get_by_run_user', 'list_for_run', 'list_for_user', 'enroll', 'set_status', 'delete_enrollment', 'count_active', 'on_user_deleted' ] ],
	'includes/class-attendance-service.php' => [ 'Hedayati_Attendance_Service', [ 'init', 'get', 'list_for_session', 'record', 'record_bulk', 'delete_mark', 'on_user_deleted' ] ],
];

foreach ( $service_api as $file => [ $class, $methods ] ) {
	$src = file_get_contents( __DIR__ . '/../' . $file );

	foreach ( $methods as $m ) {
		check( "{$class}::{$m}() declared", (bool) preg_match( '/function ' . preg_quote( $m, '/' ) . '\s*\(/', $src ) );
	}

	check( "{$class} addresses tables via Hedayati_DB_Schema", str_contains( $src, 'Hedayati_DB_Schema::get_table_' ) );
	check( "{$class} has no literal wp_ table name", ! preg_match( '/[\'"]wp_[a-z_]*(posts|users|options|hedayati)/', $src ) );
	check( "{$class} uses \$wpdb->prepare", str_contains( $src, '$wpdb->prepare(' ) );
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n8. Migration & roles wiring (source inspection):\n";
$db_src    = file_get_contents( __DIR__ . '/../includes/class-db-schema.php' );
$roles_src = file_get_contents( __DIR__ . '/../includes/class-roles.php' );

// Phase 2B requires at least the 2.1.0 schema. Later phases (2.2.0 audit log)
// legitimately raise CURRENT_DB_VERSION — assert a minimum, not an exact string.
preg_match( "/CURRENT_DB_VERSION\\s*=\\s*'([0-9.]+)'/", $db_src, $dbv );
check( "DB target version is >= 2.1.0 (Phase 2B schema present)", isset( $dbv[1] ) && version_compare( $dbv[1], '2.1.0', '>=' ) );
check( "migrate_2_1_0 registered in MIGRATIONS", (bool) preg_match( "/'2\\.1\\.0'\\s*=>\\s*'migrate_2_1_0'/", $db_src ) );
check( "migrate_2_1_0() method still defined", str_contains( $db_src, 'function migrate_2_1_0(' ) );
check( "Phase 2A migration 2.0.0 still present (2.1.0 did not replace it)", str_contains( $db_src, 'migrate_2_0_0' ) && (bool) preg_match( "/'2\\.0\\.0'\\s*=>\\s*'migrate_2_0_0'/", $db_src ) );
check( "phone table untouched by 2.1.0 (no ALTER on hedayati_user_phones)", ! preg_match( '/ALTER TABLE[^;]*hedayati_user_phones/i', $db_src ) );
check( "no MySQL ENUM in schema", ! preg_match( '/\bENUM\s*\(/i', $db_src ) );
check( "roles version 2.1.0", str_contains( $roles_src, "ROLES_VERSION = '2.1.0'" ) );
check( "hedayati_manage_teachers in capability list", substr_count( $roles_src, "'hedayati_manage_teachers'" ) >= 2 );

require_once __DIR__ . '/../includes/class-roles.php';
check( "get_all_hedayati_capabilities() returns 22", count( Hedayati_Roles::get_all_hedayati_capabilities() ) === 22 );
$mgr = Hedayati_Roles::get_roles_definition()['hedayati_manager']['capabilities'];
check( "manager has hedayati_manage_teachers", ! empty( $mgr['hedayati_manage_teachers'] ) );
check( "manager still lacks manage_options", empty( $mgr['manage_options'] ) );
$ta = Hedayati_Roles::get_roles_definition()['teacher_assistant']['capabilities'];
check( "TA still lacks record_attendance (D11 preserved)", empty( $ta['hedayati_record_attendance'] ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n9. Teacher CPT capability model (1.5.2 meta-cap collision fix):\n";
$teacher_src = file_get_contents( __DIR__ . '/../includes/class-teacher.php' );

check( "Teacher CPT declares map_meta_cap => true", (bool) preg_match( "/'map_meta_cap'\\s*=>\\s*true/", $teacher_src ) );

preg_match( "/'capabilities'\\s*=>\\s*\\[(.*?)\\]\\s*,\\s*\\n\\s*\\]\\s*\\)\\s*;/s", $teacher_src, $cap_block );
check( "Teacher CPT 'capabilities' array parseable", isset( $cap_block[1] ) );

$t_caps = [];
if ( isset( $cap_block[1] ) ) {
	preg_match_all( "/'([a-z_]+)'\\s*=>\\s*'([a-z_]+)'/", $cap_block[1], $pairs, PREG_SET_ORDER );
	foreach ( $pairs as $p ) {
		$t_caps[ $p[1] ] = $p[2];
	}
}

$meta_keys       = [ 'edit_post', 'read_post', 'delete_post' ];
$collection_keys = [ 'edit_posts', 'edit_others_posts', 'delete_posts', 'delete_others_posts', 'publish_posts', 'read_private_posts', 'create_posts' ];

foreach ( $collection_keys as $k ) {
	check( "collection cap '{$k}' requires hedayati_manage_teachers", ( $t_caps[ $k ] ?? null ) === 'hedayati_manage_teachers' );
}
foreach ( $meta_keys as $k ) {
	check( "meta cap '{$k}' does NOT reuse the primitive (the 1.5.1 collision)", ( $t_caps[ $k ] ?? null ) !== 'hedayati_manage_teachers' );
	check( "meta cap '{$k}' is present and distinct-named", isset( $t_caps[ $k ] ) && ! in_array( $t_caps[ $k ], $collection_keys, true ) );
}
check( "the three meta caps have three distinct names", count( array_unique( [ $t_caps['edit_post'] ?? '', $t_caps['read_post'] ?? '', $t_caps['delete_post'] ?? '' ] ) ) === 3 );

// Port of WP core: _post_type_meta_capabilities() would copy these meta-cap
// *values* into $post_type_meta_caps as KEYS; a key there is object-scoped and can
// never be tested bare. The primitive must NOT become such a key.
$ptmc = [];
foreach ( $meta_keys as $core ) {
	if ( isset( $t_caps[ $core ] ) ) {
		$ptmc[ $t_caps[ $core ] ] = $core;
	}
}
check( "hedayati_manage_teachers stays a bare primitive (not object-scoped)", ! array_key_exists( 'hedayati_manage_teachers', $ptmc ) );
check( "negative control: the 1.5.1 config WOULD trip this guard", array_key_exists( 'hedayati_manage_teachers', [ 'hedayati_manage_teachers' => 'edit_post' ] ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n9b. Teacher CPT status-conditional caps (1.5.3 fix — map_meta_cap() completeness):\n";

// With map_meta_cap => true, WordPress's get_post_type_capabilities() silently
// auto-fills any OMITTED key among these four from capability_type (here:
// 'edit_published_hedayati_teachers' etc.) — a capability nobody is ever
// granted. map_meta_cap('edit_post'|'delete_post', ...) requires one of these
// IN ADDITION TO edit_others_posts/delete_others_posts for a publish/private
// post authored by someone else — the Teacher CPT's normal case (manager/
// admin acting on a post_author=0 profile). Omitting them silently vetoed
// current_user_can('edit_post'|'delete_post', $teacher_id) for
// manager/administrator even after the 1.5.2 bare-primitive fix (verified on
// staging: object-level edit_post/delete_post still resolved false).
$status_conditional_keys = [ 'edit_published_posts', 'edit_private_posts', 'delete_published_posts', 'delete_private_posts' ];

foreach ( $status_conditional_keys as $k ) {
	check( "status-conditional cap '{$k}' is declared (not left to capability_type auto-fill)", isset( $t_caps[ $k ] ) );
	check( "status-conditional cap '{$k}' requires hedayati_manage_teachers", ( $t_caps[ $k ] ?? null ) === 'hedayati_manage_teachers' );
}

// Negative control: the 1.5.2 config (these four keys absent) would trip this
// guard — proves the check is meaningful, not tautological.
$pre_1_5_3_caps = $t_caps;
foreach ( $status_conditional_keys as $k ) {
	unset( $pre_1_5_3_caps[ $k ] );
}
check(
	"negative control: the 1.5.2 config (no status-conditional caps) WOULD trip this guard",
	! isset( $pre_1_5_3_caps['edit_published_posts'] )
);

echo "\n=========================================\n";
echo "PHASE 2B TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=========================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
