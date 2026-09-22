<?php
/**
 * D57 runtime acceptance — administrator login landing + menu persistence.
 *
 * 1. Administrator's NORMAL login lands on /panel/; a deliberate wp-admin
 *    redirect_to is honored; wp-admin itself stays unrestricted for admins;
 *    every other role's routing is unchanged.
 * 2. A user-created menu survives repeated activation/provisioning logic, a
 *    wiped/fresh theme_mods row (theme ZIP replaced under a new folder, core
 *    fallback theme switch), core's after_switch_theme mapping, and the
 *    /panel/?view=navigation editor — and is never overwritten.
 *
 * Every menu, option and theme_mod touched here is snapshotted first and put
 * back at the end, so the suite leaves the disposable site as it found it.
 *
 * @package Hedayati_Core\LocalTest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 2 );
}

/** Thrown by the redirect / wp_die interceptors so a handler's exit never kills the run. */
final class HDIT_D57_Halt extends RuntimeException {
	public function __construct( public readonly string $kind, public readonly string $target = '' ) {
		parent::__construct( $kind . ' ' . $target );
	}
}

/**
 * Runs a panel admin-post handler in-process as $user_id with a valid nonce.
 *
 * @return string 'redirect' | 'die'
 */
function hdit_d57_post( string $handler, string $nonce_action, int $user_id, array $post ): string {
	wp_set_current_user( $user_id );
	$prev_method               = $_SERVER['REQUEST_METHOD'] ?? null;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST                     = array_merge( $post, [ '_wpnonce' => wp_create_nonce( $nonce_action ), 'action' => $nonce_action ] );
	$_REQUEST                  = $_POST;

	$on_redirect = static function ( $location ) {
		throw new HDIT_D57_Halt( 'redirect', (string) $location );
	};
	$on_die      = static fn() => static function () {
		throw new HDIT_D57_Halt( 'die' );
	};
	// WP-CLI's own wp_redirect handler only prints a backtrace warning; ours throws instead.
	$cli_handler     = 'WP_CLI\Utils\wp_redirect_handler';
	$cli_handler_pri = has_filter( 'wp_redirect', $cli_handler );
	if ( false !== $cli_handler_pri ) {
		remove_filter( 'wp_redirect', $cli_handler, $cli_handler_pri );
	}
	add_filter( 'wp_redirect', $on_redirect, PHP_INT_MAX );
	add_filter( 'wp_die_handler', $on_die, PHP_INT_MAX );

	$kind = 'none';
	try {
		call_user_func( [ 'Hedayati_Navigation_Panel', $handler ] );
	} catch ( HDIT_D57_Halt $h ) {
		$kind = $h->kind;
	} finally {
		remove_filter( 'wp_redirect', $on_redirect, PHP_INT_MAX );
		if ( false !== $cli_handler_pri ) {
			add_filter( 'wp_redirect', $cli_handler, $cli_handler_pri );
		}
		remove_filter( 'wp_die_handler', $on_die, PHP_INT_MAX );
		$_POST    = [];
		$_REQUEST = [];
		if ( null === $prev_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $prev_method;
		}
		wp_set_current_user( 0 );
	}
	return $kind;
}

function hdit_d57_item( int $menu_id, int $item_id ): ?WP_Post {
	foreach ( (array) wp_get_nav_menu_items( $menu_id, [ 'post_status' => 'any' ] ) as $item ) {
		if ( (int) $item->ID === $item_id ) {
			return $item;
		}
	}
	return null;
}

function hdit_run_d57(): void {
	$adm  = HDIT_Env::make_user( 'd57_admin', 'administrator' );
	$mgr  = HDIT_Env::make_user( 'd57_mgr', 'hedayati_manager' );
	$rcpt = HDIT_Env::make_user( 'd57_rcpt', 'reception' );
	$tchr = HDIT_Env::make_user( 'd57_tchr', 'teacher' );
	$ta   = HDIT_Env::make_user( 'd57_ta', 'teacher_assistant' );
	$stu  = HDIT_Env::make_user( 'd57_stu', 'student' );

	Hedayati_Staff_Portal::ensure_page();

	// ── 1. Administrator login landing ─────────────────────────────────────
	HDIT::section( 'D57 — administrator normal login lands on /panel/, wp-admin stays deliberate' );

	$dest  = new ReflectionMethod( 'Hedayati_Login', 'post_login_destination' );
	$dest->setAccessible( true );
	$panel = Hedayati_Staff_Portal::url();
	$path  = static fn( string $u ): string => trailingslashit( (string) wp_parse_url( $u, PHP_URL_PATH ) );

	$admin_user = get_userdata( $adm );
	HDIT::eq( 'administrator /login/ with no redirect_to → /panel/', $path( $panel ), $path( $dest->invoke( null, $admin_user, '' ) ) );
	HDIT::eq( 'core wp-login.php login_redirect chain (default admin_url(), no request) → /panel/ for administrator', $path( $panel ), $path( apply_filters( 'login_redirect', admin_url(), '', $admin_user ) ) );
	HDIT::eq( 'administrator deliberate redirect_to=/wp-admin/ is honored', admin_url(), $dest->invoke( null, $admin_user, admin_url() ) );
	$deep = admin_url( 'edit.php?post_type=course' );
	HDIT::eq( 'administrator deliberate deep wp-admin redirect_to (auth_redirect) is honored', $deep, $dest->invoke( null, $admin_user, $deep ) );
	HDIT::ok( 'administrator redirect_to pointing at /login/ itself still never loops (D56 guard intact)', $path( Hedayati_Login::url() ) !== $path( $dest->invoke( null, $admin_user, Hedayati_Login::url() ) ) );
	HDIT::ok( 'administrator off-site redirect_to is rejected (no open redirect)', ! str_contains( $dest->invoke( null, $admin_user, 'https://evil.example/' ), 'evil.example' ) );

	foreach ( [ 'hedayati_manager' => $mgr, 'reception' => $rcpt, 'teacher' => $tchr, 'teacher_assistant' => $ta ] as $role => $uid ) {
		HDIT::eq( "{$role} normal login still → /panel/", $path( $panel ), $path( $dest->invoke( null, get_userdata( $uid ), '' ) ) );
	}
	wp_set_current_user( $stu );
	HDIT::ok( 'student normal login still → /account/', str_contains( $dest->invoke( null, get_userdata( $stu ), '' ), '/account/' ) );
	wp_set_current_user( 0 );

	wp_set_current_user( $adm );
	HDIT::ok( 'administrator is never subject to the interactive wp-admin redirect', ! Hedayati_Admin_Access::redirect_is_enforced_for_current_user() );
	HDIT::ok( 'administrator retains manage_options (full wp-admin)', current_user_can( 'manage_options' ) );
	HDIT::ok( 'administrator is allowed into /panel/', Hedayati_Staff_Portal::allowed() );
	wp_set_current_user( 0 );
	foreach ( [ $mgr, $rcpt, $tchr, $ta, $stu ] as $uid ) {
		wp_set_current_user( $uid );
		HDIT::ok( 'non-admin role (' . implode( ',', get_userdata( $uid )->roles ) . ') is still redirected out of wp-admin', Hedayati_Admin_Access::redirect_is_enforced_for_current_user() );
	}
	wp_set_current_user( 0 );

	// ── 2. Menu persistence ────────────────────────────────────────────────
	HDIT::section( 'D57 — user-created menus survive activation, provisioning, theme replacement and the panel editor' );

	$stylesheet    = get_stylesheet();
	$mods_option   = 'theme_mods_' . $stylesheet;
	$saved_mods    = get_option( $mods_option, null );
	$saved_memory  = get_option( Hedayati_Menu_Guardian::OPTION, null );
	$saved_widgets = get_option( 'sidebars_widgets', null ); // core's after_switch_theme also re-maps widgets
	$created_menus = [];

	try {
		Hedayati_Public_Content::ensure_pages();
		$about = get_page_by_path( 'about' );

		$menu_a = wp_create_nav_menu( 'HDIT D57 custom top menu' );
		HDIT::not_wp_error( 'a custom menu is created with core APIs', $menu_a );
		$created_menus[] = (int) $menu_a;

		$i_custom = wp_update_nav_menu_item( $menu_a, 0, [
			'menu-item-title'   => 'Custom Link',
			'menu-item-url'     => home_url( '/custom-landing/' ),
			'menu-item-type'    => 'custom',
			'menu-item-status'  => 'publish',
			'menu-item-classes' => 'hd-user-class',
			'menu-item-target'  => '_blank',
			'menu-item-position' => 1,
		] );
		$i_page = wp_update_nav_menu_item( $menu_a, 0, [
			'menu-item-object-id' => (int) $about->ID,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => 2,
		] );
		$i_child = wp_update_nav_menu_item( $menu_a, 0, [
			'menu-item-title'     => 'Child Link',
			'menu-item-url'       => home_url( '/child/' ),
			'menu-item-type'      => 'custom',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => (int) $i_custom,
			'menu-item-position'  => 3,
		] );

		$locations            = get_theme_mod( 'nav_menu_locations', [] );
		$locations['primary'] = (int) $menu_a;
		set_theme_mod( 'nav_menu_locations', $locations );

		$assigned = static fn(): int => (int) ( get_nav_menu_locations()['primary'] ?? 0 );
		$count    = static fn( int $m ): int => count( (array) wp_get_nav_menu_items( $m ) );

		HDIT::eq( 'guardian remembered the deliberate primary assignment', (int) $menu_a, (int) ( Hedayati_Menu_Guardian::memory()['primary'] ?? 0 ) );

		// Repeated activation / upgrade / provisioning logic.
		for ( $run = 1; $run <= 3; $run++ ) {
			Hedayati_Roles::register_roles();
			Hedayati_Student_Portal::maybe_create_account_page();
			Hedayati_Staff_Portal::ensure_page();
			Hedayati_Public_Content::ensure_pages();
			Hedayati_Login::maybe_create_page();
			Hedayati_Menu_Guardian::restore();
		}
		HDIT::eq( 'after 3× activation/provisioning: primary still points at the user menu', (int) $menu_a, $assigned() );
		HDIT::eq( 'after 3× activation/provisioning: all 3 user items still present', 3, $count( (int) $menu_a ) );

		// Theme ZIP replaced under a different folder / core fallback switch → fresh theme_mods row.
		delete_option( $mods_option );
		HDIT::eq( 'simulated theme replacement: the location really is empty now', 0, $assigned() );
		ob_start(); // the theme fallback echoes regardless of 'echo' => false
		$fallback_html = wp_nav_menu( [ 'theme_location' => 'primary', 'echo' => false, 'fallback_cb' => function_exists( 'hedayati_primary_menu_fallback' ) ? 'hedayati_primary_menu_fallback' : false ] );
		HDIT::eq( 'rendering the fallback menu does not write theme_mods', false, get_option( $mods_option, false ) );
		ob_end_clean();
		unset( $fallback_html );
		$restored = Hedayati_Menu_Guardian::restore();
		HDIT::ok( 'guardian restores the primary assignment', in_array( 'primary', $restored, true ) );
		HDIT::eq( 'primary re-attached to the SAME user menu (no new menu created)', (int) $menu_a, $assigned() );
		HDIT::eq( 'items untouched by the restore', 3, $count( (int) $menu_a ) );

		// Core after_switch_theme mapping with nothing to map (switch back from a block theme).
		update_option( 'theme_switch_menu_locations', [] );
		delete_option( $mods_option );
		do_action( 'after_switch_theme', $stylesheet, wp_get_theme() );
		HDIT::eq( 'after_switch_theme (core mapping + guardian) ends with the user menu assigned', (int) $menu_a, $assigned() );
		HDIT::eq( "core's own mapping write during after_switch_theme did not erase the guardian memory", (int) $menu_a, (int) ( Hedayati_Menu_Guardian::memory()['primary'] ?? 0 ) );

		// Idempotent, and never overwrites a real assignment.
		HDIT::eq( 'restore() is a no-op when the location is healthy', [], Hedayati_Menu_Guardian::restore() );
		$menu_b          = wp_create_nav_menu( 'HDIT D57 second menu' );
		$created_menus[] = (int) $menu_b;
		$locations            = get_theme_mod( 'nav_menu_locations', [] );
		$locations['primary'] = (int) $menu_b;
		set_theme_mod( 'nav_menu_locations', $locations );
		Hedayati_Menu_Guardian::restore();
		HDIT::eq( 'a deliberately assigned (different) menu is NEVER overwritten by restore()', (int) $menu_b, $assigned() );

		// Deliberate "none" is respected.
		$locations['primary'] = 0;
		set_theme_mod( 'nav_menu_locations', $locations );
		Hedayati_Menu_Guardian::restore();
		HDIT::eq( 'a deliberate unassignment is not undone', 0, $assigned() );

		// Deleted menus are forgotten.
		$locations['primary'] = (int) $menu_b;
		set_theme_mod( 'nav_menu_locations', $locations );
		wp_delete_nav_menu( $menu_b );
		HDIT::ok( 'a deleted menu is dropped from guardian memory', ! in_array( (int) $menu_b, Hedayati_Menu_Guardian::memory(), true ) );

		$locations            = get_theme_mod( 'nav_menu_locations', [] );
		$locations['primary'] = (int) $menu_a;
		set_theme_mod( 'nav_menu_locations', $locations );

		// ── /panel/?view=navigation must not destroy item data ─────────────
		$move = hdit_d57_post( 'handle_move_item', 'hedayati_nav_panel_move_item', $mgr, [ 'location' => 'primary', 'item_id' => (int) $i_page, 'direction' => 'up' ] );
		HDIT::eq( 'panel move (manager) completes with a redirect', 'redirect', $move );
		$page_item   = hdit_d57_item( (int) $menu_a, (int) $i_page );
		$custom_item = hdit_d57_item( (int) $menu_a, (int) $i_custom );
		$child_item  = hdit_d57_item( (int) $menu_a, (int) $i_child );
		HDIT::ok( 'moved page link is still a post_type link to the same page', null !== $page_item && 'post_type' === $page_item->type && (int) $page_item->object_id === (int) $about->ID );
		HDIT::ok( 'moved page link now sits before the custom link', null !== $page_item && null !== $custom_item && (int) $page_item->menu_order < (int) $custom_item->menu_order );
		HDIT::ok( 'swapped custom link kept its CSS class and target', null !== $custom_item && in_array( 'hd-user-class', (array) $custom_item->classes, true ) && '_blank' === $custom_item->target );
		HDIT::ok( 'submenu item still has its parent after the move', null !== $child_item && (int) $child_item->menu_item_parent === (int) $i_custom );

		$save = hdit_d57_post( 'handle_save_item', 'hedayati_nav_panel_save_item', $mgr, [ 'location' => 'primary', 'item_id' => (int) $i_page, 'title' => 'About (renamed)' ] );
		HDIT::eq( 'panel title edit of a page link (no url field) completes', 'redirect', $save );
		$page_item = hdit_d57_item( (int) $menu_a, (int) $i_page );
		HDIT::ok( 'renamed page link keeps type/object and gets the new title', null !== $page_item && 'post_type' === $page_item->type && (int) $page_item->object_id === (int) $about->ID && 'About (renamed)' === $page_item->title );

		$save = hdit_d57_post( 'handle_save_item', 'hedayati_nav_panel_save_item', $mgr, [ 'location' => 'primary', 'item_id' => (int) $i_child, 'title' => 'Child Link', 'url' => '/child-2/' ] );
		$child_item = hdit_d57_item( (int) $menu_a, (int) $i_child );
		HDIT::ok( 'editing a submenu custom link updates its URL and keeps its parent', null !== $child_item && str_ends_with( $child_item->url, '/child-2/' ) && (int) $child_item->menu_item_parent === (int) $i_custom );

		$bad = hdit_d57_post( 'handle_save_item', 'hedayati_nav_panel_save_item', $mgr, [ 'location' => 'primary', 'item_id' => (int) $i_child, 'title' => 'x', 'url' => 'javascript:alert(1)' ] );
		$child_item = hdit_d57_item( (int) $menu_a, (int) $i_child );
		HDIT::ok( 'a javascript: URL is still rejected and the item is left unchanged', 'redirect' === $bad && str_ends_with( $child_item->url, '/child-2/' ) );

		// Panel "add link" with a lost assignment re-attaches instead of shadowing.
		delete_option( $mods_option );
		$menus_before = count( wp_get_nav_menus() );
		$add = hdit_d57_post( 'handle_save_item', 'hedayati_nav_panel_save_item', $mgr, [ 'location' => 'primary', 'item_id' => 0, 'title' => 'Added', 'url' => '/added/' ] );
		HDIT::eq( 'panel add with a lost assignment completes', 'redirect', $add );
		HDIT::eq( 'no new (empty) menu was created to shadow the user menu', $menus_before, count( wp_get_nav_menus() ) );
		HDIT::eq( 'the link was appended to the user menu that got re-attached', 4, $count( (int) $menu_a ) );
		HDIT::eq( 'primary is the user menu again', (int) $menu_a, $assigned() );

		// Explicit recovery action.
		update_option( Hedayati_Menu_Guardian::OPTION, [] );
		delete_option( $mods_option );
		HDIT::eq( 'reception (no hedayati_manage_settings) cannot assign a menu', 'die', hdit_d57_post( 'handle_assign_menu', 'hedayati_nav_panel_assign_menu', $rcpt, [ 'location' => 'primary', 'menu_id' => (int) $menu_a ] ) );
		HDIT::eq( 'manager cannot assign to an unmanaged location', 'die', hdit_d57_post( 'handle_assign_menu', 'hedayati_nav_panel_assign_menu', $mgr, [ 'location' => 'sidebar', 'menu_id' => (int) $menu_a ] ) );
		HDIT::eq( 'manager cannot assign a non-menu term id', 'die', hdit_d57_post( 'handle_assign_menu', 'hedayati_nav_panel_assign_menu', $mgr, [ 'location' => 'primary', 'menu_id' => 999999 ] ) );
		hdit_d57_post( 'handle_assign_menu', 'hedayati_nav_panel_assign_menu', $mgr, [ 'location' => 'primary', 'menu_id' => (int) $menu_a ] );
		HDIT::eq( 'manager re-attaches the orphaned user menu explicitly', (int) $menu_a, $assigned() );
		$menu_c          = wp_create_nav_menu( 'HDIT D57 third menu' );
		$created_menus[] = (int) $menu_c;
		hdit_d57_post( 'handle_assign_menu', 'hedayati_nav_panel_assign_menu', $mgr, [ 'location' => 'primary', 'menu_id' => (int) $menu_c ] );
		HDIT::eq( 'the recovery action never replaces a live assignment', (int) $menu_a, $assigned() );
		HDIT::eq( 'all user items survived the whole sequence', 4, $count( (int) $menu_a ) );
	} finally {
		foreach ( $created_menus as $m ) {
			if ( wp_get_nav_menu_object( $m ) ) {
				wp_delete_nav_menu( $m );
			}
		}
		null === $saved_mods ? delete_option( $mods_option ) : update_option( $mods_option, $saved_mods );
		null === $saved_memory ? delete_option( Hedayati_Menu_Guardian::OPTION ) : update_option( Hedayati_Menu_Guardian::OPTION, $saved_memory );
		delete_option( 'theme_switch_menu_locations' );
		null === $saved_widgets ? delete_option( 'sidebars_widgets' ) : update_option( 'sidebars_widgets', $saved_widgets );
	}
}
