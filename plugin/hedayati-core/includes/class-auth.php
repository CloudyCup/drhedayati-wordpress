<?php
/**
 * Dual Username or Phone + Password Authentication Handler.
 *
 * Extends the WordPress authentication pipeline using two cleanly separated filter stages:
 *   1. Phone Authentication Adapter (priority 30):
 *      Runs after standard username auth. If the identifier is an Iranian mobile,
 *      resolves the user and delegates password verification natively via
 *      wp_authenticate_username_password().
 *   2. Final Rate-Limit Enforcement (priority 90):
 *      Evaluates rate limits late in the authenticate chain, overriding both
 *      successful authentication and previous errors if the identifier/IP is locked.
 *
 * Single-point failure recording is handled exclusively through WordPress's
 * `wp_login_failed` action to prevent double counting.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Auth {

	/**
	 * Bootstrap authentication hooks.
	 */
	public static function init(): void {
		// 1. Phone authentication adapter (priority 30: after native username auth at priority 20)
		add_filter( 'authenticate', [ self::class, 'authenticate_phone' ], 30, 3 );

		// 2. Final rate-limit enforcement (priority 90: late in the chain to override any previous state)
		add_filter( 'authenticate', [ self::class, 'enforce_rate_limit' ], 90, 3 );

		// Authoritative single failure recording point
		add_action( 'wp_login_failed', [ self::class, 'on_login_failed' ] );
		add_action( 'wp_login', [ self::class, 'on_login_success' ], 10, 2 );
	}

	/**
	 * Stage 1: Phone Authentication Adapter.
	 * Runs after WordPress default username/password authentication.
	 *
	 * @param null|WP_User|WP_Error $user      Authentication state.
	 * @param string                $username  Provided username or phone.
	 * @param string                $password  Provided password.
	 * @return null|WP_User|WP_Error
	 */
	public static function authenticate_phone( null|WP_User|WP_Error $user, string $username, string $password ): null|WP_User|WP_Error {
		$raw_identifier = trim( $username );

		// If input does NOT look like an Iranian phone number, leave the existing result unchanged
		if ( ! Hedayati_Phone::looks_like_iranian_phone( $raw_identifier ) ) {
			return $user;
		}

		// Empty password check
		if ( '' === $password ) {
			return $user;
		}

		// Normalize phone number
		$canonical_phone = Hedayati_Phone::normalize( $raw_identifier );

		if ( is_wp_error( $canonical_phone ) ) {
			return self::get_generic_invalid_credentials_error();
		}

		// Resolve user from hedayati_user_phones table
		$matched_user = Hedayati_User_Phone_Service::find_user_by_phone( $canonical_phone );

		if ( ! ( $matched_user instanceof WP_User ) ) {
			return self::get_generic_invalid_credentials_error();
		}

		// Delegate password verification through WordPress's native authentication flow
		$auth_result = wp_authenticate_username_password( null, $matched_user->user_login, $password );

		if ( is_wp_error( $auth_result ) ) {
			return self::get_generic_invalid_credentials_error();
		}

		return $auth_result;
	}

	/**
	 * Stage 2: Final Rate-Limit Enforcement.
	 * Runs late in the authentication chain (priority 90). Overrides both successful
	 * WP_User results and previous errors if rate limits are exceeded.
	 *
	 * @param null|WP_User|WP_Error $user      Authentication state.
	 * @param string                $username  Provided username or phone.
	 * @param string                $password  Provided password.
	 * @return null|WP_User|WP_Error
	 */
	public static function enforce_rate_limit( null|WP_User|WP_Error $user, string $username, string $password ): null|WP_User|WP_Error {
		$raw_identifier = trim( $username );
		$client_ip      = Hedayati_Rate_Limiter::get_client_ip();

		if ( '' === $raw_identifier || '' === $password ) {
			return $user;
		}

		// If rate limit is exceeded for identifier or IP, override and block access
		if ( Hedayati_Rate_Limiter::is_rate_limited( $raw_identifier, $client_ip ) ) {
			return new WP_Error(
				'too_many_retries',
				sprintf(
					'<strong>%s</strong>: %s',
					esc_html__( 'خطای دسترسی', 'hedayati-core' ),
					esc_html__( 'تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً پس از ۱۵ دقیقه مجدداً تلاش نمایید.', 'hedayati-core' )
				)
			);
		}

		return $user;
	}

	/**
	 * Authoritative single failure recording point.
	 * Triggered by WordPress whenever an authentication attempt returns a WP_Error.
	 *
	 * @param string $username
	 */
	public static function on_login_failed( string $username ): void {
		$client_ip = Hedayati_Rate_Limiter::get_client_ip();
		Hedayati_Rate_Limiter::record_failure( $username, $client_ip );
	}

	/**
	 * Clear account identifier rate-limit buckets upon successful login.
	 * Shared IP bucket is intentionally NOT cleared to protect against distributed brute force.
	 *
	 * @param string  $user_login
	 * @param WP_User $user
	 */
	public static function on_login_success( string $user_login, WP_User $user ): void {
		// Clear identifier bucket for user_login
		Hedayati_Rate_Limiter::clear_identifier_attempts( $user_login );

		// Clear identifier bucket for registered canonical phone if present
		$phone_record = Hedayati_User_Phone_Service::get_phone_record_by_user( $user->ID );
		if ( $phone_record ) {
			Hedayati_Rate_Limiter::clear_identifier_attempts( $phone_record['phone_e164'] );
		}
	}

	/**
	 * Return a generic, privacy-safe invalid credentials error object.
	 * Avoids user enumeration by giving the exact same error for unknown users and wrong passwords.
	 *
	 * @return WP_Error
	 */
	public static function get_generic_invalid_credentials_error(): WP_Error {
		return new WP_Error(
			'invalid_credentials',
			sprintf(
				'<strong>%s</strong>: %s',
				esc_html__( 'خطا', 'hedayati-core' ),
				esc_html__( 'نام کاربری/شماره موبایل یا رمز عبور اشتباه است.', 'hedayati-core' )
			)
		);
	}
}
