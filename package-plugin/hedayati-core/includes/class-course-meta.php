<?php
/**
 * Registers course post meta fields via register_post_meta().
 *
 * Each field specifies:
 *   - type            : PHP/REST type
 *   - single          : always true (one value per post)
 *   - default         : safe empty default
 *   - sanitize_callback : server-side sanitization (in addition to meta-box save)
 *   - auth_callback   : capability gate
 *   - show_in_rest    : false for all fields in Phase 1 (no public API yet)
 *
 * Date fields use ISO 8601 (YYYY-MM-DD) as the canonical stored format.
 * Persian/Shamsi formatting is a presentation concern for a later phase.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Course_Meta {

	/**
	 * Allowlist for registration state values.
	 */
	public const REGISTRATION_STATES = [ 'open', 'closed', 'soon' ];

	/**
	 * Register all course meta fields.
	 */
	public static function register(): void {
		$post_type     = 'course';
		$auth_callback = [ self::class, 'auth_edit_post' ];

		// ── Display / identity ────────────────────────────────────────────────

		register_post_meta( $post_type, '_course_english_name', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Instructor ────────────────────────────────────────────────────────

		register_post_meta( $post_type, '_course_teacher', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Schedule / duration ───────────────────────────────────────────────

		register_post_meta( $post_type, '_course_duration', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// Next start date — stored as ISO 8601 YYYY-MM-DD for machine sortability.
		// Presentation as Shamsi is a future frontend concern.
		register_post_meta( $post_type, '_course_next_start_date', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => [ self::class, 'sanitize_iso_date' ],
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Curriculum ───────────────────────────────────────────────────────

		register_post_meta( $post_type, '_course_level', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		register_post_meta( $post_type, '_course_prerequisites', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_textarea_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Commerce — Phase 1 stores as display string.
		// Architecture note: migrate to integer (IRR rial, no decimals) in a
		// future phase when payment gateway is integrated.
		register_post_meta( $post_type, '_course_price', [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Registration state — validated against allowlist ──────────────────

		register_post_meta( $post_type, '_course_registration_state', [
			'type'              => 'string',
			'single'            => true,
			'default'           => 'soon',
			'sanitize_callback' => [ self::class, 'sanitize_registration_state' ],
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );

		// ── Homepage featured flag — boolean ──────────────────────────────────
		// WordPress stores meta as strings; rest_sanitize_boolean handles
		// PHP bool → '1'/''/null normalisation transparently.
		register_post_meta( $post_type, '_course_is_featured', [
			'type'              => 'boolean',
			'single'            => true,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'auth_callback'     => $auth_callback,
			'show_in_rest'      => false,
		] );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * Sanitize a date value to ISO 8601 (YYYY-MM-DD) and verify Gregorian validity.
	 *
	 * Rejects invalid dates like 2026-02-31 via checkdate().
	 * Returns an empty string if the input is not a valid calendar date.
	 */
	public static function sanitize_iso_date( string $value ): string {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return '';
		}

		// Strict pattern: YYYY-MM-DD
		if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $value, $matches ) ) {
			$year  = (int) $matches[1];
			$month = (int) $matches[2];
			$day   = (int) $matches[3];

			if ( checkdate( $month, $day, $year ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Sanitize registration state against the defined allowlist.
	 * Falls back to 'soon' for any unrecognised value.
	 */
	public static function sanitize_registration_state( string $value ): string {
		return in_array( $value, self::REGISTRATION_STATES, true ) ? $value : 'soon';
	}

	// ── Auth callback ─────────────────────────────────────────────────────────

	/**
	 * Auth callback shared across all course meta fields.
	 * Grants access to any user who can edit the specific post.
	 *
	 * @param bool   $allowed   Whether the user is allowed by default.
	 * @param string $meta_key  The meta key being checked.
	 * @param int    $object_id The post ID.
	 */
	public static function auth_edit_post( bool $allowed, string $meta_key, int $object_id ): bool {
		return current_user_can( 'edit_post', $object_id );
	}
}
