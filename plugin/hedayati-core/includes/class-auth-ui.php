<?php
/**
 * Phase 2D — Branded login, role-aware routing, and login-flow hardening.
 *
 * WordPress remains the sole password/session authority (D6) — this class only
 * brands the existing `wp-login.php` flow and routes users after authentication;
 * it never re-implements login/password verification itself.
 *
 * Account model (owner decision, `docs/PHASE_2D_PLANNING.md` §4a): student
 * accounts are reception-created only. No public self-registration exists or is
 * added here — `option_users_can_register` is forced to read false regardless of
 * the stored option value, so WordPress's own registration link/form can never
 * appear, without touching the actual stored option (non-destructive, reversible
 * by removing this filter).
 *
 * Enumeration resistance: the phone-login path already returns a generic error
 * (`Hedayati_Auth`, Phase 2A). This class extends the same discipline to the
 * password-reset request flow via the `lostpassword_errors` filter — an
 * account-existence-revealing error code is converted to the same "check your
 * email" success response a real account gets, so a requester cannot tell
 * whether the identifier they submitted belongs to a real account. WordPress's
 * own default username/email login error wording is a separate, pre-existing,
 * documented limitation (see docs/PHASE_2D_PLANNING.md) — not touched here, to
 * avoid broad changes to WordPress's own core error branching beyond the one
 * documented, tested hardening point.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Auth_UI {

	public static function init(): void {
		// No public self-registration, ever, regardless of the stored option.
		add_filter( 'option_users_can_register', '__return_false' );

		// Branded login screen.
		add_action( 'login_enqueue_scripts', [ self::class, 'enqueue_login_assets' ] );
		add_filter( 'login_headerurl', [ self::class, 'login_header_url' ] );
		add_filter( 'login_headertext', [ self::class, 'login_header_text' ] );

		// Password-reset enumeration hardening.
		add_filter( 'lostpassword_errors', [ self::class, 'neutralize_lostpassword_enumeration' ], 10, 2 );

		// Role-aware post-login routing.
		add_filter( 'login_redirect', [ self::class, 'student_login_redirect' ], 10, 3 );

		// Students never see wp-admin or the admin bar.
		add_action( 'admin_init', [ self::class, 'maybe_redirect_student_away_from_admin' ] );
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_for_students' ] );
	}

	// ── Branded login ───────────────────────────────────────────────────────

	public static function enqueue_login_assets(): void {
		// A theme asset, not a plugin asset — the branded login screen must stay
		// visually correct even if a future theme switch changes the plugin's
		// own asset paths. Falls back silently (no enqueue) if the active theme
		// isn't `hedayati` or doesn't ship this file, rather than a broken URL.
		$theme_version = wp_get_theme()->get( 'Version' );
		$css_path      = get_theme_file_path( 'assets/css/login.css' );

		if ( ! file_exists( $css_path ) ) {
			return;
		}

		wp_enqueue_style(
			'hedayati-login',
			get_theme_file_uri( 'assets/css/login.css' ),
			[],
			$theme_version ?: false
		);
	}

	public static function login_header_url(): string {
		return home_url( '/' );
	}

	public static function login_header_text(): string {
		return get_bloginfo( 'name' );
	}

	// ── Password-reset enumeration hardening ────────────────────────────────

	/**
	 * `retrieve_password()` (wp-includes/user.php) returns
	 * `apply_filters( 'lostpassword_errors', $errors, $user_login )` at each of
	 * its early-return validation-failure points, each with exactly one error
	 * code already added. wp-login.php then does `if ( ! is_wp_error( $errors ) )`
	 * to decide between the generic "check your email" success redirect and
	 * showing the error. Returning `true` (not a WP_Error) here for the specific
	 * codes that reveal account existence makes `is_wp_error()` false, so
	 * wp-login.php takes the exact same success path a real account gets —
	 * indistinguishable from the outside. `empty_username` (nothing submitted)
	 * is left alone: it's a form-validation message, not an account-existence
	 * leak, so a real user still gets useful feedback for an empty submission.
	 *
	 * @param WP_Error   $errors
	 * @param string|null $user_data
	 * @return true|WP_Error
	 */
	public static function neutralize_lostpassword_enumeration( WP_Error $errors, $user_data ): true|WP_Error {
		$enumeration_codes = [ 'invalid_email', 'invalidcombo', 'invalid_username' ];

		foreach ( $enumeration_codes as $code ) {
			if ( $errors->get_error_message( $code ) ) {
				return true;
			}
		}

		return $errors;
	}

	// ── Role-aware routing ───────────────────────────────────────────────────

	/**
	 * @param string           $redirect_to
	 * @param string           $requested_redirect_to
	 * @param WP_User|WP_Error $user
	 */
	public static function student_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
		if ( $user instanceof WP_User && self::is_portal_only_user( $user ) ) {
			return Hedayati_Student_Portal::get_account_url();
		}

		return $redirect_to;
	}

	/**
	 * Students must never reach wp-admin. Every exclusion below is required to
	 * avoid breaking a legitimate non-page-view request: AJAX and admin-post.php
	 * requests are how the portal's own mutations work; cron and WP-CLI never
	 * have a "page" to redirect; REST requests are a separate transport this
	 * check must not interfere with.
	 */
	public static function maybe_redirect_student_away_from_admin(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
		if ( in_array( $pagenow, [ 'admin-post.php', 'admin-ajax.php' ], true ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! self::is_portal_only_user( wp_get_current_user() ) ) {
			return;
		}

		wp_safe_redirect( Hedayati_Student_Portal::get_account_url() );
		exit;
	}

	public static function hide_admin_bar_for_students( bool $show ): bool {
		if ( is_user_logged_in() && self::is_portal_only_user( wp_get_current_user() ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * True only for a user whose sole role is `student` — never for a user who
	 * also holds a staff/technical role, so a manager/administrator who happens
	 * to also be flagged `student` (not an expected combination, but not
	 * impossible) keeps full wp-admin access.
	 */
	private static function is_portal_only_user( WP_User $user ): bool {
		$roles = (array) $user->roles;

		return [ 'student' ] === array_values( $roles );
	}
}
