/**
 * Node.js static & logic verification for Phase 2D: the shared account shell
 * and student self-service portal (Hedayati_Auth_UI, Hedayati_Student_Portal,
 * the account-page template, and the theme's Phase 2D assets).
 *
 * Real WordPress-runtime behaviour (login redirects, capability enforcement,
 * ownership checks, no-cache headers, upload/download round-trips) is verified
 * by the Docker acceptance suite (docker/wp-tests/test-phase-2d.php), not
 * here — this file is static/structural only.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.join(__dirname, '..');
const THEME_ROOT   = path.join(__dirname, '..', '..', '..', 'theme', 'hedayati');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const readPlugin = (rel) => fs.readFileSync(path.join(PLUGIN_ROOT, rel), 'utf8');
const readTheme  = (rel) => fs.readFileSync(path.join(THEME_ROOT, rel), 'utf8');
const codeOnly = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
const braces = (src) => {
	const ob = (src.match(/{/g) || []).length;
	const cb = (src.match(/}/g) || []).length;
	return { ob, cb, balanced: ob === cb };
};

console.log('=== NODE.JS PHASE 2D VERIFICATION ===\n');

// ── 1. class-auth-ui.php ────────────────────────────────────────────────────

console.log('1. class-auth-ui.php (Hedayati_Auth_UI):');
const authUi = readPlugin('includes/class-auth-ui.php');
assert('declares strict_types', authUi.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', authUi.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(authUi);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('forces users_can_register to false regardless of the stored option (no self-registration, ever)', authUi.includes("add_filter( 'option_users_can_register', '__return_false' )"));
assert('branded login assets enqueued via login_enqueue_scripts', authUi.includes("'login_enqueue_scripts'"));
assert('login CSS is a theme asset (get_theme_file_uri), not hardcoded into the plugin URL', authUi.includes('get_theme_file_uri(') && authUi.includes('get_theme_file_path('));
assert('lostpassword_errors filter registered for enumeration hardening', authUi.includes("'lostpassword_errors'"));
{
	const src = codeOnly(authUi);
	assert('enumeration hardening neutralizes invalid_email/invalidcombo/invalid_username specifically', /invalid_email/.test(src) && /invalidcombo/.test(src) && /invalid_username/.test(src));
	assert('enumeration hardening does NOT swallow empty_username (real UX error, not an existence leak)', !/empty_username/.test(src));
}
assert('neutralize_lostpassword_enumeration can return true (fakes the same success path as a real account)', /function neutralize_lostpassword_enumeration[\s\S]{0,400}return true;/.test(authUi));
assert('login_redirect filter registered for role-aware routing', authUi.includes("'login_redirect'"));
assert('student login redirect targets the account URL via Hedayati_Student_Portal, not a hardcoded path', /student_login_redirect[\s\S]{0,300}Hedayati_Student_Portal::get_account_url\(\)/.test(authUi));
assert('wp-admin block hooked on admin_init', authUi.includes("add_action( 'admin_init', [ self::class, 'maybe_redirect_student_away_from_admin' ] )"));
{
	const guardFn = (authUi.match(/function maybe_redirect_student_away_from_admin\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('wp-admin block excludes wp_doing_ajax()', guardFn.includes('wp_doing_ajax()'));
	assert('wp-admin block excludes wp_doing_cron()', guardFn.includes('wp_doing_cron()'));
	assert('wp-admin block excludes WP_CLI', guardFn.includes('WP_CLI'));
	assert('wp-admin block excludes REST_REQUEST', guardFn.includes('REST_REQUEST'));
	assert('wp-admin block excludes admin-post.php and admin-ajax.php explicitly', guardFn.includes("'admin-post.php'") && guardFn.includes("'admin-ajax.php'"));
	assert('wp-admin block only redirects a user whose ONLY role is student (never touches an admin/manager)', authUi.includes("[ 'student' ] === array_values( $roles )"));
}
assert('admin bar hidden for portal-only (student) users via show_admin_bar filter', authUi.includes("'show_admin_bar'"));

// ── 2. class-student-portal.php ─────────────────────────────────────────────

console.log('\n2. class-student-portal.php (Hedayati_Student_Portal):');
const portal = readPlugin('includes/class-student-portal.php');
assert('declares strict_types', portal.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', portal.includes("if ( ! defined( 'ABSPATH' ) ) {"));
{
	const b = braces(portal);
	assert(`braces balanced (${b.ob}/${b.cb})`, b.balanced);
}
assert('account page auto-created on activation via maybe_create_account_page()', portal.includes('function maybe_create_account_page()'));
assert('admin_init safety net for a manual-file-replace deploy (mirrors the migration/roles pattern)', portal.includes("add_action( 'admin_init', [ self::class, 'maybe_create_account_page' ] )"));
assert('guard_account_page() hooked on template_redirect', portal.includes("add_action( 'template_redirect', [ self::class, 'guard_account_page' ] )"));
{
	const guardFn = (portal.match(/function guard_account_page\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('no-cache headers sent before the login check (so even a redirect is never cached)', /send_no_cache_headers\(\);[\s\S]*is_user_logged_in\(\)/.test(guardFn));
	assert('unauthenticated visitor redirected to wp_login_url()', guardFn.includes('wp_login_url('));
	assert('authenticated but unprivileged visitor gets a 403, not silent passthrough', /current_user_can\( self::VIEW_CAPABILITY \)[\s\S]{0,150}response.{0,10}403/.test(guardFn));
}
assert('send_no_cache_headers() calls WP core nocache_headers()', portal.includes('nocache_headers();'));
assert('send_no_cache_headers() also fires the LiteSpeed Cache plugin exclusion hook when active (has_action guarded, safe no-op otherwise)', portal.includes("has_action( 'litespeed_control_set_nocache' )") && portal.includes("do_action( 'litespeed_control_set_nocache'"));

// ── 3. Ownership — the core Phase 2D security requirement ──────────────────

console.log('\n3. Ownership: owner is ALWAYS get_current_user_id(), never a posted user_id:');
assert('verify_self_service() takes NO $user_id parameter — it can only return get_current_user_id()', /private static function verify_self_service\( string \$nonce_action, string \$capability \): int/.test(portal));
assert('verify_self_service() returns get_current_user_id(), not any $_POST-derived value', /function verify_self_service[\s\S]{0,600}return get_current_user_id\(\);/.test(portal));
{
	const mutationHandlers = ['handle_profile_save', 'handle_phone_save', 'handle_document_upload'];
	mutationHandlers.forEach((fn) => {
		const body = (portal.match(new RegExp(`function ${fn}\\(\\)[\\s\\S]*?\\n\\t\\}`)) || [''])[0];
		assert(`${fn}() derives its owner from verify_self_service(), not $_POST['user_id']`, body.includes('verify_self_service(') && !/\$_POST\[\s*['"]user_id['"]\s*\]/.test(body));
	});
}
assert('this file never trusts a client-submitted user_id anywhere (no $_POST[\'user_id\'] / $_GET[\'user_id\'] read at all)', !/\$_(POST|GET)\[\s*['"]user_id['"]\s*\]/.test(portal));
assert('does not call the staff-only require_student_scope() (that check is intentionally insufficient for self-service — docs/PHASE_2D_PLANNING.md §9)', !codeOnly(portal).includes('require_student_scope('));

// ── 4. Document ownership — explicit check before every document action ────

console.log('\n4. Document ownership (list/upload/download):');
{
	const downloadFn = (portal.match(/function handle_document_download\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('handle_document_download() loads the document via Hedayati_Document_Service::get() before streaming', downloadFn.includes('Hedayati_Document_Service::get('));
	assert('handle_document_download() explicitly compares $doc[\'user_id\'] to get_current_user_id() (the ownership check Hedayati_Document_Service itself does not perform)', /\(int\) \$doc\[\s*'user_id'\s*\] !== \$user_id/.test(downloadFn));
	assert('handle_document_download() derives $user_id from get_current_user_id(), not a request parameter', downloadFn.includes('$user_id = get_current_user_id();'));
	assert('a missing document and someone else\'s document get the identical response (never confirms another student\'s document exists)', /404/.test(downloadFn));
}
{
	const uploadFn = (portal.match(/function handle_document_upload\(\)[\s\S]*?\n\t\}/) || [''])[0];
	assert('handle_document_upload() gated on hedayati_upload_own_documents', uploadFn.includes('hedayati_upload_own_documents'));
	assert('handle_document_upload() passes verify_self_service()\'s returned $user_id into Hedayati_Document_Service::upload(), not a posted id', /Hedayati_Document_Service::upload\(\s*\$user_id/.test(uploadFn));
}
assert('list_for_user is called with the portal-resolved $user_id in render_documents_view, not a request value', /render_documents_view\( int \$user_id \)[\s\S]{0,200}Hedayati_Document_Service::list_for_user\( \$user_id \)/.test(portal));

// ── 5. Verification display — status + presence only, never staff-internal fields ──

console.log('\n5. Verification display narrowing (no reviewer/note/decrypted-value leakage):');
{
	const verFn = (portal.match(/function render_verification_view\( int \$user_id \)[\s\S]*?\n\t\}/) || [''])[0];
	assert('render_verification_view() calls get_status() and get_national_id_masked() only', verFn.includes('Hedayati_Verification_Service::get_status(') && verFn.includes('Hedayati_Verification_Service::get_national_id_masked('));
	assert('render_verification_view() never calls get_national_id_decrypted()', !verFn.includes('get_national_id_decrypted'));
	assert("render_verification_view() never echoes reviewer_id", !/reviewer_id/.test(verFn));
	assert("render_verification_view() never echoes reviewed_at", !/reviewed_at/.test(verFn));
	assert("render_verification_view() never echoes the status array's note field", !/\$status\[\s*'note'\s*\]/.test(verFn));
}
assert('get_national_id_decrypted() is never called anywhere in this entire portal controller', !codeOnly(portal).includes('get_national_id_decrypted('));
assert('no student-facing verification action exists (no approve/reject/initiate call anywhere in this file)', !/Hedayati_Verification_Service::(approve|reject|initiate)\(/.test(portal));
assert('no self-enrollment path exists (no ::enroll( call anywhere in this file)', !/Hedayati_Enrollment_Service::enroll\(/.test(portal));

// ── 6. Shamsi/Gregorian discipline ──────────────────────────────────────────

console.log('\n6. Shamsi display, Gregorian storage (no new storage-format change):');
assert('enrollments/sessions view uses Hedayati_Jalali::format() for display', portal.includes('Hedayati_Jalali::format('));
assert('no direct date storage/parsing bypassing existing services (no new dbDelta/migration in this file)', !/dbDelta|CREATE TABLE/i.test(portal));

// ── 7. Bootstrap wiring ──────────────────────────────────────────────────────

console.log('\n7. hedayati-core.php bootstrap wiring:');
const boot = readPlugin('hedayati-core.php');
assert('requires class-auth-ui.php', boot.includes('includes/class-auth-ui.php'));
assert('requires class-student-portal.php', boot.includes('includes/class-student-portal.php'));
assert('boots Hedayati_Auth_UI::init()', boot.includes('Hedayati_Auth_UI::init()'));
assert('boots Hedayati_Student_Portal::init()', boot.includes('Hedayati_Student_Portal::init()'));
assert('activation hook creates the account page', boot.includes('Hedayati_Student_Portal::maybe_create_account_page()'));
assert('plugin version bumped to 1.7.0', boot.includes("HEDAYATI_CORE_VERSION', '1.7.0'"));
assert("plugin header 'Version:' matches HEDAYATI_CORE_VERSION", boot.includes('Version:           1.7.0'));
assert('no new database migration was added for Phase 2D (no schema change — every read/write reuses an existing table)', !/'2\.4\.0'/.test(readPlugin('includes/class-db-schema.php')));
assert('no new capability was added for Phase 2D (no capability change — every check reuses an existing hedayati_* capability)', (() => {
	const roles = readPlugin('includes/class-roles.php');
	const capListMatch = roles.match(/get_all_hedayati_capabilities\(\): array \{\s*return \[([\s\S]*?)\];/);
	const count = capListMatch ? (capListMatch[1].match(/'hedayati_[a-z_]+'/g) || []).length : -1;
	return count === 23 && roles.includes("ROLES_VERSION = '2.2.0'");
})());

// ── 8. Theme: page-account.php ──────────────────────────────────────────────

console.log('\n8. theme/hedayati/page-account.php:');
const template = readTheme('page-account.php');
assert('calls get_header() with no arguments (matches every other template in this theme)', /get_header\(\s*\);/.test(template));
assert('calls get_footer() with no arguments', /get_footer\(\s*\);/.test(template));
assert('uses the shared #site-main skip-link target, matching singular.php\'s convention', template.includes('id="site-main"'));
assert('uses the shared .container wrapper class', template.includes('class="container'));
assert('renders through Hedayati_Student_Portal::render_current_view() (no duplicated business logic in the template)', template.includes('Hedayati_Student_Portal::render_current_view()'));
assert('view whitelist re-validated in the template too (defense in depth against a stray $_GET value reaching nav "is-active" state)', template.includes('Hedayati_Student_Portal::VIEWS'));
assert('logout link uses wp_logout_url() (a nonced WP core URL), not a raw ?action=logout link', template.includes('wp_logout_url('));

// ── 9. Theme assets: no new framework/bundler/jQuery ────────────────────────

console.log('\n9. theme/hedayati/assets/{css,js}/account.* — no new framework:');
const accountCss = readTheme('assets/css/account.css');
const accountJs  = readTheme('assets/js/account.js');
assert('account.css reuses existing --hd-* custom properties (no new palette)', /var\(--hd-/.test(accountCss));
assert('account.css defines no new @media prefers-color-scheme / [data-theme] block of its own (dark mode stays centralized in main.css)', !/prefers-color-scheme|\[data-theme/.test(accountCss));
{
	const jsCode = codeOnly(accountJs).trim();
	assert('account.js is a single IIFE, matching main.js\'s convention', /^\(function \(\) \{[\s\S]*\}\(\)\);\s*$/.test(jsCode));
	assert('account.js has no jQuery reference', !/jQuery|\$\(/.test(jsCode));
	assert('account.js has no import/require/bundler syntax', !/\bimport\s|\brequire\(|\bexport\s/.test(jsCode));
}
assert('login.css is RTL for the login screen (Persian-first, D2.1)', readTheme('assets/css/login.css').includes('direction: rtl'));

console.log(`\n========================================`);
console.log(`PHASE 2D SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
