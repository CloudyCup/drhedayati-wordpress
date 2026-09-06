<?php
/**
 * Shared harness for the Hedayati local integration / acceptance suite.
 *
 * Loaded inside a fully-booted WordPress via `wp eval-file`. This layer is
 * ADDITIONAL to the repo's static/unit suites (tests/verify-*.js,
 * tests/test-*.php) — it does not replace them. It exercises the plugin through
 * its public service APIs and real WordPress behaviour (roles, hooks, REST,
 * $wpdb, UNIQUE constraints, cascades) against a disposable DB.
 *
 * All data it creates is synthetic and namespaced (see the constants below) and
 * is removed by HDIT_Env::reset() before and after every run.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "helpers.php must run inside WordPress (wp eval-file)\n" );
	exit( 2 );
}

if ( ! class_exists( 'Hedayati_DB_Schema' ) || ! class_exists( 'Hedayati_Course_Run_Service' ) ) {
	fwrite( STDERR, "Hedayati Core is not active — run scripts/wp-install.sh (or .ps1) first.\n" );
	exit( 2 );
}

/**
 * Only ever defined here, inside the disposable WP-CLI acceptance harness.
 * Hedayati_Student_Admin::maybe_exit() checks this so a raw (non wp_die/
 * wp_redirect) admin-post response — the privileged national-ID reveal action,
 * the document download action — can be asserted on in-process instead of
 * calling PHP's exit() and killing the whole suite mid-run. Never reachable
 * in a real deployment.
 */
if ( ! defined( 'HDIT_TESTING' ) ) {
	define( 'HDIT_TESTING', true );
}

/**
 * Minimal assertion recorder with concise PASS/FAIL output and a section rollup.
 */
final class HDIT {

	public static int $pass = 0;
	public static int $fail = 0;
	/** @var string[] */
	public static array $failures = [];

	public static string $section = '';
	private static int $sec_pass  = 0;
	private static int $sec_fail  = 0;

	/** Synthetic-data namespace — everything created by the suite carries these. */
	public const USER_PREFIX  = 'hdit_';
	public const EMAIL_DOMAIN  = 'hedayati.test';
	public const POST_MARKER   = '_hdit_synthetic';

	public static function section( string $name ): void {
		self::flush_section();
		self::$section  = $name;
		self::$sec_pass = 0;
		self::$sec_fail = 0;
		echo "\n## {$name}\n";
	}

	private static function flush_section(): void {
		if ( '' === self::$section ) {
			return;
		}
		printf(
			"   -> %s (%d passed, %d failed)\n",
			0 === self::$sec_fail ? 'PASS' : 'FAIL',
			self::$sec_pass,
			self::$sec_fail
		);
	}

	public static function ok( string $desc, bool $cond ): bool {
		if ( $cond ) {
			self::$pass++;
			self::$sec_pass++;
			echo "  [PASS] {$desc}\n";
		} else {
			self::$fail++;
			self::$sec_fail++;
			self::$failures[] = ( '' !== self::$section ? self::$section . ' :: ' : '' ) . $desc;
			echo "  [FAIL] {$desc}\n";
		}
		return $cond;
	}

	public static function eq( string $desc, mixed $expected, mixed $actual ): bool {
		$cond = ( $expected === $actual );
		if ( ! $cond ) {
			$desc .= sprintf( '  (expected %s, got %s)', self::dump( $expected ), self::dump( $actual ) );
		}
		return self::ok( $desc, $cond );
	}

	public static function is_wp_error( string $desc, mixed $thing, ?string $code = null ): bool {
		$is = is_wp_error( $thing );
		if ( $is && null !== $code && $thing->get_error_code() !== $code ) {
			$desc .= sprintf( '  (expected code %s, got %s)', $code, $thing->get_error_code() );
			$is    = false;
		}
		if ( ! $is && ! is_wp_error( $thing ) ) {
			$desc .= '  (not a WP_Error: ' . self::dump( $thing ) . ')';
		}
		return self::ok( $desc, $is );
	}

	public static function not_wp_error( string $desc, mixed $thing ): bool {
		if ( is_wp_error( $thing ) ) {
			$desc .= '  (WP_Error ' . $thing->get_error_code() . ': ' . $thing->get_error_message() . ')';
		}
		return self::ok( $desc, ! is_wp_error( $thing ) );
	}

	private static function dump( mixed $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( null === $v ) {
			return 'null';
		}
		if ( is_scalar( $v ) ) {
			return (string) $v;
		}
		if ( is_array( $v ) ) {
			return 'array(' . count( $v ) . ')';
		}
		return get_debug_type( $v );
	}

	public static function finish(): int {
		self::flush_section();
		self::$section = '';

		echo "\n========================================================================\n";
		printf(
			"ACCEPTANCE TOTAL: %d passed, %d failed  (%d assertions)\n",
			self::$pass,
			self::$fail,
			self::$pass + self::$fail
		);

		if ( self::$fail > 0 ) {
			echo "FAILED CHECKS:\n";
			foreach ( self::$failures as $f ) {
				echo "  - {$f}\n";
			}
			echo "\nRESULT: FAIL\n";
			return 1;
		}

		echo "RESULT: PASS\n";
		return 0;
	}
}

/**
 * Disposable environment control: synthetic-data factories + a hard reset to a
 * known-clean state.
 */
final class HDIT_Env {

	/** @return string[] Hedayati tables, child-before-parent for clean deletes. */
	public static function tables(): array {
		return [
			Hedayati_DB_Schema::get_table_audit_log(),
			Hedayati_DB_Schema::get_table_attendance(),
			Hedayati_DB_Schema::get_table_enrollments(),
			Hedayati_DB_Schema::get_table_sessions(),
			Hedayati_DB_Schema::get_table_run_staff(),
			Hedayati_DB_Schema::get_table_course_runs(),
			Hedayati_DB_Schema::get_table_user_phones(),
			Hedayati_DB_Schema::get_table_documents(),
			Hedayati_DB_Schema::get_table_student_verification(),
			Hedayati_DB_Schema::get_table_consultations(),
			Hedayati_DB_Schema::get_table_certificates(),
			Hedayati_DB_Schema::get_table_session_materials(),
			Hedayati_DB_Schema::get_table_support_tickets(),
			Hedayati_DB_Schema::get_table_support_messages(),
			Hedayati_DB_Schema::get_table_notifications(),
		];
	}

	/**
	 * Phase 2C: remove any files this run wrote under the private document
	 * storage root (local/Docker-CI fallback only — see
	 * Hedayati_Document_Storage::resolve_root()). Called by reset() so no
	 * synthetic document bytes survive between/after runs.
	 */
	public static function clear_document_storage(): void {
		$root = Hedayati_Document_Storage::resolve_root();
		if ( is_wp_error( $root ) ) {
			return;
		}

		$dirs = glob( rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR );
		foreach ( (array) $dirs as $dir ) {
			$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*' );
			foreach ( (array) $files as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			@rmdir( $dir );
		}
	}

	/**
	 * Refuse to run against anything but the disposable local container
	 * (HD-004). Two independent, cheap-to-fake-badly signals: WP-CLI is the
	 * only way this suite is ever invoked, and docker/docker-compose.yml
	 * hardcodes WP_ENVIRONMENT_TYPE=local. This does not make destructive
	 * use against a real site impossible, but it stops the ordinary mistake
	 * of pointing wp-tests/ at the wrong WordPress.
	 *
	 * @throws RuntimeException if the environment does not look disposable.
	 */
	public static function assert_disposable_environment(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new RuntimeException(
				'refusing to run: not invoked via WP-CLI. This harness deletes data — never load it on a real site.'
			);
		}

		if ( 'local' !== wp_get_environment_type() ) {
			throw new RuntimeException(
				sprintf(
					'refusing to run: WP_ENVIRONMENT_TYPE is "%s", expected "local". ' .
					'This harness deletes data — never point it at a real WordPress site.',
					wp_get_environment_type()
				)
			);
		}
	}

	/**
	 * Remove every trace of synthetic data and empty the Hedayati tables.
	 * Deterministic: two runs in a row leave the exact same state.
	 *
	 * Returns true only once every table is CONFIRMED empty and no synthetic
	 * user/post remains — a DELETE returning false, or a row surviving the
	 * pass, makes this return false instead of silently reporting success
	 * (HD-004). It never throws for a cleanup shortfall; only
	 * assert_disposable_environment() (called first) throws, and only when
	 * this does not look like the disposable container at all.
	 */
	public static function reset(): bool {
		global $wpdb;

		self::assert_disposable_environment();

		require_once ABSPATH . 'wp-admin/includes/user.php';

		wp_set_current_user( 0 );
		$_POST = [];

		$ok = true;

		// 1. Synthetic posts (course + teacher) — fires the cascade hooks.
		$posts = get_posts(
			[
				'post_type'        => [ 'course', Hedayati_Teacher::POST_TYPE ],
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'meta_key'         => HDIT::POST_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
			]
		);
		foreach ( $posts as $pid ) {
			if ( ! wp_delete_post( (int) $pid, true ) ) {
				$ok = false;
			}
		}

		// 2. Synthetic users — fires deleted_user cascade hooks.
		$users = get_users(
			[
				'search'         => HDIT::USER_PREFIX . '*',
				'search_columns' => [ 'user_login' ],
				'fields'         => 'ID',
			]
		);
		foreach ( $users as $uid ) {
			if ( ! wp_delete_user( (int) $uid ) ) {
				$ok = false;
			}
		}

		// 3. Empty every Hedayati table (also clears audit noise from steps 1–2).
		foreach ( self::tables() as $table ) {
			if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) { // phpcs:ignore WordPress.DB
				$ok = false;
			}
		}

		// 3b. Private document bytes (Phase 2C) — never left behind, even
		// though the metadata rows are already gone from step 3 above.
		if ( class_exists( 'Hedayati_Document_Storage' ) ) {
			self::clear_document_storage();
		}

		// 4. Rate-limiter transients.
		if (
			false === $wpdb->query(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_hd_rl_%'
				    OR option_name LIKE '_transient_timeout_hd_rl_%'
				    OR option_name LIKE '_transient_hd_consult_rl_%'
				    OR option_name LIKE '_transient_timeout_hd_consult_rl_%'
				    OR option_name LIKE '_transient_hd_cert_verify_rl_%'
				    OR option_name LIKE '_transient_timeout_hd_cert_verify_rl_%'"
			)
		) {
			$ok = false;
		}

		wp_cache_flush();

		return $ok && self::verify_clean();
	}

	/**
	 * Independently re-check the state reset() just tried to establish —
	 * a DELETE can return a non-false "success" and still leave rows behind
	 * (e.g. a foreign hook re-inserting one), so this re-queries rather than
	 * trusting the write results alone.
	 */
	private static function verify_clean(): bool {
		global $wpdb;

		foreach ( self::tables() as $table ) {
			if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) !== 0 ) {
				return false;
			}
		}

		$remaining_users = get_users(
			[
				'search'         => HDIT::USER_PREFIX . '*',
				'search_columns' => [ 'user_login' ],
				'fields'         => 'ID',
			]
		);
		if ( ! empty( $remaining_users ) ) {
			return false;
		}

		$remaining_posts = get_posts(
			[
				'post_type'        => [ 'course', Hedayati_Teacher::POST_TYPE ],
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => HDIT::POST_MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => false,
			]
		);

		return empty( $remaining_posts );
	}

	/**
	 * Phase 2C — write a minimal, genuinely valid file of the given kind to a
	 * temp path for document-storage tests. Real bytes (not just a renamed
	 * extension) so content-sniffing assertions are meaningful.
	 */
	public static function write_temp_file( string $kind ): string {
		$path = sys_get_temp_dir() . '/hdit_' . uniqid( '', true );

		switch ( $kind ) {
			case 'pdf':
				file_put_contents( $path, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF" );
				break;
			case 'png':
				// 1x1 transparent PNG.
				file_put_contents( $path, base64_decode(
					'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
				) );
				break;
			case 'jpg':
				// 1x1 white JPEG.
				file_put_contents( $path, base64_decode(
					'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
				) );
				break;
			case 'html_as_pdf':
				// Malicious content, .pdf extension applied by the caller — MUST be rejected.
				file_put_contents( $path, '<html><body><script>alert(1)</script></body></html>' );
				break;
			case 'text_as_jpg':
				file_put_contents( $path, 'just some plain text, not an image' );
				break;
			default:
				file_put_contents( $path, 'unrecognized' );
		}

		return $path;
	}

	public static function password_for( string $slug ): string {
		return 'Corr3ct-Horse!' . $slug;
	}

	public static function make_user( string $slug, string $role ): int {
		$uid = wp_insert_user(
			[
				'user_login' => HDIT::USER_PREFIX . $slug,
				'user_pass'  => self::password_for( $slug ),
				'user_email' => $slug . '@' . HDIT::EMAIL_DOMAIN,
				'role'       => $role,
			]
		);

		if ( is_wp_error( $uid ) ) {
			throw new RuntimeException( "make_user({$slug}) failed: " . $uid->get_error_message() );
		}

		return (int) $uid;
	}

	public static function make_course( string $title = 'Synthetic course' ): int {
		return self::make_post( 'course', $title );
	}

	public static function make_teacher( string $title = 'Synthetic teacher', int $link_user = 0 ): int {
		$pid = self::make_post( Hedayati_Teacher::POST_TYPE, $title );
		update_post_meta( $pid, Hedayati_Teacher::META_USER_ID, $link_user );
		return $pid;
	}

	private static function make_post( string $type, string $title ): int {
		$pid = wp_insert_post(
			[
				'post_type'   => $type,
				'post_title'  => $title,
				'post_status' => 'publish',
			],
			true
		);

		if ( is_wp_error( $pid ) ) {
			throw new RuntimeException( "make_post({$type}) failed: " . $pid->get_error_message() );
		}

		update_post_meta( $pid, HDIT::POST_MARKER, 1 );
		return (int) $pid;
	}
}
