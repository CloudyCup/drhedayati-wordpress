<?php
/**
 * Phase 2B — Course Run repository & service.
 *
 * A Course Run is an operational cohort of a catalog `course`. It is the source of
 * truth for a cohort's teacher(s), schedule, tuition, capacity and registration
 * state — the catalog `_course_*` meta stays only as a display fallback.
 *
 * All SQL is prepared and dynamic-prefixed. Business states are validated strings
 * (`Hedayati_Academic_Validation`), never MySQL ENUMs. "Unknown" capacity / tuition
 * is stored as NULL and never fabricated.
 *
 * Cascade: permanently deleting a `course` deletes its runs; deleting a run cascades
 * to its sessions, enrollments, attendance and staff rows (see delete_run()).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Course_Run_Service {

	public static function init(): void {
		// Cascade run cleanup when the parent catalog course is permanently deleted.
		add_action( 'before_delete_post', [ self::class, 'on_course_deleted' ], 10, 2 );
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	public static function get( int $run_id ): ?array {
		global $wpdb;

		if ( $run_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_course_runs();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $run_id ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * List runs, optionally filtered.
	 *
	 * @param array{course_id?:int, run_status?:string, registration_status?:string, orderby?:string, order?:string, limit?:int, offset?:int} $args
	 * @return array<int, array>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$table  = Hedayati_DB_Schema::get_table_course_runs();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['course_id'] ) ) {
			$where[]  = 'course_id = %d';
			$params[] = (int) $args['course_id'];
		}

		if ( ! empty( $args['run_status'] ) ) {
			$where[]  = 'run_status = %s';
			$params[] = Hedayati_Academic_Validation::sanitize_run_status( (string) $args['run_status'] );
		}

		if ( ! empty( $args['registration_status'] ) ) {
			$where[]  = 'registration_status = %s';
			$params[] = Hedayati_Academic_Validation::sanitize_registration_status( (string) $args['registration_status'] );
		}

		$allowed_orderby = [ 'id', 'start_date', 'end_date', 'created_at', 'updated_at', 'run_status' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'start_date';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$limit  = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_for_course( int $course_id ): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_course_runs();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE course_id = %d", $course_id )
		);
	}

	// ── Create ──────────────────────────────────────────────────────────────

	/**
	 * @param array $data course_id (req), label, run_status, registration_status,
	 *                     start_date, end_date, schedule_text, capacity, tuition_rial, notes
	 * @return int|WP_Error New run ID, or error.
	 */
	public static function create( array $data ): int|WP_Error {
		global $wpdb;

		$course_id = isset( $data['course_id'] ) ? (int) $data['course_id'] : 0;

		if ( $course_id <= 0 || get_post_type( $course_id ) !== 'course' ) {
			return new WP_Error( 'invalid_course', esc_html__( 'دورهٔ کاتالوگ معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		$fields = self::validate_writable_fields( $data, true, null );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_course_runs();

		$row = array_merge(
			$fields,
			[
				'course_id'  => $course_id,
				'created_at' => $now,
				'updated_at' => $now,
			]
		);

		$formats = self::formats_for( $row );

		$inserted = $wpdb->insert( $table, $row, $formats );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت دورهٔ اجرایی در پایگاه داده ناموفق بود.', 'hedayati-core' ) );
		}

		$run_id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'course_run.created', 'course_run', $run_id, 'course #' . $course_id );

		return $run_id;
	}

	// ── Update ──────────────────────────────────────────────────────────────

	public static function update( int $run_id, array $data ): true|WP_Error {
		global $wpdb;

		$existing = self::get( $run_id );
		if ( null === $existing ) {
			return new WP_Error( 'run_not_found', esc_html__( 'دورهٔ اجرایی یافت نشد.', 'hedayati-core' ) );
		}

		$fields = self::validate_writable_fields( $data, false, $existing );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( empty( $fields ) ) {
			return true;
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$table                = Hedayati_DB_Schema::get_table_course_runs();

		$updated = $wpdb->update( $table, $fields, [ 'id' => $run_id ], self::formats_for( $fields ), [ '%d' ] );

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', esc_html__( 'به‌روزرسانی دورهٔ اجرایی ناموفق بود.', 'hedayati-core' ) );
		}

		$changed = array_values( array_diff( array_keys( $fields ), [ 'updated_at' ] ) );
		Hedayati_Audit_Log::record( 'course_run.updated', 'course_run', $run_id, 'fields: ' . implode( ', ', $changed ) );

		return true;
	}

	// ── Delete (cascade) ────────────────────────────────────────────────────

	/**
	 * Permanently delete a run and every operational record hanging off it.
	 *
	 * @return bool
	 */
	public static function delete_run( int $run_id ): bool {
		global $wpdb;

		if ( $run_id <= 0 ) {
			return false;
		}

		$sessions    = Hedayati_DB_Schema::get_table_sessions();
		$enrollments = Hedayati_DB_Schema::get_table_enrollments();
		$attendance  = Hedayati_DB_Schema::get_table_attendance();
		$run_staff   = Hedayati_DB_Schema::get_table_run_staff();
		$runs        = Hedayati_DB_Schema::get_table_course_runs();

		// Attendance rows for sessions or enrollments in this run.
		$wpdb->query( $wpdb->prepare(
			"DELETE a FROM {$attendance} a
			 LEFT JOIN {$sessions} s ON s.id = a.session_id
			 LEFT JOIN {$enrollments} e ON e.id = a.enrollment_id
			 WHERE s.run_id = %d OR e.run_id = %d",
			$run_id,
			$run_id
		) );

		$wpdb->delete( $sessions, [ 'run_id' => $run_id ], [ '%d' ] );
		$wpdb->delete( $enrollments, [ 'run_id' => $run_id ], [ '%d' ] );
		$wpdb->delete( $run_staff, [ 'run_id' => $run_id ], [ '%d' ] );

		$affected = $wpdb->delete( $runs, [ 'id' => $run_id ], [ '%d' ] );

		if ( $affected ) {
			// Child sessions/enrollments/attendance/staff are removed by the SQL
			// above without individual audit rows — the run deletion is the
			// auditable event; the cascade is implied and recorded here.
			Hedayati_Audit_Log::record( 'course_run.deleted', 'course_run', $run_id, 'cascade: sessions, enrollments, attendance, staff' );
		}

		return false !== $affected;
	}

	public static function on_course_deleted( int $post_id, WP_Post $post ): void {
		if ( 'course' !== $post->post_type ) {
			return;
		}

		$total = self::count_for_course( $post_id );

		if ( 0 === $total ) {
			return;
		}

		Hedayati_Audit_Log::record( 'course.deleted', 'course', $post_id, $total . ' run(s) cascade-deleted' );

		// Delete in bounded batches until the course has no runs left — a course
		// with hundreds of cohorts must not leave orphans behind one page.
		$guard = 0;
		do {
			$runs = self::query( [ 'course_id' => $post_id, 'limit' => 200 ] );
			foreach ( $runs as $run ) {
				self::delete_run( (int) $run['id'] );
			}
			$guard++;
		} while ( ! empty( $runs ) && $guard < 100 );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	/**
	 * Validate and collect only the fields present in $data.
	 *
	 * @param bool $is_create When true, missing status fields get their safe defaults.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function validate_writable_fields( array $data, bool $is_create, ?array $existing = null ): array|WP_Error {
		$out = [];

		if ( array_key_exists( 'label', $data ) ) {
			$out['label'] = sanitize_text_field( (string) $data['label'] );
			if ( mb_strlen( $out['label'] ) > 190 ) {
				$out['label'] = mb_substr( $out['label'], 0, 190 );
			}
		} elseif ( $is_create ) {
			$out['label'] = '';
		}

		if ( array_key_exists( 'run_status', $data ) ) {
			$out['run_status'] = Hedayati_Academic_Validation::sanitize_run_status( (string) $data['run_status'] );
		} elseif ( $is_create ) {
			$out['run_status'] = 'draft';
		}

		if ( array_key_exists( 'registration_status', $data ) ) {
			$out['registration_status'] = Hedayati_Academic_Validation::sanitize_registration_status( (string) $data['registration_status'] );
		} elseif ( $is_create ) {
			$out['registration_status'] = 'closed';
		}

		if ( array_key_exists( 'start_date', $data ) ) {
			$raw = trim( (string) $data['start_date'] );
			if ( '' === $raw ) {
				$out['start_date'] = null;
			} else {
				$parsed = Hedayati_Academic_Validation::parse_iso_date( $raw );
				if ( null === $parsed ) {
					return new WP_Error( 'invalid_start_date', esc_html__( 'تاریخ شروع نامعتبر است (فرمت میلادی YYYY-MM-DD).', 'hedayati-core' ) );
				}
				$out['start_date'] = $parsed;
			}
		}

		if ( array_key_exists( 'end_date', $data ) ) {
			$raw = trim( (string) $data['end_date'] );
			if ( '' === $raw ) {
				$out['end_date'] = null;
			} else {
				$parsed = Hedayati_Academic_Validation::parse_iso_date( $raw );
				if ( null === $parsed ) {
					return new WP_Error( 'invalid_end_date', esc_html__( 'تاریخ پایان نامعتبر است (فرمت میلادی YYYY-MM-DD).', 'hedayati-core' ) );
				}
				$out['end_date'] = $parsed;
			}
		}

		// Cross-field: end must not precede start, using the effective values that
		// will be in the row after this write (new value if provided, else existing).
		$eff_start = array_key_exists( 'start_date', $out ) ? $out['start_date'] : ( $existing['start_date'] ?? null );
		$eff_end   = array_key_exists( 'end_date', $out ) ? $out['end_date'] : ( $existing['end_date'] ?? null );
		if ( null !== $eff_start && null !== $eff_end && $eff_end < $eff_start ) {
			return new WP_Error( 'date_range', esc_html__( 'تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.', 'hedayati-core' ) );
		}

		if ( array_key_exists( 'schedule_text', $data ) ) {
			$out['schedule_text'] = sanitize_text_field( (string) $data['schedule_text'] );
			if ( mb_strlen( $out['schedule_text'] ) > 255 ) {
				$out['schedule_text'] = mb_substr( $out['schedule_text'], 0, 255 );
			}
		} elseif ( $is_create ) {
			$out['schedule_text'] = '';
		}

		if ( array_key_exists( 'capacity', $data ) ) {
			$cap = Hedayati_Academic_Validation::parse_optional_nonneg_int( (string) $data['capacity'], 'invalid_capacity' );
			if ( is_wp_error( $cap ) ) {
				return $cap;
			}
			$out['capacity'] = $cap; // int or null
		}

		if ( array_key_exists( 'tuition_rial', $data ) ) {
			$tuition = Hedayati_Academic_Validation::parse_optional_nonneg_int( (string) $data['tuition_rial'], 'invalid_tuition' );
			if ( is_wp_error( $tuition ) ) {
				return $tuition;
			}
			$out['tuition_rial'] = $tuition; // int or null
		}

		if ( array_key_exists( 'notes', $data ) ) {
			$out['notes'] = sanitize_textarea_field( (string) $data['notes'] );
		}

		return $out;
	}

	/**
	 * $wpdb format specifiers matching a row/subset. Nullable ints still use %d;
	 * an explicit PHP null is written as SQL NULL by $wpdb regardless of format.
	 */
	private static function formats_for( array $row ): array {
		$map = [
			'course_id'           => '%d',
			'label'               => '%s',
			'run_status'          => '%s',
			'registration_status' => '%s',
			'start_date'          => '%s',
			'end_date'            => '%s',
			'schedule_text'       => '%s',
			'capacity'            => '%d',
			'tuition_rial'        => '%d',
			'notes'               => '%s',
			'created_at'          => '%s',
			'updated_at'          => '%s',
		];

		$formats = [];
		foreach ( $row as $key => $_ ) {
			$formats[] = $map[ $key ] ?? '%s';
		}

		return $formats;
	}

	private static function hydrate( array $row ): array {
		return [
			'id'                  => (int) $row['id'],
			'course_id'           => (int) $row['course_id'],
			'label'               => (string) $row['label'],
			'run_status'          => (string) $row['run_status'],
			'registration_status' => (string) $row['registration_status'],
			'start_date'          => $row['start_date'] ?: null,
			'end_date'            => $row['end_date'] ?: null,
			'schedule_text'       => (string) $row['schedule_text'],
			'capacity'            => isset( $row['capacity'] ) && null !== $row['capacity'] ? (int) $row['capacity'] : null,
			'tuition_rial'        => isset( $row['tuition_rial'] ) && null !== $row['tuition_rial'] ? (int) $row['tuition_rial'] : null,
			'notes'               => (string) ( $row['notes'] ?? '' ),
			'created_at'          => (string) $row['created_at'],
			'updated_at'          => (string) $row['updated_at'],
		];
	}
}
