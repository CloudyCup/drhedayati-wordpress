<?php
/**
 * Audit-log unit & contract test suite (PHP CLI, no WordPress boot).
 *
 * Runnable checks: token/note sanitization, the action / object-type
 * vocabularies, and the append-only API contract (via reflection).
 *
 * OUT OF SCOPE here (needs a real $wpdb — staging, `docs/PHASE_2B_ACCEPTANCE.md`
 * section J): actual INSERT, the re-entrancy guard under a live insert, cascade
 * exclusion, and the deletion-cleanup hooks.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, ...$args ): mixed { return $value; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $s ): string {
		$s = preg_replace( '/<[^>]*>/', '', $s );
		$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
		return trim( preg_replace( '/\s{2,}/', ' ', $s ) );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool { return false; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int { return 0; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
}

// Test-harness only: a UTF-8-correct mbstring fallback for CLI environments
// without ext-mbstring. Production never relies on this — WordPress core ships
// its own mb_* compat in wp-includes/compat.php, and ext-mbstring is the norm.
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $str, $start, $length = null, $enc = null ) {
		$chars = preg_split( '//u', (string) $str, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$slice = ( null === $length ) ? array_slice( $chars, $start ) : array_slice( $chars, $start, $length );
		return implode( '', $slice );
	}
}
if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $str, $enc = null ): int {
		return count( preg_split( '//u', (string) $str, -1, PREG_SPLIT_NO_EMPTY ) ?: [] );
	}
}

// Minimal shim so the class file loads without the full schema class.
if ( ! class_exists( 'Hedayati_DB_Schema' ) ) {
	class Hedayati_DB_Schema {
		public static function get_table_audit_log(): string { return 'wp_hedayati_audit_log'; }
	}
}

require_once __DIR__ . '/../includes/class-audit-log.php';

$passed = 0;
$failed = 0;
function check( string $desc, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { echo "  [PASS] {$desc}\n"; $passed++; }
	else { echo "  [FAIL] {$desc}\n"; $failed++; }
}

echo "=== AUDIT-LOG UNIT & CONTRACT SUITE ===\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "1. Append-only API contract (reflection):\n";
$rc = new ReflectionClass( 'Hedayati_Audit_Log' );
$methods = array_map( static fn( $m ) => $m->getName(), $rc->getMethods( ReflectionMethod::IS_PUBLIC ) );

check( 'has record()', in_array( 'record', $methods, true ) );
check( 'has get() / query() / count()', in_array( 'get', $methods, true ) && in_array( 'query', $methods, true ) && in_array( 'count', $methods, true ) );
check( 'has NO update() method', ! in_array( 'update', $methods, true ) );
check( 'has NO delete()/delete_* method', 0 === count( array_filter( $methods, static fn( $m ) => str_starts_with( $m, 'delete' ) ) ) );
check( 'has NO set()/purge()/clear() method', ! array_intersect( [ 'set', 'purge', 'clear', 'truncate', 'reset' ], $methods ) );

$src = file_get_contents( __DIR__ . '/../includes/class-audit-log.php' );
check( 'record() only $wpdb->insert (no update/delete on the table)', str_contains( $src, '$wpdb->insert(' ) && ! preg_match( '/\$wpdb->(update|delete)\(/', $src ) );
check( 'has a re-entrancy guard', str_contains( $src, 'static $in_progress = false;' ) );
check( 'no IP / user-agent / $_SERVER anywhere', ! preg_match( '/REMOTE_ADDR|HTTP_USER_AGENT|user_agent|X-Forwarded/i', $src ) );
check( 'addresses the table via Hedayati_DB_Schema', str_contains( $src, 'Hedayati_DB_Schema::get_table_audit_log()' ) );
check( 'no literal wp_ table name', ! preg_match( '/[\'"]wp_[a-z_]/', $src ) );
check( 'reads use $wpdb->prepare', substr_count( $src, '$wpdb->prepare(' ) >= 3 );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n2. Vocabularies:\n";
$actions = Hedayati_Audit_Log::actions();
$types   = Hedayati_Audit_Log::object_types();

check( 'actions() is a non-empty string list', is_array( $actions ) && count( $actions ) >= 10 && $actions === array_values( array_filter( $actions, 'is_string' ) ) );
check( 'every action is dotted <object>.<verb>', 0 === count( array_filter( $actions, static fn( $a ) => ! preg_match( '/^[a-z_]+\.[a-z_]+$/', $a ) ) ) );
foreach ( [ 'course_run.created', 'course_run.updated', 'course_run.deleted', 'session.created', 'run_staff.assigned', 'enrollment.created', 'enrollment.status_changed', 'attendance.recorded', 'attendance.updated' ] as $expected ) {
	check( "action vocabulary includes {$expected}", in_array( $expected, $actions, true ) );
}
check( 'object_types() includes the Phase 2B objects', ! array_diff( [ 'course', 'course_run', 'session', 'run_staff', 'enrollment', 'attendance' ], $types ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n3. Token / note sanitization (private methods via reflection):\n";
$sanitize_token = $rc->getMethod( 'sanitize_token' );
$sanitize_token->setAccessible( true );
$sanitize_note = $rc->getMethod( 'sanitize_note' );
$sanitize_note->setAccessible( true );

$tok = static fn( string $v, int $max = 64 ) => $sanitize_token->invoke( null, $v, $max );
$note = static fn( string $v ) => $sanitize_note->invoke( null, $v );

check( "token lowercases + strips spaces/punctuation", $tok( '  Course Run.Created! (v2) ' ) === 'courserun.createdv2' );
check( "token keeps [a-z0-9_.-]", $tok( 'enrollment.status_changed-v2' ) === 'enrollment.status_changed-v2' );
check( "token strips markup", $tok( 'x<script>y</script>' ) === 'xscriptyscript' );
check( "token length-capped", strlen( $tok( str_repeat( 'a', 200 ), 32 ) ) === 32 );
check( "empty token stays empty", $tok( '   ' ) === '' );

check( "note strips tags", ! preg_match( '/[<>]/', $note( 'run <b>5</b> deleted' ) ) );
check( "note collapses newlines/tabs", ! preg_match( '/[\r\n\t]/', $note( "line1\nline2\tend" ) ) );
check( "note length-capped at 255", mb_strlen( $note( str_repeat( 'n', 400 ) ) ) === 255 );
check( "safe enum values survive", $note( 'active -> withdrawn' ) === 'active -> withdrawn' );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n4. record() signature does not accept ip / user-agent:\n";
$record = $rc->getMethod( 'record' );
$params = array_map( static fn( $p ) => $p->getName(), $record->getParameters() );
check( 'record() params are exactly action/object_type/object_id/note/actor_id', $params === [ 'action', 'object_type', 'object_id', 'note', 'actor_id' ] );
check( 'no param named ip / ua / user_agent / request', 0 === count( array_intersect( $params, [ 'ip', 'ua', 'user_agent', 'request', 'context', 'meta' ] ) ) );

// ─────────────────────────────────────────────────────────────────────────────
echo "\n5. Migration 2.2.0 wiring + the AUDIT-TABLE DDL only:\n";
$db_src = file_get_contents( __DIR__ . '/../includes/class-db-schema.php' );

preg_match( "/CURRENT_DB_VERSION\\s*=\\s*'([0-9.]+)'/", $db_src, $dbv );
check( 'DB target version is >= 2.2.0 (audit-log schema present)', isset( $dbv[1] ) && version_compare( $dbv[1], '2.2.0', '>=' ) );
check( 'migrate_2_2_0 in MIGRATIONS', (bool) preg_match( "/'2\\.2\\.0'\\s*=>\\s*'migrate_2_2_0'/", $db_src ) );
check( 'earlier migrations 2.0.0 + 2.1.0 still registered', (bool) preg_match( "/'2\\.0\\.0'\\s*=>\\s*'migrate_2_0_0'/", $db_src ) && (bool) preg_match( "/'2\\.1\\.0'\\s*=>\\s*'migrate_2_1_0'/", $db_src ) );

// Isolate the audit-table CREATE TABLE statement and evaluate THAT alone —
// other tables legitimately have `updated_at`, and prose comments elsewhere
// mention ip / user_agent.
preg_match( '/CREATE TABLE \{\$table_audit_log\}\s*\((.*?)\)\s*\{\$charset_collate\};/s', $db_src, $ddlm );
$audit_ddl = $ddlm[1] ?? '';
check( 'audit CREATE TABLE statement was isolated', '' !== $audit_ddl && str_contains( $db_src, '{$table_audit_log}' ) );

foreach ( [ 'id ', 'actor_id ', 'action ', 'object_type ', 'object_id ', 'note ', 'created_at ' ] as $col ) {
	check( "audit DDL declares column: " . trim( $col ), (bool) preg_match( '/^\s*' . preg_quote( trim( $col ), '/' ) . '\s/m', $audit_ddl ) );
}
check( 'audit DDL has NO ip / ip_address column', ! preg_match( '/^\s*(ip|ip_address|ip_addr|remote_addr)\s/mi', $audit_ddl ) );
check( 'audit DDL has NO user_agent column', ! preg_match( '/^\s*(user_agent|useragent|ua)\s/mi', $audit_ddl ) );
check( 'audit DDL has NO updated_at column (append-only signal)', ! preg_match( '/^\s*updated_at\s/mi', $audit_ddl ) );
check( 'audit DDL has NO json / blob / text / context / payload column', ! preg_match( '/^\s*[a-z_]*\s+(json|blob|longblob|mediumblob|text|longtext|mediumtext)\b/mi', $audit_ddl ) && ! preg_match( '/^\s*(context|payload|body|meta|data)\s/mi', $audit_ddl ) );
check( 'migrate_2_2_0 verifies the table before advancing the version', (bool) preg_match( '/migrate_2_2_0.*?SHOW TABLES LIKE %s.*?\$exists === \$table_audit_log/s', $db_src ) );

foreach ( [
	'class-course-run-service'  => [ 'course_run.created', 'course_run.updated', 'course_run.deleted', 'course.deleted' ],
	'class-session-service'     => [ 'session.created', 'session.updated', 'session.deleted' ],
	'class-run-staff-service'   => [ 'run_staff.assigned', 'run_staff.removed' ],
	'class-enrollment-service'  => [ 'enrollment.created', 'enrollment.status_changed', 'enrollment.deleted' ],
	'class-attendance-service'  => [ 'attendance.recorded', 'attendance.updated' ],
] as $file => $expected_actions ) {
	$s = file_get_contents( __DIR__ . "/../includes/{$file}.php" );
	foreach ( $expected_actions as $a ) {
		check( "{$file} records {$a}", str_contains( $s, "'{$a}'" ) );
	}
	check( "{$file} never targets the audit table in a cascade", ! str_contains( $s, 'get_table_audit_log' ) );
}

echo "\n=========================================\n";
echo "AUDIT-LOG TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=========================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
