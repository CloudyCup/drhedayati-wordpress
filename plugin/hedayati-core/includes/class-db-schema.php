<?php
/**
 * Versioned Database Schema Manager & Migration Framework.
 *
 * Manages versioned database schema upgrades for Hedayati Core.
 * Executes ordered, idempotent migration routines, tracks the installed
 * schema version in wp_options only upon verified success, uses dynamic table
 * prefixes and collations, and guards against concurrent migration execution
 * via atomic add_option() locking with stale lock recovery.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_DB_Schema {

	/**
	 * Current target database schema version.
	 */
	public const CURRENT_DB_VERSION = '2.1.0';

	/**
	 * Option name for tracking the installed schema version.
	 */
	public const OPTION_DB_VERSION = 'hedayati_core_db_version';

	/**
	 * Option name for atomic migration concurrency locking.
	 */
	public const LOCK_OPTION = 'hedayati_db_migration_lock';

	/**
	 * Lock timeout in seconds (stale lock recovery threshold).
	 */
	private const LOCK_TIMEOUT_SECONDS = 60;

	/**
	 * Map of version numbers to their corresponding migration methods.
	 */
	private const MIGRATIONS = [
		'2.0.0' => 'migrate_2_0_0',
		'2.1.0' => 'migrate_2_1_0',
	];

	/**
	 * Bootstrap database hooks.
	 */
	public static function init(): void {
		// Cheap version check in admin only to avoid frontend request overhead
		add_action( 'admin_init', [ self::class, 'maybe_migrate' ] );
	}

	/**
	 * Return the full table name for user phone identities.
	 *
	 * @return string
	 */
	public static function get_table_user_phones(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_user_phones';
	}

	/**
	 * Phase 2B — Academic Operations table name accessors.
	 * Always dynamic-prefixed; never assume `wp_`.
	 */
	public static function get_table_course_runs(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_course_runs';
	}

	public static function get_table_run_staff(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_run_staff';
	}

	public static function get_table_sessions(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_sessions';
	}

	public static function get_table_enrollments(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_enrollments';
	}

	public static function get_table_attendance(): string {
		global $wpdb;
		return $wpdb->prefix . 'hedayati_attendance';
	}

	/**
	 * Check if migrations are pending and run them.
	 */
	public static function maybe_migrate(): void {
		$installed_version = get_option( self::OPTION_DB_VERSION, '1.0.0' );

		if ( version_compare( (string) $installed_version, self::CURRENT_DB_VERSION, '<' ) ) {
			self::migrate();
		}
	}

	/**
	 * Execute all pending migrations in version order with atomic locking.
	 */
	public static function migrate(): void {
		// Acquire atomic lock using add_option()
		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$installed_version = get_option( self::OPTION_DB_VERSION, '1.0.0' );

			foreach ( self::MIGRATIONS as $version => $method ) {
				if ( version_compare( (string) $installed_version, $version, '<' ) ) {
					if ( method_exists( self::class, $method ) ) {
						$success = self::$method();

						// Only advance stored version if migration returned true
						if ( true === $success ) {
							$installed_version = $version;
							update_option( self::OPTION_DB_VERSION, $version );
						} else {
							// Stop on failure — allow safe retry on future request
							break;
						}
					}
				}
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Atomically acquire migration lock using add_option().
	 * Handles stale lock recovery if previous migration crashed.
	 *
	 * @return bool
	 */
	private static function acquire_lock(): bool {
		$now = time();

		// Attempt atomic insert
		$acquired = add_option( self::LOCK_OPTION, $now, '', 'no' );

		if ( $acquired ) {
			return true;
		}

		// Lock exists — check for stale lock
		$lock_time = (int) get_option( self::LOCK_OPTION, 0 );

		if ( ( $now - $lock_time ) > self::LOCK_TIMEOUT_SECONDS ) {
			// Lock is stale: delete and retry atomic acquisition
			delete_option( self::LOCK_OPTION );
			return (bool) add_option( self::LOCK_OPTION, $now, '', 'no' );
		}

		return false;
	}

	/**
	 * Release atomic migration lock.
	 */
	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Migration 2.0.0: Create user phones table for identity foundation.
	 *
	 * @return bool True if table was created and verified to exist, false on failure.
	 */
	private static function migrate_2_0_0(): bool {
		global $wpdb;

		$charset_collate   = $wpdb->get_charset_collate();
		$table_user_phones = self::get_table_user_phones();

		/*
		 * dbDelta syntax requirements:
		 *   - Separate line for each field and key.
		 *   - PRIMARY KEY must have 2 spaces before the key definition.
		 *   - KEY must be followed by a key name and column(s) in parentheses.
		 */
		$sql_user_phones = "CREATE TABLE {$table_user_phones} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			phone_e164 varchar(20) NOT NULL,
			is_verified tinyint(1) NOT NULL DEFAULT 0,
			verified_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_user_id (user_id),
			UNIQUE KEY uq_phone_e164 (phone_e164),
			KEY idx_is_verified (is_verified)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql_user_phones );

		// Verify table actually exists in database before declaring success
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$table_user_phones
			)
		);

		return ( $table_exists === $table_user_phones );
	}

	/**
	 * Migration 2.1.0: Phase 2B academic-operations relational tables.
	 *
	 * Creates course runs, run staff assignments, class sessions, enrollments and
	 * attendance. Additive only — does NOT touch `hedayati_user_phones` or any
	 * Phase 2A data. Idempotent: `dbDelta` no-ops on re-run, and the stored version
	 * only advances after every table is confirmed present.
	 *
	 * Business-state columns are `varchar` (validated in PHP), never MySQL ENUM, so
	 * the state vocabularies can evolve without a schema migration (docs/DECISIONS.md D13).
	 *
	 * @return bool True only if all five tables exist afterwards.
	 */
	private static function migrate_2_1_0(): bool {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_course_runs = self::get_table_course_runs();
		$table_run_staff   = self::get_table_run_staff();
		$table_sessions    = self::get_table_sessions();
		$table_enrollments = self::get_table_enrollments();
		$table_attendance  = self::get_table_attendance();

		// course_id / user_id / teacher_id reference wp_posts.ID and wp_users.ID
		// (both unsigned bigint). No DB-level FK — MyISAM cores and cross-engine
		// installs cannot be assumed; referential integrity is enforced in the
		// service layer and via the deletion-cleanup hooks.
		$sql_course_runs = "CREATE TABLE {$table_course_runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			course_id bigint(20) unsigned NOT NULL,
			label varchar(190) NOT NULL DEFAULT '',
			run_status varchar(20) NOT NULL DEFAULT 'draft',
			registration_status varchar(20) NOT NULL DEFAULT 'closed',
			start_date date DEFAULT NULL,
			end_date date DEFAULT NULL,
			schedule_text varchar(255) NOT NULL DEFAULT '',
			capacity int(10) unsigned DEFAULT NULL,
			tuition_rial bigint(20) unsigned DEFAULT NULL,
			notes text DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_course_id (course_id),
			KEY idx_run_status (run_status),
			KEY idx_registration_status (registration_status)
		) {$charset_collate};";

		$sql_run_staff = "CREATE TABLE {$table_run_staff} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			staff_role varchar(30) NOT NULL,
			teacher_id bigint(20) unsigned DEFAULT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_run_id (run_id),
			KEY idx_teacher_id (teacher_id),
			KEY idx_user_id (user_id)
		) {$charset_collate};";

		$sql_sessions = "CREATE TABLE {$table_sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			session_number int(10) unsigned NOT NULL,
			starts_at datetime NOT NULL,
			ends_at datetime DEFAULT NULL,
			topic varchar(190) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'scheduled',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_run_session (run_id, session_number),
			KEY idx_run_id (run_id),
			KEY idx_starts_at (starts_at)
		) {$charset_collate};";

		$sql_enrollments = "CREATE TABLE {$table_enrollments} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			enrolled_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_run_user (run_id, user_id),
			KEY idx_run_id (run_id),
			KEY idx_user_id (user_id),
			KEY idx_status (status)
		) {$charset_collate};";

		$sql_attendance = "CREATE TABLE {$table_attendance} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			enrollment_id bigint(20) unsigned NOT NULL,
			status varchar(20) NOT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			recorded_by bigint(20) unsigned DEFAULT NULL,
			recorded_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_session_enrollment (session_id, enrollment_id),
			KEY idx_session_id (session_id),
			KEY idx_enrollment_id (enrollment_id)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql_course_runs );
		dbDelta( $sql_run_staff );
		dbDelta( $sql_sessions );
		dbDelta( $sql_enrollments );
		dbDelta( $sql_attendance );

		foreach ( [ $table_course_runs, $table_run_staff, $table_sessions, $table_enrollments, $table_attendance ] as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				return false;
			}
		}

		return true;
	}
}
