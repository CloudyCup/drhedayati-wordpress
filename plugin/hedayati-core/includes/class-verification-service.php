<?php
/**
 * Phase 2C — Student identity (national ID) + verification workflow.
 *
 * One row per student in `hedayati_student_verification` (migration 2.3.0):
 * the encrypted national ID, its keyed-HMAC fingerprint (DB-level duplicate
 * detection, same pattern as `hedayati_user_phones`, D7), and the verification
 * review state.
 *
 * Verification has an ENFORCED transition table (D37) — not the "any value at
 * any time" convention used by Phase 2B operational statuses:
 *
 *   unverified -> pending   (initiate)
 *   rejected   -> pending   (initiate — makes rejection reversible)
 *   pending    -> verified  (approve)
 *   pending    -> rejected  (reject)
 *   verified   -> unverified  (ONLY via reset_for_identity_change)
 *
 * No manager/administrator override of this state machine exists — that would
 * be a separate, explicit, future decision.
 *
 * Security note (D36): `get_national_id_decrypted()` is the ONE method in this
 * plugin that intentionally breaks the "capability-agnostic service" convention
 * used everywhere else. It enforces `hedayati_verify_students` itself, in
 * addition to whatever the caller checks, because it is the single highest-risk
 * read in the codebase. Every other method here stays capability-agnostic —
 * the caller (Hedayati_Student_Admin) is responsible for capability + nonce
 * checks, exactly like every other Hedayati_*_Service.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Verification_Service {

	public static function init(): void {
		add_action( 'profile_update', [ self::class, 'on_profile_update' ], 10, 2 );
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );
	}

	// ── Read ────────────────────────────────────────────────────────────────

	/**
	 * @return array{status:string, reviewer_id:int, reviewed_at:?string, note:string, has_national_id:bool}
	 */
	public static function get_status( int $user_id ): array {
		$row = self::get_row( $user_id );

		if ( null === $row ) {
			return [
				'status'          => 'unverified',
				'reviewer_id'     => 0,
				'reviewed_at'     => null,
				'note'            => '',
				'has_national_id' => false,
			];
		}

		return [
			'status'          => $row['status'],
			'reviewer_id'     => $row['reviewer_id'],
			'reviewed_at'     => $row['reviewed_at'],
			'note'            => $row['note'],
			'has_national_id' => null !== $row['national_id_enc'],
		];
	}

	public static function get_national_id_masked( int $user_id ): string {
		$row = self::get_row( $user_id );
		return ( $row && null !== $row['national_id_enc'] ) ? 'set' : 'not_set';
	}

	/**
	 * The ONE decrypted read path in the plugin. Enforces
	 * `hedayati_verify_students` itself (defense in depth — the controller MUST
	 * also check it before ever calling this). There is no owner/self branch:
	 * a student can never reach a decrypted value through this method.
	 *
	 * @return string|null|WP_Error Decrypted national ID, null if none on file,
	 *                               or WP_Error (including 'forbidden').
	 */
	public static function get_national_id_decrypted( int $user_id, ?int $viewer_id = null ): string|null|WP_Error {
		$viewer_id = $viewer_id ?? get_current_user_id();

		if ( ! user_can( $viewer_id, 'hedayati_verify_students' ) ) {
			return new WP_Error( 'forbidden', esc_html__( 'دسترسی غیرمجاز.', 'hedayati-core' ) );
		}

		$row = self::get_row( $user_id );

		if ( null === $row || null === $row['national_id_enc'] ) {
			return null;
		}

		return Hedayati_Crypto::decrypt( $row['national_id_enc'] );
	}

	// ── Write: national ID ─────────────────────────────────────────────────

	/**
	 * Normalize, validate (Iranian national-ID checksum), fingerprint, encrypt,
	 * and insert-or-update. Fails closed if the crypto keys aren't configured.
	 * Resets a `verified` record to `unverified` if the value actually changed.
	 *
	 * @return true|WP_Error
	 */
	public static function set_national_id( int $user_id, string $raw_value, ?int $actor_id = null ): true|WP_Error {
		global $wpdb;

		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error( 'invalid_user', esc_html__( 'کاربر مورد نظر یافت نشد.', 'hedayati-core' ) );
		}

		if ( ! Hedayati_Crypto::is_configured() ) {
			return new WP_Error(
				'crypto_not_configured',
				esc_html__( 'این ویژگی نیازمند پیکربندی کلید رمزنگاری در محیط میزبان است.', 'hedayati-core' )
			);
		}

		$normalized = self::normalize_national_id( $raw_value );

		if ( null === $normalized ) {
			return new WP_Error( 'invalid_national_id', esc_html__( 'کد ملی نامعتبر است.', 'hedayati-core' ) );
		}

		$hmac = Hedayati_Crypto::fingerprint( $normalized );
		if ( is_wp_error( $hmac ) ) {
			return $hmac;
		}

		$existing = self::get_row( $user_id );

		if ( $existing && null !== $existing['national_id_hmac'] && hash_equals( $existing['national_id_hmac'], $hmac ) ) {
			// Unchanged value — no-op, preserves existing verification state.
			return true;
		}

		if ( self::hmac_in_use( $hmac, $user_id ) ) {
			return new WP_Error(
				'national_id_already_exists',
				esc_html__( 'این کد ملی قبلاً برای کاربر دیگری ثبت شده است.', 'hedayati-core' )
			);
		}

		$encrypted = Hedayati_Crypto::encrypt( $normalized );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}

		$table = Hedayati_DB_Schema::get_table_student_verification();
		$now   = current_time( 'mysql', true );

		if ( $existing ) {
			$updated = $wpdb->update(
				$table,
				[
					'national_id_enc'  => $encrypted,
					'national_id_hmac' => $hmac,
					'key_version'      => Hedayati_Crypto::current_key_version(),
					'updated_at'       => $now,
				],
				[ 'user_id' => $user_id ],
				[ '%s', '%s', '%d', '%s' ],
				[ '%d' ]
			);

			if ( false === $updated ) {
				if ( self::hmac_in_use( $hmac, $user_id ) ) {
					return new WP_Error( 'national_id_already_exists', esc_html__( 'این کد ملی قبلاً برای کاربر دیگری ثبت شده است.', 'hedayati-core' ) );
				}
				return new WP_Error( 'db_update_failed', esc_html__( 'ذخیرهٔ کد ملی ناموفق بود.', 'hedayati-core' ) );
			}
		} else {
			$inserted = $wpdb->insert(
				$table,
				[
					'user_id'           => $user_id,
					'national_id_enc'   => $encrypted,
					'national_id_hmac'  => $hmac,
					'key_version'       => Hedayati_Crypto::current_key_version(),
					'status'            => 'unverified',
					'created_at'        => $now,
					'updated_at'        => $now,
				],
				[ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);

			if ( false === $inserted ) {
				if ( self::hmac_in_use( $hmac, $user_id ) ) {
					return new WP_Error( 'national_id_already_exists', esc_html__( 'این کد ملی قبلاً برای کاربر دیگری ثبت شده است.', 'hedayati-core' ) );
				}
				return new WP_Error( 'db_insert_failed', esc_html__( 'ذخیرهٔ کد ملی ناموفق بود.', 'hedayati-core' ) );
			}
		}

		Hedayati_Audit_Log::record( 'identity.set', 'student_identity', $user_id, 'national id set/updated', $actor_id );

		if ( $existing && 'verified' === $existing['status'] ) {
			self::reset_for_identity_change( $user_id, 'national_id_changed' );
		}

		return true;
	}

	// ── Verification workflow ──────────────────────────────────────────────

	public static function initiate( int $user_id, ?int $actor_id = null ): true|WP_Error {
		$row = self::get_row( $user_id );

		if ( null === $row || null === $row['national_id_enc'] ) {
			return new WP_Error( 'missing_national_id', esc_html__( 'ابتدا باید کد ملی دانشجو ثبت شود.', 'hedayati-core' ) );
		}

		if ( 'pending' === $row['status'] ) {
			return new WP_Error( 'already_pending', esc_html__( 'درخواست احراز هویت این دانشجو در حال بررسی است.', 'hedayati-core' ) );
		}

		if ( 'verified' === $row['status'] ) {
			return new WP_Error( 'already_verified', esc_html__( 'این دانشجو قبلاً احراز هویت شده است.', 'hedayati-core' ) );
		}

		$result = self::set_status( $user_id, 'pending', 0, '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Hedayati_Audit_Log::record( 'verification.initiated', 'student_identity', $user_id, '', $actor_id );

		return true;
	}

	public static function approve( int $user_id, int $reviewer_id, string $note = '' ): true|WP_Error {
		$row = self::get_row( $user_id );

		if ( null === $row || null === $row['national_id_enc'] ) {
			return new WP_Error( 'missing_national_id', esc_html__( 'ابتدا باید کد ملی دانشجو ثبت شود.', 'hedayati-core' ) );
		}

		if ( 'pending' !== $row['status'] ) {
			return new WP_Error( 'not_pending', esc_html__( 'این درخواست در وضعیت در حال بررسی نیست.', 'hedayati-core' ) );
		}

		$result = self::set_status( $user_id, 'verified', $reviewer_id, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Hedayati_Audit_Log::record( 'verification.approved', 'student_identity', $user_id, 'reviewer #' . $reviewer_id, $reviewer_id );

		return true;
	}

	public static function reject( int $user_id, int $reviewer_id, string $note = '' ): true|WP_Error {
		$row = self::get_row( $user_id );

		if ( null === $row ) {
			return new WP_Error( 'not_pending', esc_html__( 'این درخواست در وضعیت در حال بررسی نیست.', 'hedayati-core' ) );
		}

		if ( 'pending' !== $row['status'] ) {
			return new WP_Error( 'not_pending', esc_html__( 'این درخواست در وضعیت در حال بررسی نیست.', 'hedayati-core' ) );
		}

		$result = self::set_status( $user_id, 'rejected', $reviewer_id, $note );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Hedayati_Audit_Log::record( 'verification.rejected', 'student_identity', $user_id, 'reviewer #' . $reviewer_id, $reviewer_id );

		return true;
	}

	/**
	 * The only path out of 'verified'. Internal — called by set_national_id()
	 * and on_profile_update(). No-ops if already unverified.
	 */
	public static function reset_for_identity_change( int $user_id, string $reason ): void {
		$row = self::get_row( $user_id );

		if ( null === $row || 'unverified' === $row['status'] ) {
			return;
		}

		self::set_status( $user_id, 'unverified', 0, '' );

		Hedayati_Audit_Log::record( 'verification.reset', 'student_identity', $user_id, $reason );
	}

	// ── Hooks ───────────────────────────────────────────────────────────────

	/**
	 * @param object $old_user_data stdClass of the user's prior data.
	 */
	public static function on_profile_update( int $user_id, object $old_user_data ): void {
		$row = self::get_row( $user_id );

		if ( null === $row || 'verified' !== $row['status'] ) {
			return;
		}

		$new_user = get_userdata( $user_id );
		if ( ! $new_user ) {
			return;
		}

		$old_first = isset( $old_user_data->first_name ) ? (string) $old_user_data->first_name : '';
		$old_last  = isset( $old_user_data->last_name ) ? (string) $old_user_data->last_name : '';

		if ( $old_first !== $new_user->first_name || $old_last !== $new_user->last_name ) {
			self::reset_for_identity_change( $user_id, 'legal_name_changed' );
		}
	}

	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$row = self::get_row( $user_id );
		if ( null === $row ) {
			return;
		}

		Hedayati_Audit_Log::record( 'user.identity_purged', 'user', $user_id, '' );

		$table = Hedayati_DB_Schema::get_table_student_verification();
		$wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	/**
	 * @return array{id:int, user_id:int, national_id_enc:?string, national_id_hmac:?string, key_version:int, status:string, reviewer_id:int, reviewed_at:?string, note:string, created_at:string, updated_at:string}|null
	 */
	private static function get_row( int $user_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_student_verification();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		return [
			'id'                => (int) $row['id'],
			'user_id'           => (int) $row['user_id'],
			'national_id_enc'   => $row['national_id_enc'] !== null ? (string) $row['national_id_enc'] : null,
			'national_id_hmac'  => $row['national_id_hmac'] !== null ? (string) $row['national_id_hmac'] : null,
			'key_version'       => (int) $row['key_version'],
			'status'            => (string) $row['status'],
			'reviewer_id'       => (int) $row['reviewer_id'],
			'reviewed_at'       => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
			'note'              => (string) $row['note'],
			'created_at'        => (string) $row['created_at'],
			'updated_at'        => (string) $row['updated_at'],
		];
	}

	private static function hmac_in_use( string $hmac, int $exclude_user_id ): bool {
		global $wpdb;

		$table    = Hedayati_DB_Schema::get_table_student_verification();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE national_id_hmac = %s AND user_id != %d LIMIT 1",
				$hmac,
				$exclude_user_id
			)
		);

		return null !== $existing;
	}

	/**
	 * Sets status + reviewer/reviewed_at/note for an existing or new row.
	 * Internal only — callers must already have validated the transition.
	 *
	 * @return true|WP_Error
	 */
	private static function set_status( int $user_id, string $status, int $reviewer_id, string $note ): true|WP_Error {
		global $wpdb;

		if ( ! in_array( $status, Hedayati_Academic_Validation::VERIFICATION_STATUSES, true ) ) {
			return new WP_Error( 'invalid_status', esc_html__( 'وضعیت نامعتبر است.', 'hedayati-core' ) );
		}

		$table = Hedayati_DB_Schema::get_table_student_verification();
		$now   = current_time( 'mysql', true );

		$fields = [
			'status'      => $status,
			'reviewer_id' => $reviewer_id > 0 ? $reviewer_id : null,
			'reviewed_at' => $reviewer_id > 0 ? $now : null,
			'note'        => mb_substr( sanitize_text_field( $note ), 0, 255 ),
			'updated_at'  => $now,
		];

		$updated = $wpdb->update(
			$table,
			$fields,
			[ 'user_id' => $user_id ],
			[ '%s', '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', esc_html__( 'به‌روزرسانی وضعیت ناموفق بود.', 'hedayati-core' ) );
		}

		return true;
	}

	/**
	 * Normalize (Persian/Arabic digits -> ASCII), validate shape + the standard
	 * Iranian 10-digit national-code checksum (public weighted mod-11 algorithm).
	 * Also rejects the ten well-known all-repeated-digit strings, which the naive
	 * checksum does not reliably reject for every digit.
	 *
	 * @return string|null Canonical 10-digit string, or null if invalid.
	 */
	private static function normalize_national_id( string $raw ): ?string {
		$value = trim( Hedayati_Text::digits_to_ascii( $raw ) );

		if ( ! preg_match( '/^\d{10}$/', $value ) ) {
			return null;
		}

		if ( 1 === preg_match( '/^(\d)\1{9}$/', $value ) ) {
			return null;
		}

		$digits = array_map( 'intval', str_split( $value ) );
		$sum    = 0;

		for ( $i = 0; $i < 9; $i++ ) {
			$sum += $digits[ $i ] * ( 10 - $i );
		}

		$remainder     = $sum % 11;
		$check_digit   = $remainder < 2 ? $remainder : 11 - $remainder;

		return ( $check_digit === $digits[9] ) ? $value : null;
	}
}
