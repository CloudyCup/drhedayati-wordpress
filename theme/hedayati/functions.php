<?php
/**
 * Hedayati Theme — functions.php
 *
 * Bootstraps theme support, asset enqueueing, nav menus, and
 * provides helpers used across templates.
 *
 * @package Hedayati
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'HEDAYATI_VERSION', '1.1.0' );
define( 'HEDAYATI_DIR', get_template_directory() );
define( 'HEDAYATI_URI', get_template_directory_uri() );

// ── Includes ─────────────────────────────────────────────────────────────────

require_once HEDAYATI_DIR . '/inc/menu-fallbacks.php';

// ── Theme Setup ───────────────────────────────────────────────────────────────

add_action( 'after_setup_theme', 'hedayati_setup' );

function hedayati_setup(): void {
	// Allow WordPress to manage the <title> tag
	add_theme_support( 'title-tag' );

	// Featured images for posts and courses
	add_theme_support( 'post-thumbnails' );
	add_image_size( 'course-card',   560, 320, true );
	add_image_size( 'course-hero',  1200, 600, true );

	// HTML5 semantic markup
	add_theme_support( 'html5', [
		'comment-list',
		'comment-form',
		'search-form',
		'gallery',
		'caption',
		'style',
		'script',
	] );

	// Gutenberg wide/full alignment
	add_theme_support( 'align-wide' );

	// Block editor color support (managed via theme.json)
	add_theme_support( 'editor-color-palette' );

	// Responsive embeds
	add_theme_support( 'responsive-embeds' );

	// Custom logo
	add_theme_support( 'custom-logo', [
		'height'      => 80,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	] );

	// Register navigation menus
	register_nav_menus( [
		'primary' => 'منوی اصلی',
		'footer'  => 'منوی فوتر',
	] );

	// Content width for embeds
	$GLOBALS['content_width'] = 1240;

	// RTL support is native since body/html set direction: rtl
	// Explicit stylesheet loading is handled via wp_enqueue_scripts

	// Load theme text domain
	load_theme_textdomain( 'hedayati', HEDAYATI_DIR . '/languages' );
}

// ── Asset Enqueueing ──────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', 'hedayati_enqueue_assets' );

function hedayati_enqueue_assets(): void {
	/*
	 * Font loading policy:
	 *   - Do NOT @import Google Fonts in CSS.
	 *   - Do NOT enqueue from an external CDN in Phase 1.
	 *   - Vazirmatn will be self-hosted and enqueued here once the final
	 *     approved font files are available.
	 *   - For now the CSS font stack falls back to system Persian fonts.
	 *
	 * When ready, add:
	 *   wp_enqueue_style( 'hedayati-font-vazirmatn', HEDAYATI_URI . '/assets/fonts/vazirmatn.css', [], '4.5.1' );
	 * and update the font-family declaration in main.css.
	 */

	// Main stylesheet
	wp_enqueue_style(
		'hedayati-main',
		HEDAYATI_URI . '/assets/css/main.css',
		[],
		HEDAYATI_VERSION
	);

	// RTL overrides (loaded for all users; direction is set globally)
	wp_enqueue_style(
		'hedayati-rtl',
		HEDAYATI_URI . '/assets/css/rtl.css',
		[ 'hedayati-main' ],
		HEDAYATI_VERSION
	);

	// Main JavaScript (defer execution, no jQuery dependency)
	wp_enqueue_script(
		'hedayati-main',
		HEDAYATI_URI . '/assets/js/main.js',
		[],
		HEDAYATI_VERSION,
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

	// Phase 2D — account portal assets, loaded only on the account page (its
	// page ID is resolved via Hedayati_Student_Portal, never a hardcoded slug
	// check, so a staff rename of the page slug can't silently break this).
	if (
		class_exists( 'Hedayati_Student_Portal' )
		&& is_page( Hedayati_Student_Portal::get_account_page_id() )
		&& Hedayati_Student_Portal::get_account_page_id() > 0
	) {
		wp_enqueue_style(
			'hedayati-account',
			HEDAYATI_URI . '/assets/css/account.css',
			[ 'hedayati-main' ],
			HEDAYATI_VERSION
		);

		wp_enqueue_script(
			'hedayati-account',
			HEDAYATI_URI . '/assets/js/account.js',
			[],
			HEDAYATI_VERSION,
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}
}

// ── Body Classes ──────────────────────────────────────────────────────────────

add_filter( 'body_class', 'hedayati_body_classes' );

function hedayati_body_classes( array $classes ): array {
	$classes[] = 'hd-site';

	if ( is_singular( 'course' ) ) {
		$classes[] = 'hd-single-course';
	}

	if ( is_post_type_archive( 'course' ) || is_tax( 'course-category' ) ) {
		$classes[] = 'hd-course-archive';
	}

	return $classes;
}

// ── Head: Dark-mode no-flash inline script ────────────────────────────────────
// This script runs synchronously in head before first paint.
// Wrapped in try/catch to safely handle environments where localStorage is blocked.

add_action( 'wp_head', 'hedayati_dark_mode_noflash', 1 );

function hedayati_dark_mode_noflash(): void {
	?>
	<script>
	(function(){
		try {
			var stored = localStorage.getItem('hedayati-theme');
			var theme = (stored === 'light' || stored === 'dark') ? stored : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
			document.documentElement.setAttribute('data-theme', theme);
		} catch (e) {
			document.documentElement.setAttribute('data-theme', 'light');
		}
	}());
	</script>
	<?php
}

// ── Helpers available to templates ───────────────────────────────────────────

/**
 * Return the registration state label and CSS modifier class.
 *
 * @param string $state  Value from _course_registration_state meta.
 * @return array{ label: string, class: string }
 */
function hedayati_registration_state_display( string $state ): array {
	return match ( $state ) {
		'open'   => [ 'label' => 'ثبت‌نام باز', 'class' => 'is-open'   ],
		'closed' => [ 'label' => 'ثبت‌نام بسته', 'class' => 'is-closed' ],
		'soon'   => [ 'label' => 'به‌زودی',      'class' => 'is-soon'   ],
		default  => [ 'label' => 'به‌زودی',      'class' => 'is-soon'   ],
	};
}

/**
 * Return the first 3 uppercase characters of an English course name
 * for use as a card monogram. Falls back to first 3 chars of the
 * post title if no English name is set.
 *
 * @param int $post_id
 * @return string
 */
function hedayati_course_monogram( int $post_id ): string {
	$english_name = (string) get_post_meta( $post_id, '_course_english_name', true );

	if ( '' !== $english_name ) {
		// Use first word of the English name (e.g. "CCNA", "Python", "Network+")
		$first_word = explode( ' ', trim( $english_name ) )[0];
		return esc_html( strtoupper( mb_substr( $first_word, 0, 4 ) ) );
	}

	// No English name — use initials from Persian title (first 2 chars)
	$title = get_the_title( $post_id );
	return esc_html( mb_substr( $title, 0, 2 ) );
}

/**
 * Output post classes for course cards, adding course-specific classes.
 *
 * @param int $post_id
 */
function hedayati_course_card_classes( int $post_id ): void {
	$state   = (string) get_post_meta( $post_id, '_course_registration_state', true ) ?: 'soon';
	$classes = [ 'course-card', 'hd-course-card', 'reg-' . esc_attr( $state ) ];
	echo 'class="' . esc_attr( implode( ' ', $classes ) ) . '"';
}

/**
 * Check if the Hedayati Core plugin is active.
 * Templates use this to degrade gracefully if the plugin is absent.
 *
 * @return bool
 */
function hedayati_core_active(): bool {
	return class_exists( 'Hedayati_Query' );
}
