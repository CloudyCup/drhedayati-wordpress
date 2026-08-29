<?php
/**
 * Plugin Name:       Hedayati Core
 * Plugin URI:        https://mystik.ir
 * Description:       هسته عملکردی مجتمع آموزشی دکتر هدایتی — دوره‌ها، طبقه‌بندی‌ها، متادیتا و توابع کمکی.
 * Version:           1.0.0
 * Author:            مجتمع آموزشی دکتر هدایتی
 * Author URI:        https://mystik.ir
 * Text Domain:       hedayati-core
 * Domain Path:       /languages
 * Requires at least: 6.6
 * Requires PHP:      8.3
 * License:           Private
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ────────────────────────────────────────────────────────────────

define( 'HEDAYATI_CORE_VERSION', '1.0.0' );
define( 'HEDAYATI_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'HEDAYATI_CORE_URL', plugin_dir_url( __FILE__ ) );

// ── Includes ─────────────────────────────────────────────────────────────────

require_once HEDAYATI_CORE_DIR . 'includes/class-post-types.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-taxonomies.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-course-meta.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-meta-box.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-query-helpers.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-settings.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-term-meta.php';

// ── Hook Registration ─────────────────────────────────────────────────────────

add_action( 'init', [ Hedayati_Post_Types::class, 'register' ] );
add_action( 'init', [ Hedayati_Taxonomies::class, 'register' ] );
add_action( 'init', [ Hedayati_Course_Meta::class, 'register' ] );
add_action( 'add_meta_boxes', [ Hedayati_Meta_Box::class, 'register_boxes' ] );
add_action( 'save_post_course', [ Hedayati_Meta_Box::class, 'save' ], 10, 2 );
add_action( 'admin_enqueue_scripts', 'hedayati_core_admin_assets' );

// Settings page and term meta initialisation
Hedayati_Settings::init();
Hedayati_Term_Meta::init();

// ── Shared helpers (callable from theme without knowing internals) ─────────────

/**
 * Convert a stored phone string to a safe dialable tel: URI value.
 *
 * Rules:
 *   - If the display string begins with '+', preserve the leading '+' (E.164).
 *   - Strip all non-digit characters after that.
 *   - Return empty string if nothing dialable remains.
 *
 * @param string $phone  The human-readable phone string (may contain spaces, dashes, etc.)
 * @return string        Dialable value for use after "tel:" — empty string on failure.
 */
function hedayati_phone_to_tel_uri( string $phone ): string {
	$phone = trim( $phone );

	if ( '' === $phone ) {
		return '';
	}

	$has_plus = str_starts_with( $phone, '+' );
	$digits   = preg_replace( '/\D/', '', $phone );

	if ( '' === $digits ) {
		return '';
	}

	return $has_plus ? '+' . $digits : $digits;
}

// ── Admin Assets ──────────────────────────────────────────────────────────────

function hedayati_core_admin_assets( string $hook ): void {
	global $post;

	if (
		in_array( $hook, [ 'post.php', 'post-new.php' ], true )
		&& isset( $post )
		&& 'course' === $post->post_type
	) {
		wp_enqueue_style(
			'hedayati-core-admin',
			HEDAYATI_CORE_URL . 'assets/css/admin.css',
			[],
			HEDAYATI_CORE_VERSION
		);
	}
}

// ── Activation / Deactivation ─────────────────────────────────────────────────

register_activation_hook( __FILE__, function (): void {
	Hedayati_Post_Types::register();
	Hedayati_Taxonomies::register();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
