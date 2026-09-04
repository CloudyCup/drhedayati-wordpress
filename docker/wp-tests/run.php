<?php
/**
 * Entry point for the Hedayati local integration / acceptance suite.
 *
 * Run it from the repo root with one command:
 *
 *   scripts/run-acceptance.sh            (bash / macOS / Linux / WSL / Git Bash)
 *   scripts\run-acceptance.ps1           (Windows PowerShell)
 *
 * or directly against a running environment:
 *
 *   docker compose -f docker/docker-compose.yml run --rm wpcli eval-file /wp-tests/run.php
 *
 * Exit code: 0 = every assertion passed, 1 = one or more failed, 2 = the suite
 * could not run (plugin inactive, fatal error). Deterministic and repeatable:
 * it resets to a known-clean state before and after, and uses only synthetic
 * disposable data.
 *
 * This is an ADDITIONAL layer — it does not replace tests/verify-*.js or
 * tests/test-*.php.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "run via:  wp eval-file /wp-tests/run.php\n" );
	exit( 2 );
}

require __DIR__ . '/helpers.php';
require __DIR__ . '/test-phase-2a.php';
require __DIR__ . '/test-phase-2b.php';

global $wpdb;

echo "========================================================================\n";
echo "HEDAYATI LOCAL INTEGRATION / ACCEPTANCE SUITE\n";
printf(
	"WordPress %s  ·  PHP %s  ·  table prefix %s\n",
	get_bloginfo( 'version' ),
	PHP_VERSION,
	$wpdb->prefix
);
printf(
	"plugin hedayati-core %s  ·  theme %s  ·  db schema %s  ·  roles %s\n",
	defined( 'HEDAYATI_CORE_VERSION' ) ? HEDAYATI_CORE_VERSION : '?',
	wp_get_theme()->get( 'Version' ) ?: '?',
	(string) get_option( Hedayati_DB_Schema::OPTION_DB_VERSION ),
	(string) get_option( Hedayati_Roles::OPTION_ROLES_VERSION )
);
echo "========================================================================\n";

$exit = 2;

try {
	HDIT_Env::reset();
	hdit_run_phase_2a();
	hdit_run_phase_2b();
	$exit = HDIT::finish();
} catch ( \Throwable $e ) {
	echo "\n[FATAL] " . get_debug_type( $e ) . ': ' . $e->getMessage() . "\n";
	echo $e->getFile() . ':' . $e->getLine() . "\n";
	echo $e->getTraceAsString() . "\n";
	$exit = 2;
} finally {
	try {
		HDIT_Env::reset();
	} catch ( \Throwable $e ) {
		echo "[warn] post-run reset failed: " . $e->getMessage() . "\n";
	}
}

echo "\n(environment reset to a clean state)\n";

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( $exit );
}

exit( $exit );
