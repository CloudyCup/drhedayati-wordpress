/**
 * Node.js static & logic verification for the metadata-only audit log
 * (class-audit-log.php + migration 2.2.0 + wiring into the Phase 2B services).
 *
 * Runtime $wpdb behaviour is covered by tests/test-audit-log.php (PHP — NOT
 * runnable here) and, ultimately, staging acceptance
 * (docs/PHASE_2B_ACCEPTANCE.md, section J).
 */

'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.join(__dirname, '..');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), 'utf8');
const codeOnly = (s) => s
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/'(?:\\.|[^'\\])*'/g, "''")
	.replace(/"(?:\\.|[^"\\])*"/g, '""')
	.replace(/(^|[^:'"])\/\/[^\n]*/g, '$1');

console.log('=== NODE.JS AUDIT-LOG VERIFICATION ===\n');

// ─────────────────────────────────────────────────────────────────────────────
// 1. Migration 2.2.0 schema contract
// ─────────────────────────────────────────────────────────────────────────────
console.log('1. Migration 2.2.0 (class-db-schema.php):');
const db = read('includes/class-db-schema.php');
assert("CURRENT_DB_VERSION >= 2.2.0 (audit-log baseline retained)", /CURRENT_DB_VERSION = '2\.\d+\.\d+'/.test(db) && !db.includes("CURRENT_DB_VERSION = '2.0.") && !db.includes("CURRENT_DB_VERSION = '2.1."));
assert("MIGRATIONS registers '2.2.0' => 'migrate_2_2_0'", /'2\.2\.0'\s*=>\s*'migrate_2_2_0'/.test(db));
assert("migrate_2_2_0() defined", db.includes('private static function migrate_2_2_0()'));
assert("2.0.0 + 2.1.0 migrations still present (no regression)", db.includes('migrate_2_0_0') && db.includes('migrate_2_1_0'));
assert("get_table_audit_log() accessor, dynamic prefix", db.includes('$wpdb->prefix . \'hedayati_audit_log\''));

const ddl = (db.match(/CREATE TABLE \{\$table_audit_log\}[\s\S]*?\)\s*\{\$charset_collate\};/) || [''])[0];
assert("DDL block present", ddl.length > 50);
for (const col of ['id ', 'actor_id ', 'action ', 'object_type ', 'object_id ', 'note ', 'created_at ']) {
	assert(`audit_log column: ${col.trim()}`, ddl.includes(col));
}
assert("audit_log has NO ip column", !/\bip\b|ip_address|ip_addr/i.test(ddl));
assert("audit_log has NO user_agent column", !/user_agent|useragent|\bua\b/i.test(ddl));
assert("audit_log has NO updated_at (append-only signal)", !ddl.includes('updated_at'));
assert("audit_log has NO json/blob/serialized context column", !/\bjson\b|\bblob\b|\btext\b|context|payload|body|data /i.test(ddl));
assert("actor_id NOT NULL DEFAULT 0", /actor_id bigint\(20\) unsigned NOT NULL DEFAULT 0/.test(ddl));
assert("object_id NOT NULL DEFAULT 0", /object_id bigint\(20\) unsigned NOT NULL DEFAULT 0/.test(ddl));
assert("note is bounded varchar(255)", /note varchar\(255\) NOT NULL DEFAULT ''/.test(ddl));
assert("index on (object_type, object_id)", ddl.includes('KEY idx_object (object_type, object_id)'));
assert("index on actor_id / action / created_at", ddl.includes('idx_actor') && ddl.includes('idx_action') && ddl.includes('idx_created_at'));
assert("migrate_2_2_0 verifies table with SHOW TABLES LIKE before success", /migrate_2_2_0[\s\S]*SHOW TABLES LIKE %s[\s\S]*return \( \$exists === \$table_audit_log \);/.test(db));
assert("migrate_2_2_0 touches no existing table (additive)", !/ALTER TABLE[^;]*hedayati_(user_phones|course_runs|sessions|enrollments|attendance|run_staff)/i.test(ddl + db.slice(db.indexOf('migrate_2_2_0'))));

// ─────────────────────────────────────────────────────────────────────────────
// 2. class-audit-log.php — append-only API surface + safety
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n2. class-audit-log.php structure:');
const al = read('includes/class-audit-log.php');
const alCode = codeOnly(al);
assert("declares strict_types + ABSPATH guard", al.includes('declare( strict_types=1 );') && al.includes("if ( ! defined( 'ABSPATH' ) ) {"));
const b = [/{/g, /}/g, /\(/g, /\)/g].map((r) => (alCode.match(r) || []).length);
assert(`balanced braces/parens (${b})`, b[0] === b[1] && b[2] === b[3]);

assert("exposes record()", /public static function record\(/.test(al));
assert("exposes read helpers get()/query()/count()", /function get\(/.test(al) && /function query\(/.test(al) && /function count\(/.test(al));
assert("APPEND-ONLY: no update() method", !/function update\(/.test(al));
assert("APPEND-ONLY: no delete()/delete_* method", !/function delete/.test(al));
assert("record() has a re-entrancy guard", al.includes('$in_progress') && /static \$in_progress = false;/.test(al));
assert("record() does not call any Hedayati_*_Service (no recursion path)", !/Hedayati_[A-Za-z]+_Service::/.test(al));
assert("record() only $wpdb->insert (no update/delete on the table)", al.includes('$wpdb->insert(') && !/\$wpdb->(update|delete)\(/.test(al));
assert("no ip / user_agent / $_SERVER referenced at all", !/user_agent|REMOTE_ADDR|HTTP_USER_AGENT|X-Forwarded/i.test(al));
assert("actor resolved from get_current_user_id (0 when none)", al.includes('get_current_user_id'));
assert("action/object_type sanitized to [a-z0-9_.-] + length cap", al.includes("preg_replace( '/[^a-z0-9_.\\-]/'") && al.includes('mb_substr'));
assert("note sanitized (sanitize_text_field) + 255 cap", al.includes('sanitize_text_field( $note )') && al.includes('mb_substr( $note, 0, 255 )'));
assert("read gated on hedayati_view_audit_logs", al.includes("VIEW_CAPABILITY = 'hedayati_view_audit_logs'") && al.includes('current_user_can_view'));
assert("table via Hedayati_DB_Schema::get_table_audit_log()", al.includes('Hedayati_DB_Schema::get_table_audit_log()'));
assert("no literal wp_ table name", !/['"]wp_[a-z_]/.test(al));
assert("all reads use $wpdb->prepare (or param-less COUNT)", (al.match(/\$wpdb->prepare\(/g) || []).length >= 3);
assert("action/object vocabularies are filterable", al.includes("apply_filters( 'hedayati_audit_actions'") && al.includes("apply_filters( 'hedayati_audit_object_types'"));

// ─────────────────────────────────────────────────────────────────────────────
// 3. Wiring into the Phase 2B services — success paths only, cascades excluded
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n3. Service wiring (audit on success, never on failure, never in cascades):');

const services = {
	'includes/class-course-run-service.php': ['course_run.created', 'course_run.updated', 'course_run.deleted', 'course.deleted'],
	'includes/class-session-service.php': ['session.created', 'session.updated', 'session.deleted'],
	'includes/class-run-staff-service.php': ['run_staff.assigned', 'run_staff.removed', 'run_staff.purged_for_user', 'run_staff.purged_for_teacher'],
	'includes/class-enrollment-service.php': ['enrollment.created', 'enrollment.status_changed', 'enrollment.deleted', 'enrollment.purged_for_user'],
	'includes/class-attendance-service.php': ['attendance.recorded', 'attendance.updated', 'attendance.deleted'],
};

for (const [file, actions] of Object.entries(services)) {
	const src = read(file);
	for (const a of actions) {
		assert(`${path.basename(file)} records '${a}'`, src.includes(`'${a}'`));
	}
	// No audit call sits before a `return new WP_Error` on the same guard —
	// heuristic: every Hedayati_Audit_Log::record( line is preceded within 6 lines
	// by a success signal ($wpdb->insert_id / $affected / false !== / return true).
	const lines = src.split('\n');
	let ok = true;
	lines.forEach((ln, i) => {
		if (ln.includes('Hedayati_Audit_Log::record(')) {
			const ctx = lines.slice(Math.max(0, i - 8), i).join('\n');
			if (!/insert_id|\$affected|false === \$inserted|false === \$updated|!== \$affected|\$updated \)|\$deleted|count\(|\$enrollments \)|\bif \(/.test(ctx)) {
				ok = false;
			}
		}
	});
	assert(`${path.basename(file)} audit calls are on success paths`, ok);
	// audit calls appear after, not before, the WP_Error returns for the operation
	assert(`${path.basename(file)} — no audit call inside an is_wp_error early return`,
		!/is_wp_error\([\s\S]{0,120}Hedayati_Audit_Log::record/.test(src));
}

// class-audit-log itself must never be in a deletion cascade
for (const file of ['includes/class-course-run-service.php', 'includes/class-session-service.php', 'includes/class-enrollment-service.php']) {
	const src = read(file);
	assert(`${path.basename(file)} cascade SQL never targets the audit table`,
		!/get_table_audit_log|hedayati_audit_log/.test(src));
}

// teacher-user unlink on account deletion is audited
const teacher = read('includes/class-teacher.php');
assert("teacher.unlinked recorded when a linked WP account is deleted", teacher.includes("'teacher.unlinked'") && /on_user_deleted[\s\S]*META_USER_ID, 0[\s\S]*Hedayati_Audit_Log::record/.test(teacher));
assert("teacher.unlinked is in the action vocabulary", al.includes("'teacher.unlinked'"));

// course deletion cascades ALL runs, not just one page
const runSrc = read('includes/class-course-run-service.php');
assert("on_course_deleted loops until the course has no runs left (no orphan page)",
	/on_course_deleted[\s\S]*do \{[\s\S]*self::query\([\s\S]*\} while \(/.test(runSrc));

// ─────────────────────────────────────────────────────────────────────────────
// 4. Admin viewer — read-only, capability-gated
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n4. Read-only audit viewer (class-academic-admin.php):');
const admin = read('includes/class-academic-admin.php');
assert("submenu registered with hedayati_view_audit_logs cap", admin.includes('Hedayati_Audit_Log::VIEW_CAPABILITY') && admin.includes('add_submenu_page('));
assert("render_audit_log() re-checks the capability server-side", /render_audit_log\(\): void \{\s*if \( ! current_user_can\( Hedayati_Audit_Log::VIEW_CAPABILITY \)/.test(admin));
assert("viewer is GET-only (no admin-post handler, no nonce-gated writes)", !/admin_post_hedayati_audit/.test(admin));
assert("viewer performs no writes", !/Hedayati_Audit_Log::record\(/.test(admin));
assert("viewer output is escaped", /render_audit_log[\s\S]*?esc_html\([\s\S]*?esc_attr\(/.test(admin));
assert("filters validated against known vocabularies", admin.includes('in_array( $f_type, Hedayati_Audit_Log::object_types()') && admin.includes('in_array( $f_action, Hedayati_Audit_Log::actions()'));

// ─────────────────────────────────────────────────────────────────────────────
// 5. Pure-logic port — token/note sanitization
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n5. Sanitizer logic port:');
const sanitizeToken = (v, max) => v.toLowerCase().trim().replace(/[^a-z0-9_.\-]/g, '').slice(0, max);
const sanitizeNote = (n) => n.replace(/<[^>]*>/g, '').replace(/[\r\n\t]+/g, ' ').trim().slice(0, 255);
assert("token strips spaces/punctuation, lowercases", sanitizeToken('  Course Run.Created! (v2) ', 64) === 'courserun.createdv2');
assert("token keeps dots/underscores/hyphens", sanitizeToken('enrollment.status_changed-v2', 64) === 'enrollment.status_changed-v2');
assert("token length-capped", sanitizeToken('a'.repeat(200), 32).length === 32);
assert("note strips tags", !/[<>]/.test(sanitizeNote('run <script>x</script> #5')));
assert("note length-capped at 255", sanitizeNote('n'.repeat(500)).length === 255);
assert("safe enum values survive in note", sanitizeNote('active -> withdrawn') === 'active -> withdrawn');

// ─────────────────────────────────────────────────────────────────────────────
// 6. hedayati-core.php wiring
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n6. Plugin bootstrap:');
const boot = read('hedayati-core.php');
assert("requires class-audit-log.php before the services", boot.indexOf('class-audit-log.php') < boot.indexOf('class-course-run-service.php'));
assert("plugin version >= 1.4.0", /HEDAYATI_CORE_VERSION', '1\.[4-9]\.\d+'/.test(boot));

// ─────────────────────────────────────────────────────────────────────────────
// 7. Behavioural port — audit emission semantics
// ─────────────────────────────────────────────────────────────────────────────
console.log('\n7. Audit emission semantics (in-memory port):');

(function () {
	// In-memory model mirroring the PHP: record() only appends; there is no
	// update/delete; cascades delete domain rows but never audit rows.
	const store = { runs: [], sessions: [], enrollments: [], audit: [], _id: 0 };
	let reentrant = false;

	const record = (action, objectType, objectId = 0, note = '') => {
		if (reentrant) return false;
		reentrant = true;
		store.audit.push({ id: ++store._id, actor_id: 0, action, object_type: objectType, object_id: objectId, note });
		reentrant = false;
		return store._id;
	};

	// success → audit
	const runCreate = (courseId) => {
		if (!courseId) return { err: 'invalid_course' };          // FAIL path: no audit
		const id = store.runs.push({ id: store.runs.length + 1, course_id: courseId }) && store.runs.length;
		record('course_run.created', 'course_run', id, 'course #' + courseId); // only after success
		return { id };
	};
	const enroll = (runId, userId) => {
		if (!store.runs.find((r) => r.id === runId)) return { err: 'invalid_run' };
		if (store.enrollments.find((e) => e.run_id === runId && e.user_id === userId)) return { err: 'already_enrolled' };
		const id = store.enrollments.push({ id: store.enrollments.length + 1, run_id: runId, user_id: userId }) && store.enrollments.length;
		record('enrollment.created', 'enrollment', id, 'run #' + runId);
		return { id };
	};
	const deleteRunCascade = (runId) => {
		const before = store.audit.length;
		store.sessions = store.sessions.filter((s) => s.run_id !== runId);
		store.enrollments = store.enrollments.filter((e) => e.run_id !== runId);
		const existed = store.runs.some((r) => r.id === runId);
		store.runs = store.runs.filter((r) => r.id !== runId);
		if (existed) record('course_run.deleted', 'course_run', runId, 'cascade');
		return { auditBefore: before };
	};

	const r1 = runCreate(7);
	assert("successful run create emits exactly one audit event", store.audit.length === 1 && store.audit[0].action === 'course_run.created');

	const rBad = runCreate(0);
	assert("failed run create emits NO audit event", !!rBad.err && store.audit.length === 1);

	enroll(r1.id, 100);
	enroll(r1.id, 101);
	const dupe = enroll(r1.id, 100);
	assert("failed (duplicate) enroll emits no audit event", !!dupe.err && store.audit.filter((a) => a.action === 'enrollment.created').length === 2);

	const auditCountBeforeDelete = store.audit.length; // 3: run.created + 2x enrollment.created
	deleteRunCascade(r1.id);
	assert("cascade delete removes domain rows", store.runs.length === 0 && store.enrollments.length === 0);
	assert("cascade delete does NOT remove historical audit rows", store.audit.length === auditCountBeforeDelete + 1);
	assert("the pre-delete enrollment.created entries still exist", store.audit.filter((a) => a.action === 'enrollment.created').length === 2);
	assert("append-only: audit ids are strictly increasing, none reused", store.audit.every((a, i) => i === 0 || a.id > store.audit[i - 1].id));

	// re-entrancy: a record() call from inside record() is refused
	reentrant = true;
	const nested = record('x.y', 'x');
	reentrant = false;
	assert("re-entrant record() is refused", nested === false);
})();

console.log(`\n========================================`);
console.log(`AUDIT-LOG VERIFICATION SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
