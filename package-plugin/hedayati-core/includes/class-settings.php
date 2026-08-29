<?php
/**
 * Institute Settings — admin settings page for contact/business data.
 *
 * Business/contact information belongs to the plugin (Hedayati Core),
 * not the theme. This class provides:
 *   - A settings page under Settings → Hedayati
 *   - WordPress Settings API registration (nonces handled by WP)
 *   - Sanitization callbacks on every field
 *   - A static accessor API used by the theme
 *
 * Theme access: Hedayati_Settings::get( 'phone_consult' )
 *               Hedayati_Settings::tel_uri( 'phone_consult' )
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Settings {

	private const OPTION_GROUP = 'hedayati_institute';
	private const OPTION_NAME  = 'hedayati_institute_settings';
	private const PAGE_SLUG    = 'hedayati-settings';
	private const CAPABILITY   = 'manage_options';

	/**
	 * Default values for every field.
	 */
	private const DEFAULTS = [
		'phone_consult'  => '',
		'phone_tabriz'   => '',
		'phone_tehran'   => '',
		'address_tabriz' => '',
	];

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',    [ self::class, 'add_page'    ] );
		add_action( 'admin_init',    [ self::class, 'register'    ] );
	}

	// ── Admin menu ────────────────────────────────────────────────────────────

	public static function add_page(): void {
		add_options_page(
			'تنظیمات مجتمع هدایتی',
			'هدایتی',
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// ── Settings API registration ─────────────────────────────────────────────

	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_all' ],
				'default'           => self::DEFAULTS,
			]
		);

		add_settings_section(
			'hedayati_contact_section',
			'اطلاعات تماس مجتمع',
			[ self::class, 'render_section_intro' ],
			self::PAGE_SLUG
		);

		$fields = [
			'phone_consult'  => 'تلفن مشاوره و ثبت‌نام',
			'phone_tabriz'   => 'تلفن تبریز',
			'phone_tehran'   => 'تلفن تهران',
			'address_tabriz' => 'آدرس تبریز',
		];

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'hedayati_' . $key,
				$label,
				[ self::class, 'render_field' ],
				self::PAGE_SLUG,
				'hedayati_contact_section',
				[ 'key' => $key, 'label' => $label ]
			);
		}
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public static function render_section_intro(): void {
		echo '<p>' . esc_html__( 'اطلاعات تماس و آدرس مجتمع در فوتر سایت و صفحه اصلی نمایش داده می‌شود.', 'hedayati-core' ) . '</p>';
	}

	public static function render_field( array $args ): void {
		$key     = $args['key'];
		$current = self::get( $key );
		$name    = self::OPTION_NAME . '[' . esc_attr( $key ) . ']';
		$id      = 'hedayati_field_' . esc_attr( $key );

		if ( 'address_tabriz' === $key ) {
			printf(
				'<textarea id="%s" name="%s" rows="3" class="large-text">%s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( $current )
			);
		} else {
			printf(
				'<input type="text" id="%s" name="%s" value="%s" class="regular-text" dir="ltr">',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $current )
			);
			echo '<p class="description">' . esc_html__( 'مثال: 04133373735 یا +98-41-3337-3735', 'hedayati-core' ) . '</p>';
		}
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'hedayati-core' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'تنظیمات مجتمع دکتر هدایتی', 'hedayati-core' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( 'ذخیره تنظیمات' );
				?>
			</form>
		</div>
		<?php
	}

	// ── Sanitization ──────────────────────────────────────────────────────────

	/**
	 * Sanitize the entire settings array on save.
	 *
	 * @param mixed $input Raw submitted data.
	 * @return array<string,string>
	 */
	public static function sanitize_all( mixed $input ): array {
		$out = self::DEFAULTS;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$phone_keys = [ 'phone_consult', 'phone_tabriz', 'phone_tehran' ];
		foreach ( $phone_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = self::sanitize_phone( (string) $input[ $key ] );
			}
		}

		if ( isset( $input['address_tabriz'] ) ) {
			$out['address_tabriz'] = sanitize_textarea_field( wp_unslash( (string) $input['address_tabriz'] ) );
		}

		return $out;
	}

	/**
	 * Sanitize a phone/contact string.
	 * Allows digits, spaces, hyphens, parentheses, and a leading +.
	 */
	public static function sanitize_phone( string $value ): string {
		$value = sanitize_text_field( wp_unslash( $value ) );
		// Strip characters that are not valid in a displayable phone number
		return preg_replace( '/[^\d\s\+\-\(\)\.#,]/', '', $value );
	}

	// ── Public API for theme ──────────────────────────────────────────────────

	/**
	 * Get a single setting value.
	 *
	 * @param string $key  One of: phone_consult, phone_tabriz, phone_tehran, address_tabriz
	 * @return string      Empty string if not set or plugin inactive.
	 */
	public static function get( string $key ): string {
		$options = get_option( self::OPTION_NAME, [] );
		if ( ! is_array( $options ) ) {
			return '';
		}
		$value = $options[ $key ] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Produce a safe dialable tel: URI value from a stored phone string.
	 *
	 * Rules:
	 *  - If the stored value starts with '+', preserve it (E.164 international prefix).
	 *  - Strip all non-digit characters after the leading '+'.
	 *  - Return an empty string if nothing dialable remains.
	 *
	 * @param string $key  Setting key (e.g. 'phone_consult').
	 * @return string      tel: URI value (without the 'tel:' scheme) or ''.
	 */
	public static function tel_uri( string $key ): string {
		$phone = self::get( $key );
		return hedayati_phone_to_tel_uri( $phone );
	}
}
