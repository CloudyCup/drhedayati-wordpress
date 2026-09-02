<?php
/**
 * Shamsi/Jalali unit suite (PHP CLI, no WordPress boot).
 *
 * The multi-decade round-trip fuzz lives in verify-jalali.js (same algorithm);
 * this file confirms the PHP class loads and its public API behaves — including
 * the format / parse contract that the JS port does not exercise directly.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../' );
}

require_once __DIR__ . '/../includes/class-text.php';
require_once __DIR__ . '/../includes/class-jalali.php';

$passed = 0;
$failed = 0;
function check( string $desc, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { echo "  [PASS] {$desc}\n"; $passed++; }
	else { echo "  [FAIL] {$desc}\n"; $failed++; }
}

echo "=== SHAMSI / JALALI UNIT SUITE ===\n\n";

echo "1. Fixed conversions:\n";
$vectors = [
	[ [ 2024, 3, 20 ], [ 1403, 1, 1 ] ],
	[ [ 2025, 3, 21 ], [ 1404, 1, 1 ] ],
	[ [ 2021, 3, 21 ], [ 1400, 1, 1 ] ],
	[ [ 1979, 2, 11 ], [ 1357, 11, 22 ] ],
	[ [ 2000, 1, 1 ], [ 1378, 10, 11 ] ],
];
foreach ( $vectors as [ $g, $j ] ) {
	check( "G {$g[0]}-{$g[1]}-{$g[2]} -> J {$j[0]}/{$j[1]}/{$j[2]}", Hedayati_Jalali::from_gregorian( $g[0], $g[1], $g[2] ) === $j );
	check( "J {$j[0]}/{$j[1]}/{$j[2]} -> G {$g[0]}-{$g[1]}-{$g[2]}", Hedayati_Jalali::to_gregorian( $j[0], $j[1], $j[2] ) === $g );
}

echo "\n2. Round-trip fuzz (every day 2005-01-01 .. 2035-12-31):\n";
$bad  = 0;
$ts   = gmmktime( 12, 0, 0, 1, 1, 2005 );
$end  = gmmktime( 12, 0, 0, 12, 31, 2035 );
$days = 0;
for ( ; $ts <= $end; $ts += 86400 ) {
	$gy = (int) gmdate( 'Y', $ts );
	$gm = (int) gmdate( 'n', $ts );
	$gd = (int) gmdate( 'j', $ts );
	[ $jy, $jm, $jd ] = Hedayati_Jalali::from_gregorian( $gy, $gm, $gd );
	[ $ry, $rm, $rd ] = Hedayati_Jalali::to_gregorian( $jy, $jm, $jd );
	if ( $ry !== $gy || $rm !== $gm || $rd !== $gd ) {
		$bad++;
	}
	$days++;
}
check( "G->J->G identity for all {$days} days", 0 === $bad );

echo "\n3. Leap year (Esfand 30):\n";
check( '1403 is a Jalali leap year', Hedayati_Jalali::is_leap_year( 1403 ) );
check( '1404 is NOT a Jalali leap year', ! Hedayati_Jalali::is_leap_year( 1404 ) );

echo "\n4. format() / format_long() — storage stays Gregorian, time copied verbatim:\n";
check( "format('2025-03-21') = '۱۴۰۴/۰۱/۰۱'", Hedayati_Jalali::format( '2025-03-21' ) === '۱۴۰۴/۰۱/۰۱' );
check( "format ASCII digits", Hedayati_Jalali::format( '2025-03-21', false ) === '1404/01/01' );
check( "format with time keeps the wall-clock time unchanged", Hedayati_Jalali::format( '2025-03-21 09:30:00', false, true ) === '1404/01/01 09:30' );
check( "format ignores time when \$with_time = false", Hedayati_Jalali::format( '2025-03-21 09:30:00', false ) === '1404/01/01' );
check( "format_long('2025-03-21') mentions فروردین", str_contains( Hedayati_Jalali::format_long( '2025-03-21', false ), 'فروردین' ) );
check( "format('') = '' (empty passthrough)", Hedayati_Jalali::format( '' ) === '' );
check( "format('2026-02-31') = '' (not a real day)", Hedayati_Jalali::format( '2026-02-31' ) === '' );
check( "format('garbage') = ''", Hedayati_Jalali::format( 'garbage' ) === '' );

echo "\n5. parse_input() — Shamsi typed by the user -> canonical Gregorian Y-m-d:\n";
check( "'1404/01/01' -> '2025-03-21'", Hedayati_Jalali::parse_input( '1404/01/01' ) === '2025-03-21' );
check( "Persian digits '۱۴۰۴/۰۱/۰۱' -> '2025-03-21'", Hedayati_Jalali::parse_input( '۱۴۰۴/۰۱/۰۱' ) === '2025-03-21' );
check( "dash separator '1403-1-1' -> '2024-03-20'", Hedayati_Jalali::parse_input( '1403-1-1' ) === '2024-03-20' );
check( "dot separator '1403.01.01'", Hedayati_Jalali::parse_input( '1403.01.01' ) === '2024-03-20' );
check( "'1404/12/30' rejected (1404 not leap)", null === Hedayati_Jalali::parse_input( '1404/12/30' ) );
check( "'1403/12/30' accepted (1403 leap)", null !== Hedayati_Jalali::parse_input( '1403/12/30' ) );
check( "'1403/13/01' rejected (month)", null === Hedayati_Jalali::parse_input( '1403/13/01' ) );
check( "'1403/07/31' rejected (Mehr has 30)", null === Hedayati_Jalali::parse_input( '1403/07/31' ) );
check( "'' -> null", null === Hedayati_Jalali::parse_input( '' ) );
check( "'not a date' -> null", null === Hedayati_Jalali::parse_input( 'not a date' ) );

echo "\n6. parse_input() . format() round-trips through storage:\n";
foreach ( [ '1400/01/01', '1403/12/30', '1404/07/15', '1410/09/22' ] as $shamsi ) {
	$iso = Hedayati_Jalali::parse_input( $shamsi );
	check( "{$shamsi} -> {$iso} -> back", null !== $iso && Hedayati_Jalali::format( $iso, false ) === str_pad( explode( '/', $shamsi )[0], 4, '0', STR_PAD_LEFT ) . '/' . sprintf( '%02d/%02d', (int) explode( '/', $shamsi )[1], (int) explode( '/', $shamsi )[2] ) );
}

echo "\n=========================================\n";
echo "SHAMSI/JALALI TEST RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "=========================================\n";

if ( $failed > 0 ) {
	exit( 1 );
}
