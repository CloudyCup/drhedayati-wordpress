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
 * password-reset request flow — WITHOUT filtering `lostpassword_errors`.
 *
 * An earlier revision of this class filtered `lostpassword_errors` and
 * returned boolean `true` for an account-existence-revealing error code. That
 * violates the filter's contract: `retrieve_password()` (wp-includes/user.php)
 * calls `$errors->has_errors()` directly on whatever `apply_filters(
 * 'lostpassword_errors', $errors, $user_data )` returns, unconditionally — a
 * non-WP_Error return value fatals ("Call to a member function has_errors() on
 * bool") on exactly the request this class exists to protect: an unknown-
 * account password-reset attempt. Deleting the error codes from inside that
 * filter is also insufficient on its own, because WordPress adds `invalidcombo`
 * itself when `$user_data` is false — a re-add this class cannot reliably race
 * against from inside that one filter.
 *
 * The fix does not filter `lostpassword_errors` at all, so there is no
 * WP_Error/boolean contract to violate. Instead, `handle_lostpassword_request()`
 * hooks the `login_form_lostpassword` action — fired by wp-login.php's
 * `lostpassword`/`retrievepassword` case for both a plain GET (form display,
 * left untouched) and a submitted POST that did not already redirect (a
 * successful reset already exits before this action can fire, at whichever
 * point in that case block WordPress calls it). When a non-empty identifier
 * was submitted, this method calls the REAL `retrieve_password()` itself
 * (idempotent: if WordPress core already called it earlier in the same
 * request, calling it again for an unknown account is a harmless no-op — it
 * still sends no email and creates no account; for a real account it is, at
 * worst, a second legitimate reset email, never a security issue) and then
 * ALWAYS redirects to the exact same `wp-login.php?checkemail=confirm` URL
 * used by a genuine success — regardless of whether the account existed. The
 * outward response (redirect target, HTTP status, rendered message) is
 * therefore byte-identical for an existing and a nonexistent identifier,
 * without this class ever inspecting, discarding, or manufacturing a
 * WP_Error. An empty submission (`user_login` missing/blank) is left
 * untouched — that is a real form-validation message, not an
 * account-existence leak, so WordPress's native "enter a username or email"
 * feedback still renders normally.
 *
 * WordPress's own default username/email LOGIN error wording (not
 * password-reset) is a separate, pre-existing, documented limitation (see
 * docs/PHASE_2D_PLANNING.md) — not touched here.
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

		// Password-reset enumeration hardening — NOT a lostpassword_errors
		// filter (see the class docblock for why that approach is unsafe).
		add_action( 'login_form_lostpassword', [ self::class, 'handle_lostpassword_request' ] );
		add_action( 'login_form_retrievepassword', [ self::class, 'handle_lostpassword_request' ] );
		add_filter( 'authenticate', [ self::class, 'generic_login_error' ], 99, 3 );

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
	 * See the class docblock for the full design rationale. Summary: never
	 * inspects or manufactures a WP_Error; always gives an existing and a
	 * nonexistent identifier the identical outward redirect.
	 *
	 * Deliberately never sends an email itself, never creates an account, and
	 * never branches its own behavior on whether the account exists — the ONE
	 * call into WordPress's real `retrieve_password()` is the only place an
	 * email can be sent, and it is WordPress's own native logic (unmodified)
	 * that decides whether that email actually goes out.
	 */
	public static function handle_lostpassword_request(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		$login = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? trim( wp_unslash( $_POST['user_login'] ) ) : '';

		if ( '' === $login ) {
			// Nothing submitted — real WordPress validation feedback, not an
			// account-existence leak. Let the native flow render it.
			return;
		}

		// The real, unmodified WordPress reset flow: generates a genuine
		// reset key and sends a genuine email ONLY if $login resolves to a
		// real account. Its return value is deliberately never inspected —
		// that is exactly the branch point that used to leak account
		// existence.
		// Separate abuse buckets from login; successful and unknown requests count equally.
		$identifier = 'reset:' . strtolower( $login );
		$ip_bucket = 'reset:' . Hedayati_Rate_Limiter::get_client_ip();
		if ( ! Hedayati_Rate_Limiter::is_rate_limited( $identifier, $ip_bucket ) ) {
			Hedayati_Rate_Limiter::record_failure( $identifier, $ip_bucket );
			retrieve_password();
		}

		wp_safe_redirect( 'wp-login.php?checkemail=confirm' );
		exit;
	}

	public static function generic_login_error( $user, string $username, string $password ) {
		if ( '' !== $username && '' !== $password && is_wp_error( $user ) && array_intersect( $user->get_error_codes(), [ 'invalid_username', 'invalid_email', 'incorrect_password' ] ) ) {
			return new WP_Error( 'invalid_credentials', __( 'نام کاربری یا رمز عبور صحیح نیست.', 'hedayati-core' ) );
		}
		return $user;
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
