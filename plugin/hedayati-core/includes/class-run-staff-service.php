<?php
/**
 * Phase 2B — Course Run staff assignment repository & service.
 *
 * Roles (Hedayati_Academic_Validation::STAFF_ROLES):
 *   - primary_instructor    — exactly one per run; requires a Teacher profile.
 *   - additional_instructor — many per run; requires a Teacher profile.
 *   - assistant (TA)        — many per run; requires a WordPress staff user, NOT a
 *                             Teacher profile (docs/DECISIONS.md D11).
 *
 * Uniqueness (one teacher/user cannot hold the same role on a run twice) is
 * enforced here in the service; the table keeps lookup indexes only because the
 * nullable teacher_id / user_id columns can't carry a meaningful SQL UNIQUE.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Run_Staff_Service {

	public static function init(): void {
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );
		add_action( 'before_delete_post', [ self::class, 'on_post_deleted' ], 10, 2 );
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_run_staff();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @return array<int, array>
	 */
	public static function list_for_run( int $run_id ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_run_staff();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE run_id = %d ORDER BY FIELD(staff_role,'primary_instructor','additional_instructor','assistant'), id ASC",
				$run_id
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * Runs a given user is staffed on, in any role — used for teacher/TA scope checks.
	 *
	 * @return int[] Run IDs.
	 */
	public static function run_ids_for_user( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return [];
		}

		$staff    = Hedayati_DB_Schema::get_table_run_staff();
		$teacher  = Hedayati_Teacher::find_by_user_id( $user_id );
		$params   = [ $user_id ];
		$teacher_clause = '';

		if ( null !== $teacher ) {
			$teacher_clause = ' OR teacher_id = %d';
			$params[]       = $teacher;
		}

		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT run_id FROM {$staff} WHERE user_id = %d{$teacher_clause}",
			$params
		) );

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * Whether a user may act on a run as staff (assistant by user link, or
	 * instructor via their linked Teacher profile).
	 */
	public static function user_is_staff_on_run( int $user_id, int $run_id, ?string $role = null ): bool {
		global $wpdb;

		if ( $user_id <= 0 || $run_id <= 0 ) {
			return false;
		}

		$staff      = Hedayati_DB_Schema::get_table_run_staff();
		$teacher_id = Hedayati_Teacher::find_by_user_id( $user_id );

		$params = [ $run_id, $user_id ];

		if ( null !== $teacher_id ) {
			$clause   = '( user_id = %d OR teacher_id = %d )';
			$params[] = $teacher_id;
		} else {
			$clause = 'user_id = %d';
		}

		$sql = "SELECT id FROM {$staff} WHERE run_id = %d AND {$clause}";

		if ( null !== $role ) {
			$sql     .= ' AND staff_role = %s';
			$params[] = $role;
		}

		$sql .= ' LIMIT 1';

		return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	// ── Assign ──────────────────────────────────────────────────────────────

	/**
	 * @param array $data run_id (req), staff_role (req), teacher_id, user_id
	 * @return int|WP_Error New assignment ID.
	 */
	public static function assign( array $data ): int|WP_Error {
		global $wpdb;

		$run_id = isset( $data['run_id'] ) ? (int) $data['run_id'] : 0;
		if ( null === Hedayati_Course_Run_Service::get( $run_id ) ) {
			return new WP_Error( 'invalid_run', esc_html__( 'دورهٔ اجرایی معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}

		$role = Hedayati_Academic_Validation::parse_staff_role( (string) ( $data['staff_role'] ?? '' ) );
		if ( null === $role ) {
			return new WP_Error( 'invalid_staff_role', esc_html__( 'نقش عوامل دوره نامعتبر است.', 'hedayati-core' ) );
		}

		$teacher_id = isset( $data['teacher_id'] ) ? (int) $data['teacher_id'] : 0;
		$user_id    = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;

		if ( Hedayati_Academic_Validation::is_instructor_role( $role ) ) {
			if ( ! Hedayati_Teacher::exists( $teacher_id ) ) {
				return new WP_Error( 'instructor_needs_profile', esc_html__( 'برای نقش مدرس باید یک پروفایل استاد انتخاب شود.', 'hedayati-core' ) );
			}
			$user_id = 0; // instructors are referenced by profile, not account

			if ( 'primary_instructor' === $role && self::has_primary_instructor( $run_id ) ) {
				return new WP_Error( 'primary_instructor_exists', esc_html__( 'این دوره از قبل یک مدرس اصلی دارد. ابتدا مدرس فعلی را حذف کنید.', 'hedayati-core' ) );
			}
		} else {
			// assistant
			if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
				return new WP_Error( 'assistant_needs_user', esc_html__( 'برای نقش استادیار باید یک حساب کاربری کارکنان انتخاب شود.', 'hedayati-core' ) );
			}
			$teacher_id = 0;
		}

		if ( self::assignment_exists( $run_id, $role, $teacher_id, $user_id ) ) {
			return new WP_Error( 'assignment_exists', esc_html__( 'این فرد از قبل با همین نقش به این دوره اختصاص یافته است.', 'hedayati-core' ) );
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_run_staff();

		$inserted = $wpdb->insert(
			$table,
			[
				'run_id'     => $run_id,
				'staff_role' => $role,
				'teacher_id' => $teacher_id > 0 ? $teacher_id : null,
				'user_id'    => $user_id > 0 ? $user_id : null,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[ '%d', '%s', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت اختصاص عوامل دوره ناموفق بود.', 'hedayati-core' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function remove( int $assignment_id ): bool {
		global $wpdb;

		if ( $assignment_id <= 0 ) {
			return false;
		}

		$table = Hedayati_DB_Schema::get_table_run_staff();

		return false !== $wpdb->delete( $table, [ 'id' => $assignment_id ], [ '%d' ] );
	}

	// ── Lifecycle ───────────────────────────────────────────────────────────

	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$table = Hedayati_DB_Schema::get_table_run_staff();
		$wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );
	}

	public static function on_post_deleted( int $post_id, WP_Post $post ): void {
		global $wpdb;

		if ( Hedayati_Teacher::POST_TYPE !== $post->post_type ) {
			return;
		}

		$table = Hedayati_DB_Schema::get_table_run_staff();
		$wpdb->delete( $table, [ 'teacher_id' => $post_id ], [ '%d' ] );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	public static function has_primary_instructor( int $run_id ): bool {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_run_staff();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE run_id = %d AND staff_role = 'primary_instructor' LIMIT 1",
			$run_id
		) );
	}

	private static function assignment_exists( int $run_id, string $role, int $teacher_id, int $user_id ): bool {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_run_staff();

		if ( $teacher_id > 0 ) {
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE run_id = %d AND staff_role = %s AND teacher_id = %d LIMIT 1",
				$run_id,
				$role,
				$teacher_id
			) );
		}

		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE run_id = %d AND staff_role = %s AND user_id = %d LIMIT 1",
			$run_id,
			$role,
			$user_id
		) );
	}

	private static function hydrate( array $row ): array {
		return [
			'id'         => (int) $row['id'],
			'run_id'     => (int) $row['run_id'],
			'staff_role' => (string) $row['staff_role'],
			'teacher_id' => isset( $row['teacher_id'] ) && null !== $row['teacher_id'] ? (int) $row['teacher_id'] : null,
			'user_id'    => isset( $row['user_id'] ) && null !== $row['user_id'] ? (int) $row['user_id'] : null,
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		];
	}
}
