<?php
/**
 * Phase 2A — Identity & Database — WordPress-runtime integration checks.
 *
 * Covers the "NOT RUN" runtime rows of docs/PHASE_2A_ACCEPTANCE.md that a static
 * suite cannot: real migrations, live UNIQUE constraints, the role structure as
 * WordPress actually resolves it, the authenticate filter chain, and the
 * transient-backed rate limiter.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

function hdit_run_phase_2a(): void {
	global $wpdb;

	// ── Migrations & version markers ────────────────────────────────────────
	HDIT::section( 'Phase 2A — migrations & version markers' );

	HDIT::eq(
		'installed DB schema option == CURRENT_DB_VERSION',
		Hedayati_DB_Schema::CURRENT_DB_VERSION,
		get_option( Hedayati_DB_Schema::OPTION_DB_VERSION )
	);
	HDIT::eq( 'DB schema version is 2.3.0', '2.3.0', get_option( Hedayati_DB_Schema::OPTION_DB_VERSION ) );
	HDIT::eq(
		'installed roles schema option == ROLES_VERSION',
		Hedayati_Roles::ROLES_VERSION,
		get_option( Hedayati_Roles::OPTION_ROLES_VERSION )
	);
	HDIT::eq( 'roles schema version is current', Hedayati_Roles::ROLES_VERSION, get_option( Hedayati_Roles::OPTION_ROLES_VERSION ) );
	HDIT::eq(
		'managed-capability list has 24 entries',
		24,
		count( (array) get_option( Hedayati_Roles::OPTION_MANAGED_CAPS ) )
	);
	HDIT::eq( 'no migration lock is held after a completed run', false, (bool) get_option( Hedayati_DB_Schema::LOCK_OPTION ) );

	$before = get_option( Hedayati_DB_Schema::OPTION_DB_VERSION );
	Hedayati_DB_Schema::migrate();
	Hedayati_DB_Schema::migrate();
	HDIT::eq( 're-running migrate() is idempotent (version unchanged)', $before, get_option( Hedayati_DB_Schema::OPTION_DB_VERSION ) );

	// ── Tables, columns & indexes ──────────────────────────────────────────
	HDIT::section( 'Phase 2A — tables, columns & indexes' );

	$prefix = $wpdb->prefix;
	HDIT::ok( 'table prefix is NOT the default wp_ (canonical data rule)', 'wp_' !== $prefix );

	foreach (
		[
			'hedayati_user_phones',
			'hedayati_course_runs',
			'hedayati_run_staff',
			'hedayati_sessions',
			'hedayati_enrollments',
			'hedayati_attendance',
			'hedayati_audit_log',
		] as $suffix
	) {
		$table  = $prefix . $suffix;
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		HDIT::eq( "table {$suffix} exists", $table, $exists );
	}

	$charset = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT CCSA.CHARACTER_SET_NAME
			   FROM information_schema.TABLES T
			   JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
			     ON CCSA.COLLATION_NAME = T.TABLE_COLLATION
			  WHERE T.TABLE_SCHEMA = %s AND T.TABLE_NAME = %s",
			$wpdb->dbname,
			$prefix . 'hedayati_user_phones'
		)
	);
	HDIT::eq( 'hedayati_user_phones charset is utf8mb4', 'utf8mb4', $charset );

	$run_status_type = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT DATA_TYPE FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'run_status'",
			$wpdb->dbname,
			$prefix . 'hedayati_course_runs'
		)
	);
	HDIT::eq( 'course_runs.run_status is varchar, not MySQL ENUM (D13)', 'varchar', $run_status_type );

	$has_index = static function ( string $table, string $index ) use ( $wpdb ): bool {
		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
	};
	HDIT::ok( 'UNIQUE uq_phone_e164 on user_phones', $has_index( $prefix . 'hedayati_user_phones', 'uq_phone_e164' ) );
	HDIT::ok( 'UNIQUE uq_user_id on user_phones', $has_index( $prefix . 'hedayati_user_phones', 'uq_user_id' ) );
	HDIT::ok( 'UNIQUE uq_run_session on sessions', $has_index( $prefix . 'hedayati_sessions', 'uq_run_session' ) );
	HDIT::ok( 'UNIQUE uq_run_user on enrollments', $has_index( $prefix . 'hedayati_enrollments', 'uq_run_user' ) );
	HDIT::ok( 'UNIQUE uq_session_enrollment on attendance', $has_index( $prefix . 'hedayati_attendance', 'uq_session_enrollment' ) );

	$audit_cols = $wpdb->get_col( "SHOW COLUMNS FROM {$prefix}hedayati_audit_log" );
	foreach ( [ 'ip', 'ip_address', 'user_agent', 'ua', 'updated_at', 'context', 'request_body', 'payload' ] as $forbidden ) {
		HDIT::ok( "audit_log has NO '{$forbidden}' column (metadata-only, D16)", ! in_array( $forbidden, $audit_cols, true ) );
	}
	foreach ( [ 'actor_id', 'action', 'object_type', 'object_id', 'note', 'created_at' ] as $req ) {
		HDIT::ok( "audit_log has the '{$req}' column", in_array( $req, $audit_cols, true ) );
	}

	// ── Roles & capabilities ──────────────────────────────────────────────
	HDIT::section( 'Phase 2A — roles & capabilities (live WP_Role resolution)' );

	$matrix = [
		'hedayati_manager'  => [
			'has' => [ 'hedayati_manage_teachers', 'hedayati_manage_course_runs', 'hedayati_assign_staff', 'hedayati_view_audit_logs', 'hedayati_manage_enrollments' ],
			'not' => [ 'manage_options', 'hedayati_record_attendance' ],
		],
		'teacher'           => [
			'has' => [ 'hedayati_record_attendance', 'hedayati_manage_assigned_sessions', 'hedayati_view_assigned_roster' ],
			'not' => [ 'hedayati_manage_teachers', 'hedayati_manage_course_runs' ],
		],
		'teacher_assistant' => [
			'has' => [ 'hedayati_view_assigned_runs', 'hedayati_view_assigned_roster' ],
			'not' => [ 'hedayati_record_attendance', 'hedayati_manage_course_runs' ],
		],
		'reception'         => [
			'has' => [ 'hedayati_create_enrollments', 'hedayati_lookup_students', 'hedayati_initiate_verification' ],
			'not' => [ 'hedayati_manage_teachers', 'hedayati_verify_students', 'hedayati_manage_course_runs' ],
		],
		'student'           => [
			'has' => [ 'hedayati_view_own_portal', 'hedayati_edit_own_profile' ],
			'not' => [ 'hedayati_manage_course_runs', 'hedayati_lookup_students' ],
		],
	];

	foreach ( $matrix as $role_slug => $spec ) {
		$role = get_role( $role_slug );
		if ( ! HDIT::ok( "role {$role_slug} is registered", $role instanceof WP_Role ) ) {
			continue;
		}
		foreach ( $spec['has'] as $cap ) {
			HDIT::ok( "{$role_slug} HAS {$cap}", $role->has_cap( $cap ) );
		}
		foreach ( $spec['not'] as $cap ) {
			HDIT::ok( "{$role_slug} does NOT have {$cap}", ! $role->has_cap( $cap ) );
		}
	}

	$admin = get_role( 'administrator' );
	HDIT::ok( 'administrator gained hedayati_manage_teachers', $admin && $admin->has_cap( 'hedayati_manage_teachers' ) );
	HDIT::ok( 'administrator retains native manage_options', $admin && $admin->has_cap( 'manage_options' ) );

	// ── Phone normalization & uniqueness ──────────────────────────────────
	HDIT::section( 'Phase 2A — phone normalization & uniqueness' );

	foreach (
		[
			[ '09123456789', '+989123456789' ],
			[ '9123456789', '+989123456789' ],
			[ '+98 912 345 6789', '+989123456789' ],
			[ '0098-912-345-6789', '+989123456789' ],
			[ "\u{06F0}\u{06F9}\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}\u{06F8}\u{06F9}", '+989123456789' ],
		] as [ $in, $out ]
	) {
		HDIT::eq( "normalize({$in})", $out, Hedayati_Phone::normalize( $in ) );
	}
	HDIT::is_wp_error( 'normalize(landline 02112345678) rejected', Hedayati_Phone::normalize( '02112345678' ) );
	HDIT::is_wp_error( 'normalize("not-a-phone") rejected', Hedayati_Phone::normalize( 'not-a-phone' ) );

	$a = HDIT_Env::make_user( 'phone_a', 'student' );
	$b = HDIT_Env::make_user( 'phone_b', 'student' );

	HDIT::not_wp_error( 'assign_phone to user A', Hedayati_User_Phone_Service::assign_phone( $a, '09120000001' ) );
	HDIT::is_wp_error(
		'assign the SAME phone (different format) to user B is refused',
		Hedayati_User_Phone_Service::assign_phone( $b, '0912 000 0001' ),
		'phone_already_exists'
	);
	HDIT::eq(
		'find_user_by_phone resolves a non-canonical form to user A',
		$a,
		(int) ( Hedayati_User_Phone_Service::find_user_by_phone( '+98 912 000 0001' )?->ID ?? 0 )
	);

	$table = Hedayati_DB_Schema::get_table_user_phones();
	$now   = current_time( 'mysql', true );
	$wpdb->hide_errors();
	$dup = $wpdb->insert(
		$table,
		[ 'user_id' => $b, 'phone_e164' => '+989120000001', 'is_verified' => 0, 'created_at' => $now, 'updated_at' => $now ],
		[ '%d', '%s', '%d', '%s', '%s' ]
	);
	$wpdb->show_errors();
	HDIT::eq( 'a direct duplicate-phone INSERT is rejected by uq_phone_e164', false, $dup );

	Hedayati_User_Phone_Service::verify_phone( $a );
	HDIT::ok(
		'phone marked verified',
		true === ( Hedayati_User_Phone_Service::get_phone_record_by_user( $a )['is_verified'] ?? null )
	);
	Hedayati_User_Phone_Service::update_phone( $a, '09120000009' );
	HDIT::ok(
		'changing the phone number resets is_verified to false',
		false === ( Hedayati_User_Phone_Service::get_phone_record_by_user( $a )['is_verified'] ?? null )
	);

	// HD-002: the deleted_user -> delete_phone hook is asserted DIRECTLY here,
	// before any HDIT_Env::reset() runs. reset() also empties the phone table,
	// which would otherwise hide a cleanup failure behind a clean final state.
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$doomed = HDIT_Env::make_user( 'phone_del', 'student' );
	Hedayati_User_Phone_Service::assign_phone( $doomed, '09120000777' );
	$rows_before = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $doomed )
	);
	HDIT::eq( 'HD-002: phone row exists for the doomed user before deletion', 1, $rows_before );

	$deleted = wp_delete_user( $doomed );
	HDIT::ok( 'HD-002: wp_delete_user() reports success', true === $deleted );
	HDIT::ok( 'HD-002: the WP user record is gone', false === get_user_by( 'id', $doomed ) );

	$rows_after = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $doomed )
	);
	HDIT::eq( 'HD-002: deleted_user hook removed the phone row (checked BEFORE any reset)', 0, $rows_after );
	HDIT::ok(
		'HD-002: the freed phone number is immediately available again',
		Hedayati_User_Phone_Service::is_phone_available( '09120000777' )
	);

	// ── Username / phone authentication ──────────────────────────────────
	HDIT::section( 'Phase 2A — username / phone authentication (authenticate chain)' );

	$uid   = HDIT_Env::make_user( 'auth_user', 'student' );
	$login = HDIT::USER_PREFIX . 'auth_user';
	$pass  = HDIT_Env::password_for( 'auth_user' );
	Hedayati_User_Phone_Service::assign_phone( $uid, '09121110000' );

	$by_username = wp_authenticate( $login, $pass );
	HDIT::ok( 'username + correct password -> the right WP_User', $by_username instanceof WP_User && $by_username->ID === $uid );

	$by_phone = wp_authenticate( '09121110000', $pass );
	HDIT::ok( 'phone (09XXXXXXXXX) + correct password -> the right WP_User', $by_phone instanceof WP_User && $by_phone->ID === $uid );

	$by_e164 = wp_authenticate( '+989121110000', $pass );
	HDIT::ok( 'phone (E.164) + correct password -> the same WP_User', $by_e164 instanceof WP_User && $by_e164->ID === $uid );

	$wrong = wp_authenticate( '09121110000', 'definitely-wrong' );
	HDIT::is_wp_error( 'phone + wrong password -> WP_Error', $wrong );

	$unknown = wp_authenticate( '09129999999', 'anything' );
	HDIT::is_wp_error( 'unknown phone -> WP_Error (generic, no enumeration)', $unknown );
	HDIT::ok(
		'unknown-phone and wrong-password errors share one generic message',
		is_wp_error( $wrong ) && is_wp_error( $unknown )
			&& $wrong->get_error_message() === $unknown->get_error_message()
	);

	// ── Rate limiter ────────────────────────────────────────────────────
	HDIT::section( 'Phase 2A — authentication rate limiter' );

	$ip  = '203.0.113.9';
	$max = (int) Hedayati_Rate_Limiter::get_config()['identifier_max_attempts'];

	Hedayati_Rate_Limiter::clear_identifier_attempts( '09121110000' );
	HDIT::ok( 'identifier is not rate-limited initially', ! Hedayati_Rate_Limiter::is_rate_limited( '09121110000', $ip ) );

	for ( $i = 0; $i < $max; $i++ ) {
		Hedayati_Rate_Limiter::record_failure( '09121110000', $ip );
	}
	HDIT::ok( "identifier locked after {$max} failures", Hedayati_Rate_Limiter::is_rate_limited( '09121110000', $ip ) );
	HDIT::ok(
		'a different identifier on the same IP is still allowed (per-account bucket)',
		! Hedayati_Rate_Limiter::is_rate_limited( 'hdit_other_person', $ip )
	);
	HDIT::ok(
		'lockout bucket is shared across phone formats (canonicalised identifier)',
		Hedayati_Rate_Limiter::is_rate_limited( '+989121110000', '198.51.100.7' )
	);
	Hedayati_Rate_Limiter::clear_identifier_attempts( '09121110000' );
	HDIT::ok( 'clear_identifier_attempts lifts the lock', ! Hedayati_Rate_Limiter::is_rate_limited( '09121110000', '198.51.100.7' ) );

	for ( $i = 0; $i < $max; $i++ ) {
		Hedayati_Rate_Limiter::record_failure( $login, $ip );
	}
	$blocked = wp_authenticate( $login, $pass );
	HDIT::is_wp_error( 'a rate-limited identifier is blocked even WITH the correct password', $blocked, 'too_many_retries' );

	$key_method = new ReflectionMethod( Hedayati_Rate_Limiter::class, 'get_transient_key' );
	$key_method->setAccessible( true );
	$id_key = (string) $key_method->invoke( null, 'id', $login );
	$count_before_blocked_retry = (int) get_transient( $id_key );
	Hedayati_Auth::on_login_failed( $login, new WP_Error( 'too_many_retries' ) );
	HDIT::eq(
		'an already-blocked retry does not increment or extend its lockout counter',
		$count_before_blocked_retry,
		(int) get_transient( $id_key )
	);
	Hedayati_Rate_Limiter::clear_identifier_attempts( $login );
}
