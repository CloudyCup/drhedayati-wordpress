<?php
/**
 * Phase 2C (foundation) — Student profile fields in usermeta.
 *
 * Scope of this class: the **non-sensitive, unambiguously approved** part of the
 * student profile — a mailing address (line, city, Iranian postal code) stored in
 * `wp_usermeta` (ROADMAP P1.2: "address + extensible fields in usermeta").
 *
 * Deliberately NOT here (blocked on institute policy — see docs/OPEN_QUESTIONS.md):
 *   - National ID: needs the dedicated HEDAYATI_DATA_ENCRYPTION_KEY + keyed-HMAC
 *     design (D15) established in server config first. No plaintext national ID.
 *   - Verification state machine: conceptual states are approved but the reset
 *     rules and any benefit linkage are undecided.
 *   - Private documents: storage-outside-webroot / retention / offsite-transfer
 *     protocol undecided.
 *
 * Extensibility: fields come from `field_registry()`, filterable via
 * `hedayati_student_profile_fields`, so Phase 2C/2D can add fields without
 * touching the render / save / validate plumbing.
 *
 * Authorization:
 *   - a user editing their **own** profile needs `hedayati_edit_own_profile`;
 *   - editing/viewing **another** user's fields needs `hedayati_view_student_profiles_basic`
 *     (WordPress already gates the user-edit screen itself on `edit_user`).
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Student_Profile {

	public const META_ADDRESS = 'hedayati_address';
	public const META_CITY    = 'hedayati_city';
	public const META_POSTAL  = 'hedayati_postal_code';

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_meta' ] );

		add_action( 'show_user_profile', [ self::class, 'render_fields' ] );
		add_action( 'edit_user_profile', [ self::class, 'render_fields' ] );

		add_action( 'personal_options_update', [ self::class, 'save' ] );
		add_action( 'edit_user_profile_update', [ self::class, 'save' ] );

		add_action( 'user_profile_update_errors', [ self::class, 'validate' ], 10, 3 );
	}

	// ── Field registry ──────────────────────────────────────────────────────

	/**
	 * @return array<string, array{meta:string, label:string, type:string}>
	 */
	public static function field_registry(): array {
		$fields = [
			'address' => [
				'meta'  => self::META_ADDRESS,
				'label' => __( 'نشانی پستی', 'hedayati-core' ),
				'type'  => 'textarea',
			],
			'city' => [
				'meta'  => self::META_CITY,
				'label' => __( 'شهر', 'hedayati-core' ),
				'type'  => 'text',
			],
			'postal_code' => [
				'meta'  => self::META_POSTAL,
				'label' => __( 'کد پستی (۱۰ رقم)', 'hedayati-core' ),
				'type'  => 'text',
			],
		];

		/**
		 * Filter the student-profile field set.
		 *
		 * @param array $fields key => { meta, label, type ('text'|'textarea') }
		 */
		return (array) apply_filters( 'hedayati_student_profile_fields', $fields );
	}

	// ── Public read API ─────────────────────────────────────────────────────

	/**
	 * @return array<string,string> field key => stored value
	 */
	public static function get( int $user_id ): array {
		$out = [];

		if ( $user_id <= 0 ) {
			return $out;
		}

		foreach ( self::field_registry() as $key => $field ) {
			$out[ $key ] = (string) get_user_meta( $user_id, $field['meta'], true );
		}

		return $out;
	}

	// ── Meta registration ───────────────────────────────────────────────────

	public static function register_meta(): void {
		$auth = static function ( bool $allowed, string $meta_key, int $user_id ): bool {
			return self::current_user_can_edit( $user_id );
		};

		register_meta( 'user', self::META_ADDRESS, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_textarea_field',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		] );

		register_meta( 'user', self::META_CITY, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		] );

		register_meta( 'user', self::META_POSTAL, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => [ self::class, 'sanitize_postal' ],
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		] );
	}

	/**
	 * Postal code: transliterate to ASCII digits and keep digits only. Length is
	 * enforced in validate() so the user sees an error rather than silent truncation.
	 */
	public static function sanitize_postal( string $value ): string {
		$value = Hedayati_Text::digits_to_ascii( $value );
		return preg_replace( '/\D/', '', $value ) ?? '';
	}

	// ── Authorization ───────────────────────────────────────────────────────

	private static function current_user_can_edit( int $target_user_id ): bool {
		$current = get_current_user_id();

		if ( $current > 0 && $current === $target_user_id ) {
			return current_user_can( 'hedayati_edit_own_profile' );
		}

		return current_user_can( 'hedayati_view_student_profiles_basic' )
			&& current_user_can( 'edit_user', $target_user_id );
	}

	// ── Render ──────────────────────────────────────────────────────────────

	public static function render_fields( WP_User $user ): void {
		if ( ! self::current_user_can_edit( $user->ID ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'اطلاعات نشانی (مجتمع هدایتی)', 'hedayati-core' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::field_registry() as $key => $field ) {
			$value = (string) get_user_meta( $user->ID, $field['meta'], true );
			$id    = 'hedayati_profile_' . esc_attr( $key );

			echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label></th><td>';

			if ( 'textarea' === ( $field['type'] ?? 'text' ) ) {
				printf(
					'<textarea name="%s" id="%s" rows="3" class="regular-text">%s</textarea>',
					esc_attr( $field['meta'] ),
					esc_attr( $id ),
					esc_textarea( $value )
				);
			} else {
				$dir = self::META_POSTAL === $field['meta'] ? 'ltr' : 'rtl';
				printf(
					'<input type="text" name="%s" id="%s" value="%s" class="regular-text" dir="%s">',
					esc_attr( $field['meta'] ),
					esc_attr( $id ),
					esc_attr( $value ),
					esc_attr( $dir )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	// ── Validate (blocks the save on error) ─────────────────────────────────

	/**
	 * @param WP_Error $errors
	 * @param bool     $update
	 * @param object   $user   stdClass of the submitted user data (has ->ID on update)
	 */
	public static function validate( WP_Error $errors, bool $update, object $user ): void {
		$target_id = isset( $user->ID ) ? (int) $user->ID : 0;

		if ( ! isset( $_POST[ self::META_POSTAL ] ) || ! self::current_user_can_edit( $target_id ) ) {
			return;
		}

		$postal = self::sanitize_postal( sanitize_text_field( wp_unslash( $_POST[ self::META_POSTAL ] ) ) );

		if ( '' !== $postal && ! preg_match( '/^\d{10}$/', $postal ) ) {
			$errors->add(
				'hedayati_postal_code',
				esc_html__( 'کد پستی باید دقیقاً ۱۰ رقم باشد یا خالی بماند.', 'hedayati-core' )
			);
		}
	}

	// ── Save ────────────────────────────────────────────────────────────────

	public static function save( int $user_id ): void {
		// WordPress core has already verified the `update-user_{id}` nonce and the
		// `edit_user` capability before firing this hook; we add the Hedayati gate.
		if ( ! self::current_user_can_edit( $user_id ) ) {
			return;
		}

		foreach ( self::field_registry() as $field ) {
			if ( ! isset( $_POST[ $field['meta'] ] ) || ! is_string( $_POST[ $field['meta'] ] ) ) {
				continue;
			}

			$raw = wp_unslash( $_POST[ $field['meta'] ] );

			if ( self::META_POSTAL === $field['meta'] ) {
				$clean = self::sanitize_postal( sanitize_text_field( $raw ) );
				// Reject an out-of-format value here too (validate() should have
				// blocked it, but never persist a bad one).
				if ( '' !== $clean && ! preg_match( '/^\d{10}$/', $clean ) ) {
					continue;
				}
			} elseif ( self::META_ADDRESS === $field['meta'] ) {
				$clean = sanitize_textarea_field( $raw );
			} else {
				$clean = sanitize_text_field( $raw );
			}

			update_user_meta( $user_id, $field['meta'], $clean );
		}
	}
}
