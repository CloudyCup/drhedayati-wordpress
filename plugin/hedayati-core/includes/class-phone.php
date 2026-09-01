<?php
/**
 * Iranian Phone Normalization Service.
 *
 * Provides strict canonical E.164 normalization (+989XXXXXXXXX), Persian/Arabic
 * digit transliteration, format validation, and display formatting.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Phone {

	/**
	 * Canonical regex pattern for Iranian mobile numbers (+989 followed by exactly 9 digits 0-9).
	 */
	public const CANONICAL_REGEX = '/^\+989[0-9]{9}$/';

	/**
	 * Allowed input character set before stripping formatting:
	 * Persian digits, Arabic digits, ASCII digits, +, spaces, hyphens, parentheses, dots.
	 */
	private const ALLOWED_CHARS_REGEX = '/^[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}+\s\-\(\)\.]+$/u';

	/**
	 * Map of Persian and Eastern Arabic digits to ASCII standard digits.
	 */
	private const DIGIT_MAP = [
		// Persian digits
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		// Eastern Arabic digits
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	];

	/**
	 * Validate input characters, transliterate digits, and strip approved separators.
	 * Rejects any unexpected characters (letters, scripts, symbols, underscores).
	 *
	 * @param string $input
	 * @return string|WP_Error Cleaned digit string (with optional leading '+') or WP_Error.
	 */
	public static function clean_and_transliterate( string $input ): string|WP_Error {
		$trimmed = trim( $input );

		if ( '' === $trimmed ) {
			return new WP_Error(
				'empty_phone',
				esc_html__( 'شماره موبایل وارد نشده است.', 'hedayati-core' )
			);
		}

		// Strict whitelist check: only allow digits (ASCII/Persian/Arabic), +, and approved separators
		if ( ! preg_match( self::ALLOWED_CHARS_REGEX, $trimmed ) ) {
			return new WP_Error(
				'invalid_phone_characters',
				esc_html__( 'شماره موبایل شامل کاراکترهای غیرمجاز است.', 'hedayati-core' )
			);
		}

		// Transliterate Persian and Arabic digits to ASCII
		$converted = strtr( $trimmed, self::DIGIT_MAP );

		// Enforce plus sign rules: at most one '+', and must be at index 0
		$plus_count = substr_count( $converted, '+' );
		if ( $plus_count > 1 || ( 1 === $plus_count && ! str_starts_with( $converted, '+' ) ) ) {
			return new WP_Error(
				'invalid_plus_position',
				esc_html__( 'علامت + فقط در ابتدای شماره موبایل مجاز است.', 'hedayati-core' )
			);
		}

		$has_leading_plus = str_starts_with( $converted, '+' );

		// Strip approved formatting characters: spaces, hyphens, parentheses, dots
		$digits_only = preg_replace( '/[\s\-\(\)\.]/', '', $has_leading_plus ? substr( $converted, 1 ) : $converted );

		if ( '' === $digits_only || ! ctype_digit( $digits_only ) ) {
			return new WP_Error(
				'invalid_phone_digits',
				esc_html__( 'شماره موبایل نامعتبر است.', 'hedayati-core' )
			);
		}

		return $has_leading_plus ? '+' . $digits_only : $digits_only;
	}

	/**
	 * Normalize any supported Iranian mobile phone representation to canonical E.164 (+989XXXXXXXXX).
	 *
	 * Supports:
	 *   - 09XXXXXXXXX    (11 digits)
	 *   - 9XXXXXXXXX     (10 digits)
	 *   - +989XXXXXXXXX  (13 chars)
	 *   - 00989XXXXXXXXX (14 digits)
	 *   - 989XXXXXXXXX   (12 digits)
	 *   - Persian (۰-۹) and Arabic (٠-٩) digits
	 *   - Approved separators: spaces, -, (), .
	 *
	 * @param string $raw_phone  Raw phone input.
	 * @return string|WP_Error   Canonical E.164 string on success, WP_Error on invalid input.
	 */
	public static function normalize( string $raw_phone ): string|WP_Error {
		$cleaned = self::clean_and_transliterate( $raw_phone );

		if ( is_wp_error( $cleaned ) ) {
			return $cleaned;
		}

		$canonical = '';

		// 1. Starts with +989 (13 characters)
		if ( str_starts_with( $cleaned, '+989' ) ) {
			$canonical = $cleaned;
		}
		// 2. Starts with 00989 (14 digits)
		elseif ( str_starts_with( $cleaned, '00989' ) ) {
			$canonical = '+98' . substr( $cleaned, 4 );
		}
		// 3. Starts with 989 (12 digits, no plus)
		elseif ( str_starts_with( $cleaned, '989' ) ) {
			$canonical = '+' . $cleaned;
		}
		// 4. Starts with 09 (11 digits, standard Iranian national format)
		elseif ( str_starts_with( $cleaned, '09' ) ) {
			$canonical = '+98' . substr( $cleaned, 1 );
		}
		// 5. Starts with 9 (10 digits, omitted leading 0)
		elseif ( str_starts_with( $cleaned, '9' ) && 10 === strlen( $cleaned ) ) {
			$canonical = '+98' . $cleaned;
		} else {
			return new WP_Error(
				'invalid_phone_format',
				esc_html__( 'فرمت شماره موبایل نامعتبر است. لطفاً شماره موبایل ۱۱ رقمی همراه با پیش‌شماره ۰۹ وارد کنید.', 'hedayati-core' )
			);
		}

		if ( ! preg_match( self::CANONICAL_REGEX, $canonical ) ) {
			return new WP_Error(
				'invalid_phone_digits',
				esc_html__( 'شماره موبایل نامعتبر است. شماره همراه باید با ۰۹ شروع شده و ۱۱ رقم باشد.', 'hedayati-core' )
			);
		}

		return $canonical;
	}

	/**
	 * Check if a raw phone string is valid and normalizable.
	 *
	 * @param string $raw_phone
	 * @return bool
	 */
	public static function is_valid( string $raw_phone ): bool {
		$result = self::normalize( $raw_phone );
		return ! is_wp_error( $result );
	}

	/**
	 * Heuristic check to determine if an input identifier resembles an Iranian mobile number
	 * rather than a standard alphanumeric username.
	 *
	 * @param string $input
	 * @return bool
	 */
	public static function looks_like_iranian_phone( string $input ): bool {
		$cleaned = self::clean_and_transliterate( $input );

		if ( is_wp_error( $cleaned ) || '' === $cleaned ) {
			return false;
		}

		// Check common prefixes after transliteration
		if (
			str_starts_with( $cleaned, '09' ) ||
			str_starts_with( $cleaned, '+989' ) ||
			str_starts_with( $cleaned, '00989' ) ||
			str_starts_with( $cleaned, '989' ) ||
			( str_starts_with( $cleaned, '9' ) && 10 === strlen( $cleaned ) )
		) {
			$digits_only = ltrim( $cleaned, '+' );
			return ctype_digit( $digits_only );
		}

		return false;
	}

	/**
	 * Format a canonical E.164 phone number for human presentation.
	 *
	 * @param string $phone_e164  Canonical E.164 number (+989XXXXXXXXX)
	 * @param string $format      'national' (09141234567), 'spaced' (0914 123 4567), or 'international' (+98 914 123 4567)
	 * @return string
	 */
	public static function format_display( string $phone_e164, string $format = 'national' ): string {
		if ( ! preg_match( self::CANONICAL_REGEX, $phone_e164 ) ) {
			return $phone_e164;
		}

		$national = '0' . substr( $phone_e164, 3 ); // 11 digits: 09141234567

		return match ( $format ) {
			'spaced'        => substr( $national, 0, 4 ) . ' ' . substr( $national, 4, 3 ) . ' ' . substr( $national, 7 ),
			'international' => '+98 ' . substr( $phone_e164, 3, 3 ) . ' ' . substr( $phone_e164, 6, 3 ) . ' ' . substr( $phone_e164, 9 ),
			default         => $national,
		};
	}
}
