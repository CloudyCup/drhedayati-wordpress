<?php
/**
 * D55 — in-panel content management for the plugin-provisioned public Pages
 * (`Hedayati_Public_Content::PAGES`), so a manager never needs wp-admin Pages
 * for ordinary institute copy.
 *
 * Deliberately narrow: this is NOT a general Page editor. Only the four
 * public-facing, staff-authored pages that already render their `post_content`
 * through `theme/hedayati/page.php` are reachable — `about`, `contact`,
 * `consult`, `teachers` (matching the owner's explicit list; `verify` is a
 * functional page, not institute copy, and stays out of scope). Every read and
 * write re-validates the target post is `post_type => page` AND its
 * `post_name` is in the fixed whitelist — an attacker (or a bug) cannot widen
 * this into "edit any Page".
 *
 * No new table, no new option, no duplicated content store: this is a thin
 * `wp_update_post()` front-end over the EXISTING Page records
 * `Hedayati_Public_Content::ensure_pages()` already creates. Title/content
 * only — slug and post type are never touched.
 *
 * Gated on `hedayati_manage_settings` (not a core `edit_pages`/`edit_page`
 * capability, which `hedayati_manager` deliberately does not hold — see
 * docs/DECISIONS.md D55): institute-level content is the same audience/trust
 * level as institute settings, so no roles/capability schema change is
 * needed to add this screen.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Content_Panel {

	public const VIEW       = 'content';
	public const CAPABILITY = 'hedayati_manage_settings';

	private const NONCE_SAVE = 'hedayati_content_panel_save';

	/** The only Pages this screen may ever read or write. */
	private const ALLOWED_SLUGS = [ 'about', 'contact', 'consult', 'teachers' ];

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
		add_action( 'admin_post_' . self::NONCE_SAVE, [ self::class, 'handle_save' ] );
		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'page' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [ 'page.content_updated' ] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views[ self::VIEW ] = [
			'capability' => self::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'محتوای صفحات عمومی', 'hedayati-core' ),
			'title'      => __( 'محتوای صفحات عمومی', 'hedayati-core' ),
			'desc'       => __( 'متن صفحات درباره، تماس، مشاوره و مدرسان', 'hedayati-core' ),
			'icon'       => 'folder',
		];
		return $views;
	}

	/** @return array<string,WP_Post> slug => Page, only for pages that actually exist. */
	private static function pages(): array {
		$out = [];
		foreach ( self::ALLOWED_SLUGS as $slug ) {
			$post = get_page_by_path( $slug );
			if ( $post instanceof WP_Post && 'page' === $post->post_type ) {
				$out[ $slug ] = $post;
			}
		}
		return $out;
	}

	private static function labels(): array {
		return [
			'about'    => __( 'دربارهٔ مجتمع', 'hedayati-core' ),
			'contact'  => __( 'تماس با ما', 'hedayati-core' ),
			'consult'  => __( 'مشاورهٔ انتخاب دوره', 'hedayati-core' ),
			'teachers' => __( 'مدرسان مجتمع', 'hedayati-core' ),
		];
	}

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$slug = isset( $_GET['page_slug'] ) ? sanitize_key( wp_unslash( $_GET['page_slug'] ) ) : '';

		if ( '' !== $slug && in_array( $slug, self::ALLOWED_SLUGS, true ) ) {
			self::render_form( $slug );
			return;
		}

		self::render_list();
	}

	private static function url( array $args = [] ): string {
		return Hedayati_Staff_Portal::url( array_merge( [ 'view' => self::VIEW ], $args ) );
	}

	private static function render_list(): void {
		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'وب‌سایت عمومی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'محتوای صفحات عمومی', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'ویرایش متن صفحاتی که در سایت عمومی مجتمع نمایش داده می‌شوند.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		$labels = self::labels();
		echo '<div class="hd-portal-cards">';
		foreach ( self::pages() as $slug => $post ) {
			$status = '' !== trim( (string) $post->post_content )
				? __( 'دارای محتوا', 'hedayati-core' )
				: __( 'هنوز محتوایی ثبت نشده', 'hedayati-core' );
			printf(
				'<a class="hd-portal-card" href="%1$s">%2$s <span class="hd-portal-note">(%3$s)</span></a>',
				esc_url( self::url( [ 'page_slug' => $slug ] ) ),
				esc_html( $labels[ $slug ] ?? $post->post_title ),
				esc_html( $status )
			);
		}
		echo '</div>';
	}

	private static function render_form( string $slug ): void {
		$pages = self::pages();
		if ( ! isset( $pages[ $slug ] ) ) {
			echo '<p class="hd-portal-notice hd-portal-notice-error">' . esc_html__( 'صفحه یافت نشد.', 'hedayati-core' ) . '</p>';
			self::render_list();
			return;
		}

		$post   = $pages[ $slug ];
		$labels = self::labels();

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'ویرایش صفحه', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html( $labels[ $slug ] ?? $post->post_title ) . '</h1>';
		echo '</div>';
		printf( '<a class="hd-portal-nav-link" href="%s">%s</a>', esc_url( self::url() ), esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) );
		echo '</header>';

		if ( in_array( $slug, [ 'contact', 'consult' ], true ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'شماره‌های تماس و نشانی از «تنظیمات مجتمع» خوانده می‌شوند؛ این متن فقط توضیح تکمیلی صفحه است.', 'hedayati-core' ) . '</p>';
		}

		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_SAVE ) . '">';
		echo '<input type="hidden" name="page_slug" value="' . esc_attr( $slug ) . '">';

		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="title" value="%s" required></label>',
			esc_html__( 'عنوان صفحه', 'hedayati-core' ),
			esc_attr( $post->post_title )
		);
		printf(
			'<label class="hd-portal-field"><span>%s</span><textarea name="content" rows="10">%s</textarea></label>',
			esc_html__( 'متن صفحه', 'hedayati-core' ),
			esc_textarea( $post->post_content )
		);

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ صفحه', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	public static function handle_save(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_SAVE, self::CAPABILITY );

		$slug = isset( $_POST['page_slug'] ) ? sanitize_key( wp_unslash( $_POST['page_slug'] ) ) : '';

		if ( ! in_array( $slug, self::ALLOWED_SLUGS, true ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$post = get_page_by_path( $slug );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'not_found', __( 'صفحه یافت نشد.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		$title   = sanitize_text_field( isset( $_POST['title'] ) ? (string) wp_unslash( $_POST['title'] ) : '' );
		$content = wp_kses_post( isset( $_POST['content'] ) ? (string) wp_unslash( $_POST['content'] ) : '' );

		if ( '' === $title ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'title', __( 'عنوان صفحه لازم است.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW, 'page_slug' => $slug ]
			);
		}

		// post_name (slug) and post_type are never part of the update — this
		// can only ever change the title/content of the one Page it just
		// re-verified by slug.
		$result = wp_update_post( [
			'ID'           => $post->ID,
			'post_title'   => $title,
			'post_content' => $content,
		], true );

		if ( is_wp_error( $result ) ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'save', __( 'ذخیرهٔ صفحه ناموفق بود.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW, 'page_slug' => $slug ]
			);
		}

		Hedayati_Audit_Log::record( 'page.content_updated', 'page', (int) $post->ID, 'via panel: ' . $slug, get_current_user_id() );

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => self::VIEW, 'page_slug' => $slug ] );
	}
}
