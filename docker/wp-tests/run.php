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
 * Exit code: 0 = every assertion passed AND cleanup was independently
 * verified; 1 = one or more assertions failed; 2 = the suite could not run
 * (plugin inactive, disposable-environment guard tripped, fatal error);
 * 3 = every assertion passed but final cleanup could not be verified (HD-004
 * — never read this as a clean run). Deterministic and repeatable: it resets
 * to a known-clean state before and after, and uses only synthetic
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
require __DIR__ . '/test-phase-2c.php';
require __DIR__ . '/test-phase-2d.php';
require __DIR__ . '/test-launch.php';
require __DIR__ . '/test-phase-3.php';

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

// HD-004: cleanup success is reported ONLY after it is independently
// verified, and a cleanup failure changes the exit code — it is never just a
// warning printed next to an unconditional "clean state" line.
$suite_completed = false;
$assertions_exit  = 2; // stays 2 (could not run) unless the suite actually completes.

try {
	if ( ! HDIT_Env::reset() ) {
		echo "\n[FATAL] could not establish a verified clean state before running — aborting.\n";
	} else {
		hdit_run_phase_2a();
		hdit_run_phase_2b();
		hdit_run_phase_2c();
		hdit_run_phase_2d();
		hdit_run_launch();
		hdit_run_phase_3();
		$assertions_exit = HDIT::finish();
		$suite_completed = true;
	}
} catch ( \Throwable $e ) {
	echo "\n[FATAL] " . get_debug_type( $e ) . ': ' . $e->getMessage() . "\n";
	echo $e->getFile() . ':' . $e->getLine() . "\n";
	echo $e->getTraceAsString() . "\n";
	$assertions_exit = 2;
}

$cleanup_verified = false;
try {
	$cleanup_verified = HDIT_Env::reset();
} catch ( \Throwable $e ) {
	echo "\n[FATAL] final cleanup threw: " . $e->getMessage() . "\n";
}

echo "\n========================================================================\n";

if ( ! $suite_completed ) {
	echo "RESULT: 2 (could not complete the run — see [FATAL] above)\n";
	$exit = 2;
} elseif ( 0 !== $assertions_exit ) {
	// An assertion failure is the headline result regardless of cleanup.
	echo $cleanup_verified
		? "(environment verified clean — all synthetic data removed)\n"
		: "CLEANUP ALSO FAILED — synthetic data may remain in this container; run scripts/env-down then scripts/run-acceptance again.\n";
	$exit = $assertions_exit; // 1
} elseif ( ! $cleanup_verified ) {
	echo "RESULT: CLEANUP FAILED — every assertion passed, but synthetic data may remain in this\n";
	echo "container (a DELETE failed, or a row survived re-verification). Do NOT treat this as a\n";
	echo "clean run; run scripts/env-down.{sh,ps1} before trusting the environment again.\n";
	$exit = 3;
} else {
	echo "(environment verified clean — all synthetic data removed)\n";
	$exit = 0;
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::halt( $exit );
}

exit( $exit );
