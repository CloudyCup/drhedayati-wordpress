<?php
/**
 * Phase 2B — Academic Operations validation & canonicalization.
 *
 * Pure, side-effect-free helpers shared by every academic-operations service and
 * admin screen. Centralizes the approved business-state vocabularies (stored as
 * validated strings, never MySQL ENUMs — see docs/DECISIONS.md D13) plus the
 * canonical date / datetime / integer parsing rules.
 *
 * Design rules honoured here:
 *   - Business states are validated strings with an explicit, safe fallback.
 *   - Dates are strict Gregorian ISO `YYYY-MM-DD`, `checkdate()`-verified.
 *   - Datetimes are canonical `YYYY-MM-DD HH:MM:SS` (24h).
 *   - Persian/Arabic digits are transliterated to ASCII before numeric parsing.
 *   - "Unknown" capacity / tuition is NULL — never a fabricated 0 or 20.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Academic_Validation {

	/**
	 * Course Run operational lifecycle states.
	 */
	public const RUN_STATUSES = [ 'draft', 'scheduled', 'in_progress', 'completed', 'cancelled' ];

	/**
	 * Course Run registration states (separate axis from RUN_STATUSES).
	 */
	public const REGISTRATION_STATUSES = [ 'closed', 'open', 'soon' ];

	/**
	 * Class Session states.
	 */
	public const SESSION_STATUSES = [ 'scheduled', 'held', 'cancelled' ];

	/**
	 * Enrollment states.
	 */
	public const ENROLLMENT_STATUSES = [ 'active', 'withdrawn', 'completed', 'cancelled' ];

	/**
	 * Attendance marks.
	 */
	public const ATTENDANCE_STATUSES = [ 'present', 'absent', 'late', 'excused' ];

	/**
	 * Run staff assignment roles.
	 *
	 * - primary_instructor / additional_instructor: require a Teacher profile.
	 * - assistant (TA): requires a WordPress staff user, not a Teacher profile.
	 */
	public const STAFF_ROLES = [ 'primary_instructor', 'additional_instructor', 'assistant' ];

	/**
	 * Roles that must be backed by a Teacher CPT profile.
	 */
	public const INSTRUCTOR_ROLES = [ 'primary_instructor', 'additional_instructor' ];

	// ── Business-state sanitizers (allowlist + safe fallback) ──────────────────

	public static function sanitize_run_status( string $value, string $fallback = 'draft' ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::RUN_STATUSES, true ) ? $value : $fallback;
	}

	public static function sanitize_registration_status( string $value, string $fallback = 'closed' ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::REGISTRATION_STATUSES, true ) ? $value : $fallback;
	}

	public static function sanitize_session_status( string $value, string $fallback = 'scheduled' ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::SESSION_STATUSES, true ) ? $value : $fallback;
	}

	public static function sanitize_enrollment_status( string $value, string $fallback = 'active' ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::ENROLLMENT_STATUSES, true ) ? $value : $fallback;
	}

	/**
	 * Attendance status has no safe implicit default — an unrecognised value is an error.
	 *
	 * @return string|null Canonical status, or null when invalid.
	 */
	public static function parse_attendance_status( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::ATTENDANCE_STATUSES, true ) ? $value : null;
	}

	/**
	 * Staff role has no safe implicit default — an unrecognised value is an error.
	 *
	 * @return string|null Canonical role, or null when invalid.
	 */
	public static function parse_staff_role( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, self::STAFF_ROLES, true ) ? $value : null;
	}

	public static function is_instructor_role( string $role ): bool {
		return in_array( $role, self::INSTRUCTOR_ROLES, true );
	}

	// ── Date / datetime parsing ───────────────────────────────────────────────

	/**
	 * Parse a strict Gregorian ISO date (`YYYY-MM-DD`), verifying the calendar day.
	 * Persian/Arabic digits are accepted and transliterated first.
	 *
	 * @param string $value
	 * @return string|null Canonical `YYYY-MM-DD`, or null when empty/invalid.
	 */
	public static function parse_iso_date( string $value ): ?string {
		$value = trim( Hedayati_Text::digits_to_ascii( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value, $m ) ) {
			return null;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : null;
	}

	/**
	 * Parse a canonical datetime. Accepts `YYYY-MM-DD HH:MM[:SS]` or the HTML
	 * `datetime-local` form `YYYY-MM-DDTHH:MM[:SS]`. Persian/Arabic digits accepted.
	 *
	 * @param string $value
	 * @return string|null Canonical `YYYY-MM-DD HH:MM:SS`, or null when empty/invalid.
	 */
	public static function parse_datetime( string $value ): ?string {
		$value = trim( Hedayati_Text::digits_to_ascii( $value ) );

		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			return null;
		}

		$year   = (int) $m[1];
		$month  = (int) $m[2];
		$day    = (int) $m[3];
		$hour   = (int) $m[4];
		$minute = (int) $m[5];
		$second = isset( $m[6] ) ? (int) $m[6] : 0;

		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		if ( $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
	}

	// ── Numeric parsing ───────────────────────────────────────────────────────

	/**
	 * Parse an optional non-negative integer (capacity, tuition in rial, …).
	 *
	 * Distinguishes "not provided" (empty string → null, meaning *unknown*) from
	 * "provided but invalid" (non-numeric / negative → WP_Error).
	 *
	 * @param string $value
	 * @param string $error_code Error code to use when the value is present but invalid.
	 * @return int|null|WP_Error
	 */
	public static function parse_optional_nonneg_int( string $value, string $error_code = 'invalid_number' ): int|null|WP_Error {
		$value = trim( Hedayati_Text::digits_to_ascii( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( ! ctype_digit( $value ) ) {
			return new WP_Error(
				$error_code,
				esc_html__( 'مقدار عددی نامعتبر است. یک عدد صحیح نامنفی وارد کنید یا فیلد را خالی بگذارید.', 'hedayati-core' )
			);
		}

		return (int) $value;
	}

	/**
	 * Parse a required positive integer (session number, …).
	 *
	 * @param string $value
	 * @return int|null Positive integer, or null when empty/invalid/zero/negative.
	 */
	public static function parse_positive_int( string $value ): ?int {
		$value = trim( Hedayati_Text::digits_to_ascii( $value ) );

		if ( '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}

		$int = (int) $value;

		return $int > 0 ? $int : null;
	}
}
