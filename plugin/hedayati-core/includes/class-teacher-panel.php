<?php
/**
 * Manager Experience (owner decision D53) — Teacher management inside `/panel/`.
 *
 * The manager clicking «اساتید» stays in the professional panel instead of being
 * dropped into the classic `edit.php?post_type=teacher` list table. This is a
 * thin front-end over the EXISTING canonical `teacher` CPT
 * (`Hedayati_Teacher`) — same post type, same `_hedayati_teacher_*` meta keys,
 * same 1:1 WP-user link rule, same `hedayati_manage_teachers` capability and the
 * same per-object `edit_post` / `delete_post` map. No second Teacher store.
 *
 * The native CPT screens remain available to the actual `administrator` as a
 * maintenance fallback and read/write the very same records.
 *
 * Every mutation is an `admin-post.php` action guarded by
 * `Hedayati_Staff_Portal::guard_action()` (POST + capability + nonce) plus an
 * explicit per-object `current_user_can( 'edit_post'|'delete_post', $id )` check
 * re-done in the handler.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Teacher_Panel {

	public const VIEW      = 'teachers';
	public const CAPABILITY = 'hedayati_manage_teachers';

	private const NONCE_SAVE  = 'hedayati_teacher_panel_save';
	private const NONCE_TRASH = 'hedayati_teacher_panel_trash';

	/** Teacher post statuses this screen lists/edits (never `trash`/`auto-draft`). */
	private const STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
		add_action( 'admin_post_' . self::NONCE_SAVE, [ self::class, 'handle_save' ] );
		add_action( 'admin_post_' . self::NONCE_TRASH, [ self::class, 'handle_trash' ] );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [ 'teacher.updated', 'teacher.trashed' ] ) );
	}

	/**
	 * @param array<string,array> $views
	 * @return array<string,array>
	 */
	public static function register_panel_view( array $views ): array {
		$views[ self::VIEW ] = [
			'capability' => self::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'اساتید', 'hedayati-core' ),
			'title'      => __( 'اساتید', 'hedayati-core' ),
			'desc'       => __( 'پروفایل عمومی استادها، اتصال حساب کاربری و وضعیت انتشار', 'hedayati-core' ),
			'icon'       => 'teacher',
		];

		return $views;
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$raw_id  = isset( $_GET['teacher_id'] ) && is_string( $_GET['teacher_id'] )
			? sanitize_text_field( wp_unslash( $_GET['teacher_id'] ) )
			: '';
		$edit_id = absint( $raw_id );

		if ( 'new' === $raw_id ) {
			self::render_form( null );
			return;
		}

		if ( $edit_id > 0 ) {
			$post = get_post( $edit_id );
			if ( ! $post instanceof WP_Post || Hedayati_Teacher::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
				echo '<p class="hd-portal-notice hd-portal-notice-error">' . esc_html__( 'استاد یافت نشد.', 'hedayati-core' ) . '</p>';
				self::render_list();
				return;
			}
			if ( ! current_user_can( 'edit_post', $edit_id ) ) {
				wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
			}
			self::render_form( $post );
			return;
		}

		self::render_list();
	}

	private static function list_url( array $args = [] ): string {
		return Hedayati_Staff_Portal::url( array_merge( [ 'view' => self::VIEW ], $args ) );
	}

	private static function render_list(): void {
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		$query = new WP_Query( [
			'post_type'      => Hedayati_Teacher::POST_TYPE,
			'post_status'    => self::STATUSES,
			'posts_per_page' => 200,
			'orderby'        => 'title',
			'order'          => 'ASC',
			's'              => $search,
			'no_found_rows'  => true,
		] );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'شناسنامهٔ آموزشی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'اساتید مجتمع', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'پروفایل هر استاد شامل نام، عنوان کوتاه، زیست‌نامه و تصویر است. اتصال حساب کاربری فقط برای استادهایی لازم است که وارد سامانه می‌شوند.', 'hedayati-core' ) . '</p>';
		echo '</div>';
		printf(
			'<a class="hd-manager-primary" href="%s">%s</a>',
			esc_url( self::list_url( [ 'teacher_id' => 'new' ] ) ),
			esc_html__( 'افزودن استاد', 'hedayati-core' )
		);
		echo '</header>';

		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="' . esc_attr( self::VIEW ) . '">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجوی استاد', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'جستجو در نام استاد…', 'hedayati-core' )
		);
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'جستجو', 'hedayati-core' ) );
		echo '</form>';

		if ( ! $query->have_posts() ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'استادی یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-table" role="table">';
		echo '<div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [
			__( 'نام استاد', 'hedayati-core' ),
			__( 'عنوان کوتاه', 'hedayati-core' ),
			__( 'حساب کاربری', 'hedayati-core' ),
			__( 'وضعیت', 'hedayati-core' ),
			__( 'مدیریت', 'hedayati-core' ),
		] as $heading ) {
			echo '<span role="columnheader">' . esc_html( $heading ) . '</span>';
		}
		echo '</div>';

		foreach ( $query->posts as $post ) {
			$teacher_id = (int) $post->ID;
			$headline   = (string) get_post_meta( $teacher_id, Hedayati_Teacher::META_HEADLINE, true );
			$user_id    = Hedayati_Teacher::get_user_id( $teacher_id );
			$account    = null;
			if ( null !== $user_id ) {
				$linked  = get_user_by( 'id', $user_id );
				$account = $linked ? $linked->user_login : sprintf( '#%d', $user_id );
			}
			$status_label = 'publish' === $post->post_status
				? __( 'منتشر شده', 'hedayati-core' )
				: __( 'پیش‌نویس', 'hedayati-core' );

			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell"><strong>' . esc_html( get_the_title( $post ) ?: __( '(بدون نام)', 'hedayati-core' ) ) . '</strong></span>';
			echo '<span role="cell">' . ( '' !== $headline ? esc_html( $headline ) : '<span class="hd-portal-note">—</span>' ) . '</span>';
			echo '<span role="cell">' . ( null !== $account ? '<bdi>' . esc_html( $account ) . '</bdi>' : '<span class="hd-portal-note">—</span>' ) . '</span>';
			echo '<span role="cell">' . esc_html( $status_label ) . '</span>';
			echo '<span role="cell">';
			printf(
				'<a class="hd-manager-row-edit" href="%s">%s</a>',
				esc_url( self::list_url( [ 'teacher_id' => $teacher_id ] ) ),
				esc_html__( 'ویرایش', 'hedayati-core' )
			);
			if ( current_user_can( 'delete_post', $teacher_id ) ) {
				echo ' ';
				self::trash_button( $teacher_id );
			}
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	private static function trash_button( int $teacher_id ): void {
		printf(
			'<form class="hd-manager-inline-form" method="post" action="%s" onsubmit="return confirm(%s);">',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( "'" . esc_js( __( 'این پروفایل استاد به زباله‌دان منتقل شود؟', 'hedayati-core' ) ) . "'" )
		);
		wp_nonce_field( self::NONCE_TRASH );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_TRASH ) . '">';
		echo '<input type="hidden" name="teacher_id" value="' . esc_attr( (string) $teacher_id ) . '">';
		echo '<button type="submit" class="hd-manager-row-delete">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	private static function render_form( ?WP_Post $post ): void {
		$is_edit    = $post instanceof WP_Post;
		$teacher_id = $is_edit ? (int) $post->ID : 0;
		$title      = $is_edit ? (string) $post->post_title : '';
		$biography  = $is_edit ? (string) $post->post_content : '';
		$headline   = $is_edit ? (string) get_post_meta( $teacher_id, Hedayati_Teacher::META_HEADLINE, true ) : '';
		$linked     = $is_edit ? (int) get_post_meta( $teacher_id, Hedayati_Teacher::META_USER_ID, true ) : 0;
		$published  = $is_edit ? ( 'publish' === $post->post_status ) : false;

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'پروفایل استاد', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html( $is_edit ? __( 'ویرایش استاد', 'hedayati-core' ) : __( 'افزودن استاد جدید', 'hedayati-core' ) ) . '</h1>';
		echo '</div>';
		printf( '<a class="hd-portal-nav-link" href="%s">%s</a>', esc_url( self::list_url() ), esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) );
		echo '</header>';

		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_SAVE ) . '">';
		echo '<input type="hidden" name="teacher_id" value="' . esc_attr( (string) $teacher_id ) . '">';

		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="title" value="%s" required></label>',
			esc_html__( 'نام و نام خانوادگی استاد', 'hedayati-core' ),
			esc_attr( $title )
		);
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="headline" value="%s" placeholder="%s"></label>',
			esc_html__( 'عنوان کوتاه', 'hedayati-core' ),
			esc_attr( $headline ),
			esc_attr__( 'مثال: مدرس شبکه و امنیت', 'hedayati-core' )
		);
		printf(
			'<label class="hd-portal-field"><span>%s</span><textarea name="biography" rows="6">%s</textarea></label>',
			esc_html__( 'زیست‌نامه (اختیاری)', 'hedayati-core' ),
			esc_textarea( $biography )
		);

		echo '<label class="hd-portal-field"><span>' . esc_html__( 'حساب کاربری مرتبط (اختیاری)', 'hedayati-core' ) . '</span>';
		wp_dropdown_users( [
			'name'              => 'linked_user',
			'selected'          => $linked,
			'show_option_none'  => esc_html__( '— بدون حساب کاربری —', 'hedayati-core' ),
			'option_none_value' => 0,
			'role__in'          => [ 'teacher', 'teacher_assistant' ],
		] );
		echo '</label>';
		echo '<p class="hd-portal-note">' . esc_html__( 'هر حساب کاربری تنها به یک استاد قابل اتصال است. اگر حساب انتخاب‌شده قبلاً به استاد دیگری متصل باشد، اتصال ذخیره نمی‌شود.', 'hedayati-core' ) . '</p>';

		printf(
			'<label class="hd-manager-check"><input type="checkbox" name="published" value="1"%s> %s</label>',
			checked( $published, true, false ),
			esc_html__( 'انتشار عمومی این پروفایل', 'hedayati-core' )
		);

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ پروفایل استاد', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_save(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_SAVE, self::CAPABILITY );

		$str = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? (string) wp_unslash( $_POST[ $key ] )
			: '';

		$teacher_id = absint( $str( 'teacher_id' ) );
		$title      = sanitize_text_field( $str( 'title' ) );
		$headline   = sanitize_text_field( $str( 'headline' ) );
		$biography  = wp_kses_post( $str( 'biography' ) );
		$linked     = absint( $str( 'linked_user' ) );
		$published  = ! empty( $_POST['published'] );

		if ( '' === $title ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'title', __( 'نام استاد لازم است.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		$status = $published ? 'publish' : 'draft';

		if ( $teacher_id > 0 ) {
			$post = get_post( $teacher_id );
			if ( ! $post instanceof WP_Post || Hedayati_Teacher::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $teacher_id ) ) {
				wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
			}
			$result = wp_update_post( [
				'ID'           => $teacher_id,
				'post_title'   => $title,
				'post_content' => $biography,
				'post_status'  => $status,
			], true );
		} else {
			// Creation: guard_action() above already enforced hedayati_manage_teachers,
			// which the teacher CPT maps create_posts/publish_posts to.
			$result = wp_insert_post( [
				'post_type'    => Hedayati_Teacher::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $biography,
				'post_status'  => $status,
			], true );
			$teacher_id = is_wp_error( $result ) ? 0 : (int) $result;
		}

		if ( is_wp_error( $result ) || $teacher_id <= 0 ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'save', __( 'ذخیرهٔ پروفایل استاد ناموفق بود.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW ]
			);
		}

		update_post_meta( $teacher_id, Hedayati_Teacher::META_HEADLINE, $headline );

		// 1:1 WP-user link — mirrors Hedayati_Teacher::save() exactly.
		$link_conflict = false;
		if ( $linked > 0 && get_user_by( 'id', $linked ) ) {
			$owner = Hedayati_Teacher::find_by_user_id( $linked );
			if ( null === $owner || $owner === $teacher_id ) {
				update_post_meta( $teacher_id, Hedayati_Teacher::META_USER_ID, $linked );
			} else {
				update_post_meta( $teacher_id, Hedayati_Teacher::META_USER_ID, 0 );
				$link_conflict = true;
			}
		} else {
			update_post_meta( $teacher_id, Hedayati_Teacher::META_USER_ID, 0 );
		}

		Hedayati_Audit_Log::record( 'teacher.updated', 'teacher', $teacher_id, 'via panel', get_current_user_id() );

		if ( $link_conflict ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'link', __( 'پروفایل ذخیره شد، اما حساب کاربری انتخاب‌شده قبلاً به استاد دیگری متصل است؛ اتصال ذخیره نشد.', 'hedayati-core' ) ),
				[ 'view' => self::VIEW, 'teacher_id' => $teacher_id ]
			);
		}

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => self::VIEW, 'teacher_id' => $teacher_id ] );
	}

	public static function handle_trash(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_TRASH, self::CAPABILITY );

		$teacher_id = isset( $_POST['teacher_id'] ) ? absint( wp_unslash( $_POST['teacher_id'] ) ) : 0;
		$post       = $teacher_id > 0 ? get_post( $teacher_id ) : null;

		if ( ! $post instanceof WP_Post || Hedayati_Teacher::POST_TYPE !== $post->post_type || ! current_user_can( 'delete_post', $teacher_id ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$trashed = wp_trash_post( $teacher_id );

		if ( $trashed ) {
			Hedayati_Audit_Log::record( 'teacher.trashed', 'teacher', $teacher_id, 'via panel', get_current_user_id() );
		}

		Hedayati_Staff_Portal::redirect_notice(
			$trashed ? true : new WP_Error( 'trash', __( 'انتقال به زباله‌دان ناموفق بود.', 'hedayati-core' ) ),
			[ 'view' => self::VIEW ]
		);
	}
}
