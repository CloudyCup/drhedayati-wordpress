<?php
/**
 * Manager Experience — Phase C (owner decision D53): in-panel course create/edit.
 *
 * `/panel/?view=course-new` and `/panel/?view=course-edit&course_id=N` — a full
 * front-end editor for every institute-facing field the system supports, over
 * the **canonical `course` CPT**. No second storage model:
 *
 *   - title / slug           → the post itself
 *   - content / excerpt      → post_content (wp_kses_post) / post_excerpt
 *   - featured image         → set_post_thumbnail() from an EXISTING image
 *                              attachment (no upload path, no new capability)
 *   - course-category        → wp_set_post_terms(), optional inline term create
 *   - _course_* meta         → the exact keys + sanitizers in Hedayati_Course_Meta
 *   - _course_is_featured    → same 8-slot homepage cap as Hedayati_Staff_Portal
 *   - menu_order             → page-attributes ordering
 *   - publish / draft        → post_status
 *
 * Security: `hedayati_manage_courses` (via guard_action) + a re-checked per-object
 * `current_user_can( 'edit_post', $course_id )` on every edit/save. POST-only
 * state changes through `admin-post.php`. All output escaped, all input through
 * the canonical sanitizers. The administrator keeps Gutenberg / the native CPT
 * editor as a maintenance interface.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Course_Panel {

	public const CAP            = 'hedayati_manage_courses';
	public const VIEW_NEW       = 'course-new';
	public const VIEW_EDIT      = 'course-edit';
	private const NONCE         = 'hedayati_course_panel_save';
	private const FEATURED_LIMIT = 8;
	private const IMAGE_CHOICES  = 60;

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_views' ] );
		add_action( 'admin_post_' . self::NONCE, [ self::class, 'handle_save' ] );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [ 'course.created', 'course.updated' ] ) );
	}

	/**
	 * @param array<string,array> $views
	 * @return array<string,array>
	 */
	public static function register_views( array $views ): array {
		// nav/title deliberately absent: these views are reached from the
		// courses list, not the sidebar or the manager dashboard.
		$def = [ 'capability' => self::CAP, 'render' => [ self::class, 'render_panel' ] ];
		$views[ self::VIEW_NEW ]  = $def;
		$views[ self::VIEW_EDIT ] = $def;

		return $views;
	}

	private static function url( array $args = [] ): string {
		return Hedayati_Staff_Portal::url( $args );
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$post = null;

		if ( self::VIEW_EDIT === $view ) {
			$course_id = isset( $_GET['course_id'] ) ? absint( wp_unslash( $_GET['course_id'] ) ) : 0;
			$post      = $course_id > 0 ? get_post( $course_id ) : null;

			if ( ! $post instanceof WP_Post || 'course' !== $post->post_type || 'trash' === $post->post_status ) {
				echo '<p class="hd-portal-notice hd-portal-notice-error">' . esc_html__( 'دوره یافت نشد.', 'hedayati-core' ) . '</p>';
				printf( '<p><a class="hd-portal-nav-link" href="%s">%s</a></p>', esc_url( self::url( [ 'view' => 'courses' ] ) ), esc_html__( 'بازگشت به فهرست دوره‌ها', 'hedayati-core' ) );
				return;
			}
			if ( ! current_user_can( 'edit_post', $course_id ) ) {
				wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
			}
		}

		self::render_form( $post );
	}

	private static function render_form( ?WP_Post $post ): void {
		$is_edit = $post instanceof WP_Post;
		$id      = $is_edit ? (int) $post->ID : 0;

		$m = static fn( string $key ): string => $is_edit ? (string) get_post_meta( $id, $key, true ) : '';
		$arr = static function ( string $key ) use ( $is_edit, $id ): array {
			if ( ! $is_edit ) {
				return [];
			}
			$v = get_post_meta( $id, $key, true );
			return is_array( $v ) ? $v : [];
		};

		$title       = $is_edit ? (string) $post->post_title : '';
		$content     = $is_edit ? (string) $post->post_content : '';
		$excerpt     = $is_edit ? (string) $post->post_excerpt : '';
		$menu_order  = $is_edit ? (int) $post->menu_order : 0;
		$published   = $is_edit ? ( 'publish' === $post->post_status ) : false;
		$reg_state   = Hedayati_Course_Meta::sanitize_registration_state( $m( '_course_registration_state' ) ?: 'soon' );
		$start_iso   = $m( '_course_next_start_date' );
		$is_featured = $is_edit && (bool) get_post_meta( $id, '_course_is_featured', true );
		$thumb_id    = $is_edit ? (int) get_post_thumbnail_id( $id ) : 0;
		$assigned    = $is_edit ? wp_get_post_terms( $id, 'course-category', [ 'fields' => 'ids' ] ) : [];
		$assigned    = is_array( $assigned ) ? array_map( 'intval', $assigned ) : [];
		$featured_now = self::featured_count();

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'مدیریت محتوا', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html( $is_edit ? __( 'ویرایش دوره', 'hedayati-core' ) : __( 'تعریف دورهٔ جدید', 'hedayati-core' ) ) . '</h1>';
		echo '</div>';
		printf( '<a class="hd-portal-nav-link" href="%s">%s</a>', esc_url( self::url( [ 'view' => 'courses' ] ) ), esc_html__( 'بازگشت به فهرست', 'hedayati-core' ) );
		echo '</header>';

		echo '<form class="hd-portal-form hd-course-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE ) . '">';
		echo '<input type="hidden" name="course_id" value="' . esc_attr( (string) $id ) . '">';

		// ── Identity ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'شناسنامهٔ دوره', 'hedayati-core' ) . '</legend>';
		self::text( 'title', __( 'عنوان فارسی دوره', 'hedayati-core' ), $title, true );
		self::text( 'english_name', __( 'نام انگلیسی / کد استاندارد', 'hedayati-core' ), $m( '_course_english_name' ), false, 'ltr', 'مثال: CCNA 200-301' );
		self::text( 'teacher', __( 'مدرس / اساتید دوره (نمایشی)', 'hedayati-core' ), $m( '_course_teacher' ) );
		self::text( 'duration', __( 'طول مدت دوره', 'hedayati-core' ), $m( '_course_duration' ), false, 'rtl', 'مثال: ۴۸ ساعت (۱۲ جلسه)' );
		self::text( 'level', __( 'سطح دوره', 'hedayati-core' ), $m( '_course_level' ), false, 'rtl', 'مثال: مقدماتی تا پیشرفته' );
		self::text( 'price', __( 'شهریه دوره', 'hedayati-core' ), $m( '_course_price' ), false, 'rtl', 'خالی بگذارید اگر نمایش لازم نیست' );
		echo '</fieldset>';

		// ── Description ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'معرفی و محتوا', 'hedayati-core' ) . '</legend>';
		self::textarea( 'content', __( 'متن کامل معرفی دوره', 'hedayati-core' ), $content, 10 );
		self::textarea( 'excerpt', __( 'خلاصهٔ کوتاه (برای کارت دوره)', 'hedayati-core' ), $excerpt, 3 );
		self::textarea( 'prerequisites', __( 'پیش‌نیازهای ورود به دوره', 'hedayati-core' ), $m( '_course_prerequisites' ), 2 );
		echo '</fieldset>';

		// ── Structured lists ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'فهرست‌های ساختاریافته — هر مورد در یک خط', 'hedayati-core' ) . '</legend>';
		self::textarea( 'syllabus', __( 'سرفصل‌های کلیدی', 'hedayati-core' ), implode( "\n", $arr( '_course_syllabus' ) ), 4 );
		self::textarea( 'target_audience', __( 'این دوره برای چه کسانی مناسب است؟', 'hedayati-core' ), implode( "\n", $arr( '_course_target_audience' ) ), 4 );
		self::textarea( 'learning_outcomes', __( 'دستاوردها و مهارت‌های پس از دوره', 'hedayati-core' ), implode( "\n", $arr( '_course_learning_outcomes' ) ), 4 );
		echo '</fieldset>';

		// ── Schedule / state ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'زمان‌بندی و وضعیت', 'hedayati-core' ) . '</legend>';
		self::date_field( 'next_start_date', __( 'تاریخ شروع دورهٔ بعدی (شمسی یا میلادی)', 'hedayati-core' ), $start_iso );
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'وضعیت ثبت‌نام', 'hedayati-core' ) . '</span><select name="registration_state">';
		foreach ( [ 'open' => __( 'باز — ثبت‌نام فعال', 'hedayati-core' ), 'closed' => __( 'بسته', 'hedayati-core' ), 'soon' => __( 'به‌زودی', 'hedayati-core' ) ] as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( $reg_state, $val, false ), esc_html( $label ) );
		}
		echo '</select></label>';
		self::text( 'menu_order', __( 'اولویت نمایش (عدد کمتر = بالاتر)', 'hedayati-core' ), (string) $menu_order, false, 'ltr' );
		printf(
			'<label class="hd-manager-check"><input type="checkbox" name="is_featured" value="1"%s> %s</label>',
			checked( $is_featured, true, false ),
			esc_html( sprintf(
				/* translators: 1: current featured count, 2: limit */
				__( 'نمایش به‌عنوان دورهٔ ویژهٔ صفحهٔ نخست (%1$s از %2$s جایگاه پر است)', 'hedayati-core' ),
				Hedayati_Text::digits_to_persian( (string) $featured_now ),
				Hedayati_Text::digits_to_persian( (string) self::FEATURED_LIMIT )
			) )
		);
		printf(
			'<label class="hd-manager-check"><input type="checkbox" name="published" value="1"%s> %s</label>',
			checked( $published, true, false ),
			esc_html__( 'انتشار عمومی این دوره', 'hedayati-core' )
		);
		echo '</fieldset>';

		// ── Categories ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'دسته‌بندی', 'hedayati-core' ) . '</legend>';
		$terms = get_terms( [ 'taxonomy' => 'course-category', 'hide_empty' => false ] );
		if ( is_array( $terms ) && $terms ) {
			echo '<div class="hd-course-checkboxes">';
			foreach ( $terms as $term ) {
				printf(
					'<label class="hd-manager-check"><input type="checkbox" name="course_category[]" value="%d"%s> %s</label>',
					(int) $term->term_id,
					checked( in_array( (int) $term->term_id, $assigned, true ), true, false ),
					esc_html( $term->name )
				);
			}
			echo '</div>';
		}
		self::text( 'new_category', __( 'یا افزودن دستهٔ جدید (اختیاری)', 'hedayati-core' ), '' );
		echo '</fieldset>';

		// ── Featured image (existing media only) ──
		echo '<fieldset class="hd-course-fieldset"><legend>' . esc_html__( 'تصویر شاخص', 'hedayati-core' ) . '</legend>';
		echo '<p class="hd-portal-note">' . esc_html__( 'یک تصویر از کتابخانهٔ رسانهٔ موجود انتخاب کنید. برای بارگذاری تصویر جدید، مدیر فنی از پیشخوان وردپرس استفاده می‌کند.', 'hedayati-core' ) . '</p>';
		echo '<div class="hd-course-media">';
		printf(
			'<label class="hd-course-media-item"><input type="radio" name="featured_image" value="0"%s><span>%s</span></label>',
			checked( 0, $thumb_id, false ),
			esc_html__( 'بدون تصویر', 'hedayati-core' )
		);
		$images = get_posts( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'numberposts'    => self::IMAGE_CHOICES,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );
		if ( $thumb_id > 0 && ! in_array( $thumb_id, wp_list_pluck( $images, 'ID' ), true ) ) {
			$current = get_post( $thumb_id );
			if ( $current ) {
				array_unshift( $images, $current );
			}
		}
		foreach ( $images as $img ) {
			printf(
				'<label class="hd-course-media-item"><input type="radio" name="featured_image" value="%d"%s>%s</label>',
				(int) $img->ID,
				checked( (int) $img->ID, $thumb_id, false ),
				wp_get_attachment_image( (int) $img->ID, [ 90, 90 ], true )
			);
		}
		echo '</div></fieldset>';

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ دوره', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	private static function text( string $name, string $label, string $value, bool $required = false, string $dir = 'rtl', string $placeholder = '' ): void {
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="%s" value="%s" dir="%s"%s%s></label>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $dir ),
			$required ? ' required' : '',
			'' !== $placeholder ? ' placeholder="' . esc_attr( $placeholder ) . '"' : ''
		);
	}

	private static function textarea( string $name, string $label, string $value, int $rows ): void {
		printf(
			'<label class="hd-portal-field"><span>%s</span><textarea name="%s" rows="%d">%s</textarea></label>',
			esc_html( $label ),
			esc_attr( $name ),
			$rows,
			esc_textarea( $value )
		);
	}

	private static function date_field( string $name, string $label, string $iso ): void {
		$shamsi = '' !== $iso ? Hedayati_Jalali::format( $iso, false ) : '';
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="text" name="%s" value="%s" dir="ltr" placeholder="۱۴۰۵/۰۶/۱۵ یا 2026-09-06"></label>',
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $shamsi )
		);
	}

	// ── Featured cap helper (mirrors Hedayati_Staff_Portal) ──────────────────

	private static function featured_count(): int {
		$q = new WP_Query( [
			'post_type'      => 'course',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'meta_query'     => [
				[ 'key' => '_course_is_featured', 'value' => '1', 'compare' => '=' ],
			],
		] );

		return (int) $q->found_posts;
	}

	// ── Save ────────────────────────────────────────────────────────────────

	public static function handle_save(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE, self::CAP );

		$str = static fn( string $key ): string => isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? (string) wp_unslash( $_POST[ $key ] )
			: '';

		$course_id = absint( $str( 'course_id' ) );
		$title     = sanitize_text_field( $str( 'title' ) );
		$content   = wp_kses_post( $str( 'content' ) );
		$excerpt   = sanitize_textarea_field( $str( 'excerpt' ) );
		$published = ! empty( $_POST['published'] );
		$status    = $published ? 'publish' : 'draft';

		if ( '' === $title ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'title', __( 'عنوان دوره لازم است.', 'hedayati-core' ) ),
				[ 'view' => 'courses' ]
			);
		}

		$is_new = 0 === $course_id;

		if ( $is_new ) {
			$result    = wp_insert_post( [
				'post_type'    => 'course',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $status,
			], true );
			$course_id = is_wp_error( $result ) ? 0 : (int) $result;
		} else {
			$post = get_post( $course_id );
			if ( ! $post instanceof WP_Post || 'course' !== $post->post_type || ! current_user_can( 'edit_post', $course_id ) ) {
				wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
			}
			$result = wp_update_post( [
				'ID'           => $course_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_status'  => $status,
			], true );
		}

		if ( is_wp_error( $result ) || $course_id <= 0 ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'save', __( 'ذخیرهٔ دوره ناموفق بود.', 'hedayati-core' ) ),
				[ 'view' => 'courses' ]
			);
		}

		// ── menu_order ──
		$menu_order = absint( $str( 'menu_order' ) );
		if ( get_post_field( 'menu_order', $course_id ) !== $menu_order ) {
			wp_update_post( [ 'ID' => $course_id, 'menu_order' => $menu_order ] );
		}

		// ── Canonical meta (same keys + sanitizers as Hedayati_Course_Meta) ──
		update_post_meta( $course_id, '_course_english_name', sanitize_text_field( $str( 'english_name' ) ) );
		update_post_meta( $course_id, '_course_teacher', sanitize_text_field( $str( 'teacher' ) ) );
		update_post_meta( $course_id, '_course_duration', sanitize_text_field( $str( 'duration' ) ) );
		update_post_meta( $course_id, '_course_level', sanitize_text_field( $str( 'level' ) ) );
		update_post_meta( $course_id, '_course_price', sanitize_text_field( $str( 'price' ) ) );
		update_post_meta( $course_id, '_course_prerequisites', sanitize_textarea_field( $str( 'prerequisites' ) ) );
		update_post_meta( $course_id, '_course_registration_state', Hedayati_Course_Meta::sanitize_registration_state( $str( 'registration_state' ) ) );

		$raw_date  = trim( $str( 'next_start_date' ) );
		$iso       = '' === $raw_date
			? ''
			: ( Hedayati_Course_Meta::sanitize_iso_date( $raw_date ) ?: (string) Hedayati_Jalali::parse_input( $raw_date ) );
		update_post_meta( $course_id, '_course_next_start_date', Hedayati_Course_Meta::sanitize_iso_date( $iso ) );

		foreach ( [
			'syllabus'          => '_course_syllabus',
			'target_audience'   => '_course_target_audience',
			'learning_outcomes' => '_course_learning_outcomes',
		] as $field => $meta_key ) {
			$lines = preg_split( '/\r\n|\r|\n/', $str( $field ) ) ?: [];
			update_post_meta( $course_id, $meta_key, Hedayati_Course_Meta::sanitize_string_array( $lines ) );
		}

		// ── Featured flag — enforce the 8-slot homepage cap server-side ──
		$want_featured = ! empty( $_POST['is_featured'] );
		$currently     = (bool) get_post_meta( $course_id, '_course_is_featured', true );
		$featured_error = null;
		if ( $want_featured && ! $currently && self::featured_count() >= self::FEATURED_LIMIT ) {
			$featured_error = __( 'دوره ذخیره شد، اما حداکثر ۸ دوره می‌تواند در صفحهٔ نخست ویژه باشد؛ وضعیت ویژه اعمال نشد.', 'hedayati-core' );
		} else {
			update_post_meta( $course_id, '_course_is_featured', $want_featured );
		}

		// ── Categories ──
		$cat_ids = isset( $_POST['course_category'] ) && is_array( $_POST['course_category'] )
			? array_values( array_filter( array_map( 'absint', wp_unslash( $_POST['course_category'] ) ) ) )
			: [];
		// Inline term create — guard_action() already confirmed hedayati_manage_courses,
		// which the course-category taxonomy maps manage_terms/edit_terms to.
		$new_cat = sanitize_text_field( $str( 'new_category' ) );
		if ( '' !== $new_cat ) {
			$existing = term_exists( $new_cat, 'course-category' );
			$term     = $existing ?: wp_insert_term( $new_cat, 'course-category' );
			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				$cat_ids[] = (int) $term['term_id'];
			}
		}
		wp_set_post_terms( $course_id, $cat_ids, 'course-category', false );

		// ── Featured image — existing image attachment only ──
		$thumb = absint( $str( 'featured_image' ) );
		if ( 0 === $thumb ) {
			delete_post_thumbnail( $course_id );
		} elseif ( wp_attachment_is_image( $thumb ) ) {
			set_post_thumbnail( $course_id, $thumb );
		}

		Hedayati_Audit_Log::record( $is_new ? 'course.created' : 'course.updated', 'course', $course_id, 'via panel', get_current_user_id() );

		Hedayati_Staff_Portal::redirect_notice(
			null === $featured_error ? true : new WP_Error( 'featured_full', $featured_error ),
			[ 'view' => 'course-edit', 'course_id' => $course_id ]
		);
	}
}
