/**
 * Node.js static & structural verification for D58 — the AI-Studio-style
 * application shell for /panel/ and /account/.
 *
 * Runtime behaviour (capability-filtered nav, every module reachable and
 * rendering, owner-scoped staff inbox) is proven by docker/wp-tests/test-d58.php.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const REPO = path.join(__dirname, '..', '..', '..');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(REPO, rel), 'utf8');
const codeOnly = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
const fnBody = (src, name) => (src.match(new RegExp(`function ${name}\\([\\s\\S]*?\\n\\t\\}`)) || [''])[0];
const balanced = (src) => (src.match(/{/g) || []).length === (src.match(/}/g) || []).length;

console.log('=== NODE.JS D58 VERIFICATION (AI Studio app shell) ===\n');

// ── 1. Plugin: nav model + icons + staff inbox ─────────────────────────────
console.log('1. Plugin navigation model:');
const sp = codeOnly(read('plugin/hedayati-core/includes/class-staff-portal.php'));
const nav = fnBody(sp, 'nav_groups');
assert('nav_groups() exists and is public', /public static function nav_groups\(\): array/.test(sp));
assert('core courses/featured gated exactly like render(): manager workspace + hedayati_manage_courses', /if \( \$is_manager && current_user_can\( 'hedayati_manage_courses' \) \) \{\s*\$core\['courses'\]/.test(nav));
assert('core students gated by hedayati_lookup_students', /current_user_can\( 'hedayati_lookup_students' \) \) \{\s*\$core\['students'\]/.test(nav));
assert('module items gated by their own registered capability', /foreach \( self::module_views\(\) as \$slug => \$module \)[\s\S]*?current_user_can\( \(string\) \$module\['capability'\] \)/.test(nav));
assert('unmapped future modules still appear (never silently dropped)', /\$add_group\( 'other', __\( 'سایر'/.test(nav) && /foreach \( \$available as \$slug => \$label \)/.test(nav));
assert('nav never builds a wp-admin URL', !/admin_url\(/.test(nav));
const badge = fnBody(sp, 'nav_badge');
assert('badges only query what the viewer may open', /Hedayati_Consultation_Service::CAPABILITY/.test(badge) && /Hedayati_Support_Service::STAFF_CAP/.test(badge) && /unread_count\( get_current_user_id\(\) \)/.test(badge));
for (const slug of ['courses', 'featured', 'academic', 'teachers', 'materials', 'certificates', 'students', 'consultations', 'support', 'content', 'navigation', 'settings', 'notifications', 'audit', 'security']) {
	const lists = (sp.match(/NAV_PRIMARY = \[[^\]]*\]/) || [''])[0] + (sp.match(/NAV_GROUPS = \[[\s\S]*?\n\t\];/) || [''])[0];
	assert(`module «${slug}» is placed in the primary list or a secondary group`, lists.includes(`'${slug}'`));
}
const icons = read('plugin/hedayati-core/includes/class-icons.php');
assert('Hedayati_Icons: strict_types, ABSPATH guard, balanced', icons.includes('declare( strict_types=1 );') && icons.includes("if ( ! defined( 'ABSPATH' ) )") && balanced(codeOnly(icons)));
assert('icons are decorative (aria-hidden, focusable=false) and use currentColor', icons.includes('aria-hidden="true"') && icons.includes('focusable="false"') && icons.includes('stroke="currentColor"'));
for (const name of Object.values({ a: 'dashboard', b: 'book', c: 'star', d: 'calendar', e: 'users', f: 'teacher', g: 'folder', h: 'award', i: 'chat', j: 'lifebuoy', k: 'layout', l: 'link', m: 'settings', n: 'bell', o: 'list', p: 'lock', q: 'id', r: 'file', s: 'user', t: 'wordpress', u: 'external', v: 'logout', w: 'menu', x: 'close' })) {
	assert(`icon «${name}» defined`, new RegExp(`'${name}'\\s*=>`).test(icons));
}
const boot = read('plugin/hedayati-core/hedayati-core.php');
assert('icons class loaded before the portals', boot.indexOf("includes/class-icons.php'") > -1 && boot.indexOf("includes/class-icons.php'") < boot.indexOf("includes/class-staff-portal.php'"));
const ns = codeOnly(read('plugin/hedayati-core/includes/class-notification-service.php'));
assert('staff notification inbox registered as a panel module', /\$views\['notifications'\] = \[/.test(ns) && ns.includes("add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] )"));
const inbox = fnBody(ns, 'render_panel');
assert('staff inbox lists ONLY the current user\'s rows', /\$user_id = get_current_user_id\(\);[\s\S]*self::list_for_user\( \$user_id, 40 \)/.test(inbox));
assert('staff inbox actions are nonce-protected admin-post forms', inbox.includes("wp_nonce_field( 'hedayati_notif_read' )") && inbox.includes("wp_nonce_field( 'hedayati_notif_read_all' )"));
assert('staff inbox output is escaped', /esc_html\( \$n\['subject'\] \)/.test(inbox) && /esc_url\( \$n\['url'\] \)/.test(inbox));

// ── 2. Theme: shell, templates, assets ─────────────────────────────────────
console.log('\n2. Theme app shell:');
const shell = read('theme/hedayati/inc/app-shell.php');
assert('app shell never decides access (no current_user_can in the shell)', !/current_user_can\(/.test(codeOnly(shell)));
assert('app shell escapes labels + URLs', /esc_html\( \$item\['label'\] \)/.test(shell) && /esc_url\( \$item\['url'\] \)/.test(shell) && /esc_url\( \$link\['url'\] \)/.test(shell));
assert('active nav item exposes aria-current="page"', shell.includes('aria-current="page"'));
assert('menu button is a real button with aria-controls/aria-expanded', /class="hd-app-icon-btn hd-app-menu-btn" type="button" aria-controls="hd-app-rail" aria-expanded="false"/.test(shell));
assert('topbar reuses #theme-toggle so main.js keeps working', shell.includes('id="theme-toggle"') && shell.includes('icon-sun') && shell.includes('icon-moon'));
assert('app shell never used during a forced password change', /must_change\( get_current_user_id\(\) \)[\s\S]{0,80}\$is_app = false/.test(shell));
const panel = read('theme/hedayati/page-panel.php');
assert('page-panel.php renders nav from Hedayati_Staff_Portal::nav_groups()', panel.includes('Hedayati_Staff_Portal::nav_groups()'));
assert('page-panel.php still renders through Hedayati_Staff_Portal::render()', panel.includes('Hedayati_Staff_Portal::render()'));
assert('page-panel.php keeps #site-main + logout via wp_logout_url()', panel.includes('id="site-main"') && panel.includes('wp_logout_url('));
const account = read('theme/hedayati/page-account.php');
const views = (read('plugin/hedayati-core/includes/class-student-portal.php').match(/public const VIEWS = \[([^\]]*)\]/) || ['', ''])[1].match(/'([a-z-]+)'/g) || [];
assert(`student VIEWS parsed (${views.length})`, views.length >= 9);
for (const v of views) {
	assert(`student nav keeps view ${v}`, new RegExp(`${v.replace(/'/g, "'")}\\s*=>\\s*\\[ __\\(`).test(account));
}
assert('page-account.php re-validates views against Hedayati_Student_Portal::VIEWS', account.includes('Hedayati_Student_Portal::VIEWS'));
const header = read('theme/hedayati/header.php');
const footer = read('theme/hedayati/footer.php');
assert('header.php skips the public header in the app shell', /<\?php if \( ! \$hd_is_auth_shell && ! \$hd_is_app_shell \) :/.test(header));
assert('footer.php renders no public footer in the app shell but still calls wp_footer()', /<\?php if \( \$hd_is_app_shell \) :/.test(footer) && footer.includes('wp_footer();'));
const fns = read('theme/hedayati/functions.php');
assert('app.css enqueued only for the app shell, after account.css', /if \( hedayati_is_app_shell\(\) \) \{[\s\S]{0,200}'hedayati-app',[\s\S]{0,120}\[ 'hedayati-account' \]/.test(fns));
assert('body class hd-app-page added only in the app shell', /if \( hedayati_is_app_shell\(\) \) \{\s*\$classes\[\] = 'hd-app-page';/.test(fns));

// ── 3. CSS/JS quality gates ────────────────────────────────────────────────
console.log('\n3. CSS / JS:');
const css = read('theme/hedayati/assets/css/app.css');
const main = read('theme/hedayati/assets/css/main.css');
assert('app.css uses --hd-* tokens and defines no dark-mode block of its own (centralized in main.css)', /var\(--hd-/.test(css) && !/prefers-color-scheme|\[data-theme/.test(css));
assert('rail colours are tokens in BOTH main.css scheme blocks', /:root\s*\{[\s\S]*--hd-app-rail:\s*#17181b;[\s\S]*\}\s*\[data-theme="dark"\]\s*\{[\s\S]*--hd-app-rail:\s*#0b0c0e;/.test(main));
assert('rail is fixed-width 260px (AI Studio) and sticky full height', /grid-template-columns: 260px minmax\(0, 1fr\)/.test(css) && /\.hd-app-rail \{[\s\S]*?position: sticky;[\s\S]*?height: 100vh;/.test(css));
assert('rail becomes an off-canvas drawer below 1024px', /@media \(max-width: 1023px\)[\s\S]*\.hd-app-rail \{[\s\S]*position: fixed;[\s\S]*visibility: hidden;/.test(css) && /\.hd-app\.is-rail-open \.hd-app-rail \{[\s\S]*?visibility: visible;/.test(css));
assert('logical properties for RTL (no physical left/right offsets)', !/(^|[\s;{])(left|right)\s*:/m.test(css) && !/margin-(left|right)|padding-(left|right)/.test(css));
assert('no horizontal-overflow traps: main column + content are min-width:0', /\.hd-app-main \{[\s\S]*?min-width: 0;/.test(css) && /\.hd-app \.hd-portal-content \{[\s\S]*?min-width: 0;/.test(css));
assert('visible keyboard focus on rail links and icon buttons', /\.hd-app-nav-link:focus-visible,[\s\S]*?outline: 2px solid var\(--hd-red\)/.test(css));
assert('reduced-motion respected', /prefers-reduced-motion: reduce/.test(css));
assert('rail muted text keeps ≥4.5:1 on the rail (token #878e9b or lighter)', /--hd-app-rail-muted:\s*#878e9b;/.test(main));
const js = codeOnly(read('theme/hedayati/assets/js/account.js')).trim();
assert('account.js still a single IIFE, no jQuery', /^\(function \(\) \{[\s\S]*\}\(\)\);\s*$/.test(js) && !/jQuery|\$\(/.test(js));
assert('drawer: Escape closes, backdrop/close button close, focus returns to the menu button', /'Escape' === event\.key/.test(js) && js.includes("querySelectorAll('[data-hd-app-close]')") && /menuBtn\.focus\(\)/.test(js));
assert('drawer keeps aria-expanded in sync', /menuBtn\.setAttribute\('aria-expanded', open \? 'true' : 'false'\)/.test(js));

// ── 3b. Reference-fidelity pass (logo, hierarchy, dashboards) ─────────────
console.log('\n3b. Reference fidelity:');
assert('primary rail list follows the AI Studio order', /NAV_PRIMARY = \[ 'courses', 'featured', 'consultations', 'students', 'certificates', 'settings' \]/.test(sp));
assert('secondary groups are collapsible <details> (no JS) and open when they hold the active view', shell.includes('<details class="hd-app-nav-group"') && /\$open = \$open \|\| \$i\['active'\]/.test(nav));
assert('rail shows the real institute logo: WordPress Custom Logo first, bundled emblem otherwise', /get_theme_mod\( 'custom_logo' \)/.test(shell) && shell.includes('/assets/images/logo-mark-96.png') && shell.includes('/assets/images/logo-mark-192.png'));
assert('bundled logo files are small (<40KB each)', ['96', '192'].every((n) => fs.statSync(path.join(REPO, `theme/hedayati/assets/images/logo-mark-${n}.png`)).size < 40000));
const home = fnBody(sp, 'render_manager_home');
assert('manager home: exactly four headline KPI tiles', /\$kpis\s+= array_slice\( \$metrics, 0, 4 \)/.test(home));
assert('manager home: quick actions capped at four and capability-filtered', /array_filter\( \$actions, static fn\( \$a \) => current_user_can\( \$a\[0\] \) \)/.test(home) && /, 0, 4 \);/.test(home));
assert('manager home: featured count against the server-side FEATURED_LIMIT', /self::featured_count\(\)[\s\S]{0,120}self::FEATURED_LIMIT/.test(home));
const stu = codeOnly(read('plugin/hedayati-core/includes/class-student-portal.php'));
const dash = fnBody(stu, 'render_dashboard_view');
assert('student rows: progress from Hedayati_Progress_Service (real held/total sessions)', /Hedayati_Progress_Service::run_progress\(/.test(dash) && /Hedayati_Progress_Service::percent\(/.test(dash));
assert('student rows: no progress bar or percentage when there is no session basis', /if \( null !== \$percent \) :/.test(dash) && dash.includes('برنامهٔ جلسات ثبت نشده'));
assert('student next-class card uses real upcoming sessions only (no reminder feature invented)', /\$upcoming\[0\]/.test(dash) && !/یادآور/.test(dash));
assert('student certificate card shows only VALID certificates of this user', /Hedayati_Certificate_Service::list_for_user\( \$user_id \)[\s\S]{0,120}'valid' === \$c\['status'\]/.test(dash));
assert('no AI Studio demo data copied (68%/DH-1404/ICDL/sample student strings)', !/68|DH-1404|ICDL|دانشجوی نمونه/.test(dash + home));
assert('dashboard output escaped', (dash.match(/esc_html\(/g) || []).length >= 10 && /esc_url\( self::get_account_url\(/.test(dash));

// ── 4. Runtime coverage wired ──────────────────────────────────────────────
console.log('\n4. Runtime coverage:');
const run = read('docker/wp-tests/run.php');
const rt = read('docker/wp-tests/test-d58.php');
assert('test-d58.php wired into run.php', run.includes("require __DIR__ . '/test-d58.php';") && run.includes('hdit_run_d58();'));
for (const probe of ['every nav item is a view this role may open', 'every view this role may open is reachable from the nav', 'never shows another user', 'renders', 'student view']) {
	assert(`runtime test covers: ${probe}`, rt.includes(probe));
}

console.log(`\n========================================`);
console.log(`D58 SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
