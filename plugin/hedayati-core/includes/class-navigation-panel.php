<?php
/**
 * D55 — a constrained in-panel editor for the site's two registered nav menu
 * locations (`primary` — header, `footer` — footer quick links), so a manager
 * never has to open wp-admin Appearance → Menus for ordinary link upkeep.
 *
 * Deliberately NOT a general menu-management screen. It only ever reads or
 * writes the ONE menu currently assigned to `primary` or `footer`
 * (`get_nav_menu_locations()`), and every mutation re-verifies the target
 * `nav_menu_item` post actually belongs to one of those two menus before
 * touching it — this cannot be used to edit an unrelated menu, and there is
 * no "pick any menu" control anywhere in this screen.
 *
 * Every managed item is a simple custom link (`menu-item-type = custom`) —
 * this editor does not reproduce WordPress's full menu-item type system
 * (post/page/category/taxonomy object links). Editing a pre-existing
 * non-custom item through this screen normalizes it to a custom link
 * pointing at its current resolved URL; nesting (menu_item_parent) is not
 * supported — items are listed and reordered as one flat, top-level list.
 * `wp-admin` → Appearance → Menus remains available to the administrator for
 * anything beyond that (submenus, non-link menu items, additional menus).
 *
 * Storage: 100% the canonical `nav_menu_item` post type + `nav_menu` taxonomy
 * WordPress itself uses — `wp_update_nav_menu_item()` / `wp_delete_post()` /
 * `wp_create_nav_menu()`. No new table, no new option, no duplicated link
 * list.
 *
 * URL safety: every submitted URL is resolved through `self::sanitize_url()`
 * before being stored — only a site-relative path (starting with `/`) or an
 * absolute `http`/`https` URL is accepted; `javascript:`, `data:`, and any
 * other scheme is rejected outright (`esc_url_raw()` with an explicit
 * protocol allow-list, belt-and-braces on top of the relative-path check).
 *
 * Gated on `hedayati_manage_settings` (same audience/trust level as
 * institute settings and the D55 content-panel screen) — no roles/capability
 * schema change.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Navigation_Panel {

	public const VIEW       = 'navigation';
	public const CAPABILITY = 'hedayati_manage_settings';

	private const NONCE_SAVE_ITEM   = 'hedayati_nav_panel_save_item';
	private const NONCE_DELETE_ITEM = 'hedayati_nav_panel_delete_item';
	private const NONCE_MOVE_ITEM   = 'hedayati_nav_panel_move_item';

	/** The only two nav menu locations this screen may ever touch. */
	private const LOCATIONS = [
		'primary' => 'منوی اصلی (بالای سایت)',
		'footer'  => 'دسترسی سریع (فوتر)',
	];

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
		add_action( 'admin_post_' . self::NONCE_SAVE_ITEM, [ self::class, 'handle_save_item' ] );
		add_action( 'admin_post_' . self::NONCE_DELETE_ITEM, [ self::class, 'handle_delete_item' ] );
		add_action( 'admin_post_' . self::NONCE_MOVE_ITEM, [ self::class, 'handle_move_item' ] );
		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'nav_menu_item' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [ 'nav_link.saved', 'nav_link.deleted', 'nav_link.moved' ] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views[ self::VIEW ] = [
			'capability' => self::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'منوها و لینک‌ها', 'hedayati-core' ),
			'title'      => __( 'منوها و لینک‌ها', 'hedayati-core' ),
			'desc'       => __( 'لینک‌های منوی اصلی و دسترسی سریع فوتر', 'hedayati-core' ),
			'icon'       => 'folder',
		];
		return $views;
	}

	private static function url( array $args = [] ): string {
		return Hedayati_Staff_Portal::url( array_merge( [ 'view' => self::VIEW ], $args ) );
	}

	// ── Menu resolution (locations only — never an arbitrary menu id) ──────

	/** The WP_Term menu object currently assigned to $location, or null. */
	private static function menu_for_location( string $location ): ?WP_Term {
		if ( ! isset( self::LOCATIONS[ $location ] ) ) {
			return null;
		}
		$locations = get_nav_menu_locations();
		$menu_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
		if ( $menu_id <= 0 ) {
			return null;
		}
		$menu = wp_get_nav_menu_object( $menu_id );
		return $menu instanceof WP_Term ? $menu : null;
	}

	/**
	 * Creates and assigns a menu for $location if one doesn't exist yet —
	 * mirrors this codebase's existing "ensure the page/record exists"
	 * pattern (Hedayati_Staff_Portal::ensure_page(), etc.).
	 */
	private static function ensure_menu_for_location( string $location ): ?WP_Term {
		$existing = self::menu_for_location( $location );
		if ( null !== $existing ) {
			return $existing;
		}
		if ( ! isset( self::LOCATIONS[ $location ] ) ) {
			return null;
		}

		$menu_id = wp_create_nav_menu( self::LOCATIONS[ $location ] );
		if ( is_wp_error( $menu_id ) ) {
			return null;
		}

		$locations             = get_theme_mod( 'nav_menu_locations', [] );
		$locations[ $location ] = (int) $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return wp_get_nav_menu_object( (int) $menu_id );
	}

	/** @return int[] every nav_menu_item ID belonging to EITHER managed location's menu. */
	private static function managed_item_ids(): array {
		$ids = [];
		foreach ( array_keys( self::LOCATIONS ) as $location ) {
			$menu = self::menu_for_location( $location );
			if ( null === $menu ) {
				continue;
			}
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$ids[] = (int) $item->ID;
			}
		}
		return $ids;
	}

	/** True only if $item_id is a real item of one of the two managed menus. */
	private static function item_is_managed( int $item_id ): bool {
		return $item_id > 0 && in_array( $item_id, self::managed_item_ids(), true );
	}

	// ── URL safety ───────────────────────────────────────────────────────────

	/** @return string  '' when the URL is rejected, otherwise a safe absolute/relative URL. */
	private static function sanitize_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Site-relative path — resolve against home_url() so it can never
		// carry a foreign scheme (e.g. "javascript:alert(1)" does not match).
		if ( '/' === $raw[0] && ( strlen( $raw ) < 2 || '/' !== $raw[1] ) ) {
			return esc_url_raw( home_url( $raw ) );
		}

		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			return '';
		}

		$clean = esc_url_raw( $raw, [ 'http', 'https' ] );
		return '' !== $clean ? $clean : '';
	}

	// ── Rendering ────────────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'وب‌سایت عمومی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'منوها و لینک‌ها', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'مدیریت لینک‌های ساده در منوی اصلی و بخش دسترسی سریع فوتر. برای امکانات پیشرفته‌تر منو، از افزونهٔ ظاهر ← منوها در پیشخوان استفاده کنید.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		foreach ( self::LOCATIONS as $location => $label ) {
			self::render_location( $location, $label );
		}
	}

	private static function render_location( string $location, string $label ): void {
		$menu  = self::menu_for_location( $location );
		$items = null !== $menu ? (array) wp_get_nav_menu_items( $menu->term_id ) : [];
		usort( $items, static fn( $a, $b ) => (int) $a->menu_order <=> (int) $b->menu_order );

		echo '<section class="hd-staff-section">';
		echo '<h2 class="hd-portal-subtitle">' . esc_html( $label ) . '</h2>';

		if ( empty( $items ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'هنوز لینکی افزوده نشده است (پیش‌فرض سایت نمایش داده می‌شود).', 'hedayati-core' ) . '</p>';
		} else {
			echo '<div class="hd-manager-table" role="table">';
			echo '<div class="hd-manager-tr hd-manager-th" role="row">';
			foreach ( [ __( 'عنوان', 'hedayati-core' ), __( 'نشانی', 'hedayati-core' ), '' ] as $h ) {
				echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
			}
			echo '</div>';

			$count = count( $items );
			foreach ( $items as $i => $item ) {
				$item_id = (int) $item->ID;
				echo '<div class="hd-manager-tr" role="row">';

				$form_id = 'hd-nav-item-' . $item_id;

				echo '<span role="cell">';
				self::form_open( self::NONCE_SAVE_ITEM, [ 'location' => $location, 'item_id' => $item_id ], 'hd-manager-inline-form', $form_id );
				printf( '<input type="text" name="title" value="%s" required>', esc_attr( $item->title ) );
				echo '</span>';

				echo '<span role="cell">';
				printf( '<input type="text" name="url" value="%s" dir="ltr" required form="hd-nav-item-%d">', esc_attr( $item->url ), esc_attr( $item_id ) );
				echo '</span>';

				echo '<span role="cell" class="hd-manager-row-actions">';
				echo '<button class="hd-portal-btn hd-portal-btn-small" type="submit">' . esc_html__( 'ذخیره', 'hedayati-core' ) . '</button>';
				echo '</form>';

				if ( $i > 0 ) {
					self::move_button( $location, $item_id, 'up', '↑' );
				}
				if ( $i < $count - 1 ) {
					self::move_button( $location, $item_id, 'down', '↓' );
				}
				self::delete_button( $location, $item_id );
				echo '</span>';

				echo '</div>';
			}
			echo '</div>';
		}

		self::render_add_form( $location );
		echo '</section>';
	}

	private static function form_open( string $nonce_action, array $hidden, string $class = '', string $id = '' ): void {
		printf(
			'<form%s class="%s" method="post" action="%s">',
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : '',
			esc_attr( $class ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		wp_nonce_field( $nonce_action );
		$hidden['action'] = $nonce_action;
		foreach ( $hidden as $key => $value ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( (string) $value ) );
		}
	}

	private static function move_button( string $location, int $item_id, string $dir, string $glyph ): void {
		self::form_open( self::NONCE_MOVE_ITEM, [ 'location' => $location, 'item_id' => $item_id, 'direction' => $dir ], 'hd-manager-inline-form' );
		printf( '<button type="submit" class="hd-manager-toggle-btn" aria-label="%s">%s</button></form>', esc_attr__( 'جابه‌جایی', 'hedayati-core' ), esc_html( $glyph ) );
	}

	private static function delete_button( string $location, int $item_id ): void {
		printf(
			'<form class="hd-manager-inline-form" method="post" action="%s" onsubmit="return confirm(%s);">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( "'" . esc_js( __( 'این لینک حذف شود؟', 'hedayati-core' ) ) . "'" )
		);
		wp_nonce_field( self::NONCE_DELETE_ITEM );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::NONCE_DELETE_ITEM ) );
		printf( '<input type="hidden" name="location" value="%s">', esc_attr( $location ) );
		printf( '<input type="hidden" name="item_id" value="%d">', $item_id );
		echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button></form>';
	}

	private static function render_add_form( string $location ): void {
		self::form_open( self::NONCE_SAVE_ITEM, [ 'location' => $location, 'item_id' => 0 ] );
		echo '<h3>' . esc_html__( 'افزودن لینک', 'hedayati-core' ) . '</h3>';
		printf( '<label class="hd-portal-field"><span>%s</span><input type="text" name="title" required></label>', esc_html__( 'عنوان لینک', 'hedayati-core' ) );
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="url" dir="ltr" placeholder="/contact/ یا https://…" required></label>',
			esc_html__( 'نشانی (مسیر داخلی مثل /contact/ یا آدرس کامل https://)', 'hedayati-core' )
		);
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'افزودن', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	// ── Mutation handlers ────────────────────────────────────────────────────

	private static function deny(): void {
		wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
	}

	public static function handle_save_item(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_SAVE_ITEM, self::CAPABILITY );

		$location = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '';
		$item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$title    = sanitize_text_field( isset( $_POST['title'] ) ? (string) wp_unslash( $_POST['title'] ) : '' );
		$raw_url  = isset( $_POST['url'] ) ? (string) wp_unslash( $_POST['url'] ) : '';

		if ( ! isset( self::LOCATIONS[ $location ] ) ) {
			self::deny();
		}
		// Editing an existing item: it must already belong to ONE of the two
		// managed menus — never an id from an unrelated menu.
		if ( $item_id > 0 && ! self::item_is_managed( $item_id ) ) {
			self::deny();
		}

		$url = self::sanitize_url( $raw_url );

		if ( '' === $title || '' === $url ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'nav_link', __( 'عنوان و نشانی معتبر لازم است (فقط مسیر داخلی یا آدرس http/https).', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		$menu = self::ensure_menu_for_location( $location );
		if ( null === $menu ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'nav_menu', __( 'ایجاد منو ناموفق بود.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		$args = [
			'menu-item-title'  => $title,
			'menu-item-url'    => $url,
			'menu-item-type'   => 'custom',
			'menu-item-status' => 'publish',
		];

		// Only set an explicit position for a brand-new item (append to the
		// end); editing an existing item must never disturb its current order.
		if ( 0 === $item_id ) {
			$args['menu-item-position'] = count( (array) wp_get_nav_menu_items( $menu->term_id ) ) + 1;
		}

		$result = wp_update_nav_menu_item( $menu->term_id, $item_id, $args );

		if ( is_wp_error( $result ) ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'nav_link', __( 'ذخیرهٔ لینک ناموفق بود.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		Hedayati_Audit_Log::record( 'nav_link.saved', 'nav_menu_item', (int) $result, 'via panel: ' . $location, get_current_user_id() );

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => self::VIEW ] );
	}

	public static function handle_delete_item(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_DELETE_ITEM, self::CAPABILITY );

		$location = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '';
		$item_id  = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;

		if ( ! isset( self::LOCATIONS[ $location ] ) || ! self::item_is_managed( $item_id ) ) {
			self::deny();
		}

		$deleted = wp_delete_post( $item_id, true );

		if ( $deleted ) {
			Hedayati_Audit_Log::record( 'nav_link.deleted', 'nav_menu_item', $item_id, 'via panel: ' . $location, get_current_user_id() );
		}

		Hedayati_Staff_Portal::redirect_notice(
			$deleted ? true : new WP_Error( 'nav_link', __( 'حذف لینک ناموفق بود.', 'hedayati-core' ) ),
			[ 'view' => self::VIEW ]
		);
	}

	public static function handle_move_item(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_MOVE_ITEM, self::CAPABILITY );

		$location  = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : '';
		$item_id   = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : '';

		if ( ! isset( self::LOCATIONS[ $location ] ) || ! self::item_is_managed( $item_id ) || ! in_array( $direction, [ 'up', 'down' ], true ) ) {
			self::deny();
		}

		$menu  = self::menu_for_location( $location );
		$items = null !== $menu ? (array) wp_get_nav_menu_items( $menu->term_id ) : [];
		usort( $items, static fn( $a, $b ) => (int) $a->menu_order <=> (int) $b->menu_order );

		$index = null;
		foreach ( $items as $i => $item ) {
			if ( (int) $item->ID === $item_id ) {
				$index = $i;
				break;
			}
		}

		$swap_with = 'up' === $direction ? $index - 1 : $index + 1;

		if ( null !== $index && isset( $items[ $swap_with ] ) ) {
			$a = $items[ $index ];
			$b = $items[ $swap_with ];
			// wp_update_nav_menu_item() is a full "set these fields" call, not a
			// partial patch — any field left out (title/url/type/status) resets
			// to empty, so every field this screen manages must be resupplied
			// from the item being moved, not just the new position.
			wp_update_nav_menu_item( $menu->term_id, (int) $a->ID, [
				'menu-item-title'    => $a->title,
				'menu-item-url'      => $a->url,
				'menu-item-type'     => 'custom',
				'menu-item-status'   => 'publish',
				'menu-item-position' => (int) $b->menu_order,
			] );
			wp_update_nav_menu_item( $menu->term_id, (int) $b->ID, [
				'menu-item-title'    => $b->title,
				'menu-item-url'      => $b->url,
				'menu-item-type'     => 'custom',
				'menu-item-status'   => 'publish',
				'menu-item-position' => (int) $a->menu_order,
			] );
			Hedayati_Audit_Log::record( 'nav_link.moved', 'nav_menu_item', $item_id, 'via panel: ' . $location . ' ' . $direction, get_current_user_id() );
		}

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => self::VIEW ] );
	}
}
