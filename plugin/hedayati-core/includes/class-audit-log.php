<?php
/**
 * Application-level, append-only audit log (docs/DECISIONS.md D16).
 *
 * Records operational metadata only — WHO (`actor_id`), did WHAT (`action`), to
 * WHICH object (`object_type` + `object_id`), WHEN (`created_at`), plus a short
 * PII-free `note`. There is **no** IP address, user-agent, request body,
 * national ID, phone number, document content, credential or serialized-context
 * column, by design: the IP/UA retention policy is unresolved
 * (docs/OPEN_QUESTIONS.md Q13) and is not solved here by guessing.
 *
 * "Append-only" is honoured at the API level: this class exposes `record()` (an
 * INSERT), plus read helpers — and **no** update or delete method. The MySQL
 * table itself is not claimed to be immutable (D16). Domain deletion cascades
 * (`Hedayati_Course_Run_Service::delete_run()`, `deleted_user`, …) never touch
 * this table — audit history outlives the objects it references (D31).
 *
 * Authorization: `record()` is a capability-agnostic data-layer call, like the
 * other Hedayati_*_Service classes. Reading is gated on `hedayati_view_audit_logs`
 * (see `current_user_can_view()` and `Hedayati_Academic_Admin`'s viewer).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Audit_Log {

	public const VIEW_CAPABILITY = 'hedayati_view_audit_logs';

	/**
	 * Known object types (Phase 2B). Unknown values are sanitized, not rejected —
	 * a dropped audit entry is worse than an unfamiliar-but-safe one. Filterable
	 * so later phases can register their own without editing this file.
	 *
	 * @return string[]
	 */
	public static function object_types(): array {
		return (array) apply_filters( 'hedayati_audit_object_types', [
			'course',
			'course_run',
			'session',
			'run_staff',
			'enrollment',
			'attendance',
			'teacher',
			'user',
			'student_identity',
			'document',
		] );
	}

	/**
	 * Known action verbs (dotted: `<object>.<verb>`). Same "sanitize, don't
	 * reject" policy as object types.
	 *
	 * @return string[]
	 */
	public static function actions(): array {
		return (array) apply_filters( 'hedayati_audit_actions', [
			'course.deleted',
			'teacher.unlinked',
			'course_run.created',
			'course_run.updated',
			'course_run.deleted',
			'session.created',
			'session.updated',
			'session.deleted',
			'run_staff.assigned',
			'run_staff.removed',
			'run_staff.purged_for_user',
			'run_staff.purged_for_teacher',
			'enrollment.created',
			'enrollment.status_changed',
			'enrollment.deleted',
			'enrollment.purged_for_user',
			'attendance.recorded',
			'attendance.updated',
			'attendance.deleted',
			'identity.set',
			'identity.viewed',
			'verification.initiated',
			'verification.approved',
			'verification.rejected',
			'verification.reset',
			'user.identity_purged',
			'document.uploaded',
			'document.download_started',
			'document.archived',
			'document.purged',
			'document.purged_for_user',
		] );
	}

	// ── Write (append only) ─────────────────────────────────────────────────

	/**
	 * Append one audit entry. Never throws; returns the new row id, or false if
	 * the write did not happen (missing table, DB error, re-entrancy).
	 *
	 * Call this ONLY after the underlying operation has actually succeeded.
	 *
	 * @param string   $action       e.g. 'course_run.updated'
	 * @param string   $object_type  e.g. 'course_run'
	 * @param int      $object_id    affected row id (0 when not applicable)
	 * @param string   $note         short, PII-free context (<= 255 chars); no field values
	 *                               beyond safe enums (status names, role names)
	 * @param int|null $actor_id     override the acting user; null = current user (0 if none)
	 * @return int|false
	 */
	public static function record( string $action, string $object_type, int $object_id = 0, string $note = '', ?int $actor_id = null ): int|false {
		global $wpdb;

		// Re-entrancy guard: recording an audit entry must never cause another.
		static $in_progress = false;
		if ( $in_progress ) {
			return false;
		}

		$table = Hedayati_DB_Schema::get_table_audit_log();

		$row = [
			'actor_id'    => null === $actor_id ? self::resolve_actor() : max( 0, $actor_id ),
			'action'      => self::sanitize_token( $action, 64 ),
			'object_type' => self::sanitize_token( $object_type, 32 ),
			'object_id'   => max( 0, $object_id ),
			'note'        => self::sanitize_note( $note ),
			'created_at'  => current_time( 'mysql', true ),
		];

		$in_progress = true;
		try {
			$inserted = $wpdb->insert(
				$table,
				$row,
				[ '%d', '%s', '%s', '%d', '%s', '%s' ]
			);
		} finally {
			$in_progress = false;
		}

		return ( false === $inserted ) ? false : (int) $wpdb->insert_id;
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function current_user_can_view(): bool {
		return current_user_can( self::VIEW_CAPABILITY );
	}

	public static function get( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_audit_log();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Paged, reverse-chronological listing with optional filters.
	 *
	 * @param array{object_type?:string, object_id?:int, actor_id?:int, action?:string, per_page?:int, page?:int} $args
	 * @return array<int, array>
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$table  = Hedayati_DB_Schema::get_table_audit_log();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = self::sanitize_token( (string) $args['object_type'], 32 );
		}

		if ( isset( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}

		if ( isset( $args['actor_id'] ) && (int) $args['actor_id'] > 0 ) {
			$where[]  = 'actor_id = %d';
			$params[] = (int) $args['actor_id'];
		}

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = self::sanitize_token( (string) $args['action'], 64 );
		}

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @param array{object_type?:string, object_id?:int, actor_id?:int, action?:string} $args
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;

		$table  = Hedayati_DB_Schema::get_table_audit_log();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['object_type'] ) ) {
			$where[]  = 'object_type = %s';
			$params[] = self::sanitize_token( (string) $args['object_type'], 32 );
		}
		if ( isset( $args['object_id'] ) && (int) $args['object_id'] > 0 ) {
			$where[]  = 'object_id = %d';
			$params[] = (int) $args['object_id'];
		}
		if ( isset( $args['actor_id'] ) && (int) $args['actor_id'] > 0 ) {
			$where[]  = 'actor_id = %d';
			$params[] = (int) $args['actor_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = self::sanitize_token( (string) $args['action'], 64 );
		}

		$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

		return (int) ( empty( $params )
			? $wpdb->get_var( $sql )
			: $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function resolve_actor(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Lower-case, restrict to [a-z0-9_.-], length-cap. Keeps `action` /
	 * `object_type` safe regardless of caller.
	 */
	private static function sanitize_token( string $value, int $max ): string {
		$value = strtolower( trim( $value ) );
		$value = (string) preg_replace( '/[^a-z0-9_.\-]/', '', $value );

		return mb_substr( $value, 0, $max );
	}

	/**
	 * `note` is free-ish text but must stay short and carry no markup. Callers
	 * are responsible for not passing PII / secrets; this is the backstop for
	 * length + tags + control chars.
	 */
	private static function sanitize_note( string $note ): string {
		$note = sanitize_text_field( $note );

		return mb_substr( $note, 0, 255 );
	}

	private static function hydrate( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'actor_id'    => (int) $row['actor_id'],
			'action'      => (string) $row['action'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'note'        => (string) $row['note'],
			'created_at'  => (string) $row['created_at'],
		];
	}
}
