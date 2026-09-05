<?php
/**
 * Plugin Name:       Hedayati Core
 * Plugin URI:        https://mystik.ir
 * Description:       هسته عملکردی مجتمع آموزشی دکتر هدایتی — دوره‌ها، طبقه‌بندی‌ها، احراز هویت، متادیتا و توابع کمکی.
 * Version:           1.6.0
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

define( 'HEDAYATI_CORE_VERSION', '1.6.0' );
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

// Phase 2A Identity & Database
require_once HEDAYATI_CORE_DIR . 'includes/class-phone.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-db-schema.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-user-phone-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-roles.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-rate-limiter.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-auth.php';

// Phase 2B Academic Operations
require_once HEDAYATI_CORE_DIR . 'includes/class-text.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-jalali.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-academic-validation.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-audit-log.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-teacher.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-course-run-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-run-staff-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-session-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-enrollment-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-attendance-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-academic-admin.php';

// Phase 2C (foundation) — student profile fields only
require_once HEDAYATI_CORE_DIR . 'includes/class-student-profile.php';

// Phase 2C — student identity, verification, private documents
require_once HEDAYATI_CORE_DIR . 'includes/class-crypto.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-verification-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-document-storage.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-document-service.php';
require_once HEDAYATI_CORE_DIR . 'includes/class-student-admin.php';

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

// Phase 2A initialization
Hedayati_DB_Schema::init();
Hedayati_User_Phone_Service::init();
Hedayati_Roles::init();
Hedayati_Auth::init();

// Phase 2B initialization
Hedayati_Teacher::init();
Hedayati_Course_Run_Service::init();
Hedayati_Run_Staff_Service::init();
Hedayati_Session_Service::init();
Hedayati_Enrollment_Service::init();
Hedayati_Attendance_Service::init();
Hedayati_Academic_Admin::init();

// Phase 2C (foundation)
Hedayati_Student_Profile::init();

// Phase 2C — student identity, verification, private documents
Hedayati_Verification_Service::init();
Hedayati_Document_Service::init();
Hedayati_Student_Admin::init();

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

		wp_enqueue_script(
			'hedayati-core-admin',
			HEDAYATI_CORE_URL . 'assets/js/admin.js',
			[],
			HEDAYATI_CORE_VERSION,
			true
		);
	}
}

// ── Activation / Deactivation ─────────────────────────────────────────────────

register_activation_hook( __FILE__, function (): void {
	Hedayati_Post_Types::register();
	Hedayati_Taxonomies::register();
	Hedayati_Teacher::register();
	Hedayati_DB_Schema::migrate();
	Hedayati_Roles::register_roles();
	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
