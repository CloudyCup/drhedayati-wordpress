<?php
/**
 * Phase 2B — Enrollment repository & service.
 *
 * One enrollment per (run, student) — enforced by `UNIQUE(run_id, user_id)`.
 * Capacity, when known, is enforced against the count of non-terminal enrollments
 * (`active`) and can be bypassed with an explicit $allow_overfill flag for staff
 * overrides. Deleting an enrollment cascades to its attendance rows.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Enrollment_Service {

	public static function init(): void {
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	public static function get( int $enrollment_id ): ?array {
		global $wpdb;

		if ( $enrollment_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_enrollments();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $enrollment_id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	public static function get_by_run_user( int $run_id, int $user_id ): ?array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_enrollments();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d AND user_id = %d", $run_id, $user_id ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array{status?:string} $args
	 * @return array<int, array>
	 */
	public static function list_for_run( int $run_id, array $args = [] ): array {
		global $wpdb;

		$table  = Hedayati_DB_Schema::get_table_enrollments();
		$sql    = "SELECT * FROM {$table} WHERE run_id = %d";
		$params = [ $run_id ];

		if ( ! empty( $args['status'] ) ) {
			$sql     .= ' AND status = %s';
			$params[] = Hedayati_Academic_Validation::sanitize_enrollment_status( (string) $args['status'] );
		}

		$sql .= ' ORDER BY id ASC';

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @return array<int, array>
	 */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_enrollments();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC", $user_id ),
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_active( int $run_id ): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_enrollments();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE run_id = %d AND status = 'active'",
			$run_id
		) );
	}

	// ── Enroll ──────────────────────────────────────────────────────────────

	/**
	 * @return int|WP_Error New enrollment ID.
	 */
	public static function enroll( int $run_id, int $user_id, bool $allow_overfill = false ): int|WP_Error {
		global $wpdb;

		$run = Hedayati_Course_Run_Service::get( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'invalid_run', esc_html__( 'دورهٔ اجرایی معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'invalid_student', esc_html__( 'دانشجوی معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		if ( in_array( $run['run_status'], [ 'completed', 'cancelled' ], true ) ) {
			return new WP_Error( 'run_closed', esc_html__( 'این دوره به پایان رسیده یا لغو شده و ثبت‌نام جدید نمی‌پذیرد.', 'hedayati-core' ) );
		}

		if ( self::get_by_run_user( $run_id, $user_id ) ) {
			return new WP_Error( 'already_enrolled', esc_html__( 'این دانشجو از قبل در این دوره ثبت‌نام شده است.', 'hedayati-core' ) );
		}

		if ( ! $allow_overfill && null !== $run['capacity'] && self::count_active( $run_id ) >= $run['capacity'] ) {
			return new WP_Error( 'run_full', esc_html__( 'ظرفیت این دوره تکمیل است.', 'hedayati-core' ) );
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_enrollments();

		$inserted = $wpdb->insert(
			$table,
			[
				'run_id'      => $run_id,
				'user_id'     => $user_id,
				'status'      => 'active',
				'enrolled_at' => $now,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			if ( self::get_by_run_user( $run_id, $user_id ) ) {
				return new WP_Error( 'already_enrolled', esc_html__( 'این دانشجو از قبل در این دوره ثبت‌نام شده است.', 'hedayati-core' ) );
			}
			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت‌نام ناموفق بود.', 'hedayati-core' ) );
		}

		return (int) $wpdb->insert_id;
	}

	// ── Status transitions ──────────────────────────────────────────────────

	public static function set_status( int $enrollment_id, string $status ): true|WP_Error {
		global $wpdb;

		$existing = self::get( $enrollment_id );
		if ( null === $existing ) {
			return new WP_Error( 'enrollment_not_found', esc_html__( 'ثبت‌نام یافت نشد.', 'hedayati-core' ) );
		}

		$canonical = strtolower( trim( $status ) );
		if ( ! in_array( $canonical, Hedayati_Academic_Validation::ENROLLMENT_STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', esc_html__( 'وضعیت ثبت‌نام نامعتبر است.', 'hedayati-core' ) );
		}

		if ( $canonical === $existing['status'] ) {
			return true;
		}

		$table   = Hedayati_DB_Schema::get_table_enrollments();
		$updated = $wpdb->update(
			$table,
			[ 'status' => $canonical, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $enrollment_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', esc_html__( 'به‌روزرسانی وضعیت ثبت‌نام ناموفق بود.', 'hedayati-core' ) );
		}

		return true;
	}

	// ── Delete ──────────────────────────────────────────────────────────────

	public static function delete_enrollment( int $enrollment_id ): bool {
		global $wpdb;

		if ( $enrollment_id <= 0 ) {
			return false;
		}

		$attendance   = Hedayati_DB_Schema::get_table_attendance();
		$enrollments  = Hedayati_DB_Schema::get_table_enrollments();

		$wpdb->delete( $attendance, [ 'enrollment_id' => $enrollment_id ], [ '%d' ] );

		return false !== $wpdb->delete( $enrollments, [ 'id' => $enrollment_id ], [ '%d' ] );
	}

	public static function on_user_deleted( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		foreach ( self::list_for_user( $user_id ) as $enrollment ) {
			self::delete_enrollment( (int) $enrollment['id'] );
		}
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'run_id'      => (int) $row['run_id'],
			'user_id'     => (int) $row['user_id'],
			'status'      => (string) $row['status'],
			'enrolled_at' => (string) $row['enrolled_at'],
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		];
	}
}
