<?php
/**
 * Phase 2B — Teacher custom post type (public instructor identity).
 *
 * A Teacher profile is catalog/marketing content: name (post title), biography
 * (editor), portrait (featured image), plus a short headline. It MAY be linked to
 * a WordPress user account (`_hedayati_teacher_user_id`) but does not require one —
 * many instructors never log in. The link is 1:1: a given WP user backs at most one
 * Teacher profile.
 *
 * Course Run instructor assignments (`Hedayati_Run_Staff_Service`) reference a
 * Teacher profile, not a WP user, so the operational layer never depends on an
 * instructor having an account.
 *
 * Authorization: all editing is gated on the `hedayati_manage_teachers` capability
 * (manager + administrator). The CPT is not publicly queryable yet — a public
 * teacher directory is Phase 2D public-content work.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Teacher {

	public const POST_TYPE = 'teacher';

	public const META_USER_ID  = '_hedayati_teacher_user_id';
	public const META_HEADLINE = '_hedayati_teacher_headline';

	private const NONCE_ACTION = 'hedayati_teacher_meta_save';
	private const NONCE_FIELD  = 'hedayati_teacher_meta_nonce';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'init', [ self::class, 'register' ] );
		add_action( 'init', [ self::class, 'register_meta' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ self::class, 'register_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ self::class, 'save' ], 10, 2 );
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ self::class, 'columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ self::class, 'column_content' ], 10, 2 );
	}

	// ── Registration ─────────────────────────────────────────────────────────

	public static function register(): void {
		$labels = [
			'name'               => 'اساتید',
			'singular_name'      => 'استاد',
			'add_new'            => 'افزودن استاد',
			'add_new_item'       => 'افزودن استاد جدید',
			'edit_item'          => 'ویرایش استاد',
			'new_item'           => 'استاد جدید',
			'view_item'          => 'مشاهده استاد',
			'search_items'       => 'جستجوی اساتید',
			'not_found'          => 'استادی یافت نشد.',
			'not_found_in_trash' => 'موردی در زباله‌دان یافت نشد.',
			'all_items'          => 'همه اساتید',
			'menu_name'          => 'اساتید',
		];

		register_post_type( self::POST_TYPE, [
			'labels'              => $labels,
			'description'         => 'شناسنامه عمومی اساتید مجتمع آموزشی دکتر هدایتی',
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			// show_in_rest is deliberately false: a `show_in_rest` CPT with published
			// posts is readable by anyone via /wp-json/wp/v2/... regardless of
			// `public`/`publicly_queryable`. Teacher profiles must not leak before the
			// Phase 2D public directory is designed (D30). Classic editor is fine for
			// an admin-only record.
			'show_in_rest'        => false,
			'menu_position'       => 6,
			'menu_icon'           => 'dashicons-businessperson',
			'hierarchical'        => false,
			'supports'            => [ 'title', 'editor', 'thumbnail', 'revisions' ],
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'delete_with_user'    => false,
			'capability_type'     => 'hedayati_teacher',
			'map_meta_cap'        => true,
			// Capability model — read this before touching it (fixes the 1.5.1 T1
			// staging bug):
			//
			// `hedayati_manage_teachers` is the ONE primitive permission. It is
			// granted to `hedayati_manager` + `administrator` by Hedayati_Roles and
			// MUST stay a plain primitive so `current_user_can( 'hedayati_manage_teachers' )`
			// resolves WITHOUT an object ID — that bare check drives the admin-menu
			// visibility, the list-table screen, and every server-side guard.
			//
			// The three PER-OBJECT meta caps (`edit_post` / `read_post` /
			// `delete_post`) must therefore get DISTINCT names. WordPress's
			// `_post_type_meta_capabilities()` copies the *values* of exactly those
			// three keys into the global `$post_type_meta_caps` lookup. If a value
			// there is `hedayati_manage_teachers`, then `map_meta_cap()` rewrites a
			// bare `hedayati_manage_teachers` capability check into a per-object
			// `edit_post`/`read_post`/`delete_post` check, which fails for lack of an
			// ID — the exact collision that hid the «اساتید» menu on staging 1.5.1.
			//
			// Distinct names (`edit_hedayati_teacher` etc.) sidestep the collision.
			// They are NEVER added to any role: `map_meta_cap()` maps them back down
			// to the collection caps below, all of which require the single primitive
			// `hedayati_manage_teachers`.
			'capabilities'        => [
				// Per-object meta caps — distinct names, resolved by map_meta_cap().
				'edit_post'              => 'edit_hedayati_teacher',
				'read_post'              => 'read_hedayati_teacher',
				'delete_post'            => 'delete_hedayati_teacher',

				// Collection / status caps — all require the one primitive permission.
				'edit_posts'             => 'hedayati_manage_teachers',
				'edit_others_posts'      => 'hedayati_manage_teachers',
				'delete_posts'           => 'hedayati_manage_teachers',
				'delete_others_posts'    => 'hedayati_manage_teachers',
				'publish_posts'          => 'hedayati_manage_teachers',
				'read_private_posts'     => 'hedayati_manage_teachers',
				'create_posts'           => 'hedayati_manage_teachers',
			],
		] );
	}

	public static function register_meta(): void {
		$auth = static function ( bool $allowed, string $meta_key, int $object_id ): bool {
			return current_user_can( 'edit_post', $object_id );
		};

		register_post_meta( self::POST_TYPE, self::META_USER_ID, [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		] );

		register_post_meta( self::POST_TYPE, self::META_HEADLINE, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'auth_callback'     => $auth,
			'show_in_rest'      => false,
		] );
	}

	// ── Meta box ─────────────────────────────────────────────────────────────

	public static function register_box(): void {
		add_meta_box(
			'hedayati-teacher-details',
			'مشخصات و اتصال حساب کاربری',
			[ self::class, 'render_box' ],
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$headline     = (string) get_post_meta( $post->ID, self::META_HEADLINE, true );
		$linked_user  = (int) get_post_meta( $post->ID, self::META_USER_ID, true );

		echo '<p><label for="hd_teacher_headline"><strong>' . esc_html__( 'عنوان کوتاه', 'hedayati-core' ) . '</strong></label></p>';
		printf(
			'<input type="text" id="hd_teacher_headline" name="hd_teacher_headline" value="%s" class="widefat" placeholder="%s">',
			esc_attr( $headline ),
			esc_attr__( 'مثال: مدرس شبکه و امنیت', 'hedayati-core' )
		);

		echo '<p style="margin-top:1em"><label for="hd_teacher_user_id"><strong>' . esc_html__( 'حساب کاربری مرتبط (اختیاری)', 'hedayati-core' ) . '</strong></label></p>';

		wp_dropdown_users( [
			'id'                => 'hd_teacher_user_id',
			'name'              => 'hd_teacher_user_id',
			'selected'          => $linked_user,
			'show_option_none'  => esc_html__( '— بدون حساب کاربری —', 'hedayati-core' ),
			'option_none_value' => 0,
			'class'             => 'widefat',
		] );

		echo '<p class="description">' . esc_html__( 'اتصال حساب کاربری فقط برای اساتیدی لازم است که به سامانه وارد می‌شوند. هر حساب کاربری تنها به یک استاد قابل اتصال است.', 'hedayati-core' ) . '</p>';
	}

	public static function save( int $post_id, WP_Post $post ): void {
		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$headline = isset( $_POST['hd_teacher_headline'] )
			? sanitize_text_field( wp_unslash( $_POST['hd_teacher_headline'] ) )
			: '';
		update_post_meta( $post_id, self::META_HEADLINE, $headline );

		$requested_user = isset( $_POST['hd_teacher_user_id'] )
			? absint( wp_unslash( $_POST['hd_teacher_user_id'] ) )
			: 0;

		if ( $requested_user > 0 && get_user_by( 'id', $requested_user ) ) {
			$owner = self::find_by_user_id( $requested_user );

			// Reject the link only if another teacher profile already claims this user.
			if ( null === $owner || $owner === $post_id ) {
				update_post_meta( $post_id, self::META_USER_ID, $requested_user );
			} else {
				update_post_meta( $post_id, self::META_USER_ID, 0 );
				set_transient(
					'hedayati_teacher_notice_' . get_current_user_id(),
					esc_html__( 'حساب کاربری انتخاب‌شده قبلاً به استاد دیگری متصل است؛ اتصال ذخیره نشد.', 'hedayati-core' ),
					60
				);
			}
		} else {
			update_post_meta( $post_id, self::META_USER_ID, 0 );
		}
	}

	// ── List table columns ──────────────────────────────────────────────────

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function columns( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['hd_headline'] = esc_html__( 'عنوان کوتاه', 'hedayati-core' );
				$out['hd_account']  = esc_html__( 'حساب کاربری', 'hedayati-core' );
			}
		}
		return $out;
	}

	public static function column_content( string $column, int $post_id ): void {
		if ( 'hd_headline' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_HEADLINE, true ) ?: '—' );
			return;
		}

		if ( 'hd_account' === $column ) {
			$user_id = self::get_user_id( $post_id );
			if ( null === $user_id ) {
				echo '—';
				return;
			}
			$user = get_user_by( 'id', $user_id );
			echo esc_html( $user ? $user->user_login : sprintf( '#%d', $user_id ) );
		}
	}

	// ── Lifecycle ────────────────────────────────────────────────────────────

	/**
	 * When a WP user is deleted, unlink (do not delete) any Teacher profile that
	 * referenced it — the public instructor identity must survive an account removal.
	 */
	public static function on_user_deleted( int $user_id ): void {
		$teacher_id = self::find_by_user_id( $user_id );

		if ( null !== $teacher_id ) {
			update_post_meta( $teacher_id, self::META_USER_ID, 0 );
			Hedayati_Audit_Log::record( 'teacher.unlinked', 'teacher', $teacher_id, 'linked account deleted' );
		}
	}

	// ── Query helpers ────────────────────────────────────────────────────────

	/**
	 * Whether a published/draft Teacher profile exists for the given post ID.
	 */
	public static function exists( int $teacher_id ): bool {
		if ( $teacher_id <= 0 ) {
			return false;
		}

		$post = get_post( $teacher_id );

		return $post instanceof WP_Post
			&& self::POST_TYPE === $post->post_type
			&& ! in_array( $post->post_status, [ 'trash', 'auto-draft' ], true );
	}

	/**
	 * Return the WP user ID linked to a Teacher profile, or null.
	 */
	public static function get_user_id( int $teacher_id ): ?int {
		$linked = (int) get_post_meta( $teacher_id, self::META_USER_ID, true );

		return $linked > 0 ? $linked : null;
	}

	/**
	 * Return the Teacher profile post ID linked to a WP user, or null.
	 */
	public static function find_by_user_id( int $user_id ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}

		$found = get_posts( [
			'post_type'        => self::POST_TYPE,
			'post_status'      => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => self::META_USER_ID,
			'meta_value'       => $user_id,
			'suppress_filters' => false,
		] );

		return ! empty( $found ) ? (int) $found[0] : null;
	}
}
