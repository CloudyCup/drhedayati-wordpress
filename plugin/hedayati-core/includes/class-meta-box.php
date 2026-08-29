<?php
/**
 * Admin meta box for the Course CPT.
 *
 * Security model:
 *   - Nonce verification on every save
 *   - Capability check via current_user_can( 'edit_post', $post_id )
 *   - Autosave guard
 *   - All values sanitized through Hedayati_Course_Meta sanitizers (allowlist for
 *     registration state, ISO date validation, string array sanitizers) and WordPress core sanitizers
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
			'اطلاعات و تنظیمات ساختاریافته دوره آموزشی',
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

		// Structured repeatable arrays
		$syllabus      = get_post_meta( $post->ID, '_course_syllabus', true );
		$syllabus      = is_array( $syllabus ) ? $syllabus : [];

		$audience      = get_post_meta( $post->ID, '_course_target_audience', true );
		$audience      = is_array( $audience ) ? $audience : [];

		$outcomes      = get_post_meta( $post->ID, '_course_learning_outcomes', true );
		$outcomes      = is_array( $outcomes ) ? $outcomes : [];

		// Registration state options
		$states = [
			'open'   => 'باز — ثبت‌نام فعال',
			'closed' => 'بسته — ثبت‌نام بسته',
			'soon'   => 'به‌زودی',
		];
		?>
		<div class="hd-meta-box">

			<!-- Guidance Notice for Institute Staff -->
			<div class="hd-admin-notice">
				<div class="hd-admin-notice-title">
					<span class="dashicons dashicons-info" aria-hidden="true"></span>
					<strong>راهنمای تکمیل اطلاعات دوره:</strong>
				</div>
				<ul class="hd-admin-notice-list">
					<li><strong>ویرایشگر گوتنبرگ (بالا):</strong> برای نوشتن متن کامل معرفی دوره، توضیحات تفصیلی و سرفصل‌های تشریحی استفاده می‌شود.</li>
					<li><strong>فیلدهای ساختاریافته (اینجا):</strong> برای شناسنامه دوره، جدول مشخصات سریع، سرفصل‌های کلیدی، مخاطبان و نتایج یادگیری استفاده می‌شوند.</li>
					<li><strong>تصویر شاخص (سایدبار):</strong> تصویر کاور اصلی دوره در صفحه دوره‌ها و هدر اختصاصی دوره را مشخص می‌کند.</li>
				</ul>
			</div>

			<!-- Section 1: Basic Information -->
			<div class="hd-section-group">
				<h3 class="hd-section-title">
					<span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>
					۱. شناسنامه و مشخصات اجرایی دوره
				</h3>

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
							<span>نمایش به عنوان دوره ویژه در صفحه اصلی <small>(حداکثر ۸ دوره برتر)</small></span>
						</label>
					</div>

					<div class="hd-meta-field">
						<label for="hm_english_name">نام انگلیسی / کد استاندارد بین‌المللی</label>
						<input
							type="text"
							id="hm_english_name"
							name="hm_english_name"
							value="<?php echo esc_attr( $english_name ); ?>"
							placeholder="e.g. CCNA 200-301, Python for Data Science"
							dir="ltr"
						>
						<p class="hd-hint">برای نشان لاتین و تگ بالای کارت دوره استفاده می‌شود.</p>
					</div>

					<div class="hd-meta-field">
						<label for="hm_teacher">مدرس / اساتید دوره</label>
						<input
							type="text"
							id="hm_teacher"
							name="hm_teacher"
							value="<?php echo esc_attr( $teacher ); ?>"
							placeholder="مثال: مهندس رضایی"
						>
					</div>

					<div class="hd-meta-field">
						<label for="hm_duration">طول مدت دوره</label>
						<input
							type="text"
							id="hm_duration"
							name="hm_duration"
							value="<?php echo esc_attr( $duration ); ?>"
							placeholder="مثال: ۴۸ ساعت (۱۲ جلسه)"
						>
					</div>

					<div class="hd-meta-field">
						<label for="hm_level">سطح دوره</label>
						<input
							type="text"
							id="hm_level"
							name="hm_level"
							value="<?php echo esc_attr( $level ); ?>"
							placeholder="مثال: مقدماتی تا پیشرفته، متوسط"
						>
					</div>

					<div class="hd-meta-field">
						<label for="hm_price">شهریه دوره</label>
						<input
							type="text"
							id="hm_price"
							name="hm_price"
							value="<?php echo esc_attr( $price ); ?>"
							placeholder="مثال: ۴٬۵۰۰٬۰۰۰ تومان (خالی بگذارید اگر نیاز به نمایش نیست)"
						>
						<p class="hd-hint">در صورت خالی بودن، بخش قیمت در سایت نمایش داده نمی‌شود.</p>
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
						<p class="hd-hint">فرمت میلادی (YYYY-MM-DD). خالی بگذارید در صورتی که هنوز تعیین نشده است.</p>
					</div>

					<div class="hd-meta-field">
						<label for="hm_menu_order">اولویت نمایش (ترتیب)</label>
						<input
							type="number"
							id="hm_menu_order"
							name="hm_menu_order"
							value="<?php echo esc_attr( (string) $post->menu_order ); ?>"
							min="0"
							step="1"
						>
						<p class="hd-hint">عدد کمتر = اولویت بالاتر (۰ بالاترین اولویت است).</p>
					</div>

					<div class="hd-meta-field hd-full-width">
						<label for="hm_prerequisites">پیش‌نیازهای ورود به دوره</label>
						<textarea
							id="hm_prerequisites"
							name="hm_prerequisites"
							rows="2"
							placeholder="مثال: آشنایی اولیه با مفاهیم شبکه (Network+) و کاربری کامپیوتر"
						><?php echo esc_textarea( $prerequisites ); ?></textarea>
						<p class="hd-hint">در صورت خالی بودن، بخش پیش‌نیاز در صفحه دوره نمایش داده نمی‌شود.</p>
					</div>
				</div>
			</div>

			<!-- Section 2: Course Syllabus (Repeatable) -->
			<div class="hd-section-group">
				<h3 class="hd-section-title">
					<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
					۲. سرفصل‌های کلیدی دوره (Syllabus)
				</h3>
				<p class="hd-section-desc">عناوین اصلی سرفصل‌ها یا ماژول‌های آموزشی دوره را به صورت موارد کوتاه وارد کنید.</p>

				<div class="hd-repeater-wrapper" data-field-name="hm_syllabus" data-placeholder="عنوان سرفصل یا ماژول آموزشی...">
					<div class="hd-repeater-list">
						<?php if ( ! empty( $syllabus ) ) : ?>
							<?php foreach ( $syllabus as $item ) : ?>
								<div class="hd-repeater-row">
									<div class="hd-repeater-btn-group">
										<button type="button" class="button hd-repeater-move-up" title="انتقال به بالا" aria-label="انتقال این مورد به بالا">▲</button>
										<button type="button" class="button hd-repeater-move-down" title="انتقال به پایین" aria-label="انتقال این مورد به پایین">▼</button>
									</div>
									<input type="text" name="hm_syllabus[]" value="<?php echo esc_attr( $item ); ?>" placeholder="عنوان سرفصل یا ماژول آموزشی..." class="hd-repeater-input">
									<button type="button" class="button hd-repeater-remove-btn" title="حذف این مورد" aria-label="حذف این مورد">✕</button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button button-secondary hd-repeater-add-btn">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						افزودن سرفصل جدید
					</button>
				</div>
			</div>

			<!-- Section 3: Target Audience (Repeatable) -->
			<div class="hd-section-group">
				<h3 class="hd-section-title">
					<span class="dashicons dashicons-groups" aria-hidden="true"></span>
					۳. این دوره برای چه کسانی مناسب است؟ (Target Audience)
				</h3>
				<p class="hd-section-desc">گروه‌های مخاطب دوره (دانشجویان، کارشناسان، مدیران و...) را به تفکیک وارد نمایید.</p>

				<div class="hd-repeater-wrapper" data-field-name="hm_target_audience" data-placeholder="توصیف مخاطب هدف دوره...">
					<div class="hd-repeater-list">
						<?php if ( ! empty( $audience ) ) : ?>
							<?php foreach ( $audience as $item ) : ?>
								<div class="hd-repeater-row">
									<div class="hd-repeater-btn-group">
										<button type="button" class="button hd-repeater-move-up" title="انتقال به بالا" aria-label="انتقال این مورد به بالا">▲</button>
										<button type="button" class="button hd-repeater-move-down" title="انتقال به پایین" aria-label="انتقال این مورد به پایین">▼</button>
									</div>
									<input type="text" name="hm_target_audience[]" value="<?php echo esc_attr( $item ); ?>" placeholder="توصیف مخاطب هدف دوره..." class="hd-repeater-input">
									<button type="button" class="button hd-repeater-remove-btn" title="حذف این مورد" aria-label="حذف این مورد">✕</button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button button-secondary hd-repeater-add-btn">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						افزودن مخاطب هدف جدید
					</button>
				</div>
			</div>

			<!-- Section 4: Learning Outcomes (Repeatable) -->
			<div class="hd-section-group">
				<h3 class="hd-section-title">
					<span class="dashicons dashicons-awards" aria-hidden="true"></span>
					۴. دستاوردها و مهارت‌های کسب‌شده پس از دوره (Learning Outcomes)
				</h3>
				<p class="hd-section-desc">مهارت‌های عملی، توانمندی‌ها و نتایجی که دانشجو در پایان این دوره به آنها مسلط خواهد شد.</p>

				<div class="hd-repeater-wrapper" data-field-name="hm_learning_outcomes" data-placeholder="دستاورد یا مهارت کسب‌شده...">
					<div class="hd-repeater-list">
						<?php if ( ! empty( $outcomes ) ) : ?>
							<?php foreach ( $outcomes as $item ) : ?>
								<div class="hd-repeater-row">
									<div class="hd-repeater-btn-group">
										<button type="button" class="button hd-repeater-move-up" title="انتقال به بالا" aria-label="انتقال این مورد به بالا">▲</button>
										<button type="button" class="button hd-repeater-move-down" title="انتقال به پایین" aria-label="انتقال این مورد به پایین">▼</button>
									</div>
									<input type="text" name="hm_learning_outcomes[]" value="<?php echo esc_attr( $item ); ?>" placeholder="دستاورد یا مهارت کسب‌شده..." class="hd-repeater-input">
									<button type="button" class="button hd-repeater-remove-btn" title="حذف این مورد" aria-label="حذف این مورد">✕</button>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<button type="button" class="button button-secondary hd-repeater-add-btn">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						افزودن دستاورد جدید
					</button>
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

		// ── Repeatable string arrays ──────────────────────────────────────────

		$repeaters = [
			'hm_syllabus'          => '_course_syllabus',
			'hm_target_audience'   => '_course_target_audience',
			'hm_learning_outcomes' => '_course_learning_outcomes',
		];

		foreach ( $repeaters as $post_key => $meta_key ) {
			$raw_items = isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] )
				? (array) $_POST[ $post_key ]
				: [];
			$clean_items = Hedayati_Course_Meta::sanitize_string_array( $raw_items );
			update_post_meta( $post_id, $meta_key, $clean_items );
		}
	}
}
