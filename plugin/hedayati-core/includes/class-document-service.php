<?php
/**
 * Phase 2C — Private document metadata service (D14/D38).
 *
 * DB-facing, capability-agnostic — like every other Hedayati_*_Service, all
 * capability + ownership checks live in the caller (Hedayati_Student_Admin
 * today; a future Phase 2D portal caller later, unmodified, per the documented
 * authorization contract below).
 *
 * Bytes are delegated entirely to Hedayati_Document_Storage; this class only
 * ever stores/reads the abstract storage_backend + storage_key reference,
 * never a path or public URL (D14).
 *
 * Authorization contract (enforced by callers, not this class):
 *   - view/download: hedayati_view_private_documents (staff), OR
 *     hedayati_view_own_portal && $user_id === get_current_user_id() (student's
 *     own document — ready for a Phase 2D portal caller; unreachable in Phase 2C
 *     since no such caller exists yet).
 *   - upload on behalf of a student: hedayati_upload_student_documents (staff)
 *     + the target must hold the 'student' role.
 *   - upload own: hedayati_upload_own_documents (ready for Phase 2D; unreachable
 *     in Phase 2C).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Document_Service {

	public const DOC_TYPES = [ 'national_card', 'birth_certificate', 'other' ];

	public static function init(): void {
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function get( int $doc_id ): ?array {
		global $wpdb;

		if ( $doc_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_documents();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $doc_id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @return array<int, array>
	 */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_documents();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND deleted_at IS NULL ORDER BY id DESC", $user_id ),
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @return array<int, array>
	 */
	public static function purge_eligible(): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_documents();
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE archived_at IS NOT NULL AND archived_at <= (UTC_TIMESTAMP() - INTERVAL 7 DAY) AND deleted_at IS NULL ORDER BY archived_at ASC",
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	// ── Upload ──────────────────────────────────────────────────────────────

	/**
	 * Saves bytes first, then inserts metadata. If the metadata insert fails,
	 * the just-saved bytes are deleted immediately so no orphan file is left
	 * referencing a metadata row that doesn't exist.
	 *
	 * @param array $php_file_upload one element of $_FILES
	 * @return int|WP_Error New document id.
	 */
	public static function upload( int $user_id, array $php_file_upload, string $doc_type, ?int $actor_id = null ): int|WP_Error {
		global $wpdb;

		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'invalid_user', esc_html__( 'کاربر مورد نظر یافت نشد.', 'hedayati-core' ) );
		}

		$doc_type = in_array( $doc_type, self::DOC_TYPES, true ) ? $doc_type : 'other';

		$saved = Hedayati_Document_Storage::save( $user_id, $php_file_upload );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$table = Hedayati_DB_Schema::get_table_documents();
		$now   = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			$table,
			[
				'user_id'        => $user_id,
				'doc_type'       => $doc_type,
				'storage_backend' => 'local',
				'storage_key'     => $saved['storage_key'],
				'original_mime'   => $saved['mime'],
				'size_bytes'      => $saved['size'],
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			// Orphan-file cleanup: the DB row does not exist, so the bytes must
			// not either.
			Hedayati_Document_Storage::delete( $saved['storage_key'] );
			return new WP_Error( 'db_insert_failed', esc_html__( 'ثبت اطلاعات مدرک ناموفق بود.', 'hedayati-core' ) );
		}

		$doc_id = (int) $wpdb->insert_id;

		Hedayati_Audit_Log::record( 'document.uploaded', 'document', $doc_id, $doc_type, $actor_id );

		return $doc_id;
	}

	// ── Download ────────────────────────────────────────────────────────────

	/**
	 * Audits `document.download_started` immediately before initiating the
	 * stream. This proves an authorized request reached the point of starting
	 * a byte stream — it does NOT prove the client received every byte, since
	 * readfile() can still fail partway (client disconnect, broken pipe). No
	 * unsafe post-response logging is attempted to claim full delivery.
	 *
	 * @return true|WP_Error
	 */
	public static function download( int $doc_id, ?int $actor_id = null ): true|WP_Error {
		$row = self::get( $doc_id );

		if ( null === $row || null !== $row['deleted_at'] ) {
			return new WP_Error( 'document_not_found', esc_html__( 'مدرک یافت نشد.', 'hedayati-core' ) );
		}

		Hedayati_Audit_Log::record( 'document.download_started', 'document', $doc_id, $row['doc_type'], $actor_id );

		return Hedayati_Document_Storage::stream( $row['storage_key'] );
	}

	// ── Archive / retention ─────────────────────────────────────────────────

	public static function mark_archived( int $doc_id, int $actor_id, ?string $reference = null ): true|WP_Error {
		global $wpdb;

		$row = self::get( $doc_id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return new WP_Error( 'document_not_found', esc_html__( 'مدرک یافت نشد.', 'hedayati-core' ) );
		}

		$table = Hedayati_DB_Schema::get_table_documents();
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->update(
			$table,
			[
				'archive_reference' => $reference ? mb_substr( sanitize_text_field( $reference ), 0, 190 ) : null,
				'archived_at'       => $now,
				'updated_at'        => $now,
			],
			[ 'id' => $doc_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', esc_html__( 'ثبت تأیید انتقال ناموفق بود.', 'hedayati-core' ) );
		}

		Hedayati_Audit_Log::record( 'document.archived', 'document', $doc_id, '', $actor_id );

		return true;
	}

	/**
	 * Manual, staff-triggered only — never a cron job. Explicit failure
	 * semantics so a row can never falsely claim bytes were purged when they
	 * still exist:
	 *   - filesystem delete fails -> deleted_at stays unset, 'purge_failed'.
	 *   - delete succeeds but the DB update fails -> bytes ARE gone but the row
	 *     doesn't reflect it yet; returns 'purge_partially_failed' distinctly
	 *     so an operator can reconcile it.
	 *
	 * @return true|WP_Error
	 */
	public static function purge( int $doc_id, int $actor_id ): true|WP_Error {
		global $wpdb;

		$row = self::get( $doc_id );
		if ( null === $row || null !== $row['deleted_at'] ) {
			return new WP_Error( 'document_not_found', esc_html__( 'مدرک یافت نشد.', 'hedayati-core' ) );
		}

		$deleted = Hedayati_Document_Storage::delete( $row['storage_key'] );
		if ( is_wp_error( $deleted ) ) {
			return new WP_Error( 'purge_failed', esc_html__( 'حذف فایل ناموفق بود؛ مدرک همچنان موجود است.', 'hedayati-core' ) );
		}

		$table   = Hedayati_DB_Schema::get_table_documents();
		$updated = $wpdb->update(
			$table,
			[ 'deleted_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $doc_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			// Bytes are gone but the row doesn't reflect it yet — distinct,
			// loggable inconsistency, not a silent success.
			error_log( sprintf( 'Hedayati: document #%d bytes purged but deleted_at update failed — reconcile manually.', $doc_id ) );
			return new WP_Error( 'purge_partially_failed', esc_html__( 'فایل حذف شد اما به‌روزرسانی رکورد ناموفق بود.', 'hedayati-core' ) );
		}

		Hedayati_Audit_Log::record( 'document.purged', 'document', $doc_id, '', $actor_id );

		return true;
	}

	// ── Cleanup ─────────────────────────────────────────────────────────────

	public static function on_user_deleted( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$docs = self::list_for_user( $user_id );

		foreach ( $docs as $doc ) {
			Hedayati_Document_Storage::delete( $doc['storage_key'] );

			global $wpdb;
			$table = Hedayati_DB_Schema::get_table_documents();
			$wpdb->update(
				$table,
				[ 'deleted_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ],
				[ 'id' => $doc['id'] ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		if ( ! empty( $docs ) ) {
			Hedayati_Audit_Log::record(
				'document.purged_for_user',
				'user',
				$user_id,
				count( $docs ) . ' document(s) removed on account deletion'
			);
		}
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'                => (int) $row['id'],
			'user_id'           => (int) $row['user_id'],
			'doc_type'          => (string) $row['doc_type'],
			'storage_backend'   => (string) $row['storage_backend'],
			'storage_key'       => (string) $row['storage_key'],
			'original_mime'     => (string) $row['original_mime'],
			'size_bytes'        => (int) $row['size_bytes'],
			'archive_reference' => $row['archive_reference'] !== null ? (string) $row['archive_reference'] : null,
			'archived_at'       => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
			'deleted_at'        => $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		];
	}
}
