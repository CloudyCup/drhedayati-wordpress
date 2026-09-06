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

define( 'HEDAYATI_VERSION', '1.3.0' );
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
	wp_enqueue_style( 'hedayati-public-pages', HEDAYATI_URI . '/assets/css/public-pages.css', [ 'hedayati-main' ], HEDAYATI_VERSION );
	/*
	 * Font loading policy (Phase 3): Vazirmatn is self-hosted — the variable
	 * WOFF2 in assets/fonts/ (SIL OFL) is declared with @font-face at the top of
	 * main.css, which is enqueued below and site-wide, so no separate font
	 * stylesheet is needed. Never @import Google Fonts; never load from a CDN.
	 * wp-login.php gets its own copy in login.css (main.css is not loaded there).
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

	// Phase 2D/3 — account + staff portal assets. Loaded on the student account
	// page (ID resolved via the plugin, never a hardcoded slug), the staff
	// `/panel/` page, and any page while a logged-in user is being forced
	// through the first-login password change (that screen can render on top of
	// any request). Cheap boolean checks only — no queries.
	$hd_account_id  = class_exists( 'Hedayati_Student_Portal' ) ? Hedayati_Student_Portal::get_account_page_id() : 0;
	$hd_needs_portal = ( $hd_account_id > 0 && is_page( $hd_account_id ) )
		|| is_page( 'panel' )
		|| (
			class_exists( 'Hedayati_Account_Security' )
			&& is_user_logged_in()
			&& Hedayati_Account_Security::must_change( get_current_user_id() )
		);

	if ( $hd_needs_portal ) {
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
