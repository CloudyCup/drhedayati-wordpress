/**
 * Node.js verification for the Shamsi/Jalali conversion layer (class-jalali.php).
 *
 * Ports the integer algorithm and checks it against well-known fixed dates plus a
 * multi-decade round-trip fuzz (G->J->G identity for every day 2000..2040, and
 * J->G->J for every valid Jalali day 1380..1420). This is a pure-math layer —
 * no $wpdb, no WordPress — so the Node port and the PHP source share one
 * algorithm and this suite is authoritative for correctness.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..');
let passed = 0, failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const idiv = (a, b) => Math.floor(a / b);

function fromGregorian(gy, gm, gd) {
	const gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
	const gy2 = gm > 2 ? gy + 1 : gy;
	let days = 355666 + 365 * gy + idiv(gy2 + 3, 4) - idiv(gy2 + 99, 100) + idiv(gy2 + 399, 400) + gd + gdm[gm - 1];
	let jy = -1595 + 33 * idiv(days, 12053);
	days %= 12053;
	jy += 4 * idiv(days, 1461);
	days %= 1461;
	if (days > 365) { jy += idiv(days - 1, 365); days = (days - 1) % 365; }
	let jm, jd;
	if (days < 186) { jm = 1 + idiv(days, 31); jd = 1 + (days % 31); }
	else { jm = 7 + idiv(days - 186, 30); jd = 1 + ((days - 186) % 30); }
	return [jy, jm, jd];
}

function toGregorian(jy, jm, jd) {
	jy += 1595;
	let days = -355668 + 365 * jy + idiv(jy, 33) * 8 + idiv((jy % 33) + 3, 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
	let gy = 400 * idiv(days, 146097);
	days %= 146097;
	if (days > 36524) {
		days--;
		gy += 100 * idiv(days, 36524);
		days %= 36524;
		if (days >= 365) days++;
	}
	gy += 4 * idiv(days, 1461);
	days %= 1461;
	if (days > 365) { gy += idiv(days - 1, 365); days = (days - 1) % 365; }
	let gd = days + 1;
	const leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
	const months = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
	let gm = 0;
	while (gm < 13 && gd > months[gm]) { gd -= months[gm]; gm++; }
	return [gy, gm, gd];
}

console.log('=== NODE.JS SHAMSI / JALALI VERIFICATION ===\n');

console.log('1. Fixed reference dates (Gregorian -> Jalali):');
const vectors = [
	['2024-03-20', [1403, 1, 1]],   // Nowruz 1403
	['2025-03-21', [1404, 1, 1]],   // Nowruz 1404
	['2021-03-21', [1400, 1, 1]],   // Nowruz 1400
	['2016-03-20', [1395, 1, 1]],   // Nowruz 1395 (G leap year)
	['1979-02-11', [1357, 11, 22]], // 22 Bahman 1357
	['2000-01-01', [1378, 10, 11]],
	['2020-02-29', [1398, 12, 10]], // G leap day
];
for (const [iso, [jy, jm, jd]] of vectors) {
	const [y, m, d] = iso.split('-').map(Number);
	const got = fromGregorian(y, m, d);
	assert(`${iso} -> ${jy}/${jm}/${jd}`, got[0] === jy && got[1] === jm && got[2] === jd);
}

console.log('\n2. Fixed reference dates (Jalali -> Gregorian):');
for (const [iso, [jy, jm, jd]] of vectors) {
	const got = toGregorian(jy, jm, jd);
	const back = `${String(got[0]).padStart(4, '0')}-${String(got[1]).padStart(2, '0')}-${String(got[2]).padStart(2, '0')}`;
	assert(`${jy}/${jm}/${jd} -> ${iso}`, back === iso);
}

console.log('\n3. Round-trip fuzz — every Gregorian day 2000-01-01 .. 2040-12-31:');
{
	let n = 0, bad = 0;
	const dt = new Date(Date.UTC(2000, 0, 1));
	const end = Date.UTC(2040, 11, 31);
	while (dt.getTime() <= end) {
		const gy = dt.getUTCFullYear(), gm = dt.getUTCMonth() + 1, gd = dt.getUTCDate();
		const [jy, jm, jd] = fromGregorian(gy, gm, gd);
		const [ry, rm, rd] = toGregorian(jy, jm, jd);
		if (ry !== gy || rm !== gm || rd !== gd) { bad++; if (bad <= 3) console.error(`      mismatch ${gy}-${gm}-${gd} -> ${jy}/${jm}/${jd} -> ${ry}-${rm}-${rd}`); }
		n++;
		dt.setUTCDate(dt.getUTCDate() + 1);
	}
	assert(`G->J->G identity for all ${n} days`, bad === 0);
}

console.log('\n4. Round-trip fuzz — every unambiguously-valid Jalali day 1380 .. 1420:');
{
	const roundTrips = (jy, jm, jd) => {
		const [gy, gm, gd] = toGregorian(jy, jm, jd);
		const [ry, rm, rd] = fromGregorian(gy, gm, gd);
		return ry === jy && rm === jm && rd === jd;
	};
	let n = 0, bad = 0;
	for (let jy = 1380; jy <= 1420; jy++) {
		for (let jm = 1; jm <= 12; jm++) {
			const maxd = jm <= 6 ? 31 : (jm <= 11 ? 30 : 29); // Esfand: 29 always valid, 30 only in leap
			for (let jd = 1; jd <= maxd; jd++) {
				if (roundTrips(jy, jm, jd)) n++;
				else { bad++; if (bad <= 3) console.error(`      ${jy}/${jm}/${jd} did not round-trip`); }
			}
		}
	}
	assert(`J->G->J identity for all ${n} valid Jalali days`, bad === 0);

	// Esfand 30: valid only in leap years. Over 1380..1420 the leap years in this
	// cycle include 1383, 1387, 1391, 1395, 1399, 1403, 1408, 1412, 1416, 1420.
	let leapCount = 0;
	for (let jy = 1380; jy <= 1420; jy++) if (roundTrips(jy, 12, 30)) leapCount++;
	assert('Esfand-30 exists in ~1/4 of years (leap cycle)', leapCount >= 9 && leapCount <= 11);
	assert('Esfand-30 round-trips in 1403 (a known leap year)', roundTrips(1403, 12, 30));
	assert('Esfand-30 does NOT exist in 1404 (a known common year)', !roundTrips(1404, 12, 30));
}

console.log('\n5. Monotonicity — consecutive Gregorian days never decrease the Jalali ordinal:');
{
	const ord = ([y, m, d]) => y * 400 + m * 31 + d;
	let prev = null, bad = 0;
	const dt = new Date(Date.UTC(2018, 0, 1));
	const end = Date.UTC(2030, 11, 31);
	while (dt.getTime() <= end) {
		const j = fromGregorian(dt.getUTCFullYear(), dt.getUTCMonth() + 1, dt.getUTCDate());
		if (prev !== null && ord(j) < ord(prev) && !(prev[1] === 12 && j[1] === 1)) bad++;
		prev = j;
		dt.setUTCDate(dt.getUTCDate() + 1);
	}
	assert('Jalali dates advance with Gregorian dates', bad === 0);
}

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n6. class-jalali.php structure & parse/format contract:');
const src = read('includes/class-jalali.php');
assert('declares strict_types + ABSPATH guard', src.includes('declare( strict_types=1 );') && src.includes("if ( ! defined( 'ABSPATH' ) ) {"));
const b = [/{/g, /}/g, /\(/g, /\)/g].map((r) => (src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/'(?:\\.|[^'\\])*'/g, "''").replace(/"(?:\\.|[^"\\])*"/g, '""').match(r) || []).length);
assert(`balanced braces/parens (${b})`, b[0] === b[1] && b[2] === b[3]);
assert('exposes from_gregorian / to_gregorian / format / format_long / parse_input', ['from_gregorian', 'to_gregorian', 'format', 'format_long', 'parse_input'].every((m) => src.includes(`function ${m}(`)));
assert('storage stays Gregorian — parse_input returns Y-m-d, format() takes ISO', /parse_input.*: \?string/.test(src) && src.includes("sprintf( '%04d-%02d-%02d', \$gy, \$gm, \$gd )"));
assert('time part is copied verbatim, never converted', src.includes('time is a wall-clock time') || src.includes('copied verbatim') || src.includes('$out .= \' \' . $time'));
assert('Persian-digit output only via Hedayati_Text::digits_to_persian', src.includes('Hedayati_Text::digits_to_persian'));
assert('parse_input round-trip guards invalid Jalali days', /Round-trip guard[\s\S]*return null;/.test(src));
assert('format() rejects an unparseable / non-calendar ISO (checkdate)', src.includes('checkdate( $gm, $gd, $gy )'));
assert('12 Persian month names', (src.match(/=> '[^']+',/g) || []).filter((s) => /[\u0600-\u06FF]/.test(s)).length >= 12);

const text = read('includes/class-text.php');
assert('Hedayati_Text::digits_to_persian added (display only)', text.includes('function digits_to_persian') && text.includes('Display only'));

const boot = read('hedayati-core.php');
assert('plugin requires class-jalali.php (before the services that format dates)', boot.indexOf('includes/class-jalali.php') > 0 && boot.indexOf('includes/class-jalali.php') < boot.indexOf('includes/class-academic-admin.php'));
assert('plugin version >= 1.5.0', /HEDAYATI_CORE_VERSION', '1\.[5-9]\.\d+'/.test(boot));

console.log('\n7. Shamsi display wired into the Phase 2B admin (additive, Gregorian retained):');
const admin = read('includes/class-academic-admin.php');
assert('date_cell() helper — Gregorian kept, Shamsi appended in parens', /function date_cell\([\s\S]*?Hedayati_Jalali::format\([\s\S]*?esc_html\( \$iso \)/.test(admin));
assert('run list / session / attendance / enrollment / audit rows use date_cell()', (admin.match(/self::date_cell\(/g) || []).length >= 5);
assert('run form date fields carry a معادل شمسی hint', admin.includes('shamsi_hint') && admin.includes('معادل شمسی'));
assert('date_cell falls back to plain Gregorian if Jalali::format returns \'\'', /date_cell[\s\S]*'' === \$shamsi[\s\S]*\$greg/.test(admin));

// ─────────────────────────────────────────────────────────────────────────────
console.log('\n8. Course Run start/end date — accepts Gregorian ISO OR Shamsi (parse_run_date):');

const DMAP = { '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9', '٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9' };
const toAscii2 = (s) => String(s).replace(/[۰-۹٠-٩]/g, (c) => DMAP[c] || c);

function jalaliParseInput(value) {
	value = toAscii2(value).trim();
	if (value === '') return null;
	const m = value.match(/^(\d{3,4})[/\-.](\d{1,2})[/\-.](\d{1,2})$/);
	if (!m) return null;
	const jy = +m[1], jm = +m[2], jd = +m[3];
	if (jy < 1200 || jy > 1700 || jm < 1 || jm > 12 || jd < 1 || jd > 31) return null;
	const [gy, gm, gd] = toGregorian(jy, jm, jd);
	const [ry, rm, rd] = fromGregorian(gy, gm, gd);
	if (ry !== jy || rm !== jm || rd !== jd) return null;
	return `${String(gy).padStart(4, '0')}-${String(gm).padStart(2, '0')}-${String(gd).padStart(2, '0')}`;
}

// mirrors Hedayati_Academic_Validation::parse_iso_date()
function parseIsoDateStrict(v) {
	v = toAscii2(v).trim();
	if (v === '') return null;
	const m = v.match(/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/);
	if (!m) return null;
	const [y, mo, d] = [+m[1], +m[2], +m[3]];
	const dt = new Date(Date.UTC(y, mo - 1, d));
	return (dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d) ? v : null;
}

// mirrors Hedayati_Course_Run_Service::parse_run_date() — ISO first, Shamsi fallback
const parseRunDate = (raw) => parseIsoDateStrict(raw) ?? jalaliParseInput(raw);

assert('Gregorian ISO accepted unchanged (existing behaviour)', parseRunDate('2026-03-21') === '2026-03-21');
assert('Gregorian ISO with Persian digits still works', parseRunDate('۲۰۲۶-۰۳-۲۱') === '2026-03-21');
assert('Shamsi with slashes accepted and converted (1404/12/29 -> 2026-03-20)', parseRunDate('1404/12/29') === '2026-03-20');
assert('Shamsi Nowruz 1405/01/01 -> 2026-03-21', parseRunDate('1405/01/01') === '2026-03-21');
assert('mistyped Gregorian 2026-02-31 is NOT reinterpreted as Jalali year 2026', parseRunDate('2026-02-31') === null);
assert('a real Gregorian year (2026-...) is never taken as Jalali', jalaliParseInput('2026/03/21') === null);
assert('Persian-digit Shamsi accepted', parseRunDate('۱۴۰۵/۰۱/۰۱') === '2026-03-21');
assert('Shamsi with dot separators accepted', parseRunDate('1403.01.01') === '2024-03-20');
assert('invalid Jalali (1404/12/30 — not a leap year) rejected', parseRunDate('1404/12/30') === null);
assert('invalid Jalali month (1404/13/01) rejected', parseRunDate('1404/13/01') === null);
assert('invalid Gregorian (2026-02-31) still rejected', parseRunDate('2026-02-31') === null);
assert('garbage rejected', parseRunDate('next tuesday') === null);
assert('leap-day Shamsi 1403/12/30 accepted (1403 is leap) -> 2025-03-20', parseRunDate('1403/12/30') === '2025-03-20');
assert('output is always canonical Gregorian Y-m-d (storage unchanged)', /^\d{4}-\d{2}-\d{2}$/.test(parseRunDate('1404/07/15')));

const runSrc2 = read('includes/class-course-run-service.php');
assert('parse_run_date(): ISO first, then Hedayati_Jalali::parse_input()', /parse_run_date[\s\S]*parse_iso_date\( \$raw \)\s*\?\?\s*Hedayati_Jalali::parse_input\( \$raw \)/.test(runSrc2));
assert('both start_date and end_date use parse_run_date()', (runSrc2.match(/self::parse_run_date\(/g) || []).length === 2);
assert('run form label says میلادی یا شمسی', admin.includes('میلادی (YYYY-MM-DD) یا شمسی'));

console.log(`\n========================================`);
console.log(`SHAMSI/JALALI VERIFICATION SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
