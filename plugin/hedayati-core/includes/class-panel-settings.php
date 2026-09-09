<?php
/**
 * AI Studio parity — institute settings inside `/panel/` (owner decision D52).
 *
 * The manager no longer has to leave the custom panel for ordinary settings.
 * This is a thin front-end for the EXISTING Settings API option — same option
 * name, same canonical `Hedayati_Settings::sanitize_all()` sanitizer, same
 * `hedayati_manage_settings` capability. No duplicate settings source; the
 * wp-admin screen (Settings → هدایتی) stays available as an administrator
 * fallback and reads/writes the very same values.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Panel_Settings {

	private const NONCE_ACTION = 'hedayati_panel_settings_save';

	public static function init(): void {
		add_action( 'admin_post_hedayati_panel_settings_save', [ self::class, 'handle_save' ] );
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'settings' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [ 'settings.updated' ] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views['settings'] = [
			'capability' => Hedayati_Settings::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'تنظیمات مجتمع', 'hedayati-core' ),
			'title'      => __( 'تنظیمات مجتمع', 'hedayati-core' ),
			'desc'       => __( 'نام، شماره‌های تماس و نشانی شعب که در سایت نمایش داده می‌شوند', 'hedayati-core' ),
			'icon'       => 'settings',
		];
		return $views;
	}

	public static function render_panel(): void {
		if ( ! current_user_can( Hedayati_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$values = Hedayati_Settings::all();

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'پیکربندی سامانه', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'تنظیمات اطلاعات مجتمع', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'این مقادیر در فوتر و صفحات تماس سایت نمایش داده می‌شوند و با صفحهٔ تنظیمات وردپرس یکسان هستند.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		echo '<form class="hd-portal-form hd-panel-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="hedayati_panel_settings_save">';

		foreach ( Hedayati_Settings::field_labels() as $key => $label ) {
			$value = (string) ( $values[ $key ] ?? '' );
			if ( Hedayati_Settings::is_textarea( $key ) ) {
				printf(
					'<label class="hd-portal-field"><span>%s</span><textarea name="%s" rows="3">%s</textarea></label>',
					esc_html( $label ),
					esc_attr( $key ),
					esc_textarea( $value )
				);
			} else {
				printf(
					'<label class="hd-portal-field"><span>%s</span><input type="text" name="%s" value="%s"%s></label>',
					esc_html( $label ),
					esc_attr( $key ),
					esc_attr( $value ),
					'institute_name' === $key ? '' : ' dir="ltr"'
				);
			}
		}

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ذخیرهٔ تنظیمات', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	public static function handle_save(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_ACTION, Hedayati_Settings::CAPABILITY );

		$input = [];
		foreach ( array_keys( Hedayati_Settings::field_labels() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = wp_unslash( (string) $_POST[ $key ] );
			}
		}

		Hedayati_Settings::update( $input );
		Hedayati_Audit_Log::record( 'settings.updated', 'settings', 0, 'via panel', get_current_user_id() );

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => 'settings' ] );
	}
}
