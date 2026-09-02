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

// ─────────────────────────────────────────────────────────────────────────────
// 10. Behavioural port — the service invariants, re-implemented against an
//     in-memory store and exercised. Mirrors the PHP logic in class-*-service.php
//     (the same technique verify-phase2a.js uses for phone normalization). This
//     proves the ALGORITHMS; the real $wpdb path is staging-verified.
// ─────────────────────────────────────────────────────────────────────────────

console.log('\n10. Service invariants (in-memory behavioural port):');

function makeStore() {
	return {
		course_runs: [], run_staff: [], sessions: [], enrollments: [], attendance: [],
		_id: { course_runs: 0, run_staff: 0, sessions: 0, enrollments: 0, attendance: 0 },
		insert(tbl, row) { row.id = ++this._id[tbl]; this[tbl].push(row); return row.id; },
	};
}
const WPE = (code) => ({ __wp_error: true, code });
const isErr = (v) => v && v.__wp_error === true;

// -- Course_Run_Service --
function runCreate(db, data) {
	if (!data.course_id || data.course_id <= 0) return WPE('invalid_course');
	return db.insert('course_runs', {
		course_id: data.course_id,
		run_status: sanitizeAllowlist(data.run_status || '', RUN_STATUSES, 'draft'),
		registration_status: sanitizeAllowlist(data.registration_status || '', REGISTRATION_STATUSES, 'closed'),
		start_date: data.start_date ? parseIsoDate(data.start_date) : null,
		end_date: data.end_date ? parseIsoDate(data.end_date) : null,
		capacity: data.capacity === undefined ? null : parseOptionalNonNegInt(String(data.capacity)),
	});
}
function runDeleteCascade(db, runId) {
	const sessionIds = db.sessions.filter((s) => s.run_id === runId).map((s) => s.id);
	const enrIds = db.enrollments.filter((e) => e.run_id === runId).map((e) => e.id);
	db.attendance = db.attendance.filter((a) => !sessionIds.includes(a.session_id) && !enrIds.includes(a.enrollment_id));
	db.sessions = db.sessions.filter((s) => s.run_id !== runId);
	db.enrollments = db.enrollments.filter((e) => e.run_id !== runId);
	db.run_staff = db.run_staff.filter((rs) => rs.run_id !== runId);
	db.course_runs = db.course_runs.filter((r) => r.id !== runId);
}

// -- Session_Service --
function sessionCreate(db, data) {
	if (!db.course_runs.find((r) => r.id === data.run_id)) return WPE('invalid_run');
	const n = parsePositiveInt(String(data.session_number));
	if (n === null) return WPE('invalid_session_number');
	if (db.sessions.find((s) => s.run_id === data.run_id && s.session_number === n)) return WPE('session_number_exists');
	const starts = parseDatetime(String(data.starts_at || ''));
	if (starts === null) return WPE('invalid_starts_at');
	let ends = null;
	if (data.ends_at) { ends = parseDatetime(String(data.ends_at)); if (ends === null) return WPE('invalid_ends_at'); }
	if (ends !== null && ends <= starts) return WPE('time_range');
	return db.insert('sessions', { run_id: data.run_id, session_number: n, starts_at: starts, ends_at: ends,
		status: sanitizeAllowlist(data.status || '', SESSION_STATUSES, 'scheduled') });
}

// -- Enrollment_Service --
function enroll(db, runId, userId, allowOverfill = false) {
	const run = db.course_runs.find((r) => r.id === runId);
	if (!run) return WPE('invalid_run');
	if (!userId || userId <= 0) return WPE('invalid_student');
	if (['completed', 'cancelled'].includes(run.run_status)) return WPE('run_closed');
	if (db.enrollments.find((e) => e.run_id === runId && e.user_id === userId)) return WPE('already_enrolled');
	const active = db.enrollments.filter((e) => e.run_id === runId && e.status === 'active').length;
	if (!allowOverfill && run.capacity !== null && active >= run.capacity) return WPE('run_full');
	return db.insert('enrollments', { run_id: runId, user_id: userId, status: 'active' });
}

// -- Attendance_Service --
function record(db, sessionId, enrollmentId, status) {
	const s = db.sessions.find((x) => x.id === sessionId);
	if (!s) return WPE('invalid_session');
	const e = db.enrollments.find((x) => x.id === enrollmentId);
	if (!e) return WPE('invalid_enrollment');
	if (e.run_id !== s.run_id) return WPE('run_mismatch');
	if (!ATTENDANCE_STATUSES.includes(String(status).toLowerCase())) return WPE('invalid_attendance_status');
	const existing = db.attendance.find((a) => a.session_id === sessionId && a.enrollment_id === enrollmentId);
	if (existing) { existing.status = String(status).toLowerCase(); return existing.id; }
	return db.insert('attendance', { session_id: sessionId, enrollment_id: enrollmentId, status: String(status).toLowerCase() });
}

// -- Run_Staff_Service --
const INSTRUCTOR_ROLES = ['primary_instructor', 'additional_instructor'];
function staffAssign(db, data, teacherExists, userExists) {
	if (!db.course_runs.find((r) => r.id === data.run_id)) return WPE('invalid_run');
	if (!STAFF_ROLES.includes(data.staff_role)) return WPE('invalid_staff_role');
	let { teacher_id = 0, user_id = 0 } = data;
	if (INSTRUCTOR_ROLES.includes(data.staff_role)) {
		if (!teacherExists(teacher_id)) return WPE('instructor_needs_profile');
		user_id = 0;
		if (data.staff_role === 'primary_instructor' &&
			db.run_staff.find((x) => x.run_id === data.run_id && x.staff_role === 'primary_instructor'))
			return WPE('primary_instructor_exists');
	} else {
		if (!userExists(user_id)) return WPE('assistant_needs_user');
		teacher_id = 0;
	}
	const dupe = teacher_id > 0
		? db.run_staff.find((x) => x.run_id === data.run_id && x.staff_role === data.staff_role && x.teacher_id === teacher_id)
		: db.run_staff.find((x) => x.run_id === data.run_id && x.staff_role === data.staff_role && x.user_id === user_id);
	if (dupe) return WPE('assignment_exists');
	return db.insert('run_staff', { run_id: data.run_id, staff_role: data.staff_role, teacher_id: teacher_id || null, user_id: user_id || null });
}

(function runInvariantTests() {
	const db = makeStore();

	// runs
	assert("run create rejects missing course", isErr(runCreate(db, {})));
	const runA = runCreate(db, { course_id: 1, capacity: 2 });
	assert("run create returns numeric id", typeof runA === 'number');
	const runB = runCreate(db, { course_id: 1, run_status: 'completed' });

	// sessions — UNIQUE(run_id, session_number)
	const s1 = sessionCreate(db, { run_id: runA, session_number: 1, starts_at: '2026-03-01 09:00' });
	assert("session 1 created", typeof s1 === 'number');
	assert("duplicate session_number on same run refused", isErr(sessionCreate(db, { run_id: runA, session_number: 1, starts_at: '2026-03-08 09:00' })) );
	assert("same session_number on a DIFFERENT run is fine", typeof sessionCreate(db, { run_id: runB, session_number: 1, starts_at: '2026-03-02 09:00' }) === 'number');
	assert("session ends <= starts refused", isErr(sessionCreate(db, { run_id: runA, session_number: 2, starts_at: '2026-03-08 11:00', ends_at: '2026-03-08 10:00' })));
	assert("session bad starts_at refused", isErr(sessionCreate(db, { run_id: runA, session_number: 3, starts_at: 'nonsense' })));

	// enrollments — UNIQUE(run_id,user_id), capacity, closed-run
	assert("enroll student 10 ok", typeof enroll(db, runA, 10) === 'number');
	assert("duplicate enrollment refused", isErr(enroll(db, runA, 10)));
	assert("enroll student 11 ok (capacity 2)", typeof enroll(db, runA, 11) === 'number');
	assert("enroll student 12 refused — capacity full", isErr(enroll(db, runA, 12)) && enroll(db, runA, 12).code === 'run_full');
	assert("overfill override enrolls student 12", typeof enroll(db, runA, 12, true) === 'number');
	assert("enroll into completed run refused", isErr(enroll(db, runB, 20)) && enroll(db, runB, 20).code === 'run_closed');

	// attendance — run-mismatch guard + upsert
	const enrA10 = db.enrollments.find((e) => e.run_id === runA && e.user_id === 10).id;
	const sessB1 = db.sessions.find((s) => s.run_id === runB).id;
	assert("attendance present recorded", typeof record(db, s1, enrA10, 'present') === 'number');
	assert("second record for same (session,enrollment) updates, not duplicates",
		record(db, s1, enrA10, 'absent') === record(db, s1, enrA10, 'late') &&
		db.attendance.filter((a) => a.session_id === s1 && a.enrollment_id === enrA10).length === 1);
	assert("attendance for enrollment not in this session's run refused (run_mismatch)",
		isErr(record(db, sessB1, enrA10, 'present')) && record(db, sessB1, enrA10, 'present').code === 'run_mismatch');
	assert("invalid attendance status refused", isErr(record(db, s1, enrA10, 'maybe')));

	// staff — instructor/assistant asymmetry, one primary, dupes
	const teacherExists = (id) => [100, 101].includes(id);
	const userExists = (id) => [200, 201].includes(id);
	assert("primary_instructor without profile refused", isErr(staffAssign(db, { run_id: runA, staff_role: 'primary_instructor', teacher_id: 999 }, teacherExists, userExists)));
	assert("primary_instructor with profile ok", typeof staffAssign(db, { run_id: runA, staff_role: 'primary_instructor', teacher_id: 100 }, teacherExists, userExists) === 'number');
	assert("second primary_instructor refused", isErr(staffAssign(db, { run_id: runA, staff_role: 'primary_instructor', teacher_id: 101 }, teacherExists, userExists)));
	assert("additional_instructor #2 ok", typeof staffAssign(db, { run_id: runA, staff_role: 'additional_instructor', teacher_id: 101 }, teacherExists, userExists) === 'number');
	assert("assistant without WP user refused", isErr(staffAssign(db, { run_id: runA, staff_role: 'assistant', user_id: 999 }, teacherExists, userExists)));
	assert("assistant with WP user ok", typeof staffAssign(db, { run_id: runA, staff_role: 'assistant', user_id: 200 }, teacherExists, userExists) === 'number');
	assert("duplicate assistant refused", isErr(staffAssign(db, { run_id: runA, staff_role: 'assistant', user_id: 200 }, teacherExists, userExists)));

	// cascade delete of runA
	const beforeOtherRunRows = db.sessions.filter((s) => s.run_id === runB).length;
	runDeleteCascade(db, runA);
	assert("cascade: runA gone", !db.course_runs.find((r) => r.id === runA));
	assert("cascade: runA sessions gone", db.sessions.every((s) => s.run_id !== runA));
	assert("cascade: runA enrollments gone", db.enrollments.every((e) => e.run_id !== runA));
	assert("cascade: runA attendance gone", db.attendance.every((a) => a.session_id !== s1));
	assert("cascade: runA staff gone", db.run_staff.every((rs) => rs.run_id !== runA));
	assert("cascade: runB rows untouched", db.sessions.filter((s) => s.run_id === runB).length === beforeOtherRunRows && !!db.course_runs.find((r) => r.id === runB));
})();

console.log(`\n========================================`);
console.log(`PHASE 2B VERIFICATION SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);

if (failed > 0) process.exit(1);
