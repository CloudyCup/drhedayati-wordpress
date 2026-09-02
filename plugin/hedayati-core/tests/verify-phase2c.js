/**
 * Node.js static & logic verification for the Phase 2C *foundation* slice
 * (student profile fields only — see class-student-profile.php).
 *
 * The rest of Phase 2C (verification state machine, private documents, audit log,
 * national-ID encryption) is intentionally NOT implemented — it is blocked on
 * institute policy decisions recorded in docs/OPEN_QUESTIONS.md.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');

// ── Pure-logic port of the postal-code rules ─────────────────────────────────

const DIGIT_MAP = {
	'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
	'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9',
};
const toAscii = (s) => String(s).replace(/[۰-۹٠-٩]/g, (c) => DIGIT_MAP[c] || c);
const sanitizePostal = (v) => toAscii(v).replace(/\D/g, '');
const postalValid = (v) => v === '' || /^\d{10}$/.test(v);

console.log('=== NODE.JS PHASE 2C (FOUNDATION) VERIFICATION ===\n');

console.log('1. Postal-code sanitize + validate:');
assert("Persian digits normalize", sanitizePostal('۵۱۶۶۶۱۳۷۶۹') === '5166613769');
assert("separators / letters stripped", sanitizePostal('51666-1376 9x') === '5166613769');
assert("empty stays empty (optional field)", sanitizePostal('') === '' && postalValid(''));
assert("exactly 10 digits is valid", postalValid('5166613769'));
assert("9 digits rejected", !postalValid('516661376'));
assert("11 digits rejected", !postalValid('51666137690'));
assert("normalized-then-checked Persian code is valid", postalValid(sanitizePostal('۵۱۶۶۶۱۳۷۶۹')));

// ── Structure / wiring ──────────────────────────────────────────────────────

console.log('\n2. class-student-profile.php structure & wiring:');
const sp = read('includes/class-student-profile.php');
const boot = read('hedayati-core.php');

assert("declares strict_types", sp.includes('declare( strict_types=1 );'));
assert("has ABSPATH guard", sp.includes("if ( ! defined( 'ABSPATH' ) ) {"));
const ob = (sp.match(/{/g) || []).length, cb = (sp.match(/}/g) || []).length;
assert(`braces balanced (${ob}/${cb})`, ob === cb);

assert("stores address in usermeta (not a custom table, per ROADMAP P1.2)", sp.includes("register_meta( 'user'"));
const spCode = sp
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/(^|[^:])\/\/[^\n]*/g, '$1');
assert("no national-ID meta registered (blocked on D15 key)", !/national|melli|کد ملی/i.test(spCode));
assert("no verification-state meta registered (blocked on reset rules)", !/verification|is_verified/i.test(spCode));
assert("postal code normalized via Hedayati_Text", sp.includes('Hedayati_Text::digits_to_ascii'));
assert("self-edit gated on hedayati_edit_own_profile", sp.includes("current_user_can( 'hedayati_edit_own_profile' )"));
assert("other-user access gated on hedayati_view_student_profiles_basic + edit_user", sp.includes("hedayati_view_student_profiles_basic") && sp.includes("current_user_can( 'edit_user', $target_user_id )"));
assert("validation blocks save via user_profile_update_errors", sp.includes("'user_profile_update_errors'") && sp.includes('$errors->add('));
assert("save re-checks the Hedayati capability", /function save\([\s\S]*?current_user_can_edit\(/.test(sp));
assert("save only accepts string POST values", sp.includes("is_string( $_POST[ $field['meta'] ] )"));
assert("output is escaped (esc_attr/esc_html/esc_textarea)", sp.includes('esc_textarea(') && sp.includes('esc_html__('));
assert("extensible via hedayati_student_profile_fields filter", sp.includes("apply_filters( 'hedayati_student_profile_fields'"));
assert("exposes a read API Hedayati_Student_Profile::get()", /public static function get\( int \$user_id \): array/.test(sp));

assert("plugin requires the class", boot.includes('includes/class-student-profile.php'));
assert("plugin boots Hedayati_Student_Profile::init()", boot.includes('Hedayati_Student_Profile::init()'));
assert("plugin version bumped to 1.3.0", boot.includes("HEDAYATI_CORE_VERSION', '1.3.0'"));

console.log(`\n========================================`);
console.log(`PHASE 2C (FOUNDATION) SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
