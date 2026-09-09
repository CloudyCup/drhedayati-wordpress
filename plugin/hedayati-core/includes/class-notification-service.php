<?php
/**
 * AI Studio parity — internal, on-site notifications (owner decision D50).
 *
 * No email, no SMS, no push service. One `hedayati_notifications` row per
 * (recipient, event); the student sees them in `/account/?view=notifications`
 * and as an unread count; staff see role-relevant ones in `/panel/`.
 *
 * Notifications are created only for a small, deliberate set of real events —
 * never for every CRUD write. Everything is real persistent data; there is no
 * demo/seed notification anywhere.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Notification_Service {

	public static function init(): void {
		add_action( 'admin_post_hedayati_notif_read', [ self::class, 'handle_read' ] );
		add_action( 'admin_post_hedayati_notif_read_all', [ self::class, 'handle_read_all' ] );
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );

		// Wire the deliberate event set.
		add_action( 'hedayati_consultation_created', [ self::class, 'on_consultation_created' ] );
	}

	// ── Write ───────────────────────────────────────────────────────────────

	/**
	 * @return int|false new notification id, or false if not stored
	 */
	public static function notify( int $user_id, string $type, string $subject, string $body = '', string $url = '', string $object_type = '', int $object_id = 0 ): int|false {
		global $wpdb;

		if ( $user_id <= 0 || '' === $type ) {
			return false;
		}

		$table    = Hedayati_DB_Schema::get_table_notifications();
		$inserted = $wpdb->insert(
			$table,
			[
				'user_id'     => $user_id,
				'type'        => mb_substr( sanitize_key( $type ), 0, 40 ),
				'subject'     => mb_substr( sanitize_text_field( $subject ), 0, 190 ),
				'body'        => mb_substr( sanitize_text_field( $body ), 0, 500 ),
				'url'         => esc_url_raw( $url ),
				'object_type' => mb_substr( sanitize_key( $object_type ), 0, 32 ),
				'object_id'   => max( 0, $object_id ),
				'read_at'     => null,
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return false === $inserted ? false : (int) $wpdb->insert_id;
	}

	/** Notify every user holding $capability (used for staff-queue events). */
	public static function notify_capable( string $capability, string $type, string $subject, string $body = '', string $url = '', string $object_type = '', int $object_id = 0 ): void {
		foreach ( get_users( [ 'capability' => $capability, 'number' => 50, 'fields' => [ 'ID' ] ] ) as $user ) {
			self::notify( (int) $user->ID, $type, $subject, $body, $url, $object_type, $object_id );
		}
	}

	// ── Read ────────────────────────────────────────────────────────────────

	/**
	 * @return array<int, array>
	 */
	public static function list_for_user( int $user_id, int $limit = 30 ): array {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_notifications();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
				$user_id,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function unread_count( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		$table = Hedayati_DB_Schema::get_table_notifications();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND read_at IS NULL",
			$user_id
		) );
	}

	public static function mark_read( int $id, int $user_id ): bool {
		global $wpdb;

		if ( $id <= 0 || $user_id <= 0 ) {
			return false;
		}

		$table    = Hedayati_DB_Schema::get_table_notifications();
		$affected = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET read_at = %s WHERE id = %d AND user_id = %d AND read_at IS NULL",
			current_time( 'mysql', true ),
			$id,
			$user_id
		) );

		// Return true only if a row the caller actually owns was updated.
		return is_int( $affected ) && $affected > 0;
	}

	public static function mark_all_read( int $user_id ): void {
		global $wpdb;

		$table = Hedayati_DB_Schema::get_table_notifications();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
			current_time( 'mysql', true ),
			$user_id
		) );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_read(): void {
		self::verify_own_request( 'hedayati_notif_read' );

		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		self::mark_read( $id, get_current_user_id() );

		self::redirect_back();
	}

	public static function handle_read_all(): void {
		self::verify_own_request( 'hedayati_notif_read_all' );

		self::mark_all_read( get_current_user_id() );
		self::redirect_back();
	}

	private static function verify_own_request( string $nonce_action ): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| ! is_user_logged_in()
			|| ! wp_verify_nonce( $nonce, $nonce_action )
		) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	private static function redirect_back(): void {
		$ref = wp_get_referer();
		wp_safe_redirect( $ref && wp_validate_redirect( $ref, home_url( '/' ) ) ? $ref : home_url( '/account/?view=notifications' ) );
		exit;
	}

	// ── Event wiring ────────────────────────────────────────────────────────

	public static function on_consultation_created( int $consultation_id ): void {
		self::notify_capable(
			Hedayati_Consultation_Service::CAPABILITY,
			'consultation_new',
			__( 'درخواست مشاورهٔ جدید', 'hedayati-core' ),
			__( 'یک درخواست مشاورهٔ جدید از فرم سایت ثبت شد.', 'hedayati-core' ),
			Hedayati_Staff_Portal::url( [ 'view' => 'consultations' ] ),
			'consultation',
			$consultation_id
		);
	}

	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return;
		}

		$table = Hedayati_DB_Schema::get_table_notifications();
		$wpdb->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'type'        => (string) $row['type'],
			'subject'     => (string) $row['subject'],
			'body'        => (string) $row['body'],
			'url'         => (string) $row['url'],
			'object_type' => (string) $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'read_at'     => null !== $row['read_at'] ? (string) $row['read_at'] : null,
			'created_at'  => (string) $row['created_at'],
		];
	}
}
