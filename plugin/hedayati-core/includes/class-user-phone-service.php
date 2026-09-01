<?php
/**
 * User Phone Identity Repository & Service.
 *
 * Provides safe, prepared, and transactional database interactions for the
 * `hedayati_user_phones` table. Enforces database-level uniqueness, normalization,
 * race-condition handling, verification state lifecycle, and user cleanup.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_User_Phone_Service {

	/**
	 * Bootstrap hooks.
	 */
	public static function init(): void {
		// Clean up phone record when a WordPress user is deleted
		add_action( 'deleted_user', [ self::class, 'delete_phone' ] );
	}

	/**
	 * Find a WordPress user by raw or canonical phone number.
	 *
	 * @param string $phone_input  Raw or normalized phone number.
	 * @return WP_User|null        WP_User object if found, null otherwise.
	 */
	public static function find_user_by_phone( string $phone_input ): ?WP_User {
		$canonical = Hedayati_Phone::normalize( $phone_input );

		if ( is_wp_error( $canonical ) ) {
			return null;
		}

		$user_id = self::get_user_id_by_phone( $canonical );

		if ( ! $user_id ) {
			return null;
		}

		$user = get_user_by( 'id', $user_id );

		return ( $user instanceof WP_User ) ? $user : null;
	}

	/**
	 * Retrieve the user ID associated with a canonical or raw phone number.
	 *
	 * @param string $phone_input
	 * @return int|null
	 */
	public static function get_user_id_by_phone( string $phone_input ): ?int {
		global $wpdb;

		$canonical = Hedayati_Phone::normalize( $phone_input );

		if ( is_wp_error( $canonical ) ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();

		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$table} WHERE phone_e164 = %s LIMIT 1",
				$canonical
			)
		);

		return $user_id ? (int) $user_id : null;
	}

	/**
	 * Retrieve the full phone identity record for a specific user.
	 *
	 * @param int $user_id
	 * @return array{id: int, user_id: int, phone_e164: string, is_verified: bool, verified_at: ?string, created_at: string, updated_at: string}|null
	 */
	public static function get_phone_record_by_user( int $user_id ): ?array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return null;
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d LIMIT 1",
				$user_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return [
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'phone_e164'  => (string) $row['phone_e164'],
			'is_verified' => (bool) ( (int) $row['is_verified'] === 1 ),
			'verified_at' => $row['verified_at'] ? (string) $row['verified_at'] : null,
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
		];
	}

	/**
	 * Check if a phone number is available for registration/assignment.
	 *
	 * @param string $raw_phone        Phone number to check.
	 * @param int    $exclude_user_id  Optional user ID to exclude (e.g. during profile update).
	 * @return bool
	 */
	public static function is_phone_available( string $raw_phone, int $exclude_user_id = 0 ): bool {
		global $wpdb;

		$canonical = Hedayati_Phone::normalize( $raw_phone );

		if ( is_wp_error( $canonical ) ) {
			return false;
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();

		if ( $exclude_user_id > 0 ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$table} WHERE phone_e164 = %s AND user_id != %d LIMIT 1",
					$canonical,
					$exclude_user_id
				)
			);
		} else {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$table} WHERE phone_e164 = %s LIMIT 1",
					$canonical
				)
			);
		}

		return null === $existing;
	}

	/**
	 * Assign or insert a phone number for a user.
	 * If user already has a phone record, updates it (resetting verification if phone changed).
	 *
	 * @param int    $user_id      WordPress user ID.
	 * @param string $raw_phone    Raw phone number to assign.
	 * @param bool   $is_verified  Initial verification state for newly created records only (default false).
	 * @return true|WP_Error
	 */
	public static function assign_phone( int $user_id, string $raw_phone, bool $is_verified = false ): true|WP_Error {
		global $wpdb;

		if ( $user_id <= 0 || ! get_user_by( 'id', $user_id ) ) {
			return new WP_Error(
				'invalid_user',
				esc_html__( 'کاربر مورد نظر یافت نشد.', 'hedayati-core' )
			);
		}

		$canonical = Hedayati_Phone::normalize( $raw_phone );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$existing_record = self::get_phone_record_by_user( $user_id );

		if ( $existing_record ) {
			// Update existing record
			return self::update_phone( $user_id, $raw_phone );
		}

		// Application pre-check for friendly error
		if ( ! self::is_phone_available( $canonical, $user_id ) ) {
			return new WP_Error(
				'phone_already_exists',
				esc_html__( 'این شماره موبایل قبلاً توسط کاربر دیگری ثبت شده است.', 'hedayati-core' )
			);
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();
		$now   = current_time( 'mysql', true );

		// Insert new record
		$inserted = $wpdb->insert(
			$table,
			[
				'user_id'     => $user_id,
				'phone_e164'  => $canonical,
				'is_verified' => $is_verified ? 1 : 0,
				'verified_at' => $is_verified ? $now : null,
				'created_at'  => $now,
				'updated_at'  => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		// Handle database-level constraint / race conditions
		if ( false === $inserted ) {
			if ( ! self::is_phone_available( $canonical, $user_id ) ) {
				return new WP_Error(
					'phone_already_exists',
					esc_html__( 'این شماره موبایل قبلاً توسط کاربر دیگری ثبت شده است.', 'hedayati-core' )
				);
			}

			return new WP_Error(
				'db_insert_failed',
				esc_html__( 'خطا در ثبت شماره موبایل در پایگاه داده.', 'hedayati-core' )
			);
		}

		return true;
	}

	/**
	 * Update the phone number for an existing user.
	 * Changing the phone number ALWAYS resets is_verified to 0 and verified_at to NULL.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $raw_phone  New phone number.
	 * @return true|WP_Error
	 */
	public static function update_phone( int $user_id, string $raw_phone ): true|WP_Error {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return new WP_Error( 'invalid_user', esc_html__( 'کاربر نامعتبر است.', 'hedayati-core' ) );
		}

		$canonical = Hedayati_Phone::normalize( $raw_phone );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$existing = self::get_phone_record_by_user( $user_id );

		if ( ! $existing ) {
			return self::assign_phone( $user_id, $raw_phone );
		}

		// If phone number is unchanged, preserve existing record without modification
		if ( $existing['phone_e164'] === $canonical ) {
			return true;
		}

		// Application pre-check for friendly error
		if ( ! self::is_phone_available( $canonical, $user_id ) ) {
			return new WP_Error(
				'phone_already_exists',
				esc_html__( 'این شماره موبایل قبلاً توسط کاربر دیگری ثبت شده است.', 'hedayati-core' )
			);
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();
		$now   = current_time( 'mysql', true );

		// Phone number has changed: verification MUST be reset
		$updated = $wpdb->update(
			$table,
			[
				'phone_e164'  => $canonical,
				'is_verified' => 0,
				'verified_at' => null,
				'updated_at'  => $now,
			],
			[ 'user_id' => $user_id ],
			[ '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		// Handle database-level constraint / race conditions
		if ( false === $updated ) {
			if ( ! self::is_phone_available( $canonical, $user_id ) ) {
				return new WP_Error(
					'phone_already_exists',
					esc_html__( 'این شماره موبایل قبلاً توسط کاربر دیگری ثبت شده است.', 'hedayati-core' )
				);
			}

			return new WP_Error(
				'db_update_failed',
				esc_html__( 'خطا در به‌روزرسانی شماره موبایل.', 'hedayati-core' )
			);
		}

		return true;
	}

	/**
	 * Delete a user's phone identity.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	public static function delete_phone( int $user_id ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();

		$deleted = $wpdb->delete(
			$table,
			[ 'user_id' => $user_id ],
			[ '%d' ]
		);

		return false !== $deleted;
	}

	/**
	 * Mark a user's phone number as verified.
	 * Returns false if no phone record exists for the user.
	 *
	 * @param int         $user_id
	 * @param string|null $verified_at Optional explicit UTC datetime (defaults to now).
	 * @return bool
	 */
	public static function verify_phone( int $user_id, ?string $verified_at = null ): bool {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return false;
		}

		// Ensure a phone record actually exists for this user before attempting verification
		$existing = self::get_phone_record_by_user( $user_id );
		if ( ! $existing ) {
			return false;
		}

		$table = Hedayati_DB_Schema::get_table_user_phones();
		$now   = $verified_at ?: current_time( 'mysql', true );

		$updated = $wpdb->update(
			$table,
			[
				'is_verified' => 1,
				'verified_at' => $now,
				'updated_at'  => current_time( 'mysql', true ),
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $updated;
	}
}
