<?php
/**
 * Phase 2B — Attendance repository & service.
 *
 * One attendance row per (session, enrollment) — enforced by
 * `UNIQUE(session_id, enrollment_id)`. `record()` is an upsert. Every write
 * verifies that the enrollment and the session belong to the SAME run, so a
 * caller cannot mark attendance for a student who is not in that cohort.
 *
 * Deleting the recording user keeps the attendance row but nulls `recorded_by`.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Attendance_Service {

	public static function init(): void {
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_attendance();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @return array<int, array> keyed by enrollment_id.
	 */
	public static function list_for_session( int $session_id ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_attendance();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %d", $session_id ),
			ARRAY_A
		);

		$out = [];
		foreach ( $rows ?: [] as $row ) {
			$hydrated                          = self::hydrate( $row );
			$out[ $hydrated['enrollment_id'] ] = $hydrated;
		}

		return $out;
	}

	// ── Record (upsert) ─────────────────────────────────────────────────────

	/**
	 * @return int|WP_Error Attendance row ID.
	 */
	public static function record( int $session_id, int $enrollment_id, string $status, array $opts = [] ): int|WP_Error {
		global $wpdb;

		$session = Hedayati_Session_Service::get( $session_id );
		if ( null === $session ) {
			return new WP_Error( 'invalid_session', esc_html__( 'جلسهٔ معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		$enrollment = Hedayati_Enrollment_Service::get( $enrollment_id );
		if ( null === $enrollment ) {
			return new WP_Error( 'invalid_enrollment', esc_html__( 'ثبت‌نام معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		if ( $enrollment['run_id'] !== $session['run_id'] ) {
			return new WP_Error( 'run_mismatch', esc_html__( 'این دانشجو در دورهٔ این جلسه ثبت‌نام نشده است.', 'hedayati-core' ) );
		}

		$canonical = Hedayati_Academic_Validation::parse_attendance_status( $status );
		if ( null === $canonical ) {
			return new WP_Error( 'invalid_attendance_status', esc_html__( 'وضعیت حضور و غیاب نامعتبر است.', 'hedayati-core' ) );
		}

		$note        = isset( $opts['note'] ) ? mb_substr( sanitize_text_field( (string) $opts['note'] ), 0, 255 ) : '';
		$recorded_by = isset( $opts['recorded_by'] ) ? (int) $opts['recorded_by'] : 0;
		$recorded_by = ( $recorded_by > 0 && get_user_by( 'id', $recorded_by ) ) ? $recorded_by : 0;
		$now         = current_time( 'mysql', true );
		$table       = Hedayati_DB_Schema::get_table_attendance();

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$table} WHERE session_id = %d AND enrollment_id = %d",
			$session_id,
			$enrollment_id
		), ARRAY_A );

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				[
					'status'      => $canonical,
					'note'        => $note,
					'recorded_by' => $recorded_by > 0 ? $recorded_by : null,
					'recorded_at' => $now,
					'updated_at'  => $now,
				],
				[ 'id' => (int) $existing['id'] ],
				[ '%s', '%s', '%d', '%s', '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				return new WP_Error( 'db_update_failed', esc_html__( 'ثبت حضور و غیاب ناموفق بود.', 'hedayati-core' ) );
			}

			if ( (string) $existing['status'] !== $canonical ) {
				Hedayati_Audit_Log::record(
					'attendance.updated',
					'attendance',
					(int) $existing['id'],
					'session #' . $session_id . ' · enrollment #' . $enrollment_id . ' · ' . $existing['status'] . ' -> ' . $canonical
				);
			}

			return (int) $existing['id'];
		}

		$inserted = $wpdb->insert(
			$table,
			[
				'session_id'    => $session_id,
				'enrollment_id' => $enrollment_id,
				'status'        => $canonical,
				'note'          => $note,
				'recorded_by'   => $recorded_by > 0 ? $recorded_by : null,
				'recorded_at'   => $now,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Lost a race with a concurrent insert — fall back to the existing row.
			$again = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE session_id = %d AND enrollment_id = %d",
				$session_id,
				$enrollment_id
			) );

			if ( $again ) {
				return (int) $again;
			}

			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت حضور و غیاب ناموفق بود.', 'hedayati-core' ) );
		}

		$attendance_id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record(
			'attendance.recorded',
			'attendance',
			$attendance_id,
			'session #' . $session_id . ' · enrollment #' . $enrollment_id . ' · ' . $canonical
		);

		return $attendance_id;
	}

	/**
	 * Record many marks for one session at once.
	 *
	 * @param array<int,string> $marks enrollment_id => status
	 * @return array{recorded:int, errors:array<int,string>}
	 */
	public static function record_bulk( int $session_id, array $marks, int $recorded_by = 0 ): array {
		$recorded = 0;
		$errors   = [];

		foreach ( $marks as $enrollment_id => $status ) {
			$result = self::record( $session_id, (int) $enrollment_id, (string) $status, [ 'recorded_by' => $recorded_by ] );

			if ( is_wp_error( $result ) ) {
				$errors[ (int) $enrollment_id ] = $result->get_error_message();
			} else {
				$recorded++;
			}
		}

		return [ 'recorded' => $recorded, 'errors' => $errors ];
	}

	public static function delete_mark( int $id ): bool {
		global $wpdb;

		if ( $id <= 0 ) {
			return false;
		}

		$table    = Hedayati_DB_Schema::get_table_attendance();
		$affected = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		if ( $affected ) {
			Hedayati_Audit_Log::record( 'attendance.deleted', 'attendance', $id );
		}

		return false !== $affected;
	}

	/**
	 * On account deletion, the attendance ROW is kept (academic history) — only the
	 * `recorded_by` back-reference is nulled. This is integrity housekeeping, not a
	 * domain action, so it is intentionally NOT written to the audit log.
	 */
	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$table = Hedayati_DB_Schema::get_table_attendance();
		$wpdb->update(
			$table,
			[ 'recorded_by' => null ],
			[ 'recorded_by' => $user_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'            => (int) $row['id'],
			'session_id'    => (int) $row['session_id'],
			'enrollment_id' => (int) $row['enrollment_id'],
			'status'        => (string) $row['status'],
			'note'          => (string) $row['note'],
			'recorded_by'   => isset( $row['recorded_by'] ) && null !== $row['recorded_by'] ? (int) $row['recorded_by'] : null,
			'recorded_at'   => (string) $row['recorded_at'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		];
	}
}
