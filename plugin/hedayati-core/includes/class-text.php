<?php
/**
 * Shared text / numeral normalization helpers.
 *
 * Canonical rule (see docs/DATA_MODEL.md): stored and searchable values use ASCII
 * digits. Persian (۰-۹) and Arabic-Indic (٠-٩) digits are a UI concern and must be
 * transliterated to ASCII on the server before validation or storage.
 *
 * NOTE: `Hedayati_Phone` predates this helper and keeps its own inline digit map
 * for the verified Phase 2A auth path; it is intentionally left untouched. All new
 * (Phase 2B+) code performs digit normalization through this class so the rule
 * lives in exactly one place going forward.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Text {

	/**
	 * Map of Persian and Eastern Arabic digits to ASCII digits.
	 */
	private const DIGIT_MAP = [
		// Persian (U+06F0–U+06F9)
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
		// Eastern Arabic (U+0660–U+0669)
		'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
		'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
	];

	/**
	 * ASCII digit → Persian digit map (display only).
	 */
	private const ASCII_TO_PERSIAN = [
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	];

	/**
	 * Transliterate any Persian/Arabic-Indic digits in a string to ASCII digits.
	 * All other characters are preserved unchanged.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function digits_to_ascii( string $value ): string {
		return strtr( $value, self::DIGIT_MAP );
	}

	/**
	 * Transliterate ASCII digits to Persian digits. **Display only** — never call
	 * this on a value that will be stored, searched, or compared.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function digits_to_persian( string $value ): string {
		return strtr( $value, self::ASCII_TO_PERSIAN );
	}
}
