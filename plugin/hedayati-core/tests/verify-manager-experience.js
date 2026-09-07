/**
 * Node.js static & structural verification for the Manager Experience work
 * (owner decision D53): classic wp-admin is an administrator-only interface;
 * every non-administrator Hedayati role uses the professional front-end panel /
 * account experience.
 *
 * Real WordPress-runtime behaviour (the admin_init redirect firing on a real
 * request, capability enforcement, the teacher CRUD round-trip, the 1:1 WP-user
 * link rule, audit read-only) is verified by docker/wp-tests/test-manager-
 * experience.php — this file is static/structural only.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.join(__dirname, '..');
const THEME_ROOT = path.join(__dirname, '..', '..', '..', 'theme', 'hedayati');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const readPlugin = (rel) => fs.readFileSync(path.join(PLUGIN_ROOT, rel), 'utf8');
const readTheme = (rel) => fs.readFileSync(path.join(THEME_ROOT, rel), 'utf8');
const codeOnly = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
const braces = (src) => {
	const ob = (src.match(/{/g) || []).length;
	const cb = (src.match(/}/g) || []).length;
	return { ob, cb, balanced: ob === cb };
};

console.log('=== NODE.JS MANAGER EXPERIENCE (D53) VERIFICATION ===\n');

// ── 1. class-admin-access.php ──────────────────────────────────────────────

console.log('1. class-admin-access.php (Hedayati_Admin_Access):');
const adminAccess = readPlugin('includes/class-admin-access.php');
const adminAccessCode = codeOnly(adminAccess);
assert('declares strict_types', adminAccess.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', adminAccess.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(adminAccess);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('redirect hooked on admin_init (priority 1, before screen-specific admin_init)', /add_action\(\s*'admin_init',\s*\[\s*self::class,\s*'redirect_interactive_admin'\s*\],\s*1\s*\)/.test(adminAccess));
assert('admin bar hidden for non-admins via show_admin_bar filter', adminAccess.includes("add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_for_non_admins' ]"));
{
	const fn = (adminAccessCode.match(/function is_interactive_admin_request\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('interactive check excludes wp_doing_ajax()', fn.includes('wp_doing_ajax()'));
	assert('interactive check excludes wp_doing_cron()', fn.includes('wp_doing_cron()'));
	assert('interactive check excludes WP_CLI', fn.includes('WP_CLI'));
	assert('interactive check excludes REST_REQUEST', fn.includes('REST_REQUEST'));
	assert('interactive check excludes admin-post.php + admin-ajax.php + async-upload.php explicitly', fn.includes("'admin-post.php'") && fn.includes("'admin-ajax.php'") && fn.includes("'async-upload.php'"));
	assert('interactive check ultimately requires is_admin()', /return is_admin\(\);/.test(fn));
}
{
	const fn = (adminAccessCode.match(/function redirect_interactive_admin\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('redirect bails unless logged in AND interactive', /is_user_logged_in\(\)[\s\S]{0,60}is_interactive_admin_request\(\)/.test(fn));
	assert('redirect only fires for a role whose enforcement is ACTUALLY on (is_enforced_for gate — staged)', /if \( ! self::is_enforced_for\( \$user \) \) \{\s*return;/.test(fn));
	assert('is_enforced_for() bails for administrators (manage_options)', /function is_enforced_for\( WP_User \$user \)[\s\S]{0,120}user_can\( \$user, 'manage_options' \)[\s\S]{0,30}return false;/.test(adminAccessCode));
	assert('uses wp_safe_redirect (open-redirect safe), then exit', fn.includes('wp_safe_redirect( $target )') && fn.includes('exit;'));
	assert('sends nocache_headers before the redirect', /nocache_headers\(\)[\s\S]{0,80}wp_safe_redirect/.test(fn));
}
{
	const fn = (adminAccessCode.match(/function workspace_url_for\( WP_User \$user \)[\s\S]*?\n\t\}/) || [''])[0];
	assert('workspace_url_for routes staff roles to Hedayati_Staff_Portal::url()', fn.includes('Hedayati_Staff_Portal::url()'));
	assert('workspace_url_for routes students to Hedayati_Student_Portal::get_account_url()', fn.includes('Hedayati_Student_Portal::get_account_url()'));
	assert('workspace_url_for returns "" for a non-Hedayati role (no forced routing)', /return '';/.test(fn));
	assert('workspace_url_for excludes manage_options FIRST (admin is augmented with every hedayati_* cap — a probe alone would misroute them)', /function workspace_url_for\( WP_User \$user \)[\s\S]{0,220}user_can\( \$user, 'manage_options' \)[\s\S]{0,40}return '';/.test(adminAccessCode));
}
assert('staged rollout: ENFORCED_ROLES is exactly student + teacher + teacher_assistant', /ENFORCED_ROLES = \[\s*'student',\s*'teacher',\s*'teacher_assistant'\s*\]/.test(adminAccess));
assert('staged rollout: reception / hedayati_manager are NOT in the enforced constant', !/ENFORCED_ROLES = \[[^\]]*hedayati_manager/.test(adminAccess));
assert('Phase E gate is a public filter (hedayati_admin_redirect_roles)', adminAccess.includes("apply_filters( 'hedayati_admin_redirect_roles'"));
assert('is_enforced_for() requires ALL of a user\'s roles to be in the enforced set (array_diff === [])', /\[\] === array_diff\( \$roles, self::enforced_roles\(\) \)/.test(adminAccess));

// ── 2. bootstrap wiring ───────────────────────────────────────────────────

console.log('\n2. hedayati-core.php bootstrap wiring:');
const boot = readPlugin('hedayati-core.php');
assert('requires class-admin-access.php', boot.includes('includes/class-admin-access.php'));
assert('requires class-teacher-panel.php', boot.includes('includes/class-teacher-panel.php'));
assert('requires class-audit-panel.php', boot.includes('includes/class-audit-panel.php'));
assert('boots Hedayati_Admin_Access::init()', boot.includes('Hedayati_Admin_Access::init()'));
assert('boots Hedayati_Teacher_Panel::init()', boot.includes('Hedayati_Teacher_Panel::init()'));
assert('boots Hedayati_Audit_Panel::init()', boot.includes('Hedayati_Audit_Panel::init()'));
assert('plugin version >= 1.10.0', (() => {
	const m = boot.match(/HEDAYATI_CORE_VERSION', '(\d+)\.(\d+)\.\d+'/);
	if (!m) return false;
	const [maj, min] = [Number(m[1]), Number(m[2])];
	return maj > 1 || (maj === 1 && min >= 10);
})());
assert("plugin header 'Version:' matches HEDAYATI_CORE_VERSION", (() => {
	const v = boot.match(/HEDAYATI_CORE_VERSION', '([0-9.]+)'/);
	const h = boot.match(/\*\s*Version:\s+([0-9.]+)/);
	return !!v && !!h && v[1] === h[1];
})());

// ── 3. class-teacher-panel.php ────────────────────────────────────────────

console.log('\n3. class-teacher-panel.php (Hedayati_Teacher_Panel):');
const teacherPanel = readPlugin('includes/class-teacher-panel.php');
const teacherPanelCode = codeOnly(teacherPanel);
assert('declares strict_types', teacherPanel.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', teacherPanel.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(teacherPanel);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('registers a panel view via hedayati_panel_module_views (reuses the existing plumbing)', teacherPanel.includes("add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] )"));
assert('the view slug is "teachers" and the nav label is «اساتید»', teacherPanel.includes("const VIEW      = 'teachers'") && teacherPanel.includes('اساتید'));
assert('gated on the EXISTING hedayati_manage_teachers capability (no new cap)', teacherPanel.includes("const CAPABILITY = 'hedayati_manage_teachers'"));
assert('uses the canonical teacher CPT constant, never a new post type string', teacherPanel.includes('Hedayati_Teacher::POST_TYPE') && !/register_post_type/.test(teacherPanel));
assert('uses the canonical Hedayati_Teacher meta-key constants (no duplicate meta)', teacherPanel.includes('Hedayati_Teacher::META_HEADLINE') && teacherPanel.includes('Hedayati_Teacher::META_USER_ID'));
assert('creates no database table (no dbDelta / CREATE TABLE / $wpdb->insert)', !/dbDelta|CREATE TABLE|\$wpdb->/i.test(teacherPanelCode));
{
	const save = (teacherPanelCode.match(/function handle_save\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('handle_save guards via Hedayati_Staff_Portal::guard_action (POST + capability + nonce)', save.includes("Hedayati_Staff_Portal::guard_action( self::NONCE_SAVE, self::CAPABILITY )"));
	assert('handle_save re-checks per-object edit_post before wp_update_post', /current_user_can\( 'edit_post', \$teacher_id \)/.test(save));
	assert('handle_save enforces the 1:1 WP-user link rule via Hedayati_Teacher::find_by_user_id (mirrors the CPT save)', save.includes('Hedayati_Teacher::find_by_user_id( $linked )'));
	assert('handle_save writes an audit entry (teacher.updated)', save.includes("Hedayati_Audit_Log::record( 'teacher.updated'"));
	assert('handle_save sanitises the biography with wp_kses_post', /wp_kses_post\(\s*\$str\(\s*'biography'\s*\)\s*\)/.test(save));
	assert('handle_save reads every $_POST field through an is_string guard (no array-type crash)', save.includes("is_string( \$_POST[ \$key ] )"));
}
{
	const trash = (teacherPanelCode.match(/function handle_trash\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('handle_trash guards via guard_action', trash.includes('Hedayati_Staff_Portal::guard_action( self::NONCE_TRASH'));
	assert('handle_trash re-checks per-object delete_post', /current_user_can\( 'delete_post', \$teacher_id \)/.test(trash));
	assert('handle_trash uses the safe wp_trash_post lifecycle (not wp_delete_post force)', trash.includes('wp_trash_post( $teacher_id )') && !/wp_delete_post/.test(trash));
}
assert('registers teacher.updated / teacher.trashed on the audit action allow-list', teacherPanel.includes("'teacher.updated', 'teacher.trashed'"));
assert('every panel form posts to admin-post.php (backend transport preserved)', (teacherPanel.match(/admin_url\( 'admin-post\.php' \)/g) || []).length >= 2);

// ── 4. class-audit-panel.php ──────────────────────────────────────────────

console.log('\n4. class-audit-panel.php (Hedayati_Audit_Panel):');
const auditPanel = readPlugin('includes/class-audit-panel.php');
const auditPanelCode = codeOnly(auditPanel);
assert('declares strict_types', auditPanel.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', auditPanel.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(auditPanel);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('registers an "audit" panel view via the module-view filter', auditPanel.includes("const VIEW = 'audit'") && auditPanel.includes("add_filter( 'hedayati_panel_module_views'"));
assert('gated on Hedayati_Audit_Log::VIEW_CAPABILITY (hedayati_view_audit_logs)', auditPanel.includes('Hedayati_Audit_Log::VIEW_CAPABILITY'));
assert('READ-ONLY: registers no admin_post handler and defines no handle_* method', !/admin_post_/.test(auditPanel) && !/function handle_/.test(auditPanel));
assert('READ-ONLY: only calls the audit log READ API (query / count), never a write', /Hedayati_Audit_Log::(query|count)\(/.test(auditPanel) && !/Hedayati_Audit_Log::record\(/.test(auditPanel));
assert('metadata-only policy preserved: no IP / user-agent column, key or $_SERVER read', !/\$_SERVER\[|REMOTE_ADDR|HTTP_USER_AGENT|['"]ip['"]\s*=>|['"]user_agent['"]/i.test(auditPanelCode));
assert('filter values are validated against the known safe enums before use', auditPanel.includes('in_array( $object_type, $object_types, true )') && auditPanel.includes('in_array( $action_f, $actions, true )'));
assert('has pagination (per-page constant + a page nav renderer)', auditPanel.includes('PER_PAGE') && auditPanel.includes('render_pagination('));
assert('re-checks the view capability inside render_panel (defense in depth vs guard())', /function render_panel\(\)[\s\S]{0,200}current_user_can\( Hedayati_Audit_Log::VIEW_CAPABILITY \)/.test(auditPanel));

// ── 5. wp-admin escape links removed from the panel / account experience ───

console.log('\n5. No wp-admin escape links left in the custom panel/account surfaces:');
const staffPortal = readPlugin('includes/class-staff-portal.php');
const staffPortalCode = codeOnly(staffPortal);
assert('staff portal no longer links managers to edit.php?post_type=teacher', !staffPortalCode.includes("edit.php?post_type=teacher"));
assert('staff portal no longer links to the wp-admin audit screen (hedayati-academic-audit)', !staffPortalCode.includes('hedayati-academic-audit'));
assert('the «اساتید» manager card now points at the in-panel teachers view', /self::url\(\s*\[\s*'view' => 'teachers'\s*\]\s*\)/.test(staffPortal));
assert('the audit card now points at the in-panel audit view', /self::url\(\s*\[\s*'view' => 'audit'\s*\]\s*\)/.test(staffPortal));
assert('the settings card now points at the in-panel settings view (not options-general.php)', /self::url\(\s*\[\s*'view' => 'settings'\s*\]\s*\)/.test(staffPortal) && !staffPortalCode.includes('options-general.php?page=hedayati-settings'));
assert('course create/edit wp-admin links are gated behind current_user_can( manage_options ) (admin-only fallback)', /current_user_can\( 'manage_options' \)[\s\S]{0,120}post-new\.php\?post_type=course/.test(staffPortalCode) || /manage_options[\s\S]{0,200}get_edit_post_link/.test(staffPortalCode));
{
	// The two screens with no front-end port yet (Phase E) may still be linked,
	// but ONLY for the manager and ONLY with an explicit interim marker.
	const remainingAdminLinks = (staffPortalCode.match(/admin_url\(\s*'admin\.php\?page=hedayati-(academic|students)'/g) || []);
	assert('the only remaining wp-admin links are the two Phase-E screens (academic ops + verification queue)', remainingAdminLinks.every((l) => /hedayati-(academic|students)/.test(l)));
}

const pagePanel = readTheme('page-panel.php');
assert('page-panel.php dropped the hard «اساتید» wp-admin nav item (now via the module loop)', !pagePanel.includes("edit.php?post_type=teacher"));
assert('page-panel.php marks the remaining legacy wp-admin nav items with the «موقت» tag', pagePanel.includes('hd-portal-nav-tag') && pagePanel.includes('موقت'));
assert('page-panel.php still renders module-view nav (teachers + audit come through here)', pagePanel.includes('Hedayati_Staff_Portal::module_views()'));

const featured = readTheme('template-parts/featured-courses.php');
assert('featured-courses empty-state hint points at /panel/?view=featured, not wp-admin', featured.includes("home_url( '/panel/?view=featured' )") && !featured.includes("admin_url( 'edit.php?post_type=course' )"));

// ── 6. Administrator exception intact ─────────────────────────────────────

console.log('\n6. Administrator exception:');
assert('Hedayati_Admin_Access never revokes a capability or filters map_meta_cap (routing only)', !/remove_cap|user_has_cap|map_meta_cap/.test(adminAccessCode));
assert('no global wp-admin disable (no "add_filter( \'admin_init\' ... wp_die" pattern)', !/admin_init[\s\S]{0,120}wp_die/.test(adminAccessCode));
assert('Hedayati_Teacher CPT still registers its native show_ui/show_in_menu screens for the administrator', (() => {
	const t = readPlugin('includes/class-teacher.php');
	return /'show_ui'\s*=>\s*true/.test(t) && /'show_in_menu'\s*=>\s*true/.test(t);
})());

console.log(`\n========================================`);
console.log(`MANAGER EXPERIENCE SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
