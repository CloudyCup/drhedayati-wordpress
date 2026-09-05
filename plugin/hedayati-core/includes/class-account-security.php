<?php
/**
 * Phase 3 — forced first-login password change.
 *
 * Reception (and any manager/administrator) creates a student account with a
 * strong random temporary password that is shown to the authorised staff member
 * exactly once and never stored in plaintext anywhere. Only a boolean marker
 * (`hedayati_must_change_password` usermeta) is persisted. On the student's first
 * login WordPress authenticates them normally with that temporary password, then
 * every front-end request is intercepted here and redirected to a mandatory
 * "choose a new password" screen. No other portal/panel screen is reachable
 * until the change succeeds; the marker is cleared only after
 * `wp_set_password()` returns and the session is re-established.
 *
 * No email/SMS delivery in Phase 3 (owner decision): the temporary password is
 * handed to the student in person by the staff member who created the account.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Account_Security {

	/** Usermeta flag: a non-empty value means "must change password before use". */
	public const META_MUST_CHANGE = 'hedayati_must_change_password';

	/** Nonce action for the forced-change form. */
	private const NONCE_ACTION = 'hedayati_account_set_password';

	/** Minimum length for a student-chosen password. */
	public const MIN_LENGTH = 12;

	public static function init(): void {
		// Priority 1: run before Hedayati_Student_Portal / Hedayati_Staff_Portal
		// guards (default priority) so a flagged user can never reach any other
		// front-end screen.
		add_action( 'template_redirect', [ self::class, 'intercept' ], 1 );
		add_action( 'admin_post_' . self::NONCE_ACTION, [ self::class, 'handle_change' ] );
		add_filter( 'body_class', [ self::class, 'body_class' ] );
		add_filter( 'show_admin_bar', [ self::class, 'hide_admin_bar_while_forced' ] );
	}

	/** The mandatory password screen is a focused front-end flow, not wp-admin. */
	public static function hide_admin_bar_while_forced( bool $show ): bool {
		if ( is_user_logged_in() && self::must_change( get_current_user_id() ) ) {
			return false;
		}

		return $show;
	}

	/**
	 * Marks the page so the theme can strip the site nav / CTAs while a user is
	 * locked into the forced-change screen (every nav link just bounces back
	 * here anyway — see intercept()).
	 */
	public static function body_class( array $classes ): array {
		if ( is_user_logged_in() && self::must_change( get_current_user_id() ) ) {
			$classes[] = 'hd-force-password';
		}
		return $classes;
	}

	// ── Marker helpers ──────────────────────────────────────────────────────

	public static function require_change( int $user_id ): void {
		update_user_meta( $user_id, self::META_MUST_CHANGE, '1' );
	}

	public static function clear( int $user_id ): void {
		delete_user_meta( $user_id, self::META_MUST_CHANGE );
	}

	public static function must_change( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::META_MUST_CHANGE, true );
	}

	/**
	 * Strong random temporary password. Uses WordPress's CSPRNG-backed generator
	 * with special characters; the caller shows it to staff once and never
	 * persists it (WordPress hashes it inside wp_insert_user()/wp_set_password()).
	 */
	public static function generate_temp_password(): string {
		return wp_generate_password( 18, true, true );
	}

	public static function set_password_url(): string {
		return home_url( '/account/set-password/' );
	}

	// ── Enforcement ─────────────────────────────────────────────────────────

	/**
	 * On every front-end request: if the current user is flagged, render the
	 * forced-change screen and stop. The admin-post.php change handler is a
	 * separate endpoint (no template_redirect there), and wp-login.php logout
	 * is not a template either, so both stay reachable.
	 */
	public static function intercept(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! self::must_change( $user_id ) ) {
			return;
		}

		Hedayati_Student_Portal::send_no_cache_headers();
		self::render_screen( self::take_notice() );
		exit;
	}

	private static function notice_key(): string {
		return 'hedayati_pwchange_notice_' . get_current_user_id();
	}

	/** Read and clear a one-shot error message set by a failed change attempt. */
	private static function take_notice(): string {
		$notice = (string) get_transient( self::notice_key() );
		if ( '' !== $notice ) {
			delete_transient( self::notice_key() );
		}
		return $notice;
	}

	/**
	 * @param string $message   optional error/notice to show above the form
	 */
	private static function render_screen( string $message = '' ): void {
		status_header( 200 );
		nocache_headers();

		get_header();
		?>
		<main id="site-main" class="hd-portal-main section" role="main" tabindex="-1">
			<div class="container hd-portal-shell hd-portal-shell-single">
				<div class="hd-portal-content">
					<h1 class="hd-portal-title"><?php esc_html_e( 'تغییر رمز عبور', 'hedayati-core' ); ?></h1>
					<p class="hd-portal-note">
						<?php esc_html_e( 'برای ادامه باید یک رمز عبور جدید و شخصی انتخاب کنید. تا زمانی که رمز عبور تغییر نکند، دسترسی به بخش‌های دیگر ممکن نیست.', 'hedayati-core' ); ?>
					</p>

					<?php if ( '' !== $message ) : ?>
						<div class="hd-portal-notice hd-portal-notice-error"><?php echo esc_html( $message ); ?></div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hd-portal-form">
						<?php wp_nonce_field( self::NONCE_ACTION ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>">

						<label class="hd-portal-field">
							<span><?php esc_html_e( 'رمز عبور جدید (حداقل ۱۲ نویسه)', 'hedayati-core' ); ?></span>
							<input type="password" name="new_password" autocomplete="new-password" required minlength="<?php echo esc_attr( (string) self::MIN_LENGTH ); ?>" dir="ltr">
						</label>

						<label class="hd-portal-field">
							<span><?php esc_html_e( 'تکرار رمز عبور جدید', 'hedayati-core' ); ?></span>
							<input type="password" name="confirm_password" autocomplete="new-password" required minlength="<?php echo esc_attr( (string) self::MIN_LENGTH ); ?>" dir="ltr">
						</label>

						<button type="submit" class="hd-portal-btn"><?php esc_html_e( 'ثبت رمز عبور و ادامه', 'hedayati-core' ); ?></button>
					</form>

					<p class="hd-portal-note">
						<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'خروج از حساب', 'hedayati-core' ); ?></a>
					</p>
				</div>
			</div>
		</main>
		<?php
		get_footer();
	}

	/**
	 * admin-post.php handler for the forced-change form.
	 */
	public static function handle_change(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'لطفاً ابتدا وارد شوید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$user_id = get_current_user_id();

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::NONCE_ACTION )
		) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		// A flagged user is the only one who should be here, but a user who
		// simply wants to rotate their password is harmless too — still require
		// the marker so this endpoint can't be used to weaken an account that
		// was never provisioned with a temporary password.
		if ( ! self::must_change( $user_id ) ) {
			wp_safe_redirect( self::landing_url( $user_id ) );
			exit;
		}

		$new     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		$confirm = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';
		$user    = get_userdata( $user_id );

		$error = self::validate( $new, $confirm, $user );
		if ( '' !== $error ) {
			// PRG: bounce back to the (interceptor-rendered) forced-change
			// screen with a one-shot message — no uncatchable exit mid-render.
			set_transient( self::notice_key(), $error, 60 );
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		// Record the event BEFORE wp_set_password() drops the session (actor is
		// passed explicitly for the same reason). The password value is never
		// part of the note.
		Hedayati_Audit_Log::record( 'account.password_changed', 'user', $user_id, 'forced first-login change', $user_id );

		wp_set_password( $new, $user_id );
		self::clear( $user_id );

		// wp_set_password() invalidates every session for this user. Re-establish
		// one immediately so the student lands in the portal already logged in.
		// setcookie() is a no-op / warning once output has started, which only
		// happens inside the acceptance harness — guard for it there.
		wp_set_current_user( $user_id );
		if ( ! headers_sent() ) {
			wp_clear_auth_cookie();
			wp_set_auth_cookie( $user_id, true );
		}

		wp_safe_redirect( self::landing_url( $user_id ) );
		exit;
	}

	/** Where a user goes once their password is set: portal for students, home otherwise. */
	private static function landing_url( int $user_id ): string {
		if ( user_can( $user_id, 'hedayati_view_own_portal' ) ) {
			return Hedayati_Student_Portal::get_account_url();
		}

		if ( class_exists( 'Hedayati_Staff_Portal' ) && user_can( $user_id, 'hedayati_view_assigned_runs' ) ) {
			return Hedayati_Staff_Portal::url();
		}

		return home_url( '/' );
	}

	/**
	 * @return string  empty string when valid, otherwise a Persian error message
	 */
	private static function validate( string $new, string $confirm, ?WP_User $user ): string {
		if ( strlen( $new ) < self::MIN_LENGTH ) {
			return sprintf(
				/* translators: %d: minimum character count */
				__( 'رمز عبور باید حداقل %d نویسه باشد.', 'hedayati-core' ),
				self::MIN_LENGTH
			);
		}

		if ( $new !== $confirm ) {
			return __( 'رمز عبور و تکرار آن یکسان نیست.', 'hedayati-core' );
		}

		if ( $user instanceof WP_User ) {
			$lower = strtolower( $new );
			if ( $lower === strtolower( $user->user_login ) || ( $user->user_email && $lower === strtolower( $user->user_email ) ) ) {
				return __( 'رمز عبور نباید با نام کاربری یا ایمیل شما یکسان باشد.', 'hedayati-core' );
			}
		}

		return '';
	}
}
