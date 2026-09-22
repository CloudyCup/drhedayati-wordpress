/**
 * Node.js static & structural verification for D57:
 *   1. administrator's normal login lands on /panel/ (wp-admin stays deliberate);
 *   2. user-created menus survive theme/plugin replacement + the panel editor.
 *
 * Real WordPress-runtime behaviour is proven by docker/wp-tests/test-d57.php —
 * this file is static/structural only.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const PLUGIN_ROOT = path.join(__dirname, '..');
const REPO_ROOT = path.join(PLUGIN_ROOT, '..', '..');
let passed = 0;
let failed = 0;
const assert = (d, c) => { if (c) { console.log(`  [PASS] ${d}`); passed++; } else { console.error(`  [FAIL] ${d}`); failed++; } };
const read = (rel) => fs.readFileSync(path.join(REPO_ROOT, rel), 'utf8');
const codeOnly = (src) => src.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
const fnBody = (src, name) => (src.match(new RegExp(`function ${name}\\([\\s\\S]*?\\n\\t\\}`)) || [''])[0];
const balanced = (src) => (src.match(/{/g) || []).length === (src.match(/}/g) || []).length;

console.log('=== NODE.JS D57 VERIFICATION (admin landing + menu persistence) ===\n');

// ── 1. Administrator login landing ─────────────────────────────────────────
console.log('1. Administrator post-login routing:');
const staff = codeOnly(read('plugin/hedayati-core/includes/class-staff-portal.php'));
const loginRedirect = fnBody(staff, 'login_redirect');
assert('Staff portal login_redirect handles manage_options explicitly', /user_can\( \$user, 'manage_options' \)/.test(loginRedirect));
assert('administrator → panel ONLY when no redirect_to was requested', /'' === \$requested && self::panel_page_exists\(\) \? self::url\(\) : \$url/.test(loginRedirect));
assert('administrator with a requested destination keeps it (deliberate /wp-admin/ honored)', /: \$url;/.test(loginRedirect));
assert('staff roles still always → panel', /hedayati_view_assigned_runs[\s\S]*hedayati_lookup_students[\s\S]*return self::url\(\);/.test(loginRedirect));
assert('panel landing guarded by a real, published /panel/ page (never a 404 landing)', /function panel_page_exists\(\): bool \{[\s\S]*get_page_by_path\( self::PAGE_SLUG \)[\s\S]*'publish' === \$page->post_status/.test(staff));
const login = codeOnly(read('plugin/hedayati-core/includes/class-login.php'));
assert('/login/ still routes through the login_redirect filter chain', /apply_filters\( 'login_redirect', \$fallback, \$requested, \$user \)/.test(login));
assert('D56 loop guards untouched', /if \( self::points_to_login_page\( \$requested \) \)/.test(login) && /if \( self::points_to_login_page\( \$dest \) \)/.test(login));
assert('redirect_to still validated (no open redirect)', /wp_validate_redirect\( \$requested, '' \)/.test(login));
const access = codeOnly(read('plugin/hedayati-core/includes/class-admin-access.php'));
assert('wp-admin access for manage_options users is still never restricted', (access.match(/user_can\( \$user, 'manage_options' \)/g) || []).length >= 3);
const panelTpl = read('theme/hedayati/page-panel.php');
assert('panel sidebar offers a deliberate wp-admin link to administrators only', /current_user_can\( 'manage_options' \)[\s\S]{0,200}admin_url\(\)/.test(panelTpl));

// ── 2. Menu guardian ───────────────────────────────────────────────────────
console.log('\n2. class-menu-guardian.php:');
const gRaw = read('plugin/hedayati-core/includes/class-menu-guardian.php');
const g = codeOnly(gRaw);
assert('strict_types + ABSPATH guard + balanced braces', gRaw.includes('declare( strict_types=1 );') && gRaw.includes("if ( ! defined( 'ABSPATH' ) ) {") && balanced(g));
assert('observes deliberate assignments via pre_set_theme_mod_nav_menu_locations', g.includes("add_filter( 'pre_set_theme_mod_nav_menu_locations', [ self::class, 'remember' ], 10, 2 )"));
assert('restores after core _wp_menus_changed (after_switch_theme priority 20)', g.includes("add_action( 'after_switch_theme', [ self::class, 'restore' ], 20 )"));
assert('restores on admin_init (theme replaced under a new folder / fresh theme_mods)', g.includes("add_action( 'admin_init', [ self::class, 'restore' ] )"));
assert('forgets deleted menus', g.includes("add_action( 'wp_delete_nav_menu', [ self::class, 'forget_menu' ] )"));
const remember = fnBody(g, 'remember');
assert('remember() never records core switch-time mapping as deliberate', /doing_action\( 'after_switch_theme' \)/.test(remember) && /doing_action\( 'switch_theme' \)/.test(remember));
assert('remember() returns the value unchanged (observer only)', /return \$value;\s*\}$/.test(remember.trim()));
assert('remember() records a deliberate unassignment', /unset\( \$memory\[ \(string\) \$location \] \)/.test(remember));
const restore = fnBody(g, 'restore');
assert('restore() never overwrites a real assignment', /if \( \$assigned > 0 && self::menu_exists\( \$assigned \) \) \{\s*continue;/.test(restore));
assert('restore() only re-applies a remembered menu that still exists', /\$remembered > 0 && self::menu_exists\( \$remembered \)/.test(restore));
assert('restore() writes only when something was actually restored', /if \( ! empty\( \$restored \) \)/.test(restore));
assert('guardian never creates, edits or deletes menus or items', !/wp_create_nav_menu|wp_update_nav_menu_item|wp_delete_post|wp_delete_nav_menu\(|wp_insert_post/.test(g));
const boot = read('plugin/hedayati-core/hedayati-core.php');
assert('guardian loaded + initialised', boot.includes("includes/class-menu-guardian.php'") && boot.includes('Hedayati_Menu_Guardian::init();'));
assert('plugin activation runs the idempotent restore()', /register_activation_hook[\s\S]*Hedayati_Menu_Guardian::restore\(\);[\s\S]*flush_rewrite_rules\(\);/.test(boot));

// ── 3. Navigation panel no longer destroys item data ───────────────────────
console.log('\n3. class-navigation-panel.php:');
const nRaw = read('plugin/hedayati-core/includes/class-navigation-panel.php');
const n = codeOnly(nRaw);
assert('balanced braces', balanced(n));
const itemArgs = fnBody(n, 'item_args');
for (const k of ['menu-item-object-id', 'menu-item-object', 'menu-item-parent-id', 'menu-item-position', 'menu-item-type', 'menu-item-classes', 'menu-item-target', 'menu-item-xfn', 'menu-item-attr-title', 'menu-item-description', 'menu-item-status']) {
	assert(`item_args() preserves ${k}`, itemArgs.includes(`'${k}'`));
}
const save = fnBody(n, 'handle_save_item');
assert('save: existing item starts from item_args() (no forced type=custom)', /\$args = self::item_args\( \$existing \);/.test(save));
assert('save: URL only changes for custom links', /if \( 'custom' === \$existing->type \) \{\s*\$args\['menu-item-url'\] = \$url;/.test(save));
assert('save: existing custom link still requires a valid URL', /'custom' === \$existing->type && '' === \$url/.test(save));
assert('save: still gated by guard_action + item_is_managed', /guard_action\( self::NONCE_SAVE_ITEM, self::CAPABILITY \)/.test(save) && /! self::item_is_managed\( \$item_id \)/.test(save));
assert('save: still routes URLs through sanitize_url()', /self::sanitize_url\( \$raw_url \)/.test(save));
const move = fnBody(n, 'handle_move_item');
assert('move: swaps only siblings (same parent)', /menu_item_parent === \(int\) \$target->menu_item_parent/.test(move));
assert('move: preserves every field and changes only position', /self::item_args\( \$a \)/.test(move) && /self::item_args\( \$b \)/.test(move) && !/'menu-item-type'\s*=>\s*'custom'/.test(move));
const ensure = fnBody(n, 'ensure_menu_for_location');
assert('ensure: re-attaches remembered menu before creating a new one', ensure.indexOf('Hedayati_Menu_Guardian::restore()') > -1 && ensure.indexOf('Hedayati_Menu_Guardian::restore()') < ensure.indexOf('wp_create_nav_menu'));
assert('ensure: adopts an existing same-named menu before creating a new one', ensure.indexOf('wp_get_nav_menu_object( self::LOCATIONS[ $location ] )') > -1 && ensure.indexOf('wp_get_nav_menu_object( self::LOCATIONS[ $location ] )') < ensure.indexOf('wp_create_nav_menu'));
const assign = fnBody(n, 'handle_assign_menu');
assert('assign: nonce + capability gated', /guard_action\( self::NONCE_ASSIGN_MENU, self::CAPABILITY \)/.test(assign));
assert('assign: only the two managed locations, only real nav_menu terms', /! isset\( self::LOCATIONS\[ \$location \] \) \|\| ! \$menu instanceof WP_Term/.test(assign));
assert('assign: never replaces a live assignment', /if \( null !== self::menu_for_location\( \$location \) \)/.test(assign));
assert('assign: audited', /Hedayati_Audit_Log::record\( 'nav_menu.assigned'/.test(assign) && n.includes("'nav_menu.assigned'"));
assert('no new menu storage: only core nav_menu APIs + theme_mod', !/add_option\(|\$wpdb/.test(n));

// ── 4. Theme: fallbacks are output-only, no provisioning ───────────────────
console.log('\n4. Theme menu code:');
const fb = codeOnly(read('theme/hedayati/inc/menu-fallbacks.php'));
assert('fallbacks never write menu data', !/set_theme_mod|wp_create_nav_menu|wp_update_nav_menu_item|update_option/.test(fb));
const fns = codeOnly(read('theme/hedayati/functions.php'));
assert('theme registers primary + footer locations', /register_nav_menus\([\s\S]*'primary'[\s\S]*'footer'/.test(fns));
assert('theme has no activation hook that seeds/resets menus', !/after_switch_theme|set_theme_mod\( 'nav_menu_locations'/.test(fns));

// ── 5. Runtime coverage exists ─────────────────────────────────────────────
console.log('\n5. Runtime acceptance coverage:');
const rt = read('docker/wp-tests/test-d57.php');
const run = read('docker/wp-tests/run.php');
assert('test-d57.php is wired into run.php', run.includes("require __DIR__ . '/test-d57.php';") && run.includes('hdit_run_d57();'));
for (const probe of ['3× activation/provisioning', 'simulated theme replacement', 'after_switch_theme', 'NEVER overwritten', 'deliberate unassignment', 'moved page link is still a post_type link', 'no new (empty) menu was created', 'never replaces a live assignment', 'administrator /login/ with no redirect_to → /panel/', 'deliberate redirect_to=/wp-admin/ is honored']) {
	assert(`runtime test covers: ${probe}`, rt.includes(probe));
}

console.log(`\n========================================`);
console.log(`D57 SUMMARY: ${passed} PASSED, ${failed} FAILED`);
console.log(`========================================`);
if (failed > 0) process.exit(1);
