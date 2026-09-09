<?php
/**
 * Manager Experience — Phase F (owner decision D53): the dedicated front-end
 * authentication experience at `/login/`.
 *
 * This is NOT a re-skin of `wp-login.php` and NOT a parallel auth system. It is
 * a branded front-end shell that drives WordPress's own primitives:
 *
 *   - login              → `wp_signon()` (runs the full `authenticate` filter
 *                          chain: `Hedayati_Auth` phone/username adapter, the
 *                          rate limiter, the privacy-safe generic error, and the
 *                          `wp_login` / `wp_login_failed` bucket bookkeeping —
 *                          all unchanged).
 *   - post-login routing → the existing `login_redirect` filters
 *                          (`Hedayati_Auth_UI` / `Hedayati_Staff_Portal`) plus
 *                          `Hedayati_Admin_Access::workspace_url_for()`.
 *   - forgot password    → WordPress core `retrieve_password()`, its result
 *                          never inspected, always the same outward response
 *                          (no account enumeration). Same rate-limit bucket the
 *                          `wp-login.php` path uses.
 *   - reset link         → WordPress core `check_password_reset_key()` +
 *                          `reset_password()`. Core token semantics untouched;
 *                          the email link is only re-pointed at `/login/`.
 *   - `redirect_to`      → `wp_validate_redirect()` (no open redirect).
 *
 * `wp-login.php` itself stays fully functional for the administrator (and for
 * `action=logout` / `action=rp` fallbacks). A bare `GET wp-login.php` by a
 * normal visitor is bounced to `/login/`.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Login {

	private const PAGE_SLUG      = 'login';
	private const OPTION_PAGE_ID = 'hedayati_login_page_id';
	private const NOTICE_TTL     = 45;

	/** Query-arg actions this screen handles. */
	public const ACTIONS = [ 'login', 'lostpassword', 'rp', 'resetpass' ];

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_create_page' ] );
		add_action( 'template_redirect', [ self::class, 'handle' ], 5 );

		// Re-point the password-reset email at /login/ (core token unchanged).
		add_filter( 'retrieve_password_message', [ self::class, 'filter_reset_message' ], 10, 4 );
		add_filter( 'lostpassword_url', [ self::class, 'lostpassword_url' ], 10, 2 );
		add_filter( 'login_url', [ self::class, 'login_url' ], 10, 3 );

		// Bounce a bare wp-login.php visit to the branded page (admins + the
		// action-based flows are left alone).
		add_action( 'login_init', [ self::class, 'maybe_bounce_wp_login' ] );
	}

	// ── Page bootstrap ──────────────────────────────────────────────────────

	public static function maybe_create_page(): void {
		$id = (int) get_option( self::OPTION_PAGE_ID, 0 );
		if ( $id > 0 && 'page' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			return;
		}
		$existing = get_page_by_path( self::PAGE_SLUG );
		if ( $existing instanceof WP_Post ) {
			update_option( self::OPTION_PAGE_ID, $existing->ID );
			return;
		}
		$new = wp_insert_post( [
			'post_title'   => __( 'ورود', 'hedayati-core' ),
			'post_name'    => self::PAGE_SLUG,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
		], true );
		if ( ! is_wp_error( $new ) ) {
			update_option( self::OPTION_PAGE_ID, (int) $new );
		}
	}

	public static function get_page_id(): int {
		return (int) get_option( self::OPTION_PAGE_ID, 0 );
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( $args, home_url( '/' . self::PAGE_SLUG . '/' ) );
	}

	private static function is_login_page(): bool {
		$id = self::get_page_id();
		return $id > 0 ? is_page( $id ) : is_page( self::PAGE_SLUG );
	}

	// ── wp-login.php → /login/ bounce ───────────────────────────────────────

	public static function maybe_bounce_wp_login(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';

		// Leave logout, the reset-key landing, admin-email confirmation, and any
		// non-standard action to core.
		if ( ! in_array( $action, [ 'login', 'lostpassword', 'retrievepassword' ], true ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			return;
		}

		$target = 'lostpassword' === $action || 'retrievepassword' === $action
			? self::url( [ 'action' => 'lostpassword' ] )
			: self::url();

		if ( isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ) {
			$target = add_query_arg( 'redirect_to', rawurlencode( wp_unslash( $_GET['redirect_to'] ) ), $target );
		}

		wp_safe_redirect( $target );
		exit;
	}

	// ── Reset-email re-pointing ─────────────────────────────────────────────

	/**
	 * @param string  $message
	 * @param string  $key
	 * @param string  $user_login
	 * @param WP_User $user_data
	 */
	public static function filter_reset_message( string $message, string $key, string $user_login, $user_data ): string {
		$core_url = network_site_url( "wp-login.php?action=rp&key={$key}&login=" . rawurlencode( $user_login ), 'login' );
		$our_url  = self::url( [ 'action' => 'rp', 'key' => $key, 'login' => rawurlencode( $user_login ) ] );

		return str_replace( $core_url, $our_url, $message );
	}

	public static function lostpassword_url( string $url, string $redirect ): string {
		$args = [ 'action' => 'lostpassword' ];
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		return self::url( $args );
	}

	/**
	 * Point front-end `wp_login_url()` calls (auth_redirect(), the "log in"
	 * links) at /login/. Admin-context callers ask for wp-login.php explicitly
	 * and are left alone.
	 */
	public static function login_url( string $url, string $redirect, bool $force_reauth ): string {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return $url;
		}
		$args = [];
		if ( '' !== $redirect ) {
			$args['redirect_to'] = $redirect;
		}
		if ( $force_reauth ) {
			$args['reauth'] = '1';
		}
		return self::url( $args );
	}

	// ── Request handling ───────────────────────────────────────────────────

	public static function handle(): void {
		if ( ! self::is_login_page() ) {
			return;
		}

		nocache_headers();

		$user_id = get_current_user_id();
		if ( $user_id > 0 && ! ( class_exists( 'Hedayati_Account_Security' ) && Hedayati_Account_Security::must_change( $user_id ) ) ) {
			// Already authenticated — never show them a login form.
			wp_safe_redirect( self::post_login_destination( wp_get_current_user(), self::requested_redirect() ) );
			exit;
		}

		$action = isset( $_GET['action'] ) && is_string( $_GET['action'] )
			? sanitize_key( wp_unslash( $_GET['action'] ) )
			: 'login';

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			match ( $action ) {
				'lostpassword' => self::process_lostpassword(),
				'resetpass'    => self::process_resetpass(),
				default        => self::process_login(),
			};
			return; // process_* always redirects+exits
		}

		if ( 'rp' === $action ) {
			self::process_rp_landing();
		}
	}

	private static function requested_redirect(): string {
		$raw = '';
		if ( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ) {
			$raw = wp_unslash( $_REQUEST['redirect_to'] );
		}
		return $raw;
	}

	/**
	 * Where a freshly authenticated user lands: the validated `redirect_to` if it
	 * is a safe local URL, otherwise the role workspace via the existing
	 * `login_redirect` filter chain.
	 */
	private static function post_login_destination( WP_User $user, string $requested ): string {
		$requested = wp_validate_redirect( $requested, '' );

		if ( user_can( $user, 'manage_options' ) ) {
			$fallback = '' !== $requested ? $requested : admin_url();
		} else {
			$fallback = '' !== $requested ? $requested : home_url( '/' );
		}

		/** Same filter wp-login.php fires — Hedayati_Auth_UI / Hedayati_Staff_Portal route by role. */
		$dest = apply_filters( 'login_redirect', $fallback, $requested, $user );

		return wp_validate_redirect( $dest, home_url( '/' ) );
	}

	// ── login ──────────────────────────────────────────────────────────────

	private static function process_login(): void {
		$nonce = isset( $_POST['hd_login_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hd_login_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hedayati_login' ) ) {
			self::redirect_with_notice( self::url(), __( 'نشست شما منقضی شده است. دوباره تلاش کنید.', 'hedayati-core' ) );
		}

		$creds = [
			'user_login'    => isset( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '',
			'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
			'remember'      => ! empty( $_POST['rememberme'] ),
		];

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			// wp_signon already fired wp_login_failed (rate-limiter bookkeeping).
			// Show a single privacy-safe message — never the specific code.
			$msg = in_array( $user->get_error_code(), [ 'too_many_retries', 'invalid_credentials', 'invalidcombo' ], true ) && '' !== trim( wp_strip_all_tags( (string) $user->get_error_message() ) )
				? wp_strip_all_tags( (string) $user->get_error_message() )
				: __( 'نام کاربری/شمارهٔ همراه یا رمز عبور صحیح نیست.', 'hedayati-core' );

			self::redirect_with_notice(
				add_query_arg( 'redirect_to', rawurlencode( self::requested_redirect() ), self::url() ),
				$msg
			);
		}

		wp_set_current_user( $user->ID );
		wp_safe_redirect( self::post_login_destination( $user, self::requested_redirect() ) );
		exit;
	}

	// ── forgot password ────────────────────────────────────────────────────

	private static function process_lostpassword(): void {
		$nonce = isset( $_POST['hd_lostpass_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hd_lostpass_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hedayati_lostpassword' ) ) {
			self::redirect_with_notice( self::url( [ 'action' => 'lostpassword' ] ), __( 'نشست شما منقضی شده است. دوباره تلاش کنید.', 'hedayati-core' ) );
		}

		$login = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? trim( wp_unslash( $_POST['user_login'] ) ) : '';

		if ( '' !== $login ) {
			// Same abuse buckets as Hedayati_Auth_UI's wp-login path; success and
			// unknown-account requests count equally.
			$identifier = 'reset:' . strtolower( $login );
			$ip_bucket  = 'reset:' . Hedayati_Rate_Limiter::get_client_ip();
			if ( ! Hedayati_Rate_Limiter::is_rate_limited( $identifier, $ip_bucket ) ) {
				Hedayati_Rate_Limiter::record_failure( $identifier, $ip_bucket );
				$_POST['user_login'] = $login; // retrieve_password() reads $_POST['user_login']
				retrieve_password(); // result deliberately never inspected
			}
		}

		// Byte-identical outward response whether or not the account exists.
		wp_safe_redirect( self::url( [ 'checkemail' => 'confirm' ] ) );
		exit;
	}

	// ── reset link landing + new password ──────────────────────────────────

	private static function rp_cookie_name(): string {
		return 'wp-resetpass-' . COOKIEHASH;
	}

	/**
	 * `?action=rp&key=…&login=…` — validate the core key, stash it in the same
	 * cookie wp-login.php uses, then redirect to the clean `?action=resetpass`
	 * URL (so the key never lingers in the address bar / history).
	 */
	private static function process_rp_landing(): void {
		$key   = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$login = isset( $_GET['login'] ) && is_string( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

		$user = check_password_reset_key( $key, $login );

		if ( is_wp_error( $user ) ) {
			self::redirect_with_notice( self::url( [ 'action' => 'lostpassword' ] ), __( 'این پیوند بازیابی نامعتبر یا منقضی شده است. دوباره درخواست دهید.', 'hedayati-core' ) );
		}

		$value = $login . ':' . $key;
		setcookie( self::rp_cookie_name(), $value, 0, '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::rp_cookie_name() ] = $value;

		wp_safe_redirect( self::url( [ 'action' => 'resetpass' ] ) );
		exit;
	}

	/** Resolve the (login, key) pair stashed by process_rp_landing(). */
	public static function resetpass_user(): WP_User|WP_Error {
		$cookie = isset( $_COOKIE[ self::rp_cookie_name() ] ) ? wp_unslash( $_COOKIE[ self::rp_cookie_name() ] ) : '';
		if ( ! is_string( $cookie ) || ! str_contains( $cookie, ':' ) ) {
			return new WP_Error( 'invalid_key', __( 'پیوند بازیابی نامعتبر است.', 'hedayati-core' ) );
		}
		[ $login, $key ] = array_map( 'sanitize_text_field', explode( ':', $cookie, 2 ) );
		return check_password_reset_key( $key, $login );
	}

	private static function process_resetpass(): void {
		$nonce = isset( $_POST['hd_resetpass_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hd_resetpass_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'hedayati_resetpass' ) ) {
			self::redirect_with_notice( self::url( [ 'action' => 'resetpass' ] ), __( 'نشست شما منقضی شده است. دوباره تلاش کنید.', 'hedayati-core' ) );
		}

		$user = self::resetpass_user();
		if ( is_wp_error( $user ) ) {
			self::redirect_with_notice( self::url( [ 'action' => 'lostpassword' ] ), __( 'این پیوند بازیابی نامعتبر یا منقضی شده است. دوباره درخواست دهید.', 'hedayati-core' ) );
		}

		$pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
		$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';

		if ( strlen( $pass1 ) < 12 ) {
			self::redirect_with_notice( self::url( [ 'action' => 'resetpass' ] ), __( 'رمز عبور باید حداقل ۱۲ نویسه باشد.', 'hedayati-core' ) );
		}
		if ( $pass1 !== $pass2 ) {
			self::redirect_with_notice( self::url( [ 'action' => 'resetpass' ] ), __( 'رمز عبور و تکرار آن یکسان نیست.', 'hedayati-core' ) );
		}

		// WordPress core: rotates the password, invalidates the key + sessions.
		reset_password( $user, $pass1 );

		// Clear the forced-change marker too — the user just chose a real password.
		if ( class_exists( 'Hedayati_Account_Security' ) ) {
			Hedayati_Account_Security::clear( $user->ID );
		}

		setcookie( self::rp_cookie_name(), ' ', time() - YEAR_IN_SECONDS, '/', COOKIE_DOMAIN, is_ssl(), true );
		unset( $_COOKIE[ self::rp_cookie_name() ] );

		self::redirect_with_notice( self::url( [ 'password' => 'reset' ] ), __( 'رمز عبور جدید ثبت شد. اکنون وارد شوید.', 'hedayati-core' ), 'success' );
	}

	// ── One-shot notice (PRG) ──────────────────────────────────────────────

	private static function notice_key(): string {
		// Anonymous — keyed on the client IP hash, short-lived.
		return 'hedayati_login_notice_' . md5( Hedayati_Rate_Limiter::get_client_ip() . wp_salt() );
	}

	private static function redirect_with_notice( string $url, string $text, string $type = 'error' ): void {
		set_transient( self::notice_key(), [ 'type' => $type, 'text' => $text ], self::NOTICE_TTL );
		wp_safe_redirect( wp_validate_redirect( $url, self::url() ) );
		exit;
	}

	/** Read + clear the one-shot notice. Returns [] when none. */
	public static function take_notice(): array {
		$n = get_transient( self::notice_key() );
		if ( ! is_array( $n ) ) {
			return [];
		}
		delete_transient( self::notice_key() );
		return [ 'type' => (string) ( $n['type'] ?? 'error' ), 'text' => (string) ( $n['text'] ?? '' ) ];
	}
}
