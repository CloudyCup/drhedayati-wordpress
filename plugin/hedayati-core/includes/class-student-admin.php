<?php
/**
 * Phase 2C — Student identity / verification / private documents admin UI.
 *
 * A single staff-facing wp-admin screen ("دانشجویان و احراز هویت"), following the
 * same security model as `class-academic-admin.php`: every state-changing
 * request goes through `admin-post.php` with a per-action nonce, a server-side
 * capability check, and (for staff-assisted actions) an explicit target-is-a-
 * student scope check.
 *
 * NO student-facing UI exists here or anywhere in Phase 2C — every action below
 * is staff-only. The one exception to the plugin's capability-agnostic-service
 * convention (Hedayati_Verification_Service::get_national_id_decrypted()) is
 * deliberately checked AGAIN here, at the controller, before ever being called —
 * defense in depth for the single highest-risk read in the plugin.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Student_Admin {

	private const MENU_SLUG = 'hedayati-students';
	private const CAP_VIEW  = 'hedayati_lookup_students';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		add_action( 'admin_notices', [ self::class, 'render_notices' ] );

		$redirecting_actions = [
			'hedayati_identity_set',
			'hedayati_verification_initiate',
			'hedayati_verification_approve',
			'hedayati_verification_reject',
			'hedayati_document_upload',
			'hedayati_document_archive',
			'hedayati_document_purge',
		];

		foreach ( $redirecting_actions as $action ) {
			add_action( 'admin_post_' . $action, [ self::class, 'handle_' . substr( $action, 10 ) ] );
		}

		// Streaming/rendering actions do not redirect — handled separately.
		add_action( 'admin_post_hedayati_identity_reveal', [ self::class, 'handle_identity_reveal' ] );
		add_action( 'admin_post_hedayati_document_download', [ self::class, 'handle_document_download' ] );
	}

	// ── Menu / routing ──────────────────────────────────────────────────────

	public static function register_menu(): void {
		add_menu_page(
			'دانشجویان و احراز هویت',
			'دانشجویان و احراز هویت',
			self::CAP_VIEW,
			self::MENU_SLUG,
			[ self::class, 'render_screen' ],
			'dashicons-id-alt',
			8
		);
	}

	public static function render_screen(): void {
		if ( ! current_user_can( self::CAP_VIEW ) ) {
			wp_die( esc_html__( 'شما اجازهٔ دسترسی به این بخش را ندارید.', 'hedayati-core' ) );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;

		echo '<div class="wrap"><h1>' . esc_html__( 'دانشجویان و احراز هویت', 'hedayati-core' ) . '</h1>';

		self::render_search_form();

		if ( $user_id > 0 ) {
			self::render_student_detail( $user_id );
		} else {
			self::render_search_results();
		}

		echo '</div>';
	}

	private static function render_search_form(): void {
		$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		echo '<form method="get" style="margin:1em 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::MENU_SLUG ) . '">';
		printf(
			'<input type="search" name="s" value="%s" placeholder="%s"> <button class="button">%s</button>',
			esc_attr( $term ),
			esc_attr__( 'جستجوی دانشجو (نام کاربری، ایمیل، نام)', 'hedayati-core' ),
			esc_html__( 'جستجو', 'hedayati-core' )
		);
		echo '</form>';
	}

	private static function render_search_results(): void {
		$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( '' === $term ) {
			echo '<p>' . esc_html__( 'برای مشاهدهٔ پروندهٔ دانشجو، جستجو کنید.', 'hedayati-core' ) . '</p>';
			return;
		}

		$users = get_users( [
			'role'    => 'student',
			'search'  => '*' . $term . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
			'number'  => 50,
		] );

		if ( empty( $users ) ) {
			echo '<p>' . esc_html__( 'دانشجویی یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'نام', 'hedayati-core' ) . '</th><th>' . esc_html__( 'نام کاربری', 'hedayati-core' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $users as $user ) {
			$url = add_query_arg( [ 'page' => self::MENU_SLUG, 'user_id' => $user->ID ], admin_url( 'admin.php' ) );
			printf(
				'<tr><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">%s</a></td></tr>',
				esc_html( $user->display_name ),
				esc_html( $user->user_login ),
				esc_url( $url ),
				esc_html__( 'مشاهدهٔ پرونده', 'hedayati-core' )
			);
		}

		echo '</tbody></table>';
	}

	// ── Student detail ──────────────────────────────────────────────────────

	private static function render_student_detail( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user ) {
			echo '<p>' . esc_html__( 'دانشجو یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html( $user->display_name ) . '</h2>';

		self::render_identity_box( $user_id );
		self::render_verification_box( $user_id );
		self::render_documents_box( $user_id );
	}

	private static function render_identity_box( int $user_id ): void {
		$masked = Hedayati_Verification_Service::get_national_id_masked( $user_id );

		echo '<h3>' . esc_html__( 'کد ملی', 'hedayati-core' ) . '</h3>';
		echo '<p>' . ( 'set' === $masked
			? esc_html__( 'ثبت شده (●●●●●●●●●●)', 'hedayati-core' )
			: esc_html__( 'ثبت نشده', 'hedayati-core' ) ) . '</p>';

		if ( current_user_can( 'hedayati_verify_students' ) && 'set' === $masked ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_identity_reveal_' . $user_id );
			echo '<input type="hidden" name="action" value="hedayati_identity_reveal">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
			echo '<button class="button">' . esc_html__( 'نمایش شناسه ملی', 'hedayati-core' ) . '</button>';
			echo '</form>';
		}

		if ( current_user_can( 'hedayati_upload_student_documents' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
			wp_nonce_field( 'hedayati_identity_set_' . $user_id );
			echo '<input type="hidden" name="action" value="hedayati_identity_set">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
			printf(
				'<label>%s <input type="text" name="national_id" dir="ltr" maxlength="10" placeholder="●●●●●●●●●●"></label> ',
				esc_html__( 'ثبت/جایگزینی کد ملی:', 'hedayati-core' )
			);
			echo '<button class="button">' . esc_html__( 'ذخیره', 'hedayati-core' ) . '</button>';
			echo '</form>';
		}
	}

	private static function render_verification_box( int $user_id ): void {
		$status = Hedayati_Verification_Service::get_status( $user_id );

		echo '<h3>' . esc_html__( 'وضعیت احراز هویت', 'hedayati-core' ) . '</h3>';
		echo '<p>' . esc_html( self::verification_status_label( $status['status'] ) ) . '</p>';

		if ( current_user_can( 'hedayati_initiate_verification' ) && in_array( $status['status'], [ 'unverified', 'rejected' ], true ) ) {
			self::simple_action_form( 'hedayati_verification_initiate', $user_id, __( 'شروع فرآیند احراز هویت', 'hedayati-core' ) );
		}

		if ( current_user_can( 'hedayati_verify_students' ) && 'pending' === $status['status'] ) {
			self::note_action_form( 'hedayati_verification_approve', $user_id, __( 'تأیید احراز هویت', 'hedayati-core' ) );
			self::note_action_form( 'hedayati_verification_reject', $user_id, __( 'رد احراز هویت', 'hedayati-core' ) );
		}
	}

	private static function render_documents_box( int $user_id ): void {
		echo '<h3>' . esc_html__( 'مدارک', 'hedayati-core' ) . '</h3>';

		if ( current_user_can( 'hedayati_upload_student_documents' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
			wp_nonce_field( 'hedayati_document_upload_' . $user_id );
			echo '<input type="hidden" name="action" value="hedayati_document_upload">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
			echo '<select name="doc_type">';
			foreach ( Hedayati_Document_Service::DOC_TYPES as $type ) {
				echo '<option value="' . esc_attr( $type ) . '">' . esc_html( self::doc_type_label( $type ) ) . '</option>';
			}
			echo '</select> <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png"> ';
			echo '<button class="button">' . esc_html__( 'بارگذاری مدرک', 'hedayati-core' ) . '</button>';
			echo '</form>';
		}

		if ( ! current_user_can( 'hedayati_view_private_documents' ) ) {
			return;
		}

		$docs = Hedayati_Document_Service::list_for_user( $user_id );

		if ( empty( $docs ) ) {
			echo '<p>' . esc_html__( 'مدرکی ثبت نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'نوع', 'hedayati-core' ) . '</th><th>' . esc_html__( 'وضعیت', 'hedayati-core' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $docs as $doc ) {
			echo '<tr><td>' . esc_html( self::doc_type_label( $doc['doc_type'] ) ) . '</td><td>' . esc_html( self::archive_status_label( $doc ) ) . '</td><td>';

			$download_url = wp_nonce_url(
				add_query_arg( [ 'action' => 'hedayati_document_download', 'doc_id' => $doc['id'] ], admin_url( 'admin-post.php' ) ),
				'hedayati_document_download_' . $doc['id']
			);
			echo '<a class="button button-small" href="' . esc_url( $download_url ) . '">' . esc_html__( 'دانلود', 'hedayati-core' ) . '</a> ';

			if ( null === $doc['archived_at'] ) {
				self::doc_action_form( 'hedayati_document_archive', $doc['id'], $user_id, __( 'تأیید انتقال به خارج از میزبان', 'hedayati-core' ) );
			} elseif ( self::is_purge_eligible( $doc ) ) {
				self::doc_action_form( 'hedayati_document_purge', $doc['id'], $user_id, __( 'حذف نهایی', 'hedayati-core' ) );
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	// ── Form helpers ────────────────────────────────────────────────────────

	private static function simple_action_form( string $action, int $user_id, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:.5em">';
		wp_nonce_field( $action . '_' . $user_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		echo '<button class="button">' . esc_html( $label ) . '</button></form>';
	}

	private static function note_action_form( string $action, int $user_id, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:.5em">';
		wp_nonce_field( $action . '_' . $user_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		echo '<input type="text" name="note" placeholder="' . esc_attr__( 'دلیل (اختیاری)', 'hedayati-core' ) . '">';
		echo '<button class="button">' . esc_html( $label ) . '</button></form>';
	}

	private static function doc_action_form( string $action, int $doc_id, int $user_id, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:.5em">';
		wp_nonce_field( $action . '_' . $doc_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="doc_id" value="' . esc_attr( (string) $doc_id ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		echo '<button class="button">' . esc_html( $label ) . '</button></form>';
	}

	// ── Action handlers (redirecting) ───────────────────────────────────────

	public static function handle_identity_set(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_identity_set_' . $user_id, 'hedayati_upload_student_documents' );
		self::require_student_scope( $user_id );

		$raw = isset( $_POST['national_id'] ) ? (string) wp_unslash( $_POST['national_id'] ) : '';

		$result = Hedayati_Verification_Service::set_national_id( $user_id, $raw, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'کد ملی ذخیره شد.', 'hedayati-core' ) );
	}

	public static function handle_verification_initiate(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_verification_initiate_' . $user_id, 'hedayati_initiate_verification' );
		self::require_student_scope( $user_id );

		$result = Hedayati_Verification_Service::initiate( $user_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'فرآیند احراز هویت آغاز شد.', 'hedayati-core' ) );
	}

	public static function handle_verification_approve(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_verification_approve_' . $user_id, 'hedayati_verify_students' );

		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = Hedayati_Verification_Service::approve( $user_id, get_current_user_id(), $note );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'احراز هویت تأیید شد.', 'hedayati-core' ) );
	}

	public static function handle_verification_reject(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_verification_reject_' . $user_id, 'hedayati_verify_students' );

		$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = Hedayati_Verification_Service::reject( $user_id, get_current_user_id(), $note );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'احراز هویت رد شد.', 'hedayati-core' ) );
	}

	public static function handle_document_upload(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_document_upload_' . $user_id, 'hedayati_upload_student_documents' );
		self::require_student_scope( $user_id );

		$doc_type = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : 'other';
		$file     = $_FILES['document'] ?? null;

		if ( ! is_array( $file ) || ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			self::redirect( $user_id, esc_html__( 'بارگذاری فایل ناموفق بود.', 'hedayati-core' ), 'error' );
		}

		$result = Hedayati_Document_Service::upload( $user_id, $file, $doc_type, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'مدرک بارگذاری شد.', 'hedayati-core' ) );
	}

	public static function handle_document_archive(): void {
		$doc_id  = isset( $_POST['doc_id'] ) ? absint( wp_unslash( $_POST['doc_id'] ) ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_document_archive_' . $doc_id, 'hedayati_view_private_documents' );

		$result = Hedayati_Document_Service::mark_archived( $doc_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'انتقال به خارج از میزبان تأیید شد.', 'hedayati-core' ) );
	}

	public static function handle_document_purge(): void {
		$doc_id  = isset( $_POST['doc_id'] ) ? absint( wp_unslash( $_POST['doc_id'] ) ) : 0;
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		self::verify( 'hedayati_document_purge_' . $doc_id, 'hedayati_view_private_documents' );

		$result = Hedayati_Document_Service::purge( $doc_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			self::redirect( $user_id, $result->get_error_message(), 'error' );
		}

		self::redirect( $user_id, __( 'مدرک حذف شد.', 'hedayati-core' ) );
	}

	// ── Action handlers (non-redirecting: reveal + download) ────────────────

	/**
	 * The ONE plaintext-rendering path in the plugin. POST only (never a URL
	 * query param), nonced, capability-checked at THIS controller in addition
	 * to the service's own internal check (defense in depth, D36). The value
	 * is rendered once for this response only — never written to a transient,
	 * option, or persisted form field — and the response carries no-store
	 * headers.
	 */
	public static function handle_identity_reveal(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;

		if (
			! isset( $_POST['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'hedayati_identity_reveal_' . $user_id )
		) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'hedayati_verify_students' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$value = Hedayati_Verification_Service::get_national_id_decrypted( $user_id, get_current_user_id() );

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		Hedayati_Audit_Log::record( 'identity.viewed', 'student_identity', $user_id, 'revealed by reviewer' );

		echo '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><title>' .
			esc_html__( 'شناسه ملی', 'hedayati-core' ) . '</title></head><body>';

		if ( is_wp_error( $value ) ) {
			echo '<p>' . esc_html( $value->get_error_message() ) . '</p>';
		} elseif ( null === $value ) {
			echo '<p>' . esc_html__( 'کد ملی ثبت نشده است.', 'hedayati-core' ) . '</p>';
		} else {
			echo '<p style="font-family:monospace;font-size:1.5em" dir="ltr">' . esc_html( $value ) . '</p>';
		}

		$back = add_query_arg( [ 'page' => self::MENU_SLUG, 'user_id' => $user_id ], admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $back ) . '">' . esc_html__( 'بازگشت', 'hedayati-core' ) . '</a></p>';
		echo '</body></html>';
		exit;
	}

	public static function handle_document_download(): void {
		$doc_id = isset( $_GET['doc_id'] ) ? absint( wp_unslash( $_GET['doc_id'] ) ) : 0;

		if (
			! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'hedayati_document_download_' . $doc_id )
		) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( 'hedayati_view_private_documents' ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$result = Hedayati_Document_Service::download( $doc_id, get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', [ 'response' => 404 ] );
		}

		exit;
	}

	// ── Access scope / plumbing ─────────────────────────────────────────────

	private static function verify( string $nonce_action, string $cap ): void {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
			wp_die( esc_html__( 'بررسی امنیتی ناموفق بود.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'شما اجازهٔ انجام این عمل را ندارید.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	/**
	 * Staff-assisted actions may only target a real student account — prevents
	 * "on behalf of" actions against an arbitrary WordPress user.
	 */
	private static function require_student_scope( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );

		if ( ! $user || ! in_array( 'student', (array) $user->roles, true ) ) {
			wp_die( esc_html__( 'این عملیات فقط برای دانشجویان مجاز است.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	private static function redirect( int $user_id, string $notice = '', string $type = 'success' ): void {
		if ( '' !== $notice ) {
			set_transient( self::notice_key(), [ 'type' => $type, 'text' => $notice ], 45 );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'user_id' => $user_id ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function notice_key(): string {
		return 'hedayati_students_notice_' . get_current_user_id();
	}

	public static function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$notice = get_transient( self::notice_key() );
		if ( ! is_array( $notice ) || empty( $notice['text'] ) ) {
			return;
		}

		delete_transient( self::notice_key() );

		$class = 'error' === ( $notice['type'] ?? '' ) ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( (string) $notice['text'] )
		);
	}

	// ── Label maps ───────────────────────────────────────────────────────────

	private static function verification_status_label( string $status ): string {
		$map = [
			'unverified' => __( 'احراز نشده', 'hedayati-core' ),
			'pending'    => __( 'در حال بررسی', 'hedayati-core' ),
			'verified'   => __( 'احراز شده', 'hedayati-core' ),
			'rejected'   => __( 'رد شده', 'hedayati-core' ),
		];

		return $map[ $status ] ?? $status;
	}

	private static function doc_type_label( string $type ): string {
		$map = [
			'national_card'      => __( 'کارت ملی', 'hedayati-core' ),
			'birth_certificate'  => __( 'شناسنامه', 'hedayati-core' ),
			'other'              => __( 'سایر', 'hedayati-core' ),
		];

		return $map[ $type ] ?? $type;
	}

	private static function archive_status_label( array $doc ): string {
		if ( null !== $doc['deleted_at'] ) {
			return __( 'حذف‌شده', 'hedayati-core' );
		}

		if ( null === $doc['archived_at'] ) {
			return __( 'در انتظار تأیید انتقال', 'hedayati-core' );
		}

		return self::is_purge_eligible( $doc )
			? __( 'منتقل‌شده — قابل حذف نهایی', 'hedayati-core' )
			: __( 'منتقل‌شده', 'hedayati-core' );
	}

	private static function is_purge_eligible( array $doc ): bool {
		if ( null === $doc['archived_at'] ) {
			return false;
		}

		$archived_ts = strtotime( $doc['archived_at'] . ' UTC' );

		return false !== $archived_ts && $archived_ts <= ( time() - ( 7 * DAY_IN_SECONDS ) );
	}
}
