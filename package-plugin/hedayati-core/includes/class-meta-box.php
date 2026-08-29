<?php
/**
 * Admin meta box for the Course CPT.
 *
 * Security model:
 *   - Nonce verification on every save
 *   - Capability check via current_user_can( 'edit_post', $post_id )
 *   - Autosave guard
 *   - All values sanitized through Hedayati_Course_Meta sanitizers (allowlist for
 *     registration state, ISO date validation) and WordPress core sanitizers
 *   - All output escaped with esc_attr / esc_textarea / esc_html
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Meta_Box {

	private const NONCE_ACTION = 'hedayati_course_meta_save';
	private const NONCE_FIELD  = 'hedayati_course_meta_nonce';

	// ── Registration ─────────────────────────────────────────────────────────

	public static function register_boxes(): void {
		add_meta_box(
			'hedayati-course-details',
			'جزئیات دوره آموزشی',
			[ self::class, 'render' ],
			'course',
			'normal',
			'high'
		);
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		// Read current stored values
		$english_name  = (string) get_post_meta( $post->ID, '_course_english_name', true );
		$teacher       = (string) get_post_meta( $post->ID, '_course_teacher', true );
		$duration      = (string) get_post_meta( $post->ID, '_course_duration', true );
		$level         = (string) get_post_meta( $post->ID, '_course_level', true );
		$prerequisites = (string) get_post_meta( $post->ID, '_course_prerequisites', true );
		$price         = (string) get_post_meta( $post->ID, '_course_price', true );
		$reg_state_raw = (string) get_post_meta( $post->ID, '_course_registration_state', true );
		$reg_state     = Hedayati_Course_Meta::sanitize_registration_state(
			'' !== $reg_state_raw ? $reg_state_raw : 'soon'
		);
		$start_date    = (string) get_post_meta( $post->ID, '_course_next_start_date', true );
		$is_featured   = (bool) get_post_meta( $post->ID, '_course_is_featured', true );

		// Registration state options
		$states = [
			'open'   => 'باز — ثبت‌نام فعال',
			'closed' => 'بسته — ثبت‌نام بسته',
			'soon'   => 'به‌زودی',
		];
		?>
		<div class="hd-meta-box">
			<div class="hd-meta-grid">

				<div class="hd-meta-field hd-featured-field">
					<label class="hd-featured-label">
						<input
							type="checkbox"
							id="hm_is_featured"
							name="hm_is_featured"
							value="1"
							<?php checked( $is_featured ); ?>
						>
						<span>نمایش در صفحه اصلی <small>(دوره ویژه)</small></span>
					</label>
				</div>

				<div class="hd-meta-field">
					<label for="hm_english_name">نام انگلیسی / لاتین</label>
					<input
						type="text"
						id="hm_english_name"
						name="hm_english_name"
						value="<?php echo esc_attr( $english_name ); ?>"
						placeholder="e.g. CCNA, Python, Network+"
					>
					<p class="hd-hint">برای نمایش روی کارت دوره استفاده می‌شود.</p>
				</div>

				<div class="hd-meta-field">
					<label for="hm_teacher">استاد / مدرس</label>
					<input
						type="text"
						id="hm_teacher"
						name="hm_teacher"
						value="<?php echo esc_attr( $teacher ); ?>"
					>
				</div>

				<div class="hd-meta-field">
					<label for="hm_duration">مدت دوره</label>
					<input
						type="text"
						id="hm_duration"
						name="hm_duration"
						value="<?php echo esc_attr( $duration ); ?>"
						placeholder="مثال: ۴۸ ساعت"
					>
				</div>

				<div class="hd-meta-field">
					<label for="hm_level">سطح دوره</label>
					<input
						type="text"
						id="hm_level"
						name="hm_level"
						value="<?php echo esc_attr( $level ); ?>"
						placeholder="مثال: مقدماتی، متوسط، پیشرفته"
					>
				</div>

				<div class="hd-meta-field">
					<label for="hm_price">قیمت / هزینه</label>
					<input
						type="text"
						id="hm_price"
						name="hm_price"
						value="<?php echo esc_attr( $price ); ?>"
						placeholder="مثال: ۲٬۵۰۰٬۰۰۰ تومان"
					>
				</div>

				<div class="hd-meta-field">
					<label for="hm_registration_state">وضعیت ثبت‌نام</label>
					<select id="hm_registration_state" name="hm_registration_state">
						<?php foreach ( $states as $val => $label ) : ?>
							<option
								value="<?php echo esc_attr( $val ); ?>"
								<?php selected( $reg_state, $val ); ?>
							>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="hd-meta-field">
					<label for="hm_next_start_date">تاریخ شروع دوره بعدی</label>
					<input
						type="date"
						id="hm_next_start_date"
						name="hm_next_start_date"
						value="<?php echo esc_attr( $start_date ); ?>"
					>
					<p class="hd-hint">ذخیره به فرمت میلادی (YYYY-MM-DD). نمایش شمسی در مرحله بعد.</p>
				</div>

				<div class="hd-meta-field">
					<label for="hm_menu_order">ترتیب نمایش (اولویت)</label>
					<input
						type="number"
						id="hm_menu_order"
						name="hm_menu_order"
						value="<?php echo esc_attr( (string) $post->menu_order ); ?>"
						min="0"
						step="1"
					>
					<p class="hd-hint">عدد کمتر = اولویت بالاتر در لیست دوره‌ها و دوره‌های ویژه (پیش‌فرض: ۰).</p>
				</div>

				<div class="hd-meta-field hd-full-width">
					<label for="hm_prerequisites">پیش‌نیازها</label>
					<textarea
						id="hm_prerequisites"
						name="hm_prerequisites"
						rows="3"
						placeholder="پیش‌نیاز دوره را وارد کنید…"
					><?php echo esc_textarea( $prerequisites ); ?></textarea>
				</div>

			</div>
		</div>
		<?php
	}

	// ── Save ─────────────────────────────────────────────────────────────────

	public static function save( int $post_id, WP_Post $post ): void {
		// Guard: nonce
		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
				self::NONCE_ACTION
			)
		) {
			return;
		}

		// Guard: capability
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Guard: autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Guard: post type
		if ( 'course' !== $post->post_type ) {
			return;
		}

		// ── Simple text fields ────────────────────────────────────────────────

		$text_fields = [
			'hm_english_name' => '_course_english_name',
			'hm_teacher'      => '_course_teacher',
			'hm_duration'     => '_course_duration',
			'hm_level'        => '_course_level',
			'hm_price'        => '_course_price',
		];

		foreach ( $text_fields as $input => $meta_key ) {
			$value = isset( $_POST[ $input ] )
				? sanitize_text_field( wp_unslash( $_POST[ $input ] ) )
				: '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		// ── Textarea ──────────────────────────────────────────────────────────

		$prerequisites = isset( $_POST['hm_prerequisites'] )
			? sanitize_textarea_field( wp_unslash( $_POST['hm_prerequisites'] ) )
			: '';
		update_post_meta( $post_id, '_course_prerequisites', $prerequisites );

		// ── Registration state (allowlist) ────────────────────────────────────

		$reg_state = Hedayati_Course_Meta::sanitize_registration_state(
			isset( $_POST['hm_registration_state'] )
				? sanitize_text_field( wp_unslash( $_POST['hm_registration_state'] ) )
				: 'soon'
		);
		update_post_meta( $post_id, '_course_registration_state', $reg_state );

		// ── ISO date ──────────────────────────────────────────────────────────

		$start_date = Hedayati_Course_Meta::sanitize_iso_date(
			isset( $_POST['hm_next_start_date'] )
				? sanitize_text_field( wp_unslash( $_POST['hm_next_start_date'] ) )
				: ''
		);
		update_post_meta( $post_id, '_course_next_start_date', $start_date );

		// ── Boolean: featured ─────────────────────────────────────────────────
		// Checkbox is only present in $_POST when checked; treat absence as false.

		$is_featured = isset( $_POST['hm_is_featured'] ) && '1' === $_POST['hm_is_featured'];
		update_post_meta( $post_id, '_course_is_featured', $is_featured );

		// ── Numeric menu_order (display priority) ─────────────────────────────
		if ( isset( $_POST['hm_menu_order'] ) ) {
			$menu_order = absint( wp_unslash( $_POST['hm_menu_order'] ) );
			if ( $post->menu_order !== $menu_order ) {
				remove_action( 'save_post_course', [ self::class, 'save' ], 10 );
				wp_update_post( [
					'ID'         => $post_id,
					'menu_order' => $menu_order,
				] );
				add_action( 'save_post_course', [ self::class, 'save' ], 10, 2 );
			}
		}
	}
}
