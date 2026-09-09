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
	assert('interactive check excludes admin-post.php + admin-ajax.php + async-upload.php + profile.php explicitly', fn.includes("'admin-post.php'") && fn.includes("'admin-ajax.php'") && fn.includes("'async-upload.php'") && fn.includes("'profile.php'"));
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
assert('D53 FULLY enforced: ENFORCED_ROLES = student + teacher + teacher_assistant + reception + hedayati_manager', /ENFORCED_ROLES = \[\s*'student',\s*'teacher',\s*'teacher_assistant',\s*'reception',\s*'hedayati_manager'\s*\]/.test(adminAccess));
assert('Phase E gate is a public filter (hedayati_admin_redirect_roles)', adminAccess.includes("apply_filters( 'hedayati_admin_redirect_roles'"));
assert('is_enforced_for() requires ALL of a user\'s roles to be in the enforced set (array_diff === [])', /\[\] === array_diff\( \$roles, self::enforced_roles\(\) \)/.test(adminAccess));

// ── 2. bootstrap wiring ───────────────────────────────────────────────────

console.log('\n2. hedayati-core.php bootstrap wiring:');
const boot = readPlugin('hedayati-core.php');
for (const c of ['admin-access', 'teacher-panel', 'audit-panel', 'course-panel', 'academic-panel', 'verification-panel']) {
	assert(`requires class-${c}.php`, boot.includes(`includes/class-${c}.php`));
}
for (const c of ['Hedayati_Admin_Access', 'Hedayati_Teacher_Panel', 'Hedayati_Audit_Panel', 'Hedayati_Course_Panel', 'Hedayati_Academic_Panel', 'Hedayati_Verification_Panel']) {
	assert(`boots ${c}::init()`, boot.includes(`${c}::init()`));
}
assert('plugin version >= 1.13.0', (() => {
	const m = boot.match(/HEDAYATI_CORE_VERSION', '(\d+)\.(\d+)\.\d+'/);
	if (!m) return false;
	const [maj, min] = [Number(m[1]), Number(m[2])];
	return maj > 1 || (maj === 1 && min >= 13);
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
	assert('handle_save reads every $_POST field through an is_scalar guard (no array-type crash)', save.includes("is_scalar( \$_POST[ \$key ] )"));
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
assert('teachers / audit / settings / academic are NOT hardcoded manager-home cards (they self-register via the module-view loop — no duplicates)', !/\$actions\[?\s*'?hedayati_manage_teachers'?\s*'?\]?\s*=>?\s*\[[\s\S]{0,120}view' => 'teachers'/.test(staffPortalCode) && !staffPortalCode.includes("__( 'اساتید', 'hedayati-core' ),\n\t\t\t\t__( 'پروفایل"));
assert('the manager-home card loop renders every module view that declares a title', /foreach \( self::module_views\(\) as \$slug => \$module \)[\s\S]{0,200}empty\( \$module\['title'\] \)/.test(staffPortal));
assert('the settings wp-admin escape link is gone from the staff portal', !staffPortalCode.includes('options-general.php?page=hedayati-settings'));
assert('the standalone audit <aside> was removed (module card is the single entry point)', !staffPortal.includes('hd-manager-audit'));
assert('courses list "new" button now targets the in-panel course-new view (Phase C)', /self::url\(\s*\[\s*'view' => 'course-new'\s*\]\s*\)/.test(staffPortal));
assert('courses list row edit targets the in-panel course-edit view', /self::url\(\s*\[\s*'view' => 'course-edit', 'course_id' => \$course_id\s*\]\s*\)/.test(staffPortal));
assert('no post-new.php course link anywhere in the staff portal', !staffPortalCode.includes('post-new.php?post_type=course'));
assert('academic operations wp-admin link is GONE from the staff portal (Phase E ported)', !staffPortalCode.includes('admin.php?page=hedayati-academic'));
assert('student verification wp-admin link is GONE from the staff portal (Phase E ported)', !staffPortalCode.includes('admin.php?page=hedayati-students'));
assert('ZERO wp-admin operational links remain in the staff portal for non-admins', (staffPortalCode.match(/admin_url\(\s*'admin\.php\?page=hedayati-[a-z-]+'/g) || []).length === 0);
assert('the only wp-admin course link left is the manage_options-gated Gutenberg shortcut', /current_user_can\( 'manage_options' \)[\s\S]{0,80}get_edit_post_link/.test(staffPortalCode));

const pagePanel = readTheme('page-panel.php');
assert('page-panel.php has NO wp-admin nav item at all (D53 fully enforced)', !/admin_url\(/.test(pagePanel) && !pagePanel.includes('hd-portal-nav-legacy') && !pagePanel.includes('hd-portal-nav-tag'));
assert('page-panel.php still renders module-view nav (teachers / audit / academic come through here)', pagePanel.includes('Hedayati_Staff_Portal::module_views()'));

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

// ── 7. class-course-panel.php (Phase C) ──────────────────────────────────

console.log('\n7. class-course-panel.php (Hedayati_Course_Panel — Phase C):');
const coursePanel = readPlugin('includes/class-course-panel.php');
const coursePanelCode = codeOnly(coursePanel);
assert('declares strict_types', coursePanel.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', coursePanel.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(coursePanel);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('registers course-new + course-edit as module views (existing plumbing)', coursePanel.includes("VIEW_NEW       = 'course-new'") && coursePanel.includes("VIEW_EDIT      = 'course-edit'") && coursePanel.includes("add_filter( 'hedayati_panel_module_views'"));
assert('gated on the EXISTING hedayati_manage_courses capability (no new cap)', coursePanel.includes("CAP            = 'hedayati_manage_courses'"));
assert('uses the canonical course CPT + Hedayati_Course_Meta keys/sanitizers (no second store)', /wp_insert_post\(\s*\[\s*'post_type'\s*=>\s*'course'/.test(coursePanel) && coursePanel.includes('Hedayati_Course_Meta::sanitize_registration_state') && coursePanel.includes('Hedayati_Course_Meta::sanitize_iso_date') && coursePanel.includes('Hedayati_Course_Meta::sanitize_string_array') && !/register_post_meta|dbDelta|CREATE TABLE/i.test(coursePanelCode));
assert('writes every _course_* meta key the meta box does', ['_course_english_name','_course_teacher','_course_duration','_course_level','_course_price','_course_prerequisites','_course_registration_state','_course_next_start_date','_course_syllabus','_course_target_audience','_course_learning_outcomes','_course_is_featured'].every((k) => coursePanel.includes(k)));
{
	const save = (coursePanelCode.match(/function handle_save\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('handle_save guards via guard_action (POST + hedayati_manage_courses + nonce)', save.includes('Hedayati_Staff_Portal::guard_action( self::NONCE, self::CAP )'));
	assert('handle_save re-checks per-object edit_post before wp_update_post', /current_user_can\( 'edit_post', \$course_id \)/.test(save));
	assert('handle_save enforces the 8-slot homepage featured cap server-side', save.includes('self::FEATURED_LIMIT') && /featured_count\(\)\s*>=\s*self::FEATURED_LIMIT/.test(save));
	assert('handle_save assigns categories via wp_set_post_terms on the canonical taxonomy', /wp_set_post_terms\( \$course_id, \$cat_ids, 'course-category'/.test(save));
	assert('handle_save featured image path only accepts an existing IMAGE attachment (wp_attachment_is_image), never an upload', save.includes('wp_attachment_is_image( $thumb )') && !/media_handle_upload|wp_handle_upload|\$_FILES/.test(save));
	assert('handle_save records a course.created / course.updated audit entry', /Hedayati_Audit_Log::record\(\s*\$is_new \? 'course\.created' : 'course\.updated'/.test(save));
	assert('handle_save content sanitised with wp_kses_post, excerpt with sanitize_textarea_field', save.includes('wp_kses_post( $str(') && save.includes("sanitize_textarea_field( \$str( 'excerpt' ) )"));
}
{
	const rp = (coursePanelCode.match(/function render_panel\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('render_panel re-checks self::CAP and per-object edit_post (defense in depth vs guard())', rp.includes('current_user_can( self::CAP )') && /current_user_can\( 'edit_post', \$course_id \)/.test(rp));
}
assert('next_start_date accepts Shamsi OR ISO via the existing Jalali helper, stores ISO', coursePanel.includes('Hedayati_Jalali::parse_input') && /update_post_meta\( \$course_id, '_course_next_start_date', Hedayati_Course_Meta::sanitize_iso_date/.test(coursePanel));
assert('bootstrap requires + boots Hedayati_Course_Panel', boot.includes('includes/class-course-panel.php') && boot.includes('Hedayati_Course_Panel::init()'));
assert('plugin version >= 1.11.0 (Phase C)', (() => {
	const m = boot.match(/HEDAYATI_CORE_VERSION', '(\d+)\.(\d+)\.\d+'/);
	return m && (Number(m[1]) > 1 || (Number(m[1]) === 1 && Number(m[2]) >= 11));
})());

// ── 8. class-academic-panel.php (Phase E) ────────────────────────────────

console.log('\n8. class-academic-panel.php (Hedayati_Academic_Panel — Phase E):');
const acadPanel = readPlugin('includes/class-academic-panel.php');
const acadPanelCode = codeOnly(acadPanel);
assert('declares strict_types', acadPanel.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', acadPanel.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(acadPanel);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('registers the "academic" module view (nav + manager card), cap hedayati_manage_course_runs', acadPanel.includes("VIEW = 'academic'") && acadPanel.includes("CAP  = 'hedayati_manage_course_runs'") && acadPanel.includes("add_filter( 'hedayati_panel_module_views'") && /'nav'\s*=>/.test(acadPanel));
assert('NO new data model — calls only the existing Phase 2B services', ['Hedayati_Course_Run_Service::', 'Hedayati_Run_Staff_Service::', 'Hedayati_Session_Service::', 'Hedayati_Enrollment_Service::', 'Hedayati_Attendance_Service::'].every((s) => acadPanel.includes(s)) && !/register_post_type|dbDelta|CREATE TABLE|\$wpdb->(insert|update|query)/i.test(acadPanelCode));
assert('per-action capability map matches the wp-admin class (assign_staff / record_attendance / manage_enrollments / create_enrollments / manage_course_runs)', ['hedayati_assign_staff', 'hedayati_record_attendance', 'hedayati_manage_enrollments', 'hedayati_create_enrollments', 'hedayati_manage_course_runs'].every((c) => acadPanel.includes(c)));
assert('every mutation handler goes through guard_action (POST + cap + nonce)', /function guard\( string \$action \)[\s\S]{0,160}Hedayati_Staff_Portal::guard_action\(/.test(acadPanel) && (acadPanelCode.match(/self::guard\( '/g) || []).length >= 10);
assert('every run mutation re-checks require_run_scope (manager passes; defence in depth)', acadPanel.includes('require_run_scope') && acadPanel.includes('user_is_staff_on_run') && (acadPanelCode.match(/self::require_run_scope\(/g) || []).length >= 8);
assert('attendance handler validates the whole batch (foreign enrollment ids, active status, allowed status) BEFORE any write', /handle_attendance_save\(\)[\s\S]{0,1200}wp_die\([\s\S]{0,80}response.{0,6}400/.test(acadPanelCode) && acadPanelCode.includes('Hedayati_Academic_Validation::ATTENDANCE_STATUSES'));
assert('dates render/parse Shamsi via the existing Hedayati_Jalali helper, storage stays Gregorian', acadPanel.includes('Hedayati_Jalali::format') && acadPanel.includes('Hedayati_Jalali::parse_input') && acadPanel.includes('Hedayati_Academic_Validation::parse_iso_date'));
assert('public-run opt-in toggles the canonical course meta allow-list, never a new field', acadPanel.includes('Hedayati_Public_Content::META_PUBLIC_RUN_IDS'));
assert('reuses the wp-admin class\'s (now public) label/choice maps — no duplicated Persian strings', acadPanel.includes('Hedayati_Academic_Admin::run_status_choices()') && acadPanel.includes('Hedayati_Academic_Admin::staff_role_label('));
assert('render_panel re-checks the view capability (defense in depth vs guard())', /function render_panel\(\)[\s\S]{0,120}current_user_can\( self::CAP \)/.test(acadPanel));
assert('the wp-admin Hedayati_Academic_Admin class is untouched as an administrator fallback (still registers its menu)', readPlugin('includes/class-academic-admin.php').includes("add_menu_page(") && readPlugin('includes/class-academic-admin.php').includes("self::MENU_SLUG"));
assert('bootstrap requires + boots Hedayati_Academic_Panel', boot.includes('includes/class-academic-panel.php') && boot.includes('Hedayati_Academic_Panel::init()'));
assert('plugin version >= 1.12.0 (Phase E)', (() => {
	const m = boot.match(/HEDAYATI_CORE_VERSION', '(\d+)\.(\d+)\.\d+'/);
	return m && (Number(m[1]) > 1 || (Number(m[1]) === 1 && Number(m[2]) >= 12));
})());

// ── 9. class-verification-panel.php (Phase E) ────────────────────────────

console.log('\n9. class-verification-panel.php (Hedayati_Verification_Panel — Phase E):');
const vPanel = readPlugin('includes/class-verification-panel.php');
const vPanelCode = codeOnly(vPanel);
assert('declares strict_types', vPanel.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', vPanel.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(vPanel);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('reviewer section renders inside the existing /panel/?view=students detail (called by staff-portal)', vPanel.includes('function render_reviewer_section') && staffPortal.includes('Hedayati_Verification_Panel::render_reviewer_section( $user_id )'));
assert('approve/reject gated on hedayati_verify_students; doc archive/purge on hedayati_view_private_documents', /'approve'\s*=>\s*'hedayati_verify_students'/.test(vPanel) && /'reject'\s*=>\s*'hedayati_verify_students'/.test(vPanel) && /'doc_archive'\s*=>\s*'hedayati_view_private_documents'/.test(vPanel));
assert('every mutation handler: POST method + per-object nonce + capability re-check', /function verify\( string \$nonce_action, string \$cap \)[\s\S]{0,200}REQUEST_METHOD[\s\S]{0,120}wp_verify_nonce[\s\S]{0,120}current_user_can\( \$cap \)/.test(vPanel));
assert('staff-assisted actions re-check the target is a real student (require_student)', vPanel.includes('function require_student') && (vPanelCode.match(/self::require_student\(/g) || []).length >= 2);
assert('NATIONAL-ID REVEAL: POST-only, per-user nonce, capability re-checked HERE, then get_national_id_decrypted', /handle_reveal\(\)[\s\S]{0,200}self::verify\( 'hedayati_vpanel_reveal_' \. \$user_id, 'hedayati_verify_students' \)[\s\S]{0,200}Hedayati_Verification_Service::get_national_id_decrypted\(/.test(vPanelCode));
assert('reveal emits no-store headers and audits identity.viewed, never persists the value', /handle_reveal\(\)[\s\S]*?Cache-Control: no-store[\s\S]*?Hedayati_Audit_Log::record\( 'identity\.viewed'/.test(vPanelCode) && !/set_transient|update_(user_meta|option)/.test((vPanelCode.match(/function handle_reveal\(\)[\s\S]*?\n\t\}/) || [''])[0]));
assert('reveal value never enters a URL query arg (only rendered in the page body)', !/add_query_arg\([^)]*\$value|\$value[^;]*add_query_arg/.test(vPanelCode));
assert('documents are streamed only through the existing nonced Hedayati_Student_Admin download handler (no public URL, no new stream path)', vPanel.includes("'hedayati_document_download'") && vPanel.includes("'hedayati_document_download_' . \$doc['id']") && !/readfile|fpassthru|Content-Disposition/.test(vPanelCode));
assert('reception (no verify/private-doc caps) is a no-op — the section self-gates', /render_reviewer_section\( int \$user_id \)[\s\S]{0,220}! \$can_review && ! \$can_docs[\s\S]{0,40}return;/.test(vPanel));
assert('no national ID / crypto reimplementation — only the service is called', !/openssl_|hash_hmac|Hedayati_Crypto::/.test(vPanelCode));
assert('the wp-admin Hedayati_Student_Admin screen is untouched as an administrator fallback', readPlugin('includes/class-student-admin.php').includes('add_menu_page(') && readPlugin('includes/class-student-admin.php').includes('handle_identity_reveal'));
assert('admin-access carve-out: profile.php stays reachable so every role keeps its own account/password screen', adminAccess.includes("'profile.php'"));

// ── 10. class-login.php + page-login.php + auth.css (Phase F) ─────────────

console.log('\n10. Phase F — the /login/ front-end auth experience:');
const login = readPlugin('includes/class-login.php');
const loginCode = codeOnly(login);
assert('declares strict_types', login.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', login.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(login);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('NOT a parallel auth system — login goes through wp_signon()', login.includes('wp_signon( $creds'));
assert('post-login routing reuses the existing login_redirect filter chain', /apply_filters\(\s*'login_redirect'/.test(login));
assert('redirect_to is validated with wp_validate_redirect (no open redirect)', (loginCode.match(/wp_validate_redirect\(/g) || []).length >= 3);
assert('forgot-password calls WordPress core retrieve_password() and NEVER inspects its result', login.includes('retrieve_password();') && !/retrieve_password\(\)[\s\S]{0,60}(is_wp_error|if\s*\(|has_errors)/.test(loginCode));
assert('forgot-password is enumeration-safe: one unconditional redirect for existing AND unknown accounts', /wp_safe_redirect\(\s*self::url\(\s*\[\s*'checkemail' => 'confirm'\s*\]\s*\)\s*\);\s*exit;/.test(loginCode));
assert('forgot-password shares the same rate-limit bucket the wp-login path uses (reset: prefix)', login.includes("'reset:' . strtolower( \$login )") && login.includes('Hedayati_Rate_Limiter::record_failure'));
assert('reset link landing uses core check_password_reset_key (token security untouched)', login.includes('check_password_reset_key( $key, $login )'));
assert('new password is set via core reset_password() — no custom hashing', login.includes('reset_password( $user, $pass1 )') && !/wp_set_password|password_hash|Hedayati_Crypto/.test(loginCode));
assert('the reset email link is only RE-POINTED at /login/ (str_replace of the core url), not regenerated', /filter_reset_message[\s\S]{0,400}str_replace\( \$core_url, \$our_url, \$message \)/.test(login));
assert('a bare wp-login.php GET is bounced to /login/ (logout / rp / admin-email left to core)', login.includes("add_action( 'login_init', [ self::class, 'maybe_bounce_wp_login' ] )") && /maybe_bounce_wp_login[\s\S]{0,400}in_array\( \$action, \[ 'login', 'lostpassword', 'retrievepassword' \]/.test(login));
assert('login_url filter leaves admin-context + REST callers alone', /function login_url[\s\S]{0,140}is_admin\(\)[\s\S]{0,40}REST_REQUEST[\s\S]{0,40}return \$url;/.test(login));
assert('an already-authenticated visitor is never shown a login form', /handle\(\)[\s\S]{0,400}get_current_user_id\(\)[\s\S]{0,200}wp_safe_redirect\( self::post_login_destination/.test(loginCode));
assert('a forced-first-login user is NOT bounced off /login/ before the change screen can run', /must_change\( \$user_id \)/.test(login));
assert('nonces on every POST form (login / lostpassword / resetpass)', login.includes("wp_verify_nonce( \$nonce, 'hedayati_login' )") && login.includes("'hedayati_lostpassword'") && login.includes("'hedayati_resetpass'"));
assert('bootstrap requires + boots Hedayati_Login and creates the /login/ page on activation', boot.includes('includes/class-login.php') && boot.includes('Hedayati_Login::init()') && boot.includes('Hedayati_Login::maybe_create_page()'));
assert('plugin version >= 1.14.0 (Phase F)', (() => {
	const m = boot.match(/HEDAYATI_CORE_VERSION', '(\d+)\.(\d+)\.\d+'/);
	return m && (Number(m[1]) > 1 || (Number(m[1]) === 1 && Number(m[2]) >= 14));
})());

const pageLogin = readTheme('page-login.php');
assert('page-login.php only renders — all handling is in Hedayati_Login::handle() (no wp_signon / auth logic in the template)', !/wp_signon|retrieve_password|reset_password\(|check_password_reset_key/.test(codeOnly(pageLogin)));
assert('page-login.php renders every state: login / lostpassword / resetpass / checkemail / password=reset', pageLogin.includes("'lostpassword' === $hd_action") && pageLogin.includes("'resetpass' === $hd_action") && pageLogin.includes('$hd_checkemail') && pageLogin.includes('$hd_pwreset'));
assert('page-login.php uses get_header()/get_footer() — one product with the public site, not a wp-login re-skin', /get_header\(\s*\);/.test(pageLogin) && /get_footer\(\s*\);/.test(pageLogin));
assert('login form posts username OR Iranian phone (single field, dir=ltr), remember-me, redirect_to hidden', pageLogin.includes('نام کاربری یا شمارهٔ همراه') && pageLogin.includes('name="rememberme"') && pageLogin.includes('name="redirect_to"'));
assert('no WordPress branding / wp-login language in the template', !/wordpress|wp-login|Powered by/i.test(pageLogin));

const authCss = readTheme('assets/css/auth.css');
assert('auth.css reuses the existing --hd-* tokens (no new palette)', /var\(--hd-red\)/.test(authCss) && /var\(--hd-ink\)/.test(authCss) && /var\(--hd-surface\)/.test(authCss));
assert('auth.css defines no @media dark block of its own (dark mode stays centralised in main.css)', !/prefers-color-scheme|\[data-theme/.test(authCss));
assert('auth.css is responsive (mobile breakpoint collapses the split layout)', /@media \(max-width: 720px\)[\s\S]{0,120}grid-template-columns: 1fr/.test(authCss));
assert('functions.php enqueues auth.css on the login page only', readTheme('functions.php').includes("'hedayati-auth'") && readTheme('functions.php').includes("assets/css/auth.css"));

console.log(`\n========================================`);
console.log(`MANAGER EXPERIENCE SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
