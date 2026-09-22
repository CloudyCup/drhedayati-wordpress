<?php
/**
 * D57 — user-created WordPress menus must survive theme/plugin replacement.
 *
 * Root cause this closes: WordPress stores menu-LOCATION assignments inside
 * the ACTIVE theme's `theme_mods_{stylesheet}` option, while the menus and
 * their items live in the `nav_menu` taxonomy + `nav_menu_item` posts. Any
 * deploy path that makes WordPress read a different/fresh theme_mods row for
 * the header location (theme folder deleted and re-extracted under another
 * name, a broken/nested extract that makes core fall back to a default theme,
 * a theme switch round-trip, a hand-reset theme_mods row) leaves the user's
 * menu intact in the database but UNASSIGNED — the header then silently
 * renders the hardcoded fallback links and every custom item looks "gone".
 *
 * This class keeps a theme-independent memory of the last deliberate
 * location → menu assignment (`hedayati_nav_menu_assignments`, one small
 * autoloaded option — NOT a second menu store: it holds term IDs only, never
 * items) and re-applies it ONLY when a location has no usable menu:
 *
 *   - a location that already points at a real menu is never touched;
 *   - menus and menu items are never created, edited, or deleted here;
 *   - a deliberate unassignment (Appearance → Menus / Customizer / WP-CLI, all
 *     of which go through set_theme_mod()) is remembered as such and never
 *     undone;
 *   - core's own after_switch_theme mapping is not mistaken for a deliberate
 *     change, and runs first; restoration only fills what it left empty.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Menu_Guardian {

	public const OPTION = 'hedayati_nav_menu_assignments';

	/** True while this class itself is writing nav_menu_locations. */
	private static bool $restoring = false;

	public static function init(): void {
		add_filter( 'pre_set_theme_mod_nav_menu_locations', [ self::class, 'remember' ], 10, 2 );
		// Priority 20: after core's _wp_menus_changed() (10) has mapped the old theme's locations.
		add_action( 'after_switch_theme', [ self::class, 'restore' ], 20 );
		add_action( 'admin_init', [ self::class, 'restore' ] );
		add_action( 'wp_delete_nav_menu', [ self::class, 'forget_menu' ] );
	}

	/**
	 * pre_set_theme_mod_nav_menu_locations — records deliberate assignments.
	 * Always returns $value unchanged; this filter only observes.
	 *
	 * @param mixed $value
	 * @param mixed $old_value
	 * @return mixed
	 */
	public static function remember( $value, $old_value = null ) {
		if ( self::$restoring || doing_action( 'after_switch_theme' ) || doing_action( 'switch_theme' ) || ! is_array( $value ) ) {
			return $value;
		}

		$memory = self::memory();
		$old    = is_array( $old_value ) ? $old_value : [];

		foreach ( $value as $location => $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id > 0 && self::menu_exists( $menu_id ) ) {
				$memory[ (string) $location ] = $menu_id;
			} elseif ( isset( $old[ $location ] ) && (int) $old[ $location ] > 0 ) {
				unset( $memory[ (string) $location ] ); // deliberately set to "none"
			}
		}

		// A location that was assigned before and is simply absent now was also unassigned on purpose.
		foreach ( $old as $location => $menu_id ) {
			if ( (int) $menu_id > 0 && ! array_key_exists( $location, $value ) ) {
				unset( $memory[ (string) $location ] );
			}
		}

		update_option( self::OPTION, $memory, true );
		return $value;
	}

	/**
	 * Re-assigns a remembered menu to any registered location that currently
	 * has no usable menu. Idempotent; a no-op when every location is fine.
	 *
	 * @return string[] the locations that were restored.
	 */
	public static function restore(): array {
		$registered = array_keys( get_registered_nav_menus() );
		if ( empty( $registered ) ) {
			return [];
		}

		$current = get_theme_mod( 'nav_menu_locations', [] );
		$current = is_array( $current ) ? $current : [];
		$memory  = self::memory();

		// First run: adopt whatever is validly assigned right now as the baseline.
		if ( false === get_option( self::OPTION, false ) ) {
			foreach ( $current as $location => $menu_id ) {
				if ( (int) $menu_id > 0 && self::menu_exists( (int) $menu_id ) ) {
					$memory[ (string) $location ] = (int) $menu_id;
				}
			}
			update_option( self::OPTION, $memory, true );
		}

		$restored = [];
		foreach ( $registered as $location ) {
			$assigned = isset( $current[ $location ] ) ? (int) $current[ $location ] : 0;
			if ( $assigned > 0 && self::menu_exists( $assigned ) ) {
				continue; // never overwrite a real assignment
			}
			$remembered = isset( $memory[ $location ] ) ? (int) $memory[ $location ] : 0;
			if ( $remembered > 0 && self::menu_exists( $remembered ) ) {
				$current[ $location ] = $remembered;
				$restored[]           = $location;
			}
		}

		if ( ! empty( $restored ) ) {
			self::$restoring = true;
			set_theme_mod( 'nav_menu_locations', $current );
			self::$restoring = false;
		}

		return $restored;
	}

	/** wp_delete_nav_menu — a deleted menu can never be "restored". */
	public static function forget_menu( $term_id ): void {
		$memory = self::memory();
		$before = count( $memory );
		$memory = array_filter( $memory, static fn( $id ): bool => (int) $id !== (int) $term_id );
		if ( count( $memory ) !== $before ) {
			update_option( self::OPTION, $memory, true );
		}
	}

	/** @return array<string,int> */
	public static function memory(): array {
		$memory = get_option( self::OPTION, [] );
		return is_array( $memory ) ? array_map( 'intval', $memory ) : [];
	}

	private static function menu_exists( int $menu_id ): bool {
		return wp_get_nav_menu_object( $menu_id ) instanceof WP_Term;
	}
}
