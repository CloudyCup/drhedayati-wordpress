<?php
/**
 * Shamsi (Jalali / Persian) calendar — display & input conversion layer.
 *
 * Canonical storage stays Gregorian ISO (`Y-m-d` dates, `Y-m-d H:i:s` datetimes,
 * ASCII digits) — see docs/DECISIONS.md D9. This class is the **UI layer only**:
 *   - `from_gregorian()` / `to_gregorian()` — pure integer date-part conversion.
 *   - `format()` / `format_long()` — turn a stored ISO string into a Shamsi label
 *     (optionally with Persian digits). The **time part is copied verbatim** —
 *     a session time is a wall-clock time, not a timezone-bearing instant (Q9).
 *   - `parse_input()` — accept a Shamsi date the user typed (Persian or ASCII
 *     digits, `/`, `-` or `.` separators) and return the canonical Gregorian
 *     `Y-m-d`, or null.
 *
 * The conversion uses the standard 33-year-cycle integer algorithm (Roozbeh
 * Pournader / jdf), the same one used across Iranian web software.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Jalali {

	/**
	 * Persian month names (index 1–12).
	 *
	 * @var string[]
	 */
	private const MONTHS = [
		1  => 'فروردین',
		2  => 'اردیبهشت',
		3  => 'خرداد',
		4  => 'تیر',
		5  => 'مرداد',
		6  => 'شهریور',
		7  => 'مهر',
		8  => 'آبان',
		9  => 'آذر',
		10 => 'دی',
		11 => 'بهمن',
		12 => 'اسفند',
	];

	// ── Pure date-part conversion ───────────────────────────────────────────

	/**
	 * Gregorian (y, m, d) → Jalali [jy, jm, jd].
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	public static function from_gregorian( int $gy, int $gm, int $gd ): array {
		$g_d_m = [ 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 ];

		$gy2  = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 )
			+ intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];

		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days %= 12053;

		$jy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$jy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}

		return [ $jy, $jm, $jd ];
	}

	/**
	 * Jalali (jy, jm, jd) → Gregorian [gy, gm, gd].
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		$jy += 1595;

		$days = -355668 + ( 365 * $jy ) + ( intdiv( $jy, 33 ) * 8 ) + intdiv( ( $jy % 33 ) + 3, 4 )
			+ $jd + ( ( $jm < 7 ) ? ( $jm - 1 ) * 31 : ( ( $jm - 7 ) * 30 ) + 186 );

		$gy    = 400 * intdiv( $days, 146097 );
		$days %= 146097;

		if ( $days > 36524 ) {
			$gy  += 100 * intdiv( --$days, 36524 );
			$days %= 36524;
			if ( $days >= 365 ) {
				$days++;
			}
		}

		$gy   += 4 * intdiv( $days, 1461 );
		$days %= 1461;

		if ( $days > 365 ) {
			$gy  += intdiv( $days - 1, 365 );
			$days = ( $days - 1 ) % 365;
		}

		$gd     = $days + 1;
		$is_leap = ( ( 0 === $gy % 4 && 0 !== $gy % 100 ) || 0 === $gy % 400 );
		$months  = [ 0, 31, $is_leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];

		$gm = 0;
		while ( $gm < 13 && $gd > $months[ $gm ] ) {
			$gd -= $months[ $gm ];
			$gm++;
		}

		return [ $gy, $gm, $gd ];
	}

	/**
	 * Whether a Jalali year is a leap year (has Esfand = 30 days).
	 */
	public static function is_leap_year( int $jy ): bool {
		// Esfand 30 exists iff 1404/12/30 style date round-trips; simplest robust
		// check: does day 30 of month 12 convert and convert back unchanged.
		$g = self::to_gregorian( $jy, 12, 30 );
		$j = self::from_gregorian( $g[0], $g[1], $g[2] );

		return $j[0] === $jy && 12 === $j[1] && 30 === $j[2];
	}

	// ── Formatting (stored ISO → Shamsi label) ──────────────────────────────

	/**
	 * @param string $iso            Stored `Y-m-d` or `Y-m-d H:i[:s]` (ASCII).
	 * @param bool   $persian_digits Convert the output digits to Persian.
	 * @param bool   $with_time      Append the (unconverted) `HH:MM` when present.
	 * @return string                e.g. `۱۴۰۴/۱۲/۳۰` — or '' when $iso is unparseable/empty.
	 */
	public static function format( string $iso, bool $persian_digits = true, bool $with_time = false ): string {
		$parts = self::split_iso( $iso );
		if ( null === $parts ) {
			return '';
		}

		[ $gy, $gm, $gd, $time ] = $parts;
		[ $jy, $jm, $jd ]        = self::from_gregorian( $gy, $gm, $gd );

		$out = sprintf( '%04d/%02d/%02d', $jy, $jm, $jd );

		if ( $with_time && '' !== $time ) {
			$out .= ' ' . $time;
		}

		return $persian_digits ? Hedayati_Text::digits_to_persian( $out ) : $out;
	}

	/**
	 * Long form: `۳۰ اسفند ۱۴۰۴` (+ ` ساعت HH:MM` when $with_time and a time is present).
	 */
	public static function format_long( string $iso, bool $persian_digits = true, bool $with_time = false ): string {
		$parts = self::split_iso( $iso );
		if ( null === $parts ) {
			return '';
		}

		[ $gy, $gm, $gd, $time ] = $parts;
		[ $jy, $jm, $jd ]        = self::from_gregorian( $gy, $gm, $gd );

		$month = self::MONTHS[ $jm ] ?? (string) $jm;
		$out   = sprintf( '%d %s %d', $jd, $month, $jy );

		if ( $with_time && '' !== $time ) {
			$out .= ' ساعت ' . $time;
		}

		return $persian_digits ? Hedayati_Text::digits_to_persian( $out ) : $out;
	}

	// ── Input (Shamsi typed by the user → canonical Gregorian) ──────────────

	/**
	 * Parse a user-entered Shamsi date. Accepts Persian/Arabic or ASCII digits and
	 * `/`, `-` or `.` separators. Verifies the Jalali day is real by round-tripping.
	 *
	 * @param string $value
	 * @return string|null Canonical Gregorian `Y-m-d`, or null when empty/invalid.
	 */
	public static function parse_input( string $value ): ?string {
		$value = trim( Hedayati_Text::digits_to_ascii( $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{3,4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $value, $m ) ) {
			return null;
		}

		$jy = (int) $m[1];
		$jm = (int) $m[2];
		$jd = (int) $m[3];

		// Sane Jalali year window (~1821–2321 AD). This also stops a mistyped
		// Gregorian date like `2026-02-31` from being silently reinterpreted as
		// Jalali year 2026 when it is used as a fallback after ISO parsing fails.
		if ( $jy < 1200 || $jy > 1700 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 ) {
			return null;
		}

		[ $gy, $gm, $gd ] = self::to_gregorian( $jy, $jm, $jd );

		// Round-trip guard: rejects e.g. 1404/12/30 in a non-leap year, or 31 Mehr.
		$back = self::from_gregorian( $gy, $gm, $gd );
		if ( $back[0] !== $jy || $back[1] !== $jm || $back[2] !== $jd ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $gy, $gm, $gd );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	/**
	 * @return array{0:int,1:int,2:int,3:string}|null [gy, gm, gd, "HH:MM" or ""]
	 */
	private static function split_iso( string $iso ): ?array {
		$iso = trim( $iso );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2})?)?$/', $iso, $m ) ) {
			return null;
		}

		$gy = (int) $m[1];
		$gm = (int) $m[2];
		$gd = (int) $m[3];

		if ( ! checkdate( $gm, $gd, $gy ) ) {
			return null;
		}

		$time = ( isset( $m[4], $m[5] ) ) ? sprintf( '%02d:%02d', (int) $m[4], (int) $m[5] ) : '';

		return [ $gy, $gm, $gd, $time ];
	}
}
