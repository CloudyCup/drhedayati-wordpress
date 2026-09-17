<?php
/**
 * Owner decision D54 — the last normal-user wp-admin dependency.
 *
 * `/wp-admin/profile.php` was kept reachable for every role only so staff had
 * somewhere to change their own password. This gives every `/panel/` role
 * (teacher, teacher_assistant, reception, hedayati_manager) a real in-panel
 * "Account & security" screen instead, so `Hedayati_Admin_Access` can stop
 * exempting `profile.php`. The equivalent student screen lives in
 * `Hedayati_Student_Portal::render_profile_view()` (`/account/?view=profile`).
 *
 * Gated on the `read` capability (not a `hedayati_*` one) because every
 * `/panel/` role already holds it and changing one's own password is not a
 * privileged operation — `Hedayati_Staff_Portal::guard()` already requires
 * `allowed()` (a real Hedayati capability) before any module view is reached.
 *
 * Reuses `Hedayati_Account_Security::validate_new_password()` so the password
 * rules never drift from the forced-first-login-change screen.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Panel_Security {

	private const NONCE_ACTION = 'hedayati_panel_password_save';
	private const CAPABILITY   = 'read';

	public static function init(): void {
		add_action( 'admin_post_' . self::NONCE_ACTION, [ self::class, 'handle_save' ] );
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
	}

	public static function register_panel_view( array $views ): array {
		$views['security'] = [
			'capability' => self::CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'حساب و امنیت', 'hedayati-core' ),
			'title'      => __( 'حساب و امنیت', 'hedayati-core' ),
			'desc'       => __( 'تغییر رمز عبور حساب کاربری شما', 'hedayati-core' ),
			'icon'       => 'shield',
		];
		return $views;
	}

	public static function render_panel(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$user = wp_get_current_user();

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'حساب کاربری', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'حساب و امنیت', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: username */
					__( 'ورود با نام کاربری «%s». برای تغییر رمز عبور، رمز فعلی خود را وارد کنید.', 'hedayati-core' ),
					$user->user_login
				)
			)
		);
		echo '</div></header>';

		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_ACTION ) . '">';

		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="password" name="current_password" autocomplete="current-password" required dir="ltr"></label>',
			esc_html__( 'رمز عبور فعلی', 'hedayati-core' )
		);
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="password" name="new_password" autocomplete="new-password" required minlength="%s" dir="ltr"></label>',
			esc_html( sprintf(
				/* translators: %d: minimum character count */
				__( 'رمز عبور جدید (حداقل %d نویسه)', 'hedayati-core' ),
				Hedayati_Account_Security::MIN_LENGTH
			) ),
			esc_attr( (string) Hedayati_Account_Security::MIN_LENGTH )
		);
		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="password" name="confirm_password" autocomplete="new-password" required minlength="%s" dir="ltr"></label>',
			esc_html__( 'تکرار رمز عبور جدید', 'hedayati-core' ),
			esc_attr( (string) Hedayati_Account_Security::MIN_LENGTH )
		);

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'به‌روزرسانی رمز عبور', 'hedayati-core' ) . '</button>';
		echo '</form>';
	}

	public static function handle_save(): void {
		Hedayati_Staff_Portal::guard_action( self::NONCE_ACTION, self::CAPABILITY );

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		$current = isset( $_POST['current_password'] ) ? (string) wp_unslash( $_POST['current_password'] ) : '';
		$new     = isset( $_POST['new_password'] ) ? (string) wp_unslash( $_POST['new_password'] ) : '';
		$confirm = isset( $_POST['confirm_password'] ) ? (string) wp_unslash( $_POST['confirm_password'] ) : '';

		if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'hedayati_bad_current_password', __( 'رمز عبور فعلی نادرست است.', 'hedayati-core' ) ),
				[ 'view' => 'security' ]
			);
		}

		$error = Hedayati_Account_Security::validate_new_password( $new, $confirm, $user );
		if ( '' !== $error ) {
			Hedayati_Staff_Portal::redirect_notice(
				new WP_Error( 'hedayati_invalid_new_password', $error ),
				[ 'view' => 'security' ]
			);
		}

		Hedayati_Audit_Log::record( 'account.password_changed', 'user', $user_id, 'via panel security', $user_id );

		wp_set_password( $new, $user_id );

		// wp_set_password() invalidates every session for this user, including
		// the one making this request — re-establish it so they land back in
		// the panel already logged in, matching Hedayati_Account_Security.
		wp_set_current_user( $user_id );
		if ( ! headers_sent() ) {
			wp_clear_auth_cookie();
			wp_set_auth_cookie( $user_id, true );
		}

		Hedayati_Staff_Portal::redirect_notice( true, [ 'view' => 'security' ] );
	}
}
