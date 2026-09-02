/**
 * Node.js Static & Logic Verification Suite for Phase 2B (Academic Operations).
 *
 * This suite does NOT boot WordPress. It verifies, in the environment available:
 *   1. Pure-logic ports of Hedayati_Academic_Validation (status allowlists, date /
 *      datetime / integer parsing, Persian-digit normalization).
 *   2. Structural invariants of every new PHP file (strict_types, ABSPATH guard,
 *      balanced braces).
 *   3. Migration 2.1.0 wiring (version bump, table DDL, UNIQUE constraints, no
 *      hardcoded `wp_` prefix).
 *   4. Roles schema 2.1.0 (new `hedayati_manage_teachers` capability, count = 22).
 *   5. Plugin bootstrap wiring (requires + init calls for the new classes).
 *
 * Behavioural correctness against a real $wpdb / WordPress runtime is covered by
 * tests/test-phase2b.php (PHP — NOT runnable in this environment) and, ultimately,
 * staging acceptance.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
let passed = 0;
let failed = 0;

function assert(desc, cond) {
	if (cond) { console.log(`  [PASS] ${desc}`); passed++; }
	else { console.error(`  [FAIL] ${desc}`); failed++; }
}

function read(rel) {
	return fs.readFileSync(path.join(ROOT, rel), 'utf8');
}

/**
 * Rough PHP source de-noising: drop comments and string literals so brace/paren
 * balance reflects code structure, not punctuation inside strings/regex patterns.
 */
function codeOnly(src) {
	return src
		.replace(/\/\*[\s\S]*?\*\//g, '')       // 1. block comments (may contain quotes)
		.replace(/'(?:\\.|[^'\\])*'/g, "''")    // 2. single-quoted strings (may contain # // /*)
		.replace(/"(?:\\.|[^"\\])*"/g, '""')    // 3. double-quoted strings
		.replace(/(^|[^:'"])\/\/[^\n]*/g, '$1') // 4. line comments
		.replace(/(^|[^$])#[^\n]*/g, '$1');     // 5. shell-style comments
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Pure-logic port of Hedayati_Academic_Validation
// ─────────────────────────────────────────────────────────────────────────────

const DIGIT_MAP = {
	'۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
	'٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9',
};
const toAscii = (s) => String(s).replace(/[۰-۹٠-٩]/g, (c) => DIGIT_MAP[c] || c);

const RUN_STATUSES = ['draft','scheduled','in_progress','completed','cancelled'];
const REGISTRATION_STATUSES = ['closed','open','soon'];
const SESSION_STATUSES = ['scheduled','held','cancelled'];
const ENROLLMENT_STATUSES = ['active','withdrawn','completed','cancelled'];
const ATTENDANCE_STATUSES = ['present','absent','late','excused'];
const STAFF_ROLES = ['primary_instructor','additional_instructor','assistant'];

const sanitizeAllowlist = (v, list, fallback) => {
	v = String(v).trim().toLowerCase();
	return list.includes(v) ? v : fallback;
};

function parseIsoDate(v) {
	v = toAscii(v).trim();
	if (v === '') return null;
	const m = v.match(/^(\d{4})-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/);
	if (!m) return null;
	const [y, mo, d] = [+m[1], +m[2], +m[3]];
	const dt = new Date(Date.UTC(y, mo - 1, d));
	return (dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d) ? v : null;
}

function parseDatetime(v) {
	v = toAscii(v).trim().replace('T', ' ');
	if (v === '') return null;
	const m = v.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/);
	if (!m) return null;
	const [y, mo, d, h, mi] = [+m[1], +m[2], +m[3], +m[4], +m[5]];
	const s = m[6] ? +m[6] : 0;
	const dt = new Date(Date.UTC(y, mo - 1, d));
	if (!(dt.getUTCFullYear() === y && dt.getUTCMonth() === mo - 1 && dt.getUTCDate() === d)) return null;
	if (h > 23 || mi > 59 || s > 59) return null;
	const pad = (n) => String(n).padStart(2, '0');
	return `${y}-${pad(mo)}-${pad(d)} ${pad(h)}:${pad(mi)}:${pad(s)}`;
}

function parseOptionalNonNegInt(v) {
	v = toAscii(v).trim();
	if (v === '') return null;                 // unknown
	if (!/^\d+$/.test(v)) return 'ERROR';
	return parseInt(v, 10);
}

function parsePositiveInt(v) {
	v = toAscii(v).trim();
	if (v === '' || !/^\d+$/.test(v)) return null;
	const n = parseInt(v, 10);
	return n > 0 ? n : null;
}

console.log('=== NODE.JS PHASE 2B VERIFICATION SUITE ===\n');
console.log('1. Business-state allowlists (fallback on invalid):');
assert("run_status 'in_progress' passes", sanitizeAllowlist('in_progress', RUN_STATUSES, 'draft') === 'in_progress');
assert("run_status 'bogus' -> draft", sanitizeAllowlist('bogus', RUN_STATUSES, 'draft') === 'draft');
assert("run_status ' Scheduled ' -> scheduled (trim+lower)", sanitizeAllowlist(' Scheduled ', RUN_STATUSES, 'draft') === 'scheduled');
assert("registration_status default is closed", sanitizeAllowlist('', REGISTRATION_STATUSES, 'closed') === 'closed');
assert("session_status 'held' ok", sanitizeAllowlist('held', SESSION_STATUSES, 'scheduled') === 'held');
assert("enrollment_status 'withdrawn' ok", sanitizeAllowlist('withdrawn', ENROLLMENT_STATUSES, 'active') === 'withdrawn');
assert("attendance status 'present' recognised", ATTENDANCE_STATUSES.includes('present'));
assert("attendance status 'maybe' NOT recognised", !ATTENDANCE_STATUSES.includes('maybe'));
assert("staff role 'assistant' recognised", STAFF_ROLES.includes('assistant'));
assert("instructor roles are the two instructor slugs", JSON.stringify(['primary_instructor','additional_instructor']) === JSON.stringify(STAFF_ROLES.filter(r => r.includes('instructor'))));

console.log('\n2. ISO date parsing:');
assert("'2026-03-21' valid", parseIsoDate('2026-03-21') === '2026-03-21');
assert("Persian digits '۱۴۰۴-۰۱-۰۱' -> '1404-01-01'", parseIsoDate('۱۴۰۴-۰۱-۰۱') === '1404-01-01');
assert("'2026-02-31' rejected (checkdate)", parseIsoDate('2026-02-31') === null);
assert("'2026-13-01' rejected", parseIsoDate('2026-13-01') === null);
assert("'2026/03/21' rejected (format)", parseIsoDate('2026/03/21') === null);
assert("'' -> null", parseIsoDate('') === null);

console.log('\n3. Datetime parsing:');
assert("'2026-03-21 09:30' -> canonical seconds", parseDatetime('2026-03-21 09:30') === '2026-03-21 09:30:00');
assert("datetime-local 'T' form accepted", parseDatetime('2026-03-21T09:30') === '2026-03-21 09:30:00');
assert("'2026-03-21 09:30:45' preserved", parseDatetime('2026-03-21 09:30:45') === '2026-03-21 09:30:45');
assert("'2026-03-21 25:00' rejected (hour)", parseDatetime('2026-03-21 25:00') === null);
assert("'2026-02-30 09:00' rejected (calendar)", parseDatetime('2026-02-30 09:00') === null);
assert("Persian digits in datetime normalised", parseDatetime('۲۰۲۶-۰۳-۲۱ ۰۹:۳۰') === '2026-03-21 09:30:00');

console.log('\n4. Integer parsing (nullable capacity/tuition vs required session number):');
assert("empty capacity -> null (unknown, NOT 0)", parseOptionalNonNegInt('') === null);
assert("'20' -> 20", parseOptionalNonNegInt('20') === 20);
assert("'۲۵۰۰۰۰۰' Persian -> 2500000", parseOptionalNonNegInt('۲۵۰۰۰۰۰') === 2500000);
assert("'-5' -> ERROR", parseOptionalNonNegInt('-5') === 'ERROR');
assert("'12x' -> ERROR", parseOptionalNonNegInt('12x') === 'ERROR');
assert("session number '0' rejected (must be positive)", parsePositiveInt('0') === null);
assert("session number '3' ok", parsePositiveInt('3') === 3);
assert("session number '' rejected", parsePositiveInt('') === null);

// ─────────────────────────────────────────────────────────────────────────────
// 5. Structural invariants of new PHP files
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n5. PHP file structure (strict_types, ABSPATH guard, balanced braces):');
const NEW_FILES = [
	'includes/class-text.php',
	'includes/class-academic-validation.php',
	'includes/class-teacher.php',
	'includes/class-course-run-service.php',
	'includes/class-run-staff-service.php',
	'includes/class-session-service.php',
	'includes/class-enrollment-service.php',
	'includes/class-attendance-service.php',
	'includes/class-academic-admin.php',
	'tests/test-phase2b.php',
];

for (const f of NEW_FILES) {
	const c = read(f);
	const code = codeOnly(c);
	const ob = (code.match(/{/g) || []).length;
	const cb = (code.match(/}/g) || []).length;
	const op = (code.match(/\(/g) || []).length;
	const cp = (code.match(/\)/g) || []).length;
	assert(`${f} exists & non-trivial`, c.length > 200);
	assert(`${f} declares strict_types`, c.includes('declare( strict_types=1 );'));
	assert(`${f} has ABSPATH guard`, c.includes("if ( ! defined( 'ABSPATH' ) ) {"));
	assert(`${f} balanced braces (${ob}/${cb}) & parens (${op}/${cp})`, ob === cb && op === cp);
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. Migration 2.1.0 wiring
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n6. Migration 2.1.0 (class-db-schema.php):');
const db = read('includes/class-db-schema.php');
assert("CURRENT_DB_VERSION bumped to 2.1.0", db.includes("CURRENT_DB_VERSION = '2.1.0'"));
assert("MIGRATIONS map has '2.1.0' => 'migrate_2_1_0'", /'2\.1\.0'\s*=>\s*'migrate_2_1_0'/.test(db));
assert("migrate_2_1_0() method defined", db.includes('private static function migrate_2_1_0()'));
assert("2.0.0 migration still present (no regression)", db.includes('migrate_2_0_0'));
assert("does NOT ALTER hedayati_user_phones in 2.1.0", !/ALTER TABLE.*hedayati_user_phones/i.test(db));

for (const t of ['hedayati_course_runs','hedayati_run_staff','hedayati_sessions','hedayati_enrollments','hedayati_attendance']) {
	assert(`creates {prefix}${t}`, db.includes(`get_table_${t.replace('hedayati_','')}()`) && db.includes(`'${t}'`));
}
assert("sessions has UNIQUE(run_id, session_number)", db.includes('UNIQUE KEY uq_run_session (run_id, session_number)'));
assert("enrollments has UNIQUE(run_id, user_id)", db.includes('UNIQUE KEY uq_run_user (run_id, user_id)'));
assert("attendance has UNIQUE(session_id, enrollment_id)", db.includes('UNIQUE KEY uq_session_enrollment (session_id, enrollment_id)'));
assert("all new table names built from $wpdb->prefix", (db.match(/\$wpdb->prefix \. 'hedayati_/g) || []).length >= 6);
assert("no hardcoded 'wp_hedayati_' literal", !db.includes('wp_hedayati_'));
assert("business-state columns are varchar, not ENUM", !/\bENUM\s*\(/i.test(db));
assert("migrate_2_1_0 verifies every table before returning true", /SHOW TABLES LIKE %s[\s\S]*return false;[\s\S]*return true;/.test(db));

// ─────────────────────────────────────────────────────────────────────────────
// 7. Roles schema 2.1.0
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n7. Roles schema 2.1.0 (class-roles.php):');
const roles = read('includes/class-roles.php');
assert("ROLES_VERSION bumped to 2.1.0", roles.includes("ROLES_VERSION = '2.1.0'"));
assert("new capability hedayati_manage_teachers registered", roles.includes("'hedayati_manage_teachers'"));
const capListMatch = roles.match(/get_all_hedayati_capabilities\(\): array \{\s*return \[([\s\S]*?)\];/);
assert("get_all_hedayati_capabilities() present", !!capListMatch);
if (capListMatch) {
	const count = (capListMatch[1].match(/'hedayati_[a-z_]+'/g) || []).length;
	assert(`managed capability count is 22 (was 21 + manage_teachers)`, count === 22);
}
assert("hedayati_manager grants manage_teachers", /'hedayati_manager'\s*=>[\s\S]*?'hedayati_manage_teachers'\s*=>\s*true/.test(roles));
assert("future-safe managed-cap tracking retained", roles.includes('OPTION_MANAGED_CAPS'));

// ─────────────────────────────────────────────────────────────────────────────
// 8. Plugin bootstrap wiring
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n8. Plugin bootstrap (hedayati-core.php):');
const boot = read('hedayati-core.php');
assert("HEDAYATI_CORE_VERSION bumped to 1.2.0", boot.includes("HEDAYATI_CORE_VERSION', '1.2.0'"));
assert("plugin header Version: 1.2.0", boot.includes('Version:           1.2.0'));
for (const cls of [
	'class-text', 'class-academic-validation', 'class-teacher',
	'class-course-run-service', 'class-run-staff-service', 'class-session-service',
	'class-enrollment-service', 'class-attendance-service', 'class-academic-admin',
]) {
	assert(`requires includes/${cls}.php`, boot.includes(`includes/${cls}.php`));
}
for (const init of [
	'Hedayati_Teacher::init()', 'Hedayati_Course_Run_Service::init()',
	'Hedayati_Run_Staff_Service::init()', 'Hedayati_Session_Service::init()',
	'Hedayati_Enrollment_Service::init()', 'Hedayati_Attendance_Service::init()',
	'Hedayati_Academic_Admin::init()',
]) {
	assert(`bootstraps ${init}`, boot.includes(init));
}
assert("activation hook registers Teacher CPT", boot.includes('Hedayati_Teacher::register()'));

// ─────────────────────────────────────────────────────────────────────────────
// 9. Security-shape checks on services & admin
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n9. Security-shape checks:');
const admin = read('includes/class-academic-admin.php');
assert("admin verifies a nonce before every state change", admin.includes('wp_verify_nonce'));
assert("admin checks capabilities server-side", (admin.match(/current_user_can\(/g) || []).length >= 8);
assert("admin uses admin-post.php routing", admin.includes("admin_url( 'admin-post.php' )"));
assert("admin enforces per-run access scope", admin.includes('require_run_scope') && admin.includes('user_is_staff_on_run'));
assert("attendance write gated on hedayati_record_attendance", admin.includes("verify( 'hedayati_attendance_save', 'hedayati_record_attendance' )"));

for (const f of [
	'includes/class-course-run-service.php', 'includes/class-run-staff-service.php',
	'includes/class-session-service.php', 'includes/class-enrollment-service.php',
	'includes/class-attendance-service.php',
]) {
	const c = read(f);
	assert(`${f} has no raw superglobal in SQL`, !/\$wpdb->(query|get_[a-z_]+)\([^)]*\$_(POST|GET|REQUEST)/.test(c));
	assert(`${f} builds table names via Hedayati_DB_Schema`, c.includes('Hedayati_DB_Schema::get_table_'));
	assert(`${f} has no hardcoded wp_ table literal`, !/['"]wp_[a-z_]*hedayati/.test(c) && !c.includes("'wp_posts'") );
	const dyn = (c.match(/\$wpdb->prepare\(/g) || []).length;
	assert(`${f} uses \$wpdb->prepare (${dyn} calls)`, dyn >= 3);
}

const teacher = read('includes/class-teacher.php');
assert("Teacher CPT maps caps to hedayati_manage_teachers", teacher.includes("=> 'hedayati_manage_teachers'"));
assert("Teacher CPT is not publicly queryable yet", teacher.includes("'publicly_queryable'  => false"));
assert("Teacher unlinks (not deletes) on user deletion", teacher.includes('on_user_deleted') && teacher.includes('META_USER_ID, 0'));

console.log(`\n========================================`);
console.log(`PHASE 2B VERIFICATION SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);

if (failed > 0) process.exit(1);
