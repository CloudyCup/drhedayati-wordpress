<?php
/**
 * Manager Experience — Phase E (owner decision D53): student verification / private
 * documents reviewer actions inside `/panel/?view=students`.
 *
 * The staff `/panel/?view=students` screen (`Hedayati_Staff_Portal`) already
 * covers reception intake — create account, enroll, national-ID intake, document
 * upload, initiate verification. This class adds the reviewer half that used to
 * live only in `Hedayati_Student_Admin`:
 *
 *   - approve / reject a pending verification   (hedayati_verify_students)
 *   - the privileged one-shot national-ID reveal (hedayati_verify_students)
 *   - private-document list / download           (hedayati_view_private_documents)
 *   - document archive confirmation / final purge (hedayati_view_private_documents)
 *
 * **Every Phase 2C security invariant is preserved unchanged:** the national ID
 * stays encrypted at rest with HMAC duplicate detection; reception can never
 * decrypt it; only `hedayati_verify_students` reaches
 * `Hedayati_Verification_Service::get_national_id_decrypted()`, which is
 * re-checked at THIS controller too (defence in depth, D36); the plaintext is
 * rendered once with no-store headers, never persisted, never placed in a URL,
 * log or notice, and the reveal is audited (`identity.viewed`). Documents are
 * streamed only through the existing nonced
 * `Hedayati_Student_Admin::handle_document_download` (no public URL); the
 * private storage path, capability and ownership scoping are untouched. The
 * rejection note stays staff-only (it is written by the service, never echoed
 * to a student).
 *
 * The wp-admin screen (`admin.php?page=hedayati-students`) is unchanged and
 * remains available to the real administrator.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Verification_Panel {

	/** admin-post action suffix => capability. Nonces are per-object. */
	private const REDIRECTING = [
		'approve'     => 'hedayati_verify_students',
		'reject'      => 'hedayati_verify_students',
		'doc_archive' => 'hedayati_view_private_documents',
		'doc_purge'   => 'hedayati_view_private_documents',
	];

	public static function init(): void {
		foreach ( array_keys( self::REDIRECTING ) as $suffix ) {
			add_action( 'admin_post_hedayati_vpanel_' . $suffix, [ self::class, 'handle_' . $suffix ] );
		}
		add_action( 'admin_post_hedayati_vpanel_reveal', [ self::class, 'handle_reveal' ] );
	}

	// ── Rendered by Hedayati_Staff_Portal::render_student() ──────────────────

	public static function render_reviewer_section( int $user_id ): void {
		$can_review = current_user_can( 'hedayati_verify_students' );
		$can_docs   = current_user_can( 'hedayati_view_private_documents' );

		if ( ! $can_review && ! $can_docs ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'student', (array) $user->roles, true ) ) {
			return;
		}

		echo '<section class="hd-staff-section hd-verify-panel">';
		echo '<h3>' . esc_html__( 'بررسی احراز هویت و مدارک', 'hedayati-core' ) . '</h3>';

		if ( $can_review ) {
			self::render_identity_review( $user_id );
			self::render_verification_decision( $user_id );
		}
		if ( $can_docs ) {
			self::render_documents( $user_id );
		}

		echo '</section>';
	}

	private static function render_identity_review( int $user_id ): void {
		$masked = Hedayati_Verification_Service::get_national_id_masked( $user_id );

		echo '<p>' . esc_html__( 'کد ملی:', 'hedayati-core' ) . ' '
			. ( 'set' === $masked ? esc_html__( 'ثبت شده (●●●●●●●●●●)', 'hedayati-core' ) : esc_html__( 'ثبت نشده', 'hedayati-core' ) ) . '</p>';

		if ( 'set' !== $masked ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" target="_blank" rel="noopener">';
		wp_nonce_field( 'hedayati_vpanel_reveal_' . $user_id );
		echo '<input type="hidden" name="action" value="hedayati_vpanel_reveal">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		echo '<button type="submit" class="hd-portal-btn hd-portal-btn-quiet">' . esc_html__( 'نمایش شناسه ملی (یک‌بار)', 'hedayati-core' ) . '</button>';
		echo '</form>';
		echo '<p class="hd-portal-note">' . esc_html__( 'شناسهٔ ملی رمزنگاری‌شده ذخیره می‌شود و نمایش آن ثبت می‌گردد. تنها برای بررسی احراز هویت استفاده کنید.', 'hedayati-core' ) . '</p>';
	}

	private static function render_verification_decision( int $user_id ): void {
		$status = Hedayati_Verification_Service::get_status( $user_id );

		echo '<p>' . esc_html__( 'وضعیت فعلی:', 'hedayati-core' ) . ' '
			. esc_html( Hedayati_Student_Admin::verification_status_label( $status['status'] ) ) . '</p>';

		if ( 'pending' !== $status['status'] ) {
			return;
		}

		foreach ( [
			'approve' => __( 'تأیید احراز هویت', 'hedayati-core' ),
			'reject'  => __( 'رد احراز هویت', 'hedayati-core' ),
		] as $suffix => $label ) {
			echo '<form class="hd-portal-form hd-verify-decision" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'hedayati_vpanel_' . $suffix . '_' . $user_id );
			echo '<input type="hidden" name="action" value="hedayati_vpanel_' . esc_attr( $suffix ) . '">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
			printf(
				'<label class="hd-portal-field"><span>%s</span><input type="text" name="note" placeholder="%s"></label>',
				esc_html( $label ),
				esc_attr__( 'دلیل (اختیاری، فقط برای کادر داخلی)', 'hedayati-core' )
			);
			printf( '<button class="hd-portal-btn%s" type="submit">%s</button></form>', 'reject' === $suffix ? ' hd-portal-btn-danger' : '', esc_html( $label ) );
		}
	}

	private static function render_documents( int $user_id ): void {
		$docs = Hedayati_Document_Service::list_for_user( $user_id );

		echo '<h4>' . esc_html__( 'مدارک خصوصی', 'hedayati-core' ) . '</h4>';

		if ( empty( $docs ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'مدرکی ثبت نشده است.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-table" role="table"><div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [ __( 'نوع', 'hedayati-core' ), __( 'وضعیت', 'hedayati-core' ), '' ] as $h ) {
			echo '<span role="columnheader">' . esc_html( $h ) . '</span>';
		}
		echo '</div>';

		foreach ( $docs as $doc ) {
			$type_label = [
				'national_card'     => __( 'کارت ملی', 'hedayati-core' ),
				'birth_certificate' => __( 'شناسنامه', 'hedayati-core' ),
				'other'             => __( 'سایر', 'hedayati-core' ),
			][ $doc['doc_type'] ] ?? $doc['doc_type'];

			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell">' . esc_html( $type_label ) . '</span>';
			echo '<span role="cell">' . esc_html( Hedayati_Student_Admin::archive_status_label( $doc ) ) . '</span>';
			echo '<span role="cell" class="hd-manager-row-actions">';

			if ( null === $doc['deleted_at'] ) {
				$dl = wp_nonce_url(
					add_query_arg( [ 'action' => 'hedayati_document_download', 'doc_id' => $doc['id'] ], admin_url( 'admin-post.php' ) ),
					'hedayati_document_download_' . $doc['id']
				);
				printf( '<a class="hd-manager-row-edit" href="%s">%s</a>', esc_url( $dl ), esc_html__( 'دانلود', 'hedayati-core' ) );

				if ( null === $doc['archived_at'] ) {
					self::doc_form( 'doc_archive', (int) $doc['id'], $user_id, __( 'تأیید انتقال به خارج از میزبان', 'hedayati-core' ) );
				} elseif ( Hedayati_Student_Admin::is_purge_eligible( $doc ) ) {
					self::doc_form( 'doc_purge', (int) $doc['id'], $user_id, __( 'حذف نهایی', 'hedayati-core' ) );
				}
			}

			echo '</span></div>';
		}
		echo '</div>';
	}

	private static function doc_form( string $suffix, int $doc_id, int $user_id, string $label ): void {
		echo '<form class="hd-manager-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( "'" . esc_js( $label . '؟' ) . "'" ) . ');">';
		wp_nonce_field( 'hedayati_vpanel_' . $suffix . '_' . $doc_id );
		echo '<input type="hidden" name="action" value="hedayati_vpanel_' . esc_attr( $suffix ) . '">';
		echo '<input type="hidden" name="doc_id" value="' . esc_attr( (string) $doc_id ) . '">';
		echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '">';
		echo '<button type="submit" class="hd-manager-row-delete">' . esc_html( $label ) . '</button></form>';
	}

	// ── Plumbing ────────────────────────────────────────────────────────────

	private static function post_int( string $key ): int {
		return isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
	}

	private static function verify( string $nonce_action, string $cap ): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( $nonce, $nonce_action ) || ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	private static function require_student( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! in_array( 'student', (array) $user->roles, true ) ) {
			wp_die( esc_html__( 'این عملیات فقط برای دانشجویان مجاز است.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}
	}

	private static function back( int $user_id, $result ): void {
		Hedayati_Staff_Portal::redirect_notice(
			is_wp_error( $result ) ? $result : true,
			[ 'view' => 'students', 'student_id' => $user_id ]
		);
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_approve(): void {
		$user_id = self::post_int( 'user_id' );
		self::verify( 'hedayati_vpanel_approve_' . $user_id, self::REDIRECTING['approve'] );
		self::require_student( $user_id );
		self::back( $user_id, Hedayati_Verification_Service::approve( $user_id, get_current_user_id(), sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) ) ) );
	}

	public static function handle_reject(): void {
		$user_id = self::post_int( 'user_id' );
		self::verify( 'hedayati_vpanel_reject_' . $user_id, self::REDIRECTING['reject'] );
		self::require_student( $user_id );
		self::back( $user_id, Hedayati_Verification_Service::reject( $user_id, get_current_user_id(), sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) ) ) );
	}

	public static function handle_doc_archive(): void {
		$doc_id  = self::post_int( 'doc_id' );
		$user_id = self::post_int( 'user_id' );
		self::verify( 'hedayati_vpanel_doc_archive_' . $doc_id, self::REDIRECTING['doc_archive'] );
		self::back( $user_id, Hedayati_Document_Service::mark_archived( $doc_id, get_current_user_id() ) );
	}

	public static function handle_doc_purge(): void {
		$doc_id  = self::post_int( 'doc_id' );
		$user_id = self::post_int( 'user_id' );
		self::verify( 'hedayati_vpanel_doc_purge_' . $doc_id, self::REDIRECTING['doc_purge'] );
		self::back( $user_id, Hedayati_Document_Service::purge( $doc_id, get_current_user_id() ) );
	}

	/**
	 * The one plaintext-rendering path. POST-only, per-user nonce, capability
	 * re-checked here in addition to the service's own check. Rendered once for
	 * this response only, no-store headers, never persisted, audited.
	 */
	public static function handle_reveal(): void {
		$user_id = self::post_int( 'user_id' );
		self::verify( 'hedayati_vpanel_reveal_' . $user_id, 'hedayati_verify_students' );

		$value = Hedayati_Verification_Service::get_national_id_decrypted( $user_id, get_current_user_id() );

		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		Hedayati_Audit_Log::record( 'identity.viewed', 'student_identity', $user_id, 'revealed by reviewer (panel)', get_current_user_id() );

		$back = esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'students', 'student_id' => $user_id ] ) );

		echo '<!DOCTYPE html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>'
			. esc_html__( 'شناسه ملی', 'hedayati-core' ) . '</title></head><body style="font-family:Vazirmatn,Tahoma,sans-serif;padding:2rem">';

		if ( is_wp_error( $value ) ) {
			echo '<p>' . esc_html( $value->get_error_message() ) . '</p>';
		} elseif ( null === $value ) {
			echo '<p>' . esc_html__( 'کد ملی ثبت نشده است.', 'hedayati-core' ) . '</p>';
		} else {
			echo '<p style="font-family:monospace;font-size:1.5rem" dir="ltr">' . esc_html( $value ) . '</p>';
		}

		echo '<p><a href="' . $back . '">' . esc_html__( 'بازگشت به پرونده', 'hedayati-core' ) . '</a></p>';
		echo '</body></html>';

		if ( ! defined( 'HDIT_TESTING' ) ) {
			exit;
		}
	}
}
