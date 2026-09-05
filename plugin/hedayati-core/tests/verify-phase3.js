/**
 * Node.js static & logic verification for Phase 3 (launch completion):
 *   - Hedayati_Account_Security (forced first-login password change + temp pw)
 *   - Hedayati_Staff_Portal      (scoped teacher/TA/reception panel)
 *   - Hedayati_Public_Content    (About/Contact/Consult/Teachers + run opt-in)
 *   - the course/taxonomy/settings manager-capability fixes
 *   - the theme's Phase 3 templates/assets
 *
 * Real WordPress-runtime behaviour is verified by docker/wp-tests/test-phase-3.php
 * and docker/wp-tests/test-launch.php — this file is static/structural only.
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
const braces = (src) => {
	const ob = (src.match(/{/g) || []).length;
	const cb = (src.match(/}/g) || []).length;
	return ob === cb;
};
const parens = (src) => {
	const o = (src.match(/\(/g) || []).length;
	const c = (src.match(/\)/g) || []).length;
	return o === c;
};

console.log('=== NODE.JS PHASE 3 VERIFICATION ===\n');

// ── 1. class-account-security.php ───────────────────────────────────────────
console.log('1. class-account-security.php (Hedayati_Account_Security):');
const sec = readPlugin('includes/class-account-security.php');
assert('declares strict_types', sec.includes('declare( strict_types=1 );'));
assert('has ABSPATH guard', sec.includes("if ( ! defined( 'ABSPATH' ) ) {"));
assert('braces balanced', braces(sec));
assert('parens balanced', parens(sec));
assert('marker constant is a flag key, not a password store', sec.includes("META_MUST_CHANGE = 'hedayati_must_change_password'"));
assert('minimum length is at least 12', /MIN_LENGTH\s*=\s*(1[2-9]|[2-9]\d)/.test(sec));
assert('temp password uses wp_generate_password with special chars', /wp_generate_password\(\s*1[0-9]\s*,\s*true\s*,\s*true\s*\)/.test(sec));
assert('require_change() only ever writes "1" (never a password)', /update_user_meta\(\s*\$user_id,\s*self::META_MUST_CHANGE,\s*'1'\s*\)/.test(sec));
assert('intercept() hooked on template_redirect at high priority', /add_action\(\s*'template_redirect',\s*\[\s*self::class,\s*'intercept'\s*\],\s*1\s*\)/.test(sec));
assert('change handler is an admin-post action', sec.includes("add_action( 'admin_post_' . self::NONCE_ACTION"));
assert('handler verifies a nonce', sec.includes('wp_verify_nonce('));
assert('handler requires the marker before touching the password', sec.includes('if ( ! self::must_change( $user_id ) )'));
assert('validation rejects short + mismatched + login/email-equal passwords', sec.includes('strlen( $new ) < self::MIN_LENGTH') && sec.includes('$new !== $confirm') && sec.includes('user_login'));
assert('password set via wp_set_password (WordPress hashes it)', sec.includes('wp_set_password( $new, $user_id )'));
assert('marker cleared only AFTER wp_set_password', sec.indexOf('wp_set_password( $new, $user_id )') < sec.indexOf('self::clear( $user_id )'));
assert('audits account.password_changed with an explicit actor, no password in note', /Hedayati_Audit_Log::record\(\s*'account\.password_changed',\s*'user',\s*\$user_id,\s*'[^']*',\s*\$user_id\s*\)/.test(sec));
assert('never echoes or logs $new / $confirm outside the form inputs', !/error_log\([^)]*\$(new|confirm)/.test(sec) && !/\becho\b[^;]*\$new\b/.test(sec));
assert('cookie re-issue guarded by headers_sent()', sec.includes('if ( ! headers_sent() )'));
assert('failed change uses PRG (transient + redirect), no uncatchable mid-render exit', sec.includes('set_transient( self::notice_key()') && sec.includes('wp_safe_redirect( home_url'));

// ── 2. bootstrap wiring ────────────────────────────────────────────────────
console.log('\n2. hedayati-core.php wiring:');
const boot = readPlugin('hedayati-core.php');
assert('requires class-account-security.php', boot.includes('includes/class-account-security.php'));
assert('boots Hedayati_Account_Security::init()', boot.includes('Hedayati_Account_Security::init()'));
assert('requires + boots staff portal', boot.includes('includes/class-staff-portal.php') && boot.includes('Hedayati_Staff_Portal::init()'));
assert('requires + boots public content', boot.includes('includes/class-public-content.php') && boot.includes('Hedayati_Public_Content::init()'));
assert('activation hook provisions the public pages', boot.includes('Hedayati_Public_Content::ensure_pages()'));
assert('plugin version >= 1.8.0', /HEDAYATI_CORE_VERSION', '1\.(8|9|\d{2})\./.test(boot) || /HEDAYATI_CORE_VERSION', '[2-9]\./.test(boot));
assert("plugin header 'Version:' matches the constant", (() => {
	const v = boot.match(/HEDAYATI_CORE_VERSION', '([0-9.]+)'/);
	const h = boot.match(/\*\s*Version:\s+([0-9.]+)/);
	return !!v && !!h && v[1] === h[1];
})());

// ── 3. class-staff-portal.php ──────────────────────────────────────────────
console.log('\n3. class-staff-portal.php (Hedayati_Staff_Portal):');
const staff = readPlugin('includes/class-staff-portal.php');
assert('declares strict_types + ABSPATH guard', staff.includes('declare( strict_types=1 );') && staff.includes("if ( ! defined( 'ABSPATH' ) ) {"));
assert('braces balanced', braces(staff));
assert('parens balanced', parens(staff));
assert('no dense multi-statement one-liners left (heuristic: no line > 400 chars)', staff.split('\n').every((l) => l.length <= 400));
assert('every mutation action maps to a capability', /ACTIONS\s*=\s*\[[\s\S]*?'session'\s*=>\s*'hedayati_manage_assigned_sessions'[\s\S]*?'student'\s*=>\s*'hedayati_create_students'[\s\S]*?\]/.test(staff));
assert('verify() checks POST method + capability + nonce', staff.includes("'POST' !== ( $_SERVER['REQUEST_METHOD']") && staff.includes('current_user_can( $capability )') && staff.includes('wp_verify_nonce( $nonce'));
assert('run scope goes through user_is_staff_on_run()', staff.includes('Hedayati_Run_Staff_Service::user_is_staff_on_run( get_current_user_id(), $run_id )'));
assert('can_run() also requires the run to exist', staff.includes('null === Hedayati_Course_Run_Service::get( $run_id )'));
assert('student-record actions require the target to hold the student role', staff.includes("in_array( 'student', (array) $user->roles, true )"));
assert('TA/teacher roster shows names only (comment + no email/phone rendering)', staff.includes('names only') || staff.includes('no email, phone, identity'));
assert('reception create-student generates a temp password (never reads one from POST)', staff.includes('Hedayati_Account_Security::generate_temp_password()') && !/self::post\(\s*'password'\s*\)/.test(staff));
assert('new account is flagged must-change', staff.includes('Hedayati_Account_Security::require_change( $user_id )'));
assert('account creation is audited', staff.includes("Hedayati_Audit_Log::record( 'account.created'"));
assert('temp password shown once via a short-lived transient, deleted on render', staff.includes("set_transient( self::notice_key(), $notice, 45 )") && staff.includes('delete_transient( self::notice_key() )'));
assert('phone-race compensation only deletes the just-created account', staff.includes('wp_delete_user( $user_id )') && staff.includes('Compensate only the account this request just created'));
assert('attendance batch is fully validated before any write', staff.includes('Validate the whole batch') && staff.includes('self::deny();'));
assert('student search is POST (not GET) so PII stays out of access logs', staff.includes("method=\"post\"") && staff.includes('never land in an access log URL'));

// ── 4. class-public-content.php ────────────────────────────────────────────
console.log('\n4. class-public-content.php (Hedayati_Public_Content):');
const pub = readPlugin('includes/class-public-content.php');
assert('declares strict_types + ABSPATH guard', pub.includes('declare( strict_types=1 );') && pub.includes("if ( ! defined( 'ABSPATH' ) ) {"));
assert('braces balanced', braces(pub));
assert('nothing public by default — teachers need publish status AND the opt-in meta', pub.includes("'post_status'    => 'publish'") && pub.includes("'meta_key'       => self::META_PUBLIC_TEACHER") && pub.includes("'meta_value'     => '1'"));
assert('runs() projects to exactly start_date / tuition_rial / registration_status', /'start_date'\s*=>\s*\$run\['start_date'\],\s*'tuition_rial'\s*=>\s*\$run\['tuition_rial'\],\s*'registration_status'\s*=>\s*\$run\['registration_status'\],/.test(pub));
assert('runs() never exposes roster/attendance/capacity/notes', !/capacity|roster|attendance|internal_note|'notes'/.test(pub.replace(/\/\*[\s\S]*?\*\//g, '')));
assert('runs() only surfaces scheduled/in-progress runs of a published course', pub.includes("RUN_STATUSES_PUBLIC = [ 'scheduled', 'in_progress' ]") && pub.includes("'publish' !== get_post_status( $course_id )"));
assert('save_box() checks nonce + edit_post capability', pub.includes('wp_verify_nonce( $nonce, self::NONCE_ACTION )') && pub.includes("current_user_can( 'edit_post', $post_id )"));
assert('approved run ids are validated to belong to the course', pub.includes("(int) $run['course_id'] === $post_id"));
assert('ensure_pages gated behind manage_options on admin_init', pub.includes("current_user_can( 'manage_options' )"));

// ── 5. manager capability fixes ───────────────────────────────────────────
console.log('\n5. course / taxonomy / settings capability consistency:');
const pt = readPlugin('includes/class-post-types.php');
assert('course CPT uses a dedicated capability map (map_meta_cap => true)', pt.includes("'map_meta_cap'       => true") || pt.includes("'map_meta_cap'      => true"));
assert('course primitives point at hedayati_manage_courses', /'edit_others_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt) && /'create_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt));
assert('course status-conditional caps declared (HD-006 pattern)', /'edit_published_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt) && /'delete_published_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt) && /'edit_private_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt) && /'delete_private_posts'\s*=>\s*'hedayati_manage_courses'/.test(pt));
const tax = readPlugin('includes/class-taxonomies.php');
assert('course-category taxonomy caps require hedayati_manage_courses', /'manage_terms'\s*=>\s*'hedayati_manage_courses'/.test(tax) && /'assign_terms'\s*=>\s*'hedayati_manage_courses'/.test(tax));
const termMeta = readPlugin('includes/class-term-meta.php');
assert('term-meta save no longer requires core manage_categories', !termMeta.includes("current_user_can( 'manage_categories' )") && termMeta.includes("current_user_can( 'hedayati_manage_courses' )"));
const settings = readPlugin('includes/class-settings.php');
assert('settings capability is hedayati_manage_settings', settings.includes("CAPABILITY   = 'hedayati_manage_settings'"));
assert('settings also filters option_page_capability for options.php', settings.includes("option_page_capability_' . self::OPTION_GROUP"));

// ── 6. roles ───────────────────────────────────────────────────────────────
console.log('\n6. class-roles.php:');
const roles = readPlugin('includes/class-roles.php');
assert("ROLES_VERSION is 2.3.0 (hedayati_create_students added)", roles.includes("ROLES_VERSION = '2.3.0'"));
assert('hedayati_create_students granted to reception + manager only', /'reception'[\s\S]*?'hedayati_create_students'\s*=>\s*true/.test(roles) && /'hedayati_manager'[\s\S]*?'hedayati_create_students'\s*=>\s*true/.test(roles));
assert('hedayati_create_students NOT granted to student / teacher / TA', (() => {
	const def = roles.match(/get_roles_definition\(\): array \{\s*return \[([\s\S]*?)\n\t\t\];/);
	if (!def) return false;
	const block = (slug) => {
		const m = def[1].match(new RegExp(`'${slug}'\\s*=>\\s*\\[[\\s\\S]*?'capabilities'\\s*=>\\s*\\[([\\s\\S]*?)\\],`));
		return m ? m[1] : '__MISSING__';
	};
	return ['student', 'teacher', 'teacher_assistant'].every((r) => !block(r).includes('hedayati_create_students'));
})());
assert('managed capability list has exactly 24 entries', (() => {
	const m = roles.match(/get_all_hedayati_capabilities\(\): array \{\s*return \[([\s\S]*?)\];/);
	return m && (m[1].match(/'hedayati_[a-z_]+'/g) || []).length === 24;
})());

// ── 7. audit vocabulary ───────────────────────────────────────────────────
console.log('\n7. class-audit-log.php:');
const audit = readPlugin('includes/class-audit-log.php');
assert("'account' object type registered", /'account',/.test(audit));
assert("account.created + account.password_changed actions registered", audit.includes("'account.created'") && audit.includes("'account.password_changed'"));
assert('still no ip / user-agent column (D39)', !/'ip_address'|'user_agent'/.test(audit));

// ── 8. theme templates / assets ───────────────────────────────────────────
console.log('\n8. theme:');
const fnc = readTheme('functions.php');
assert('theme version bumped to >= 1.2.0', /HEDAYATI_VERSION', '1\.(2|3|\d{2})\./.test(fnc) || /HEDAYATI_VERSION', '[2-9]\./.test(fnc));
assert('account assets also load during a forced password change', fnc.includes('Hedayati_Account_Security') && fnc.includes('must_change( get_current_user_id() )'));
assert('public-pages.css enqueued', fnc.includes("'hedayati-public-pages'"));
const page = readTheme('page.php');
assert('page.php keeps .entry-content so existing block styling still applies', page.includes('entry-content'));
assert('page.php has the shared skip-link target + role=main', page.includes('id="site-main"') && page.includes('role="main"'));
assert('page.php only reveals teachers via Hedayati_Public_Content::teachers()', page.includes('Hedayati_Public_Content::teachers()'));
const single = readTheme('single-course.php');
assert('single-course gates teacher/fee/date behind the publication opt-in', single.includes("_hedayati_public_catalog_details"));
assert('single-course renders the public-runs part', single.includes("get_template_part( 'template-parts/public-runs'"));
assert('single-course shows Shamsi start dates', single.includes('Hedayati_Jalali::format( $start_date )'));
const runsPart = readTheme('template-parts/public-runs.php');
assert('public-runs part guards on the class and non-empty projection', runsPart.includes("class_exists( 'Hedayati_Public_Content' )") && runsPart.includes('Hedayati_Public_Content::runs('));
assert('public-runs part shows Shamsi + Persian-digit fees', runsPart.includes('Hedayati_Jalali::format') && runsPart.includes('Hedayati_Text::digits_to_persian'));
const login = readTheme('assets/css/login.css');
assert('self-hosted Vazirmatn @font-face declared (no CDN)', login.includes('@font-face') && login.includes('Vazirmatn-variable.woff2') && !login.includes('fonts.googleapis'));
assert('Vazirmatn woff2 + OFL license shipped in the theme', fs.existsSync(path.join(THEME_ROOT, 'assets/fonts/Vazirmatn-variable.woff2')) && fs.existsSync(path.join(THEME_ROOT, 'assets/fonts/OFL.txt')));

// ── 9. no revived superseded architecture ─────────────────────────────────
console.log('\n9. architecture guardrails:');
for (const f of ['includes/class-account-security.php', 'includes/class-staff-portal.php', 'includes/class-public-content.php']) {
	const src = readPlugin(f);
	assert(`${f}: no React/Express/Prisma/PostgreSQL`, !/react|express|prisma|postgres/i.test(src));
	assert(`${f}: no direct $wpdb in a class that should use services`, f.includes('public-content') || f.includes('account-security') ? !src.includes('$wpdb') : true);
}

// ── 10. Phase 3 visual-completion pass ────────────────────────────────────
console.log('\n10. visual completion (portal/panel/public polish):');
assert('forced-change screen strips site nav via a body_class filter', sec.includes("add_filter( 'body_class'") && sec.includes("'hd-force-password'"));
assert('forced-change screen hides the WordPress admin bar', sec.includes("'show_admin_bar'") && sec.includes('hide_admin_bar_while_forced'));
const acctCss = readTheme('assets/css/account.css');
assert('account.css: sidebar has min-width:0 (no mobile horizontal overflow)', /\.hd-portal-sidebar\s*\{[^}]*min-width:\s*0/.test(acctCss));
assert('account.css: nav-link cards (a.hd-portal-card) have their own actionable treatment', acctCss.includes('a.hd-portal-card'));
assert('account.css: run/roster/result lists are styled', acctCss.includes('.hd-portal-run-list') && acctCss.includes('.hd-portal-roster') && acctCss.includes('.hd-portal-result-list'));
assert('account.css: compact attendance rows', acctCss.includes('.hd-portal-attendance'));
assert('account.css: one-shot secret code block is display:block / centered', /\.hd-portal-secret code\s*\{[^}]*display:\s*block/.test(acctCss));
assert('account.css: mobile collapses portal cards to one column', /max-width:\s*900px[\s\S]*\.hd-portal-cards\s*\{\s*grid-template-columns:\s*1fr/.test(acctCss));
assert('account.css: forced-change hides header nav/cta', acctCss.includes('.hd-force-password .header-nav'));
const pubCss = readTheme('assets/css/public-pages.css');
assert('public-pages.css: empty page copy is hidden', pubCss.includes('.hd-page-copy:empty'));
assert('public-pages.css: teacher image has a fixed frame', /\.hd-public-card img\s*\{[^}]*(aspect-ratio|object-fit)/.test(pubCss));
assert('public-pages.css: run-status pill classes exist', pubCss.includes('.hd-run-status--open') && pubCss.includes('.hd-run-status--soon'));
assert('public-pages.css: card CTAs bottom-align for a tidy row', /margin-block-start:\s*auto/.test(pubCss));
assert('public-runs.php: uses a section-heading + status pill', runsPart.includes('section-heading') && runsPart.includes('hd-run-status'));
assert('staff-portal: styled result lists + attendance form class + empty states', staff.includes('hd-portal-result-list') && staff.includes("'hd-portal-attendance'") && staff.includes('هنوز دانشجویی در این کلاس'));
assert('staff panel hides the WordPress admin bar', staff.includes("'show_admin_bar'") && staff.includes('hide_admin_bar_on_panel'));
const sp = readPlugin('includes/class-student-portal.php');
assert('student-portal: documents upload has real <label>s', /<label class="hd-portal-field">\s*<span><\?php esc_html_e\( 'نوع مدرک'/.test(sp) && sp.includes("'فایل مدرک"));
assert('student-portal: dashboard has a welcome line + quick-access links', sp.includes('خوش آمدید') && sp.includes('دسترسی سریع'));

// ── summary ───────────────────────────────────────────────────────────────
console.log('\n========================================');
console.log(`PHASE 3 SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log('========================================');
process.exit(failed === 0 ? 0 : 1);
