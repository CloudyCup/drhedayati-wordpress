<?php
/**
 * Authentication & Request Rate Limiter.
 *
 * Provides conservative, transient-based rate limiting for authentication attempts
 * with separate, configurable thresholds for individual identifiers (protecting accounts)
 * and client IP addresses (protecting against distributed brute force on shared networks).
 * Canonicalizes phone and username identifiers so format variations share the same rate bucket.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Rate_Limiter {

	/**
	 * Default maximum failed attempts per individual identifier (username/phone) before lockout.
	 */
	public const DEFAULT_IDENTIFIER_MAX_ATTEMPTS = 5;

	/**
	 * Default maximum failed attempts per client IP address before lockout.
	 * Set higher to accommodate legitimate users on shared networks/CGNAT.
	 */
	public const DEFAULT_IP_MAX_ATTEMPTS = 30;

	/**
	 * Default lockout duration in seconds (15 minutes).
	 */
	public const DEFAULT_LOCKOUT_SECONDS = 900;

	/**
	 * Return the active rate limiting configuration.
	 *
	 * @return array{identifier_max_attempts: int, ip_max_attempts: int, lockout_seconds: int}
	 */
	public static function get_config(): array {
		$defaults = [
			'identifier_max_attempts' => self::DEFAULT_IDENTIFIER_MAX_ATTEMPTS,
			'ip_max_attempts'         => self::DEFAULT_IP_MAX_ATTEMPTS,
			'lockout_seconds'         => self::DEFAULT_LOCKOUT_SECONDS,
		];

		/**
		 * Filter rate limit configuration.
		 *
		 * @param array $config { identifier_max_attempts: int, ip_max_attempts: int, lockout_seconds: int }
		 */
		return (array) apply_filters( 'hedayati_rate_limit_config', $defaults );
	}

	/**
	 * Canonicalize a login identifier so that format variations of the same phone or username
	 * share the exact same rate-limiting counter bucket.
	 *
	 * @param string $raw_identifier
	 * @return string
	 */
	public static function canonicalize_identifier( string $raw_identifier ): string {
		$trimmed = trim( $raw_identifier );

		if ( '' === $trimmed ) {
			return '';
		}

		if ( Hedayati_Phone::looks_like_iranian_phone( $trimmed ) ) {
			$canonical = Hedayati_Phone::normalize( $trimmed );
			if ( ! is_wp_error( $canonical ) ) {
				return $canonical;
			}
		}

		return strtolower( $trimmed );
	}

	/**
	 * Check if a client IP or identifier is currently rate limited.
	 *
	 * @param string $identifier Login identifier (username or phone).
	 * @param string $ip         Client IP address.
	 * @return bool              True if locked out, false if request is allowed.
	 */
	public static function is_rate_limited( string $identifier, string $ip ): bool {
		$config = self::get_config();

		$canonical_id = self::canonicalize_identifier( $identifier );
		$ip_key       = self::get_transient_key( 'ip', $ip );
		$id_key       = '' !== $canonical_id ? self::get_transient_key( 'id', $canonical_id ) : '';

		$ip_attempts = (int) get_transient( $ip_key );
		$id_attempts = '' !== $id_key ? (int) get_transient( $id_key ) : 0;

		return ( $id_attempts >= $config['identifier_max_attempts'] || $ip_attempts >= $config['ip_max_attempts'] );
	}

	/**
	 * Record a failed authentication attempt against both the identifier and IP buckets.
	 *
	 * @param string $identifier
	 * @param string $ip
	 */
	public static function record_failure( string $identifier, string $ip ): void {
		$config = self::get_config();

		$canonical_id = self::canonicalize_identifier( $identifier );
		$ip_key       = self::get_transient_key( 'ip', $ip );
		$id_key       = '' !== $canonical_id ? self::get_transient_key( 'id', $canonical_id ) : '';

		$ip_attempts = (int) get_transient( $ip_key );
		set_transient( $ip_key, $ip_attempts + 1, $config['lockout_seconds'] );

		if ( '' !== $id_key ) {
			$id_attempts = (int) get_transient( $id_key );
			set_transient( $id_key, $id_attempts + 1, $config['lockout_seconds'] );
		}
	}

	/**
	 * Clear recorded attempts for an individual identifier upon successful authentication.
	 * NOTE: The shared IP bucket is intentionally NOT cleared to protect against brute-force attacks
	 * originating from shared networks; it will expire naturally after lockout_seconds.
	 *
	 * @param string $identifier
	 */
	public static function clear_identifier_attempts( string $identifier ): void {
		$canonical_id = self::canonicalize_identifier( $identifier );

		if ( '' !== $canonical_id ) {
			delete_transient( self::get_transient_key( 'id', $canonical_id ) );
		}
	}

	/**
	 * Resolve client IP address safely.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

		if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '127.0.0.1';
		}

		return $ip;
	}

	/**
	 * Generate a safe, fixed-length transient key.
	 *
	 * @param string $type   'ip' or 'id'
	 * @param string $value  Value to hash
	 * @return string
	 */
	private static function get_transient_key( string $type, string $value ): string {
		$sanitized = trim( strtolower( $value ) );
		return 'hd_rl_' . $type . '_' . substr( hash( 'sha256', $sanitized ), 0, 24 );
	}
}
