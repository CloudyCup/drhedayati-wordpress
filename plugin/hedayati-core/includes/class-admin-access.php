<?php
/**
 * Owner decision D53 — classic wp-admin is an administrator-only interface.
 *
 * Every non-administrator Hedayati role uses the professional front-end
 * experience:
 *   - student                                   → /account/
 *   - teacher / teacher_assistant                → /panel/
 *   - reception / hedayati_manager               → /panel/  (see the rollout note)
 *
 * This class redirects an INTERACTIVE wp-admin page view for a non-admin role to
 * its front-end workspace, and hides the admin toolbar for those roles. It never
 * touches the endpoints WordPress and this application legitimately use as a
 * transport: `admin-post.php`, `admin-ajax.php`, REST, cron and WP-CLI all pass
 * straight through, so every panel/account mutation (all of which post to
 * `admin-post.php`) keeps working.
 *
 * ROLLOUT NOTE (D53, staged): the redirect is enforced now for the roles whose
 * front-end coverage is already complete — `student`, `teacher`,
 * `teacher_assistant`. `reception` and `hedayati_manager` still reach wp-admin
 * for the two screens that exist ONLY there (`Hedayati_Academic_Admin`,
 * `Hedayati_Student_Admin`); enforcing their redirect is gated on the Phase E
 * front-end port of those screens (see docs/ROADMAP.md). The
 * `HEDAYATI_ENFORCE_ADMIN_REDIRECT` filter flips them on in one line once E
 * lands, without another release of this file.
 *
 * The actual WordPress `administrator` (holds `manage_options`) is never
 * affected — wp-admin, Gutenberg, the native CPT editors and WordPress
 * maintenance tools all stay available to them.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Admin_Access {

	/**
	 * Roles whose interactive wp-admin redirect is enforced. As of the Phase E
	 * front-end port (teachers, courses, academic operations, student
	 * verification / private documents, settings) every non-administrator
	 * Hedayati role now has complete panel/account coverage, so the policy is
	 * FULLY enforced. The `hedayati_admin_redirect_roles` filter can still
	 * narrow or widen the set per deployment.
	 *
	 * @var string[]
	 */
	private const ENFORCED_ROLES = [ 'student', 'teacher', 'teacher_assistant', 'reception', 'hedayati_manager' ];

	public static function init(): void {
		// Priority 1: decide before any screen-specific admin_init runs.
		add_action( 'admin_init', [ self::class, 'redirect_interactive_admin' ], 1 );

		// Hide the toolbar for every non-administrator Hedayati role, on the
		// front end too (Hedayati_Auth_UI already does this for student-only
		// users; this widens it to staff without disturbing that path).
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_for_non_admins' ], 20 );
	}

	/**
	 * The set of roles currently redirected out of interactive wp-admin.
	 *
	 * @return string[]
	 */
	public static function enforced_roles(): array {
		$roles = (array) apply_filters( 'hedayati_admin_redirect_roles', self::ENFORCED_ROLES );

		return array_values( array_unique( array_filter( array_map( 'strval', $roles ) ) ) );
	}

	/**
	 * True when the request is a real, human, interactive wp-admin PAGE view —
	 * not one of the transports WordPress/this app relies on.
	 */
	private static function is_interactive_admin_request(): bool {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		// admin-post.php + admin-ajax.php are how the front-end panels submit
		// every mutation; async-upload.php backs the media uploader an admin may
		// still legitimately trigger. profile.php is a user's own-account screen
		// (there is no panel equivalent yet — see docs/ROADMAP.md), so it stays
		// reachable for everyone. None of these are a "screen" to redirect.
		if ( in_array( $pagenow, [ 'admin-post.php', 'admin-ajax.php', 'async-upload.php', 'profile.php' ], true ) ) {
			return false;
		}

		return is_admin();
	}

	/**
	 * admin_init guard: send a non-administrator Hedayati role to its front-end
	 * workspace instead of rendering a wp-admin screen. No-cache headers first so
	 * the redirect itself is never cached.
	 */
	public static function redirect_interactive_admin(): void {
		if ( ! is_user_logged_in() || ! self::is_interactive_admin_request() ) {
			return;
		}

		$user = wp_get_current_user();

		// Only roles whose redirect is actually enforced today (staged rollout —
		// student/teacher/teacher_assistant now; reception/hedayati_manager when
		// the Phase E filter adds them). Administrators are never enforced.
		if ( ! self::is_enforced_for( $user ) ) {
			return;
		}

		$target = self::workspace_url_for( $user );
		if ( '' === $target ) {
			return; // No front-end workspace to send them to — leave them be.
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * The front-end home for a user, or '' if they are not a routed Hedayati role.
	 * Students always go to /account/; any staff role that can open the panel
	 * goes to /panel/.
	 */
	public static function workspace_url_for( WP_User $user ): string {
		// The administrator is augmented with every hedayati_* capability, so a
		// capability probe alone would misroute them — exclude them explicitly.
		if ( user_can( $user, 'manage_options' ) ) {
			return '';
		}

		$roles = (array) $user->roles;

		// A user who also holds a staff role is treated as staff (matches
		// Hedayati_Auth_UI::is_portal_only_user()'s "student only" intent).
		$staff_roles = [ 'teacher', 'teacher_assistant', 'reception', 'hedayati_manager' ];
		if ( array_intersect( $staff_roles, $roles )
			&& (
				user_can( $user, 'hedayati_view_assigned_runs' )
				|| user_can( $user, 'hedayati_lookup_students' )
				|| user_can( $user, 'hedayati_manage_course_runs' )
			)
		) {
			return class_exists( 'Hedayati_Staff_Portal' ) ? Hedayati_Staff_Portal::url() : home_url( '/panel/' );
		}

		if ( in_array( 'student', $roles, true ) || user_can( $user, 'hedayati_view_own_portal' ) ) {
			return class_exists( 'Hedayati_Student_Portal' )
				? Hedayati_Student_Portal::get_account_url()
				: home_url( '/account/' );
		}

		return '';
	}

	/**
	 * True if the redirect is actually enforced for this user right now (all of
	 * their roles are in the enforced set, and they are not an administrator).
	 * `reception`/`hedayati_manager` return false until Phase E flips them on.
	 */
	private static function is_enforced_for( WP_User $user ): bool {
		if ( user_can( $user, 'manage_options' ) ) {
			return false;
		}

		$roles = array_values( (array) $user->roles );
		if ( [] === $roles ) {
			return false;
		}

		return [] === array_diff( $roles, self::enforced_roles() );
	}

	public static function hide_admin_bar_for_non_admins( bool $show ): bool {
		if ( ! is_user_logged_in() ) {
			return $show;
		}

		$user = wp_get_current_user();
		if ( user_can( $user, 'manage_options' ) ) {
			return $show;
		}

		// Any non-admin Hedayati role (routed to /panel/ or /account/) has no use
		// for the WordPress toolbar.
		if ( '' !== self::workspace_url_for( $user ) ) {
			return false;
		}

		return $show;
	}

	// Re-exposed so guards/tests can assert the same predicate this class uses.
	public static function redirect_is_enforced_for_current_user(): bool {
		return is_user_logged_in() && self::is_enforced_for( wp_get_current_user() );
	}
}
