<?php
/**
 * AI Studio parity — internal support tickets (owner decision D51).
 *
 * A student opens a ticket (`hedayati_use_support_tickets`), sees only their own
 * tickets, and replies to their own open ticket. Reception/manager
 * (`hedayati_manage_support_tickets`) work a shared queue: filter, view, reply,
 * change status (open / waiting_student / waiting_staff / closed).
 *
 * Ownership is enforced on EVERY read and write — a student can never load,
 * reply to, or even probe another student's ticket. No email/SMS. Staff
 * status changes are audited; message bodies are never copied into the audit log.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Support_Service {

	public const STUDENT_CAP = 'hedayati_use_support_tickets';
	public const STAFF_CAP    = 'hedayati_manage_support_tickets';

	public const STATUSES   = [ 'open', 'waiting_student', 'waiting_staff', 'closed' ];
	public const CATEGORIES = [ 'general', 'class', 'schedule', 'finance', 'technical' ];

	public static function init(): void {
		add_action( 'admin_post_hedayati_support_open', [ self::class, 'handle_open' ] );
		add_action( 'admin_post_hedayati_support_reply', [ self::class, 'handle_reply' ] );
		add_action( 'admin_post_hedayati_support_status', [ self::class, 'handle_status' ] );
		add_action( 'deleted_user', [ self::class, 'on_user_deleted' ] );

		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );

		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'support_ticket' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [
			'support.opened',
			'support.replied',
			'support.status_changed',
		] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views['support'] = [
			'capability' => self::STAFF_CAP,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'تیکت‌های پشتیبانی', 'hedayati-core' ),
			'title'      => __( 'پشتیبانی و تیکت‌ها', 'hedayati-core' ),
			'desc'       => __( 'صف درخواست‌های دانشجویان، پاسخ و مدیریت وضعیت', 'hedayati-core' ),
			'icon'       => 'lifebuoy',
		];
		return $views;
	}

	public static function status_label( string $s ): string {
		return [
			'open'           => __( 'باز', 'hedayati-core' ),
			'waiting_student' => __( 'در انتظار دانشجو', 'hedayati-core' ),
			'waiting_staff'  => __( 'در انتظار پشتیبانی', 'hedayati-core' ),
			'closed'         => __( 'بسته‌شده', 'hedayati-core' ),
		][ $s ] ?? $s;
	}

	public static function category_label( string $c ): string {
		return [
			'general'  => __( 'عمومی', 'hedayati-core' ),
			'class'    => __( 'کلاس و محتوا', 'hedayati-core' ),
			'schedule' => __( 'برنامه و زمان‌بندی', 'hedayati-core' ),
			'finance'  => __( 'مالی و ثبت‌نام', 'hedayati-core' ),
			'technical' => __( 'فنی', 'hedayati-core' ),
		][ $c ] ?? $c;
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Hedayati_DB_Schema::get_table_support_tickets();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** A ticket the current viewer is allowed to see, or null. */
	public static function get_for_viewer( int $id, int $user_id ): ?array {
		$ticket = self::get( $id );
		if ( null === $ticket ) {
			return null;
		}
		if ( (int) $ticket['user_id'] === $user_id ) {
			return $ticket;
		}
		return user_can( $user_id, self::STAFF_CAP ) ? $ticket : null;
	}

	/** @return array<int, array> */
	public static function list_for_user( int $user_id ): array {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_support_tickets();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY last_reply_at DESC", $user_id ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	/**
	 * @param array{status?:string,search?:string} $args
	 * @return array<int, array>
	 */
	public static function queue( array $args = [] ): array {
		global $wpdb;
		$table  = Hedayati_DB_Schema::get_table_support_tickets();
		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'subject LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
			. " ORDER BY FIELD(status,'waiting_staff','open','waiting_student','closed'), last_reply_at DESC LIMIT 100";

		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_waiting_staff(): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_support_tickets();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('waiting_staff','open')" );
	}

	/** @return array<int, array> */
	public static function messages( int $ticket_id ): array {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_support_messages();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY id ASC", $ticket_id ),
			ARRAY_A
		);
		return array_map( static fn( array $r ): array => [
			'id'          => (int) $r['id'],
			'ticket_id'   => (int) $r['ticket_id'],
			'author_id'   => (int) $r['author_id'],
			'author_kind' => (string) $r['author_kind'],
			'body'        => (string) $r['body'],
			'created_at'  => (string) $r['created_at'],
		], $rows ?: [] );
	}

	// ── Write ───────────────────────────────────────────────────────────────

	public static function open( int $user_id, string $subject, string $category, string $body, ?int $run_id = null ): int|WP_Error {
		global $wpdb;

		if ( ! user_can( $user_id, self::STUDENT_CAP ) ) {
			return new WP_Error( 'cap', __( 'اجازهٔ ثبت تیکت ندارید.', 'hedayati-core' ) );
		}

		$subject  = mb_substr( sanitize_text_field( $subject ), 0, 190 );
		$body     = trim( sanitize_textarea_field( $body ) );
		$category = in_array( $category, self::CATEGORIES, true ) ? $category : 'general';

		if ( mb_strlen( $subject ) < 3 ) {
			return new WP_Error( 'subject', __( 'موضوع تیکت را وارد کنید.', 'hedayati-core' ) );
		}
		if ( mb_strlen( $body ) < 5 ) {
			return new WP_Error( 'body', __( 'متن پیام را وارد کنید.', 'hedayati-core' ) );
		}
		if ( null !== $run_id && $run_id > 0 ) {
			$enr = Hedayati_Enrollment_Service::get_by_run_user( $run_id, $user_id );
			if ( null === $enr ) {
				$run_id = null; // silently drop a course link the student is not in
			}
		} else {
			$run_id = null;
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_support_tickets();

		$inserted = $wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'subject'         => $subject,
				'category'        => $category,
				'status'          => 'waiting_staff',
				'run_id'          => $run_id,
				'last_reply_at'   => $now,
				'last_reply_kind' => 'student',
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db', __( 'ثبت تیکت ناموفق بود.', 'hedayati-core' ) );
		}

		$ticket_id = (int) $wpdb->insert_id;
		self::insert_message( $ticket_id, $user_id, 'student', $body );

		Hedayati_Audit_Log::record( 'support.opened', 'support_ticket', $ticket_id, 'category ' . $category, $user_id );
		Hedayati_Notification_Service::notify_capable(
			self::STAFF_CAP,
			'support_new',
			__( 'تیکت پشتیبانی جدید', 'hedayati-core' ),
			$subject,
			Hedayati_Staff_Portal::url( [ 'view' => 'support', 'ticket' => $ticket_id ] ),
			'support_ticket',
			$ticket_id
		);

		return $ticket_id;
	}

	public static function reply( int $ticket_id, int $user_id, string $body ): true|WP_Error {
		global $wpdb;

		$ticket = self::get_for_viewer( $ticket_id, $user_id );
		if ( null === $ticket ) {
			return new WP_Error( 'not_found', __( 'تیکت یافت نشد.', 'hedayati-core' ) );
		}

		$is_staff = user_can( $user_id, self::STAFF_CAP ) && (int) $ticket['user_id'] !== $user_id;
		if ( ! $is_staff && (int) $ticket['user_id'] !== $user_id ) {
			return new WP_Error( 'cap', __( 'دسترسی مجاز نیست.', 'hedayati-core' ) );
		}
		if ( 'closed' === $ticket['status'] && ! $is_staff ) {
			return new WP_Error( 'closed', __( 'این تیکت بسته شده است. برای پیگیری، تیکت جدیدی باز کنید.', 'hedayati-core' ) );
		}

		$body = trim( sanitize_textarea_field( $body ) );
		if ( mb_strlen( $body ) < 2 ) {
			return new WP_Error( 'body', __( 'متن پاسخ را وارد کنید.', 'hedayati-core' ) );
		}

		$kind = $is_staff ? 'staff' : 'student';
		self::insert_message( $ticket_id, $user_id, $kind, $body );

		$new_status = 'closed' === $ticket['status']
			? 'closed'
			: ( $is_staff ? 'waiting_student' : 'waiting_staff' );

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_support_tickets();
		$wpdb->update(
			$table,
			[ 'status' => $new_status, 'last_reply_at' => $now, 'last_reply_kind' => $kind, 'updated_at' => $now ],
			[ 'id' => $ticket_id ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		Hedayati_Audit_Log::record( 'support.replied', 'support_ticket', $ticket_id, $kind . ' reply', $user_id );

		// Notify the other party.
		if ( $is_staff ) {
			Hedayati_Notification_Service::notify(
				(int) $ticket['user_id'],
				'support_reply',
				__( 'پاسخ جدید برای تیکت شما', 'hedayati-core' ),
				$ticket['subject'],
				home_url( '/account/?view=support&ticket=' . $ticket_id ),
				'support_ticket',
				$ticket_id
			);
		} else {
			Hedayati_Notification_Service::notify_capable(
				self::STAFF_CAP,
				'support_reply',
				__( 'پاسخ جدید دانشجو در تیکت', 'hedayati-core' ),
				$ticket['subject'],
				Hedayati_Staff_Portal::url( [ 'view' => 'support', 'ticket' => $ticket_id ] ),
				'support_ticket',
				$ticket_id
			);
		}

		return true;
	}

	public static function set_status( int $ticket_id, string $status, int $actor_id ): true|WP_Error {
		global $wpdb;

		if ( ! user_can( $actor_id, self::STAFF_CAP ) ) {
			return new WP_Error( 'cap', __( 'دسترسی مجاز نیست.', 'hedayati-core' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'وضعیت نامعتبر است.', 'hedayati-core' ) );
		}

		$ticket = self::get( $ticket_id );
		if ( null === $ticket ) {
			return new WP_Error( 'not_found', __( 'تیکت یافت نشد.', 'hedayati-core' ) );
		}
		if ( $ticket['status'] === $status ) {
			return true;
		}

		$table = Hedayati_DB_Schema::get_table_support_tickets();
		$wpdb->update(
			$table,
			[ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $ticket_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		Hedayati_Audit_Log::record( 'support.status_changed', 'support_ticket', $ticket_id, $ticket['status'] . ' -> ' . $status, $actor_id );

		if ( 'closed' === $status ) {
			Hedayati_Notification_Service::notify(
				(int) $ticket['user_id'],
				'support_closed',
				__( 'تیکت شما بسته شد', 'hedayati-core' ),
				$ticket['subject'],
				home_url( '/account/?view=support&ticket=' . $ticket_id ),
				'support_ticket',
				$ticket_id
			);
		}

		return true;
	}

	private static function insert_message( int $ticket_id, int $author_id, string $kind, string $body ): void {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_support_messages();
		$wpdb->insert(
			$table,
			[
				'ticket_id'   => $ticket_id,
				'author_id'   => $author_id,
				'author_kind' => $kind,
				'body'        => mb_substr( $body, 0, 5000 ),
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	public static function on_user_deleted( int $user_id ): void {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return;
		}
		$tickets  = Hedayati_DB_Schema::get_table_support_tickets();
		$messages = Hedayati_DB_Schema::get_table_support_messages();

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tickets} WHERE user_id = %d", $user_id ) );
		foreach ( $ids as $tid ) {
			$wpdb->delete( $messages, [ 'ticket_id' => (int) $tid ], [ '%d' ] );
		}
		$wpdb->delete( $tickets, [ 'user_id' => $user_id ], [ '%d' ] );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_open(): void {
		self::verify( 'hedayati_support_open', self::STUDENT_CAP );

		$result = self::open(
			get_current_user_id(),
			wp_unslash( (string) ( $_POST['subject'] ?? '' ) ),
			isset( $_POST['category'] ) ? sanitize_key( wp_unslash( (string) $_POST['category'] ) ) : 'general',
			wp_unslash( (string) ( $_POST['body'] ?? '' ) ),
			isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : null
		);

		self::redirect(
			$result,
			is_wp_error( $result )
				? home_url( '/account/?view=support' )
				: home_url( '/account/?view=support&ticket=' . (int) $result )
		);
	}

	public static function handle_reply(): void {
		$is_staff_ctx = isset( $_POST['ctx'] ) && 'staff' === $_POST['ctx'];
		self::verify( 'hedayati_support_reply', $is_staff_ctx ? self::STAFF_CAP : self::STUDENT_CAP );

		$ticket_id = isset( $_POST['ticket_id'] ) ? absint( wp_unslash( $_POST['ticket_id'] ) ) : 0;
		$result    = self::reply( $ticket_id, get_current_user_id(), wp_unslash( (string) ( $_POST['body'] ?? '' ) ) );

		$base = $is_staff_ctx
			? Hedayati_Staff_Portal::url( [ 'view' => 'support', 'ticket' => $ticket_id ] )
			: home_url( '/account/?view=support&ticket=' . $ticket_id );

		self::redirect( $result, $base );
	}

	public static function handle_status(): void {
		self::verify( 'hedayati_support_status', self::STAFF_CAP );

		$ticket_id = isset( $_POST['ticket_id'] ) ? absint( wp_unslash( $_POST['ticket_id'] ) ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';

		self::redirect(
			self::set_status( $ticket_id, $status, get_current_user_id() ),
			Hedayati_Staff_Portal::url( [ 'view' => 'support', 'ticket' => $ticket_id ] )
		);
	}

	private static function verify( string $nonce_action, string $capability ): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if (
			'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
			|| ! current_user_can( $capability )
			|| ! wp_verify_nonce( $nonce, $nonce_action )
		) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	/** @param true|WP_Error $result */
	private static function redirect( $result, string $url ): void {
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( 'support_error', rawurlencode( $result->get_error_message() ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	// ── Rendering: student (account) ────────────────────────────────────────

	public static function render_student_view( int $user_id ): string {
		$ticket_id = isset( $_GET['ticket'] ) ? absint( wp_unslash( $_GET['ticket'] ) ) : 0;
		$error     = isset( $_GET['support_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['support_error'] ) ) : '';

		ob_start();
		echo '<div class="hd-student-view-heading"><span class="hd-manager-eyebrow">' . esc_html__( 'پشتیبانی', 'hedayati-core' ) . '</span><h1 class="hd-portal-title">' . esc_html__( 'تیکت‌های پشتیبانی', 'hedayati-core' ) . '</h1></div>';
		if ( '' !== $error ) {
			echo '<p class="hd-portal-notice hd-portal-notice-error" role="alert">' . esc_html( $error ) . '</p>';
		}

		if ( $ticket_id > 0 ) {
			$ticket = self::get_for_viewer( $ticket_id, $user_id );
			if ( null === $ticket || (int) $ticket['user_id'] !== $user_id ) {
				echo '<p class="hd-portal-note">' . esc_html__( 'تیکت یافت نشد.', 'hedayati-core' ) . '</p>';
				return (string) ob_get_clean();
			}
			self::render_thread( $ticket, $user_id, false );
			return (string) ob_get_clean();
		}

		$tickets = self::list_for_user( $user_id );
		if ( ! empty( $tickets ) ) {
			echo '<ul class="hd-portal-result-list">';
			foreach ( $tickets as $t ) {
				printf(
					'<li><a href="%s"><strong>%s</strong> — %s <small>%s</small></a></li>',
					esc_url( home_url( '/account/?view=support&ticket=' . $t['id'] ) ),
					esc_html( $t['subject'] ),
					esc_html( self::status_label( $t['status'] ) ),
					esc_html( Hedayati_Jalali::format( substr( $t['last_reply_at'], 0, 10 ) ) )
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="hd-portal-note">' . esc_html__( 'تیکتی ثبت نکرده‌اید.', 'hedayati-core' ) . '</p>';
		}

		// New ticket form.
		echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_support_open' );
		echo '<input type="hidden" name="action" value="hedayati_support_open">';
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'تیکت جدید', 'hedayati-core' ) . '</h2>';
		printf( '<label class="hd-portal-field"><span>%s</span><input type="text" name="subject" maxlength="190" required></label>', esc_html__( 'موضوع', 'hedayati-core' ) );
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'دسته', 'hedayati-core' ) . '</span><select name="category">';
		foreach ( self::CATEGORIES as $c ) {
			echo '<option value="' . esc_attr( $c ) . '">' . esc_html( self::category_label( $c ) ) . '</option>';
		}
		echo '</select></label>';
		printf( '<label class="hd-portal-field"><span>%s</span><textarea name="body" rows="4" required></textarea></label>', esc_html__( 'متن پیام', 'hedayati-core' ) );
		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ارسال تیکت', 'hedayati-core' ) . '</button>';
		echo '</form>';

		return (string) ob_get_clean();
	}

	// ── Rendering: staff (panel) ───────────────────────────────────────────

	public static function render_panel(): void {
		if ( ! current_user_can( self::STAFF_CAP ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$ticket_id = isset( $_GET['ticket'] ) ? absint( wp_unslash( $_GET['ticket'] ) ) : 0;

		if ( $ticket_id > 0 ) {
			$ticket = self::get( $ticket_id );
			if ( null === $ticket ) {
				echo '<p class="hd-portal-note">' . esc_html__( 'تیکت یافت نشد.', 'hedayati-core' ) . '</p>';
				return;
			}
			echo '<p><a href="' . esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'support' ] ) ) . '">' . esc_html__( '‹ بازگشت به صف', 'hedayati-core' ) . '</a></p>';
			self::render_thread( $ticket, get_current_user_id(), true );
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$status = in_array( $status, self::STATUSES, true ) ? $status : '';

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'پشتیبانی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'صف تیکت‌های پشتیبانی', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html( sprintf( __( '%s تیکت در انتظار پاسخ پشتیبانی است.', 'hedayati-core' ), Hedayati_Text::digits_to_persian( (string) self::count_waiting_staff() ) ) )
		);
		echo '</div></header>';

		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="support">';
		printf(
			'<label class="hd-portal-field"><span class="screen-reader-text">%s</span><input type="search" name="q" value="%s" placeholder="%s"></label>',
			esc_html__( 'جستجو', 'hedayati-core' ),
			esc_attr( $search ),
			esc_attr__( 'موضوع تیکت…', 'hedayati-core' )
		);
		echo '<label class="hd-manager-check"><select name="status">';
		printf( '<option value=""%s>%s</option>', selected( '', $status, false ), esc_html__( 'همه', 'hedayati-core' ) );
		foreach ( self::STATUSES as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $s, $status, false ), esc_html( self::status_label( $s ) ) );
		}
		echo '</select></label>';
		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال', 'hedayati-core' ) );
		echo '</form>';

		$rows = self::queue( [ 'status' => $status, 'search' => $search ] );
		if ( empty( $rows ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'تیکتی یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<ul class="hd-portal-result-list">';
		foreach ( $rows as $t ) {
			$who = get_userdata( $t['user_id'] );
			printf(
				'<li><a href="%s"><strong>%s</strong> — %s<small>%s · %s</small></a></li>',
				esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'support', 'ticket' => $t['id'] ] ) ),
				esc_html( $t['subject'] ),
				esc_html( self::status_label( $t['status'] ) ),
				esc_html( $who ? $who->display_name : ( '#' . $t['user_id'] ) ),
				esc_html( Hedayati_Jalali::format( substr( $t['last_reply_at'], 0, 10 ) ) )
			);
		}
		echo '</ul>';
	}

	private static function render_thread( array $ticket, int $viewer_id, bool $staff_ctx ): void {
		$who = get_userdata( $ticket['user_id'] );

		echo '<article class="hd-support-thread">';
		echo '<header><h2>' . esc_html( $ticket['subject'] ) . '</h2>';
		echo '<p class="hd-portal-note">' . esc_html( self::category_label( $ticket['category'] ) . ' · ' . self::status_label( $ticket['status'] ) );
		if ( $staff_ctx && $who ) {
			echo ' · ' . esc_html( $who->display_name );
		}
		echo '</p></header>';

		echo '<div class="hd-support-messages">';
		foreach ( self::messages( $ticket['id'] ) as $msg ) {
			$mine = (int) $msg['author_id'] === $viewer_id;
			printf(
				'<div class="hd-support-msg hd-support-msg-%1$s%2$s"><span>%3$s</span><p>%4$s</p><time>%5$s</time></div>',
				esc_attr( $msg['author_kind'] ),
				$mine ? ' is-mine' : '',
				esc_html( 'staff' === $msg['author_kind'] ? __( 'پشتیبانی مجتمع', 'hedayati-core' ) : __( 'دانشجو', 'hedayati-core' ) ),
				nl2br( esc_html( $msg['body'] ) ),
				esc_html( Hedayati_Jalali::format( substr( $msg['created_at'], 0, 10 ) ) . ' ' . substr( $msg['created_at'], 11, 5 ) )
			);
		}
		echo '</div>';

		$can_reply = $staff_ctx || ( 'closed' !== $ticket['status'] );
		if ( $can_reply ) {
			$action_url = admin_url( 'admin-post.php' );
			echo '<form class="hd-portal-form" method="post" action="' . esc_url( $action_url ) . '">';
			wp_nonce_field( 'hedayati_support_reply' );
			echo '<input type="hidden" name="action" value="hedayati_support_reply">';
			echo '<input type="hidden" name="ticket_id" value="' . esc_attr( (string) $ticket['id'] ) . '">';
			echo '<input type="hidden" name="ctx" value="' . ( $staff_ctx ? 'staff' : 'student' ) . '">';
			printf( '<label class="hd-portal-field"><span>%s</span><textarea name="body" rows="3" required></textarea></label>', esc_html__( 'پاسخ شما', 'hedayati-core' ) );
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ارسال پاسخ', 'hedayati-core' ) . '</button>';
			echo '</form>';
		}

		if ( $staff_ctx ) {
			echo '<form class="hd-portal-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_support_status' );
			echo '<input type="hidden" name="action" value="hedayati_support_status">';
			echo '<input type="hidden" name="ticket_id" value="' . esc_attr( (string) $ticket['id'] ) . '">';
			echo '<label class="hd-portal-field"><span>' . esc_html__( 'تغییر وضعیت', 'hedayati-core' ) . '</span><select name="status">';
			foreach ( self::STATUSES as $s ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $s, $ticket['status'], false ), esc_html( self::status_label( $s ) ) );
			}
			echo '</select></label>';
			echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'ثبت وضعیت', 'hedayati-core' ) . '</button>';
			echo '</form>';
		}

		echo '</article>';
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'              => (int) $row['id'],
			'user_id'         => (int) $row['user_id'],
			'subject'         => (string) $row['subject'],
			'category'        => (string) $row['category'],
			'status'          => (string) $row['status'],
			'run_id'          => null !== $row['run_id'] ? (int) $row['run_id'] : null,
			'last_reply_at'   => (string) $row['last_reply_at'],
			'last_reply_kind' => (string) $row['last_reply_kind'],
			'created_at'      => (string) $row['created_at'],
			'updated_at'      => (string) $row['updated_at'],
		];
	}
}
