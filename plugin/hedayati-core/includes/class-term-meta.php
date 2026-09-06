<?php
/**
 * Course Category Term Metadata.
 *
 * Owned by Hedayati Core. Registers term meta fields for:
 *   - English label (for bilingual display)
 *   - Icon / text symbol (plain text only — no HTML/SVG/JS allowed)
 *   - Display order (integer, drives get_nav_categories() ordering)
 *
 * Adds fields to both Add and Edit taxonomy screens with proper
 * nonce, capability, and sanitization handling.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Term_Meta {

	private const TAXONOMY       = 'course-category';
	private const NONCE_ACTION   = 'hedayati_term_meta_save';
	private const NONCE_FIELD    = 'hedayati_term_meta_nonce';

	/** Meta keys. */
	public const META_ENGLISH = 'course_cat_english';
	public const META_ICON    = 'course_cat_icon';
	public const META_ORDER   = 'course_cat_order';

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_meta' ] );

		// Add form fields (new term screen)
		add_action( self::TAXONOMY . '_add_form_fields',  [ self::class, 'render_add_fields'  ] );
		// Edit form fields (edit term screen)
		add_action( self::TAXONOMY . '_edit_form_fields', [ self::class, 'render_edit_fields' ], 10, 2 );

		// Save on create and update
		add_action( 'created_' . self::TAXONOMY, [ self::class, 'save' ], 10, 2 );
		add_action( 'edited_'  . self::TAXONOMY, [ self::class, 'save' ], 10, 2 );

		// Add custom columns to term list table
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns',       [ self::class, 'add_columns'    ] );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column',       [ self::class, 'render_column' ], 10, 3 );
	}

	// ── Meta registration ─────────────────────────────────────────────────────

	public static function register_meta(): void {
		$taxonomy = self::TAXONOMY;

		register_term_meta( $taxonomy, self::META_ENGLISH, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => false,
		] );

		register_term_meta( $taxonomy, self::META_ICON, [
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			// Plain text only — strip HTML/SVG/JS entirely.
			'sanitize_callback' => [ self::class, 'sanitize_icon' ],
			'show_in_rest'      => false,
		] );

		register_term_meta( $taxonomy, self::META_ORDER, [
			'type'              => 'integer',
			'single'            => true,
			'default'           => 0,
			'sanitize_callback' => 'absint',
			'show_in_rest'      => false,
		] );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * Sanitize icon field to plain text only.
	 * Strips all HTML tags and limits to 8 characters to prevent abuse.
	 */
	public static function sanitize_icon( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = sanitize_text_field( $value );
		// Limit to 8 characters (enough for a symbol or short code)
		return mb_substr( $value, 0, 8 );
	}

	// ── Render: Add screen ────────────────────────────────────────────────────

	public static function render_add_fields(): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label for="hd_cat_english"><?php esc_html_e( 'نام انگلیسی', 'hedayati-core' ); ?></label>
			<input type="text" id="hd_cat_english" name="hd_cat_english" value="" dir="ltr">
			<p><?php esc_html_e( 'نام انگلیسی دسته‌بندی برای نمایش در نوار دپارتمان‌ها (مثال: NETWORK &amp; IT)', 'hedayati-core' ); ?></p>
		</div>
		<div class="form-field">
			<label for="hd_cat_icon"><?php esc_html_e( 'نماد / آیکون متنی', 'hedayati-core' ); ?></label>
			<input type="text" id="hd_cat_icon" name="hd_cat_icon" value="" maxlength="8" style="width:80px">
			<p><?php esc_html_e( 'یک کاراکتر یا نماد متنی کوتاه (مثال: ⌘ یا #). فقط متن خالص — بدون HTML.', 'hedayati-core' ); ?></p>
		</div>
		<div class="form-field">
			<label for="hd_cat_order"><?php esc_html_e( 'ترتیب نمایش', 'hedayati-core' ); ?></label>
			<input type="number" id="hd_cat_order" name="hd_cat_order" value="0" min="0" step="1" style="width:80px">
			<p><?php esc_html_e( 'عدد کمتر = اول نمایش داده می‌شود.', 'hedayati-core' ); ?></p>
		</div>
		<?php
	}

	// ── Render: Edit screen ───────────────────────────────────────────────────

	public static function render_edit_fields( WP_Term $term, string $taxonomy ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$english = (string) get_term_meta( $term->term_id, self::META_ENGLISH, true );
		$icon    = (string) get_term_meta( $term->term_id, self::META_ICON, true );
		$order   = (int)    get_term_meta( $term->term_id, self::META_ORDER, true );
		?>
		<tr class="form-field">
			<th><label for="hd_cat_english"><?php esc_html_e( 'نام انگلیسی', 'hedayati-core' ); ?></label></th>
			<td>
				<input type="text" id="hd_cat_english" name="hd_cat_english"
				       value="<?php echo esc_attr( $english ); ?>" dir="ltr">
				<p class="description"><?php esc_html_e( 'نام انگلیسی دسته‌بندی (مثال: NETWORK &amp; IT)', 'hedayati-core' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th><label for="hd_cat_icon"><?php esc_html_e( 'نماد / آیکون متنی', 'hedayati-core' ); ?></label></th>
			<td>
				<input type="text" id="hd_cat_icon" name="hd_cat_icon"
				       value="<?php echo esc_attr( $icon ); ?>" maxlength="8" style="width:80px">
				<p class="description"><?php esc_html_e( 'فقط متن خالص — بدون HTML یا SVG.', 'hedayati-core' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th><label for="hd_cat_order"><?php esc_html_e( 'ترتیب نمایش', 'hedayati-core' ); ?></label></th>
			<td>
				<input type="number" id="hd_cat_order" name="hd_cat_order"
				       value="<?php echo esc_attr( (string) $order ); ?>" min="0" step="1" style="width:80px">
				<p class="description"><?php esc_html_e( 'عدد کمتر = اول نمایش داده می‌شود.', 'hedayati-core' ); ?></p>
			</td>
		</tr>
		<?php
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	public static function save( int $term_id, int $tt_id ): void {
		// Nonce check
		if (
			! isset( $_POST[ self::NONCE_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ),
				self::NONCE_ACTION
			)
		) {
			return;
		}

		// Capability check
		if ( ! current_user_can( 'hedayati_manage_courses' ) ) {
			return;
		}

		// English label
		if ( isset( $_POST['hd_cat_english'] ) ) {
			update_term_meta(
				$term_id,
				self::META_ENGLISH,
				sanitize_text_field( wp_unslash( $_POST['hd_cat_english'] ) )
			);
		}

		// Icon — plain text only
		if ( isset( $_POST['hd_cat_icon'] ) ) {
			update_term_meta(
				$term_id,
				self::META_ICON,
				self::sanitize_icon( wp_unslash( $_POST['hd_cat_icon'] ) )
			);
		}

		// Display order — non-negative integer
		$order = isset( $_POST['hd_cat_order'] )
			? absint( wp_unslash( $_POST['hd_cat_order'] ) )
			: 0;
		update_term_meta( $term_id, self::META_ORDER, $order );
	}

	// ── Admin columns ─────────────────────────────────────────────────────────

	public static function add_columns( array $columns ): array {
		$columns['hd_icon']  = 'آیکون';
		$columns['hd_order'] = 'ترتیب';
		return $columns;
	}

	public static function render_column( string $content, string $column, int $term_id ): string {
		return match ( $column ) {
			'hd_icon'  => esc_html( (string) get_term_meta( $term_id, self::META_ICON, true ) ),
			'hd_order' => esc_html( (string) get_term_meta( $term_id, self::META_ORDER, true ) ),
			default    => $content,
		};
	}
}
