<?php
/**
 * Phase 2B — Class Session repository & service.
 *
 * Sessions belong to a Course Run. `UNIQUE(run_id, session_number)` is enforced at
 * the database level; canonical `starts_at` / `ends_at` are stored as datetimes.
 * Deleting a session cascades to its attendance rows.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Session_Service {

	public static function init(): void {}

	// ── Read ─────────────────────────────────────────────────────────────────

	public static function get( int $session_id ): ?array {
		global $wpdb;

		if ( $session_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_sessions();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $session_id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @return array<int, array>
	 */
	public static function list_for_run( int $run_id ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_sessions();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY session_number ASC", $run_id ),
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function next_session_number( int $run_id ): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_sessions();
		$max   = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(session_number) FROM {$table} WHERE run_id = %d", $run_id )
		);
		return $max + 1;
	}

	// ── Create ──────────────────────────────────────────────────────────────

	/**
	 * @param array $data run_id (req), session_number (req >0), starts_at (req),
	 *                     ends_at, topic, status
	 * @return int|WP_Error
	 */
	public static function create( array $data ): int|WP_Error {
		global $wpdb;

		$run_id = isset( $data['run_id'] ) ? (int) $data['run_id'] : 0;

		if ( null === Hedayati_Course_Run_Service::get( $run_id ) ) {
			return new WP_Error( 'invalid_run', esc_html__( 'دورهٔ اجرایی معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		$number = Hedayati_Academic_Validation::parse_positive_int( (string) ( $data['session_number'] ?? '' ) );
		if ( null === $number ) {
			return new WP_Error( 'invalid_session_number', esc_html__( 'شمارهٔ جلسه باید یک عدد صحیح مثبت باشد.', 'hedayati-core' ) );
		}

		$fields = self::validate_times_and_meta( $data, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_sessions();

		$row = array_merge( $fields, [
			'run_id'         => $run_id,
			'session_number' => $number,
			'created_at'     => $now,
			'updated_at'     => $now,
		] );

		$inserted = $wpdb->insert( $table, $row, self::formats_for( $row ) );

		if ( false === $inserted ) {
			if ( self::number_taken( $run_id, $number ) ) {
				return new WP_Error( 'session_number_exists', esc_html__( 'جلسه‌ای با این شماره برای این دوره از قبل ثبت شده است.', 'hedayati-core' ) );
			}
			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت جلسه ناموفق بود.', 'hedayati-core' ) );
		}

		$session_id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'session.created', 'session', $session_id, 'run #' . $run_id . ' · session ' . $number );

		return $session_id;
	}

	// ── Update ──────────────────────────────────────────────────────────────

	public static function update( int $session_id, array $data ): true|WP_Error {
		global $wpdb;

		$existing = self::get( $session_id );
		if ( null === $existing ) {
			return new WP_Error( 'session_not_found', esc_html__( 'جلسه یافت نشد.', 'hedayati-core' ) );
		}

		$fields = self::validate_times_and_meta( $data, false, $existing );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( array_key_exists( 'session_number', $data ) ) {
			$number = Hedayati_Academic_Validation::parse_positive_int( (string) $data['session_number'] );
			if ( null === $number ) {
				return new WP_Error( 'invalid_session_number', esc_html__( 'شمارهٔ جلسه باید یک عدد صحیح مثبت باشد.', 'hedayati-core' ) );
			}
			if ( $number !== $existing['session_number'] && self::number_taken( $existing['run_id'], $number ) ) {
				return new WP_Error( 'session_number_exists', esc_html__( 'جلسه‌ای با این شماره برای این دوره از قبل ثبت شده است.', 'hedayati-core' ) );
			}
			$fields['session_number'] = $number;
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$table                = Hedayati_DB_Schema::get_table_sessions();

		$updated = $wpdb->update( $table, $fields, [ 'id' => $session_id ], self::formats_for( $fields ), [ '%d' ] );

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', esc_html__( 'به‌روزرسانی جلسه ناموفق بود.', 'hedayati-core' ) );
		}

		$changed = array_values( array_diff( array_keys( $fields ), [ 'updated_at' ] ) );
		Hedayati_Audit_Log::record( 'session.updated', 'session', $session_id, 'run #' . $existing['run_id'] . ' · fields: ' . implode( ', ', $changed ) );

		return true;
	}

	// ── Delete ──────────────────────────────────────────────────────────────

	public static function delete_session( int $session_id ): bool {
		global $wpdb;

		if ( $session_id <= 0 ) {
			return false;
		}

		$attendance = Hedayati_DB_Schema::get_table_attendance();
		$sessions   = Hedayati_DB_Schema::get_table_sessions();

		$wpdb->delete( $attendance, [ 'session_id' => $session_id ], [ '%d' ] );

		$affected = $wpdb->delete( $sessions, [ 'id' => $session_id ], [ '%d' ] );

		if ( $affected ) {
			Hedayati_Audit_Log::record( 'session.deleted', 'session', $session_id, 'cascade: attendance' );
		}

		return false !== $affected;
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function validate_times_and_meta( array $data, bool $is_create, ?array $existing = null ): array|WP_Error {
		$out = [];

		if ( array_key_exists( 'starts_at', $data ) || $is_create ) {
			$parsed = Hedayati_Academic_Validation::parse_datetime( (string) ( $data['starts_at'] ?? '' ) );
			if ( null === $parsed ) {
				return new WP_Error( 'invalid_starts_at', esc_html__( 'زمان شروع جلسه نامعتبر است.', 'hedayati-core' ) );
			}
			$out['starts_at'] = $parsed;
		}

		if ( array_key_exists( 'ends_at', $data ) ) {
			$raw = trim( (string) $data['ends_at'] );
			if ( '' === $raw ) {
				$out['ends_at'] = null;
			} else {
				$parsed = Hedayati_Academic_Validation::parse_datetime( $raw );
				if ( null === $parsed ) {
					return new WP_Error( 'invalid_ends_at', esc_html__( 'زمان پایان جلسه نامعتبر است.', 'hedayati-core' ) );
				}
				$out['ends_at'] = $parsed;
			}
		}

		$eff_start = array_key_exists( 'starts_at', $out ) ? $out['starts_at'] : ( $existing['starts_at'] ?? null );
		$eff_end   = array_key_exists( 'ends_at', $out ) ? $out['ends_at'] : ( $existing['ends_at'] ?? null );
		if ( null !== $eff_start && null !== $eff_end && $eff_end <= $eff_start ) {
			return new WP_Error( 'time_range', esc_html__( 'زمان پایان باید پس از زمان شروع باشد.', 'hedayati-core' ) );
		}

		if ( array_key_exists( 'topic', $data ) ) {
			$out['topic'] = mb_substr( sanitize_text_field( (string) $data['topic'] ), 0, 190 );
		} elseif ( $is_create ) {
			$out['topic'] = '';
		}

		if ( array_key_exists( 'status', $data ) ) {
			$out['status'] = Hedayati_Academic_Validation::sanitize_session_status( (string) $data['status'] );
		} elseif ( $is_create ) {
			$out['status'] = 'scheduled';
		}

		return $out;
	}

	private static function number_taken( int $run_id, int $number ): bool {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_sessions();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE run_id = %d AND session_number = %d LIMIT 1",
			$run_id,
			$number
		) );
	}

	private static function formats_for( array $row ): array {
		$map = [
			'run_id'         => '%d',
			'session_number' => '%d',
			'starts_at'      => '%s',
			'ends_at'        => '%s',
			'topic'          => '%s',
			'status'         => '%s',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		];

		$formats = [];
		foreach ( $row as $key => $_ ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	private static function hydrate( array $row ): array {
		return [
			'id'             => (int) $row['id'],
			'run_id'         => (int) $row['run_id'],
			'session_number' => (int) $row['session_number'],
			'starts_at'      => (string) $row['starts_at'],
			'ends_at'        => $row['ends_at'] ?: null,
			'topic'          => (string) $row['topic'],
			'status'         => (string) $row['status'],
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
		];
	}
}
