/**
 * Node.js static & logic verification for the AI-Studio-parity modules
 * (owner decisions D46–D52): consultations, progress, materials, support,
 * notifications, certificates, in-panel settings.
 *
 * Static/structural only. Real WordPress-runtime behaviour is verified by
 * docker/wp-tests/test-ai-studio.php.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.join(__dirname, '..');
const THEME_ROOT = path.join(__dirname, '..', '..', '..', 'theme', 'hedayati');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const P = (rel) => fs.readFileSync(path.join(PLUGIN_ROOT, rel), 'utf8');
const T = (rel) => fs.readFileSync(path.join(THEME_ROOT, rel), 'utf8');
const balanced = (src) => (src.match(/{/g) || []).length === (src.match(/}/g) || []).length
	&& (src.match(/\(/g) || []).length === (src.match(/\)/g) || []).length;

console.log('=== NODE.JS AI-STUDIO-MODULES VERIFICATION ===\n');

// ── 0. every new file is well-formed + guarded ─────────────────────────────
console.log('0. file hygiene:');
const NEW_FILES = [
	'includes/class-consultation-service.php',
	'includes/class-progress-service.php',
	'includes/class-material-storage.php',
	'includes/class-material-service.php',
	'includes/class-support-service.php',
	'includes/class-notification-service.php',
	'includes/class-certificate-service.php',
	'includes/class-panel-settings.php',
];
for (const rel of NEW_FILES) {
	const src = P(rel);
	assert(`${rel}: strict_types + ABSPATH guard`, src.includes('declare( strict_types=1 );') && src.includes("if ( ! defined( 'ABSPATH' ) ) {"));
	assert(`${rel}: braces + parens balanced`, balanced(src));
	assert(`${rel}: no raw interpolated table name without prefix helper`, !/\bwp_hedayati_/.test(src));
}

// ── 1. migration 2.4.0 ────────────────────────────────────────────────────
console.log('\n1. migration 2.4.0 (class-db-schema.php):');
const db = P('includes/class-db-schema.php');
assert("CURRENT_DB_VERSION bumped to 2.4.0", db.includes("CURRENT_DB_VERSION = '2.4.0'"));
assert("MIGRATIONS registers '2.4.0' => 'migrate_2_4_0'", /'2\.4\.0'\s*=>\s*'migrate_2_4_0'/.test(db));
assert('migrate_2_4_0() defined', db.includes('private static function migrate_2_4_0()'));
for (const t of ['consultations', 'certificates', 'session_materials', 'support_tickets', 'support_messages', 'notifications']) {
	assert(`table accessor get_table_${t}() exists and is dynamic-prefixed`, new RegExp(`function get_table_${t}\\(\\): string`).test(db) && new RegExp(`\\$wpdb->prefix \\. 'hedayati_${t.replace('session_materials','session_materials')}'`).test(db));
}
assert('migrate_2_4_0 verifies all six tables before returning true', /migrate_2_4_0[\s\S]*?SHOW TABLES LIKE %s[\s\S]*?return false;[\s\S]*?return true;/.test(db));
assert('certificates table enforces one-per-enrollment (duplicate prevention)', /CREATE TABLE \{\$table_certificates\}[\s\S]*?UNIQUE KEY uq_enrollment \(enrollment_id\)/.test(db));
assert('certificates table enforces a unique public code', /CREATE TABLE \{\$table_certificates\}[\s\S]*?UNIQUE KEY uq_code \(code\)/.test(db));
assert('business-state columns are varchar, never ENUM', !/\bENUM\s*\(/i.test(db));

// ── 2. roles / capabilities ───────────────────────────────────────────────
console.log('\n2. roles + capabilities (class-roles.php):');
const roles = P('includes/class-roles.php');
assert('ROLES_VERSION advanced to 2.4.0', roles.includes("ROLES_VERSION = '2.4.0'"));
for (const cap of ['hedayati_manage_consultations', 'hedayati_manage_certificates', 'hedayati_manage_session_materials', 'hedayati_manage_support_tickets', 'hedayati_use_support_tickets', 'hedayati_view_own_certificates']) {
	assert(`${cap} is a managed capability`, roles.includes(`'${cap}'`));
}
const roleBlock = (slug) => {
	const m = roles.match(new RegExp(`'${slug}'\\s*=>\\s*\\[[\\s\\S]*?'capabilities'\\s*=>\\s*\\[([\\s\\S]*?)\\n\\t*\\],`));
	return m ? m[1] : '';
};
assert('reception gets consultations + support, NOT certificates', roleBlock('reception').includes('hedayati_manage_consultations') && roleBlock('reception').includes('hedayati_manage_support_tickets') && !roleBlock('reception').includes('hedayati_manage_certificates'));
assert('manager gets all four staff module caps', ['hedayati_manage_consultations', 'hedayati_manage_certificates', 'hedayati_manage_session_materials', 'hedayati_manage_support_tickets'].every((c) => roleBlock('hedayati_manager').includes(c)));
assert('teacher gets material management, NOT certificates/consultations', roleBlock('teacher').includes('hedayati_manage_session_materials') && !roleBlock('teacher').includes('hedayati_manage_certificates') && !roleBlock('teacher').includes('hedayati_manage_consultations'));
assert('student gets own-certificate + support caps only', roleBlock('student').includes('hedayati_view_own_certificates') && roleBlock('student').includes('hedayati_use_support_tickets') && !roleBlock('student').includes('hedayati_manage_'));

// ── 3. consultations ─────────────────────────────────────────────────────
console.log('\n3. consultations (class-consultation-service.php):');
const consult = P('includes/class-consultation-service.php');
assert('public submit is a nopriv + priv admin-post action', consult.includes("admin_post_nopriv_' . self::SUBMIT_ACTION") && consult.includes("admin_post_' . self::SUBMIT_ACTION"));
assert('public submit verifies a nonce + honeypot + IP rate limit', consult.includes('wp_verify_nonce( $nonce, self::NONCE_ACTION )') && consult.includes("hd_website") && consult.includes('is_rate_limited()'));
assert('phone is normalised to E.164 via Hedayati_Phone::normalize', consult.includes('Hedayati_Phone::normalize'));
assert('status vocabulary is new/contacted/closed', /STATUSES = \[ 'new', 'contacted', 'closed' \]/.test(consult));
assert('staff status/note handlers go through Hedayati_Staff_Portal::guard_action', (consult.match(/Hedayati_Staff_Portal::guard_action\(/g) || []).length >= 2);
assert('audit note never contains the message body (only status transition / "internal note edited")', consult.includes("'internal note edited'") && !/record\([^)]*\$message/.test(consult));
assert('registers a capability-gated panel module view', consult.includes("add_filter( 'hedayati_panel_module_views'") && consult.includes("'capability' => self::CAPABILITY"));

// ── 4. progress ──────────────────────────────────────────────────────────
console.log('\n4. progress (class-progress-service.php):');
const prog = P('includes/class-progress-service.php');
assert('run progress = held ÷ total (non-cancelled) sessions', prog.includes('$held / $total') && prog.includes("'cancelled' !== $s['status']"));
assert('attendance rate = present ÷ recorded, kept separate from run progress', prog.includes('$present / $recorded') && prog.includes('attendance_summary'));
assert('zero-session runs return null ratio (never 0%)', /0 === \$total[\s\S]*?'ratio' => null/.test(prog));
const progCode = prog.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '');
assert('no invented grade/score/exam/completion field in code (docblock disclaimer aside)', !/\b(grade|score|exam|gpa|pass_fail|completion_status)\b/i.test(progCode));
assert('percent() returns null when ratio is null', /function percent\( \?float \$ratio \)[\s\S]*?null === \$ratio \? null/.test(prog));

// ── 5. materials ─────────────────────────────────────────────────────────
console.log('\n5. materials (class-material-service.php / class-material-storage.php):');
const mat = P('includes/class-material-service.php');
const matStore = P('includes/class-material-storage.php');
assert('material storage is a thin wrapper over the hardened private store, not identity-doc code path', matStore.includes('Hedayati_Document_Storage::save') && matStore.includes('Hedayati_Document_Storage::stream') && !matStore.includes('get_table_documents'));
assert('view access requires an ACTIVE enrollment OR staff-on-run OR manager', mat.includes("null !== $enrollment && 'active' === $enrollment['status']") && mat.includes('Hedayati_Run_Staff_Service::user_is_staff_on_run'));
assert('file download handler re-verifies the viewer, never streams a caller-supplied key', mat.includes('self::can_view_run( $material[\'run_id\'], get_current_user_id() )') && mat.includes('Hedayati_Material_Storage::stream( $material[\'storage_key\'] )') && !/stream\(\s*\$_(GET|POST|REQUEST)/.test(mat));
assert('download link is nonced per-material', mat.includes("wp_nonce_url(") && mat.includes("'hedayati_material_download_' . $id"));
assert('link type requires http(s) url', mat.includes("preg_match( '#^https?://#i', $url )"));
assert('materials cleaned up on run deletion', mat.includes("add_action( 'hedayati_run_deleted'") && mat.includes('on_run_deleted'));

// ── 6. support tickets ──────────────────────────────────────────────────
console.log('\n6. support (class-support-service.php):');
const sup = P('includes/class-support-service.php');
assert('status vocabulary is open/waiting_student/waiting_staff/closed', /STATUSES\s+= \[ 'open', 'waiting_student', 'waiting_staff', 'closed' \]/.test(sup));
assert('every read for a student is ownership-checked (get_for_viewer)', sup.includes('public static function get_for_viewer( int $id, int $user_id )') && sup.includes("(int) $ticket['user_id'] === $user_id"));
assert('student cannot read another student ticket (staff cap required otherwise)', /get_for_viewer[\s\S]*?return user_can\( \$user_id, self::STAFF_CAP \) \? \$ticket : null;/.test(sup));
assert('reply() re-checks the viewer via get_for_viewer', /function reply\([\s\S]*?self::get_for_viewer\( \$ticket_id, \$user_id \)/.test(sup));
assert('status change requires the staff capability', /function set_status\([\s\S]*?user_can\( \$actor_id, self::STAFF_CAP \)/.test(sup));
assert('closed ticket blocks student replies but not staff', sup.includes("'closed' === $ticket['status'] && ! $is_staff"));
assert('audit records the kind of reply, not the body', sup.includes("'support.replied'") && !/record\([^)]*\$body/.test(sup));
assert('student + staff caps are distinct', sup.includes("STUDENT_CAP = 'hedayati_use_support_tickets'") && sup.includes("STAFF_CAP    = 'hedayati_manage_support_tickets'"));

// ── 7. notifications ────────────────────────────────────────────────────
console.log('\n7. notifications (class-notification-service.php):');
const notif = P('includes/class-notification-service.php');
assert('notify() writes a real row keyed to one recipient', notif.includes('function notify( int $user_id, string $type') && notif.includes('$wpdb->insert'));
assert('mark_read is scoped to the owner (user_id in the WHERE)', /UPDATE \{\$table\} SET read_at = %s WHERE id = %d AND user_id = %d/.test(notif));
assert('unread_count is per-user', /SELECT COUNT\(\*\) FROM \{\$table\} WHERE user_id = %d AND read_at IS NULL/.test(notif));
assert('read/read-all handlers verify a nonce + logged-in user', notif.includes('wp_verify_nonce( $nonce, $nonce_action )') && notif.includes('is_user_logged_in()'));
assert('only a deliberate event set is wired (not every CRUD)', notif.includes("add_action( 'hedayati_consultation_created'") && !/save_post|updated_post_meta|add_action\(\s*'wp_insert/.test(notif));
assert('notifications purged on user deletion', notif.includes("add_action( 'deleted_user'") && notif.includes('on_user_deleted'));

// ── 8. certificates + public verification ──────────────────────────────
console.log('\n8. certificates (class-certificate-service.php):');
const cert = P('includes/class-certificate-service.php');
assert('never auto-issued — issue() requires the manage capability', /function issue\([\s\S]*?user_can\( \$actor_id, self::MANAGE_CAP \)/.test(cert));
assert('duplicate issuance for the same enrollment is prevented', cert.includes('self::get_by_enrollment( $enrollment_id )') && cert.includes("'duplicate'"));
assert('public code is cryptographically random (random_bytes), non-sequential', cert.includes('random_bytes(') && cert.includes('random_base32'));
assert('national ID is never used as the identifier', !/national_id/i.test(cert));
assert('public verification shows only minimal fields (name/course/date/institute/code)', cert.includes('recipient_name') && cert.includes('مجتمع آموزشی دکتر هدایتی') && !/phone|address|attendance|enrollment_id.*dd>/i.test(cert.match(/render_public_verification[\s\S]*?\n\t\}/)[0]));
assert('revoked / unknown codes render a clear non-sensitive status', cert.includes('hd-verify-revoked') && cert.includes('hd-verify-unknown'));
assert('verification endpoint is IP rate-limited', cert.includes('verify_rate_limited()') && cert.includes('verify_rate_bump()'));
assert('revoke() requires the manage capability + audits', /function revoke\([\s\S]*?user_can\( \$actor_id, self::MANAGE_CAP \)[\s\S]*?'certificate\.revoked'/.test(cert));
assert('student view lists only the signed-in user\'s certificates', cert.includes('list_for_user( int $user_id )') && cert.includes('WHERE user_id = %d'));

// ── 9. in-panel settings ──────────────────────────────────────────────
console.log('\n9. in-panel settings (class-panel-settings.php / class-settings.php):');
const psettings = P('includes/class-panel-settings.php');
const settings = P('includes/class-settings.php');
assert('panel settings reuse the canonical option + sanitizer (no duplicate source)', psettings.includes('Hedayati_Settings::update( $input )') && settings.includes('update_option( self::OPTION_NAME, self::sanitize_all( $input ) )'));
assert('panel settings save is capability + nonce guarded', psettings.includes('Hedayati_Staff_Portal::guard_action( self::NONCE_ACTION, Hedayati_Settings::CAPABILITY )'));
assert('field list is a single source of truth used by both surfaces', settings.includes('public static function field_labels()') && psettings.includes('Hedayati_Settings::field_labels()'));
assert('added legitimate settings (institute name + tehran address), no demo-only fields', settings.includes("'institute_name'") && settings.includes("'address_tehran'"));
assert('save is audited', psettings.includes("Hedayati_Audit_Log::record( 'settings.updated'"));

// ── 10. panel + account wiring ────────────────────────────────────────
console.log('\n10. wiring (staff-portal / student-portal / templates / bootstrap):');
const boot = P('hedayati-core.php');
for (const c of ['Notification', 'Consultation', 'Material', 'Support', 'Certificate', 'Panel_Settings']) {
	assert(`bootstrap requires + inits Hedayati_${c}_Service/`.replace('_Service/', c === 'Panel_Settings' ? '' : '_Service'), boot.includes(`class-${c.toLowerCase().replace('_', '-')}`) || boot.includes(`class-${c.toLowerCase().replace('panel_settings','panel-settings')}`));
}
const staff = P('includes/class-staff-portal.php');
assert('staff-portal exposes a module-view registry + guard_action + redirect_notice', staff.includes('public static function module_views()') && staff.includes('public static function guard_action(') && staff.includes('public static function redirect_notice('));
assert('module views are capability-checked in guard() AND render()', /guard\(\)[\s\S]*?module_views\(\)[\s\S]*?current_user_can/.test(staff) && /function render\(\)[\s\S]*?module_views\(\)[\s\S]*?current_user_can/.test(staff));
const sp = P('includes/class-student-portal.php');
assert('student portal routes certificates/support/notifications views', sp.includes("case 'certificates':") && sp.includes("case 'support':") && sp.includes("case 'notifications':"));
assert('student enrollments view shows real progress + attendance, materials', sp.includes('Hedayati_Progress_Service::for_enrollment') && sp.includes('Hedayati_Material_Service::render_student_run'));
const pageAccount = T('page-account.php');
assert('account nav includes the new views + unread badge', pageAccount.includes("'certificates'") && pageAccount.includes("'support'") && pageAccount.includes("'notifications'") && pageAccount.includes('hd-nav-badge'));
const page = T('page.php');
assert('page.php renders the public consult form + certificate verification', page.includes('Hedayati_Consultation_Service::render_public_form()') && page.includes('Hedayati_Certificate_Service::render_public_verification()'));
const publicContent = P('includes/class-public-content.php');
assert("public content provisions a 'verify' page", /'verify'\s*=>\s*'استعلام گواهینامه'/.test(publicContent));

// ── summary ──────────────────────────────────────────────────────────────
console.log('\n========================================');
console.log(`AI-STUDIO-MODULES SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log('========================================');
process.exit(failed === 0 ? 0 : 1);
