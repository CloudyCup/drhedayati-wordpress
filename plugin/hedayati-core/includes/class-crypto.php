<?php
/**
 * Phase 2C — Dedicated encryption/HMAC primitives for national-ID data (D15/D36).
 *
 * AES-256-GCM (via OpenSSL) for reversible encryption; a separate keyed HMAC for
 * duplicate detection. Both keys come from `wp-config.php` / server config,
 * outside Git, and are validated in a strict format — base64-encoded, decoding to
 * exactly 32 raw bytes. Neither key is ever derived from `SECURE_AUTH_KEY` or any
 * rotatable WordPress salt: rotating WP salts must never make business records
 * unreadable.
 *
 * Fails closed: if either constant is missing or does not decode to a 32-byte key,
 * `is_configured()` is false and every dependent caller must refuse to operate —
 * there is no plaintext or weak-cipher fallback anywhere in this class.
 *
 * Blob format: "{key_version}:{base64 iv}:{base64 ciphertext+tag}". Key-version
 * resolution supports future rotation: version N reads
 * HEDAYATI_DATA_ENCRYPTION_KEY_V{N} (same strict validation); only version 1 falls
 * back to the unsuffixed HEDAYATI_DATA_ENCRYPTION_KEY constant.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Crypto {

	private const CIPHER      = 'aes-256-gcm';
	private const KEY_BYTES   = 32;
	private const IV_BYTES    = 12;
	private const TAG_BYTES   = 16;
	private const CURRENT_KEY_VERSION = 1;

	/**
	 * True only if both the encryption key and the HMAC key are defined and
	 * decode to a valid 32-byte key. Callers MUST check this before any
	 * encrypt/decrypt/fingerprint call that will persist or compare data.
	 */
	public static function is_configured(): bool {
		return null !== self::resolve_key( self::CURRENT_KEY_VERSION )
			&& null !== self::resolve_hmac_key();
	}

	/**
	 * @return string|WP_Error Versioned blob, or WP_Error if not configured / encryption failed.
	 */
	public static function encrypt( string $plaintext ): string|WP_Error {
		$version = self::CURRENT_KEY_VERSION;
		$key     = self::resolve_key( $version );

		if ( null === $key ) {
			return new WP_Error(
				'crypto_not_configured',
				esc_html__( 'کلید رمزنگاری پیکربندی نشده یا نامعتبر است.', 'hedayati-core' )
			);
		}

		$iv  = random_bytes( self::IV_BYTES );
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES );

		if ( false === $ciphertext || '' === $tag ) {
			return new WP_Error( 'encrypt_failed', esc_html__( 'رمزنگاری ناموفق بود.', 'hedayati-core' ) );
		}

		return sprintf(
			'%d:%s:%s',
			$version,
			base64_encode( $iv ),
			base64_encode( $ciphertext . $tag )
		);
	}

	/**
	 * @return string|WP_Error Plaintext, or WP_Error if not configured / malformed / auth failed.
	 */
	public static function decrypt( string $blob ): string|WP_Error {
		$parts = explode( ':', $blob, 3 );

		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'decrypt_failed', esc_html__( 'قالب داده رمزشده نامعتبر است.', 'hedayati-core' ) );
		}

		[ $version_raw, $iv_b64, $ct_b64 ] = $parts;

		if ( ! ctype_digit( $version_raw ) ) {
			return new WP_Error( 'decrypt_failed', esc_html__( 'قالب داده رمزشده نامعتبر است.', 'hedayati-core' ) );
		}

		$version = (int) $version_raw;
		$key     = self::resolve_key( $version );

		if ( null === $key ) {
			return new WP_Error(
				'crypto_not_configured',
				esc_html__( 'کلید رمزنگاری پیکربندی نشده یا نامعتبر است.', 'hedayati-core' )
			);
		}

		$iv        = base64_decode( $iv_b64, true );
		$ct_and_tag = base64_decode( $ct_b64, true );

		if ( false === $iv || false === $ct_and_tag || self::IV_BYTES !== strlen( $iv ) || strlen( $ct_and_tag ) <= self::TAG_BYTES ) {
			return new WP_Error( 'decrypt_failed', esc_html__( 'قالب داده رمزشده نامعتبر است.', 'hedayati-core' ) );
		}

		$tag        = substr( $ct_and_tag, -self::TAG_BYTES );
		$ciphertext = substr( $ct_and_tag, 0, -self::TAG_BYTES );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plaintext ) {
			return new WP_Error( 'decrypt_failed', esc_html__( 'رمزگشایی ناموفق بود.', 'hedayati-core' ) );
		}

		return $plaintext;
	}

	/**
	 * Deterministic keyed fingerprint for exact-match duplicate detection.
	 * Caller is responsible for normalizing the input (e.g. digits to ASCII)
	 * before calling this, so equivalent inputs always fingerprint identically.
	 *
	 * @return string|WP_Error Lowercase hex SHA-256 HMAC, or WP_Error if not configured.
	 */
	public static function fingerprint( string $normalized_value ): string|WP_Error {
		$key = self::resolve_hmac_key();

		if ( null === $key ) {
			return new WP_Error(
				'crypto_not_configured',
				esc_html__( 'کلید رمزنگاری پیکربندی نشده یا نامعتبر است.', 'hedayati-core' )
			);
		}

		return hash_hmac( 'sha256', $normalized_value, $key );
	}

	public static function current_key_version(): int {
		return self::CURRENT_KEY_VERSION;
	}

	// ── Internals ───────────────────────────────────────────────────────────

	/**
	 * Resolve and strictly validate the encryption key for a given version.
	 *
	 * @return string|null Raw 32-byte key, or null if unconfigured/invalid.
	 */
	private static function resolve_key( int $version ): ?string {
		if ( $version === self::CURRENT_KEY_VERSION ) {
			$versioned_const = 'HEDAYATI_DATA_ENCRYPTION_KEY_V' . $version;
			if ( defined( $versioned_const ) ) {
				return self::decode_strict( (string) constant( $versioned_const ) );
			}
			if ( defined( 'HEDAYATI_DATA_ENCRYPTION_KEY' ) ) {
				return self::decode_strict( (string) constant( 'HEDAYATI_DATA_ENCRYPTION_KEY' ) );
			}
			return null;
		}

		$versioned_const = 'HEDAYATI_DATA_ENCRYPTION_KEY_V' . $version;
		if ( defined( $versioned_const ) ) {
			return self::decode_strict( (string) constant( $versioned_const ) );
		}

		return null;
	}

	private static function resolve_hmac_key(): ?string {
		if ( ! defined( 'HEDAYATI_DATA_HMAC_KEY' ) ) {
			return null;
		}

		return self::decode_strict( (string) constant( 'HEDAYATI_DATA_HMAC_KEY' ) );
	}

	/**
	 * Strict base64 decode, requiring exactly KEY_BYTES raw bytes. Rejects
	 * malformed base64, wrong-length, empty, and non-string configuration.
	 */
	private static function decode_strict( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$decoded = base64_decode( $value, true );

		if ( false === $decoded || self::KEY_BYTES !== strlen( $decoded ) ) {
			return null;
		}

		return $decoded;
	}
}
