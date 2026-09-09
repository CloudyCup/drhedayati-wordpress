<?php
/**
 * AI Studio parity — course/session materials (owner decision D49).
 *
 * A material belongs to a Course Run (optionally to one session). Types:
 *   link  external URL (title + description + url)
 *   note  text only    (title + description)
 *   file  private file  (pdf/jpg/png, streamed through a gated handler)
 *
 * Authorisation:
 *   - manage: `hedayati_manage_session_materials` AND (manager OR staff-on-run)
 *   - view  : an ACTIVE enrollment in that run, OR staff-on-run, OR manager
 *   - files are never public: the `?hedayati_material=<id>` handler re-checks
 *     the viewer, then streams via Hedayati_Material_Storage.
 *
 * Identity-document storage (Phase 2C) is untouched and unrelated.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Material_Service {

	public const MANAGE_CAP = 'hedayati_manage_session_materials';

	public const TYPES = [ 'link', 'note', 'file' ];

	public static function init(): void {
		add_action( 'admin_post_hedayati_staff_material_add', [ self::class, 'handle_add' ] );
		add_action( 'admin_post_hedayati_staff_material_delete', [ self::class, 'handle_delete' ] );
		add_action( 'admin_post_hedayati_material_download', [ self::class, 'handle_download' ] );

		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );

		add_action( 'hedayati_run_deleted', [ self::class, 'on_run_deleted' ] );

		add_filter( 'hedayati_audit_object_types', static fn( array $t ): array => array_merge( $t, [ 'session_material' ] ) );
		add_filter( 'hedayati_audit_actions', static fn( array $a ): array => array_merge( $a, [
			'material.created',
			'material.deleted',
		] ) );
	}

	public static function register_panel_view( array $views ): array {
		$views['materials'] = [
			'capability' => self::MANAGE_CAP,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'منابع و جزوات', 'hedayati-core' ),
			'title'      => __( 'منابع و جزوات دوره', 'hedayati-core' ),
			'desc'       => __( 'افزودن لینک، یادداشت و فایل برای کلاس‌ها و جلسات', 'hedayati-core' ),
			'icon'       => 'folder',
		];
		return $views;
	}

	// ── Read ────────────────────────────────────────────────────────────────

	public static function get( int $id ): ?array {
		global $wpdb;
		if ( $id <= 0 ) {
			return null;
		}
		$table = Hedayati_DB_Schema::get_table_session_materials();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @return array<int, array> */
	public static function list_for_run( int $run_id ): array {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_session_materials();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY created_at DESC", $run_id ),
			ARRAY_A
		);
		return array_map( [ self::class, 'hydrate' ], $rows ?: [] );
	}

	public static function count_for_run( int $run_id ): int {
		global $wpdb;
		$table = Hedayati_DB_Schema::get_table_session_materials();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE run_id = %d", $run_id ) );
	}

	// ── Authorisation ───────────────────────────────────────────────────────

	public static function can_manage_run( int $run_id, int $user_id ): bool {
		if ( ! user_can( $user_id, self::MANAGE_CAP ) ) {
			return false;
		}
		if ( user_can( $user_id, 'hedayati_manage_course_runs' ) ) {
			return true;
		}
		return Hedayati_Run_Staff_Service::user_is_staff_on_run( $user_id, $run_id );
	}

	public static function can_view_run( int $run_id, int $user_id ): bool {
		if ( self::can_manage_run( $run_id, $user_id ) ) {
			return true;
		}
		if ( user_can( $user_id, 'hedayati_manage_course_runs' ) ) {
			return true;
		}
		$enrollment = Hedayati_Enrollment_Service::get_by_run_user( $run_id, $user_id );
		return null !== $enrollment && 'active' === $enrollment['status'];
	}

	// ── Create / delete ─────────────────────────────────────────────────────

	/**
	 * @param array $data run_id, type, title, description, url, session_id, file ($_FILES element)
	 * @return int|WP_Error
	 */
	public static function create( array $data, int $actor_id ): int|WP_Error {
		global $wpdb;

		$run_id = absint( $data['run_id'] ?? 0 );
		if ( null === Hedayati_Course_Run_Service::get( $run_id ) ) {
			return new WP_Error( 'run', __( 'کلاس معتبری انتخاب نشده است.', 'hedayati-core' ) );
		}
		if ( ! self::can_manage_run( $run_id, $actor_id ) ) {
			return new WP_Error( 'cap', __( 'برای این کلاس اجازهٔ افزودن منبع ندارید.', 'hedayati-core' ) );
		}

		$type  = in_array( $data['type'] ?? '', self::TYPES, true ) ? (string) $data['type'] : 'link';
		$title = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 190 );
		$desc  = mb_substr( sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ), 0, 500 );

		if ( mb_strlen( $title ) < 2 ) {
			return new WP_Error( 'title', __( 'عنوان منبع را وارد کنید.', 'hedayati-core' ) );
		}

		$session_id = absint( $data['session_id'] ?? 0 );
		if ( $session_id > 0 ) {
			$session = Hedayati_Session_Service::get( $session_id );
			if ( null === $session || (int) $session['run_id'] !== $run_id ) {
				return new WP_Error( 'session', __( 'جلسهٔ انتخاب‌شده به این کلاس تعلق ندارد.', 'hedayati-core' ) );
			}
		}

		$url         = '';
		$storage_key = null;
		$mime        = '';
		$size        = 0;

		if ( 'link' === $type ) {
			$url = esc_url_raw( (string) ( $data['url'] ?? '' ) );
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				return new WP_Error( 'url', __( 'نشانی اینترنتی معتبر (با http/https) وارد کنید.', 'hedayati-core' ) );
			}
		} elseif ( 'file' === $type ) {
			$file = isset( $data['file'] ) && is_array( $data['file'] ) ? $data['file'] : [];
			if ( empty( $file ) || (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
				return new WP_Error( 'file', __( 'فایلی برای بارگذاری انتخاب نشده است.', 'hedayati-core' ) );
			}
			$stored = Hedayati_Material_Storage::save( $run_id, $file );
			if ( is_wp_error( $stored ) ) {
				return $stored;
			}
			$storage_key = $stored['storage_key'];
			$mime        = $stored['mime'];
			$size        = (int) $stored['size'];
		}

		$now   = current_time( 'mysql', true );
		$table = Hedayati_DB_Schema::get_table_session_materials();

		$inserted = $wpdb->insert(
			$table,
			[
				'run_id'        => $run_id,
				'session_id'    => $session_id > 0 ? $session_id : null,
				'material_type' => $type,
				'title'         => $title,
				'description'   => $desc,
				'url'           => 'link' === $type ? $url : null,
				'storage_key'   => $storage_key,
				'original_mime' => $mime,
				'size_bytes'    => $size,
				'visibility'    => 'enrolled',
				'created_by'    => $actor_id,
				'created_at'    => $now,
				'updated_at'    => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( false === $inserted ) {
			if ( null !== $storage_key ) {
				Hedayati_Material_Storage::delete( $storage_key );
			}
			return new WP_Error( 'db', __( 'ذخیرهٔ منبع ناموفق بود.', 'hedayati-core' ) );
		}

		$id = (int) $wpdb->insert_id;
		Hedayati_Audit_Log::record( 'material.created', 'session_material', $id, 'run #' . $run_id . ' · ' . $type, $actor_id );

		return $id;
	}

	public static function delete( int $id, int $actor_id ): true|WP_Error {
		global $wpdb;

		$material = self::get( $id );
		if ( null === $material ) {
			return new WP_Error( 'not_found', __( 'منبع یافت نشد.', 'hedayati-core' ) );
		}
		if ( ! self::can_manage_run( $material['run_id'], $actor_id ) ) {
			return new WP_Error( 'cap', __( 'اجازهٔ حذف این منبع را ندارید.', 'hedayati-core' ) );
		}

		if ( null !== $material['storage_key'] ) {
			Hedayati_Material_Storage::delete( $material['storage_key'] );
		}

		$table = Hedayati_DB_Schema::get_table_session_materials();
		$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		Hedayati_Audit_Log::record( 'material.deleted', 'session_material', $id, 'run #' . $material['run_id'], $actor_id );

		return true;
	}

	public static function on_run_deleted( int $run_id ): void {
		global $wpdb;
		foreach ( self::list_for_run( $run_id ) as $material ) {
			if ( null !== $material['storage_key'] ) {
				Hedayati_Material_Storage::delete( $material['storage_key'] );
			}
		}
		$table = Hedayati_DB_Schema::get_table_session_materials();
		$wpdb->delete( $table, [ 'run_id' => $run_id ], [ '%d' ] );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function handle_add(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_material_add', self::MANAGE_CAP );

		$run_id = isset( $_POST['run_id'] ) ? absint( wp_unslash( $_POST['run_id'] ) ) : 0;

		$result = self::create(
			[
				'run_id'      => $run_id,
				'session_id'  => isset( $_POST['session_id'] ) ? absint( wp_unslash( $_POST['session_id'] ) ) : 0,
				'type'        => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( (string) $_POST['type'] ) ) : 'link',
				'title'       => wp_unslash( (string) ( $_POST['title'] ?? '' ) ),
				'description' => wp_unslash( (string) ( $_POST['description'] ?? '' ) ),
				'url'         => wp_unslash( (string) ( $_POST['url'] ?? '' ) ),
				'file'        => isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : [],
			],
			get_current_user_id()
		);

		Hedayati_Staff_Portal::redirect_notice( is_wp_error( $result ) ? $result : true, [ 'view' => 'run', 'run_id' => $run_id ] );
	}

	public static function handle_delete(): void {
		Hedayati_Staff_Portal::guard_action( 'hedayati_staff_material_delete', self::MANAGE_CAP );

		$id       = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$material = self::get( $id );
		$run_id   = $material['run_id'] ?? 0;

		Hedayati_Staff_Portal::redirect_notice( self::delete( $id, get_current_user_id() ), [ 'view' => 'run', 'run_id' => $run_id ] );
	}

	/** Gated file download: `admin-post.php?action=hedayati_material_download&id=&_wpnonce=`. */
	public static function handle_download(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}

		$id    = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'hedayati_material_download_' . $id ) ) {
			wp_die( esc_html__( 'پیوند دانلود نامعتبر یا منقضی است.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$material = self::get( $id );
		if ( null === $material || 'file' !== $material['material_type'] || null === $material['storage_key'] ) {
			wp_die( esc_html__( 'منبع یافت نشد.', 'hedayati-core' ), '', [ 'response' => 404 ] );
		}

		if ( ! self::can_view_run( $material['run_id'], get_current_user_id() ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$streamed = Hedayati_Material_Storage::stream( $material['storage_key'] );
		if ( is_wp_error( $streamed ) ) {
			wp_die( esc_html( $streamed->get_error_message() ), '', [ 'response' => 404 ] );
		}
		exit;
	}

	public static function download_url( int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=hedayati_material_download&id=' . $id ),
			'hedayati_material_download_' . $id
		);
	}

	// ── Rendering ───────────────────────────────────────────────────────────

	/** Panel module index: the staff member's runs, each linking to its materials. */
	public static function render_panel(): void {
		if ( ! current_user_can( self::MANAGE_CAP ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'آموزش', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'منابع و جزوات دوره', 'hedayati-core' ) . '</h1>';
		echo '<p class="hd-portal-note">' . esc_html__( 'منابع هر کلاس را از صفحهٔ همان کلاس مدیریت کنید.', 'hedayati-core' ) . '</p>';
		echo '</div></header>';

		$user_id = get_current_user_id();
		$runs    = current_user_can( 'hedayati_manage_course_runs' )
			? Hedayati_Course_Run_Service::query( [ 'limit' => 200 ] )
			: array_filter( array_map(
				static fn( $rid ) => Hedayati_Course_Run_Service::get( (int) $rid ),
				Hedayati_Run_Staff_Service::run_ids_for_user( $user_id )
			) );

		if ( empty( $runs ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'کلاسی برای مدیریت منابع در دسترس نیست.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<ul class="hd-portal-run-list">';
		foreach ( $runs as $run ) {
			if ( ! $run ) {
				continue;
			}
			printf(
				'<li><a href="%s">%s <small>(%s)</small></a></li>',
				esc_url( Hedayati_Staff_Portal::url( [ 'view' => 'run', 'run_id' => $run['id'] ] ) . '#materials' ),
				esc_html( get_the_title( $run['course_id'] ) . ' — ' . ( $run['label'] ?: '#' . $run['id'] ) ),
				esc_html( Hedayati_Text::digits_to_persian( (string) self::count_for_run( (int) $run['id'] ) ) )
			);
		}
		echo '</ul>';
	}

	/** Management UI inside the run view (staff-portal render_run). */
	public static function render_run_section( int $run_id ): void {
		$user_id = get_current_user_id();
		if ( ! self::can_manage_run( $run_id, $user_id ) ) {
			return;
		}

		echo '<section class="hd-staff-section" id="materials">';
		echo '<h2 class="hd-portal-subtitle">' . esc_html__( 'منابع و جزوات', 'hedayati-core' ) . '</h2>';

		$materials = self::list_for_run( $run_id );
		if ( empty( $materials ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'هنوز منبعی برای این کلاس ثبت نشده است.', 'hedayati-core' ) . '</p>';
		} else {
			echo '<ul class="hd-material-list">';
			foreach ( $materials as $m ) {
				echo '<li><div><strong>' . esc_html( $m['title'] ) . '</strong>';
				echo ' <span class="hd-material-kind">' . esc_html( self::type_label( $m['material_type'] ) ) . '</span>';
				if ( '' !== $m['description'] ) {
					echo '<p>' . esc_html( $m['description'] ) . '</p>';
				}
				if ( 'link' === $m['material_type'] && '' !== (string) $m['url'] ) {
					echo '<a href="' . esc_url( (string) $m['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html__( 'باز کردن پیوند', 'hedayati-core' ) . '</a>';
				} elseif ( 'file' === $m['material_type'] ) {
					echo '<a href="' . esc_url( self::download_url( $m['id'] ) ) . '">' . esc_html__( 'دانلود فایل', 'hedayati-core' ) . '</a>';
				}
				echo '</div>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'hedayati_staff_material_delete' );
				echo '<input type="hidden" name="action" value="hedayati_staff_material_delete">';
				echo '<input type="hidden" name="id" value="' . esc_attr( (string) $m['id'] ) . '">';
				echo '<button class="hd-manager-toggle-btn" type="submit">' . esc_html__( 'حذف', 'hedayati-core' ) . '</button>';
				echo '</form></li>';
			}
			echo '</ul>';
		}

		// Add form.
		echo '<form class="hd-portal-form" method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'hedayati_staff_material_add' );
		echo '<input type="hidden" name="action" value="hedayati_staff_material_add">';
		echo '<input type="hidden" name="run_id" value="' . esc_attr( (string) $run_id ) . '">';
		echo '<label class="hd-portal-field"><span>' . esc_html__( 'نوع منبع', 'hedayati-core' ) . '</span><select name="type">';
		foreach ( self::TYPES as $t ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( self::type_label( $t ) ) . '</option>';
		}
		echo '</select></label>';
		printf( '<label class="hd-portal-field"><span>%s</span><input type="text" name="title" maxlength="190" required></label>', esc_html__( 'عنوان', 'hedayati-core' ) );
		printf( '<label class="hd-portal-field"><span>%s</span><textarea name="description" rows="2" maxlength="500"></textarea></label>', esc_html__( 'توضیح کوتاه (اختیاری)', 'hedayati-core' ) );
		printf( '<label class="hd-portal-field"><span>%s</span><input type="url" name="url" dir="ltr" placeholder="https://…"></label>', esc_html__( 'نشانی پیوند (برای نوع «پیوند»)', 'hedayati-core' ) );
		printf( '<label class="hd-portal-field"><span>%s</span><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></label>', esc_html__( 'فایل PDF/JPEG/PNG (برای نوع «فایل»)', 'hedayati-core' ) );

		// Session dropdown.
		$sessions = Hedayati_Session_Service::list_for_run( $run_id );
		if ( ! empty( $sessions ) ) {
			echo '<label class="hd-portal-field"><span>' . esc_html__( 'اتصال به جلسه (اختیاری)', 'hedayati-core' ) . '</span><select name="session_id">';
			echo '<option value="0">' . esc_html__( 'کل کلاس', 'hedayati-core' ) . '</option>';
			foreach ( $sessions as $s ) {
				printf(
					'<option value="%s">%s</option>',
					esc_attr( (string) $s['id'] ),
					esc_html( sprintf( __( 'جلسهٔ %s', 'hedayati-core' ), Hedayati_Text::digits_to_persian( (string) $s['session_number'] ) ) . ' — ' . $s['topic'] )
				);
			}
			echo '</select></label>';
		}

		echo '<button class="hd-portal-btn" type="submit">' . esc_html__( 'افزودن منبع', 'hedayati-core' ) . '</button>';
		echo '</form></section>';
	}

	/** Read-only list for an enrolled student (account portal). */
	public static function render_student_run( int $run_id, int $user_id ): void {
		if ( ! self::can_view_run( $run_id, $user_id ) ) {
			return;
		}
		$materials = self::list_for_run( $run_id );
		if ( empty( $materials ) ) {
			return;
		}

		echo '<div class="hd-material-list">';
		foreach ( $materials as $m ) {
			echo '<article><strong>' . esc_html( $m['title'] ) . '</strong>';
			if ( '' !== $m['description'] ) {
				echo '<p>' . esc_html( $m['description'] ) . '</p>';
			}
			if ( 'link' === $m['material_type'] && '' !== (string) $m['url'] ) {
				echo '<a href="' . esc_url( (string) $m['url'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html__( 'باز کردن پیوند', 'hedayati-core' ) . '</a>';
			} elseif ( 'file' === $m['material_type'] ) {
				echo '<a href="' . esc_url( self::download_url( $m['id'] ) ) . '">' . esc_html__( 'دانلود', 'hedayati-core' ) . '</a>';
			}
			echo '</article>';
		}
		echo '</div>';
	}

	public static function type_label( string $type ): string {
		return [
			'link' => __( 'پیوند', 'hedayati-core' ),
			'note' => __( 'یادداشت', 'hedayati-core' ),
			'file' => __( 'فایل', 'hedayati-core' ),
		][ $type ] ?? $type;
	}

	// ── Internals ───────────────────────────────────────────────────────────

	private static function hydrate( array $row ): array {
		return [
			'id'            => (int) $row['id'],
			'run_id'        => (int) $row['run_id'],
			'session_id'    => null !== $row['session_id'] ? (int) $row['session_id'] : null,
			'material_type' => (string) $row['material_type'],
			'title'         => (string) $row['title'],
			'description'   => (string) $row['description'],
			'url'           => null !== $row['url'] ? (string) $row['url'] : null,
			'storage_key'   => null !== $row['storage_key'] ? (string) $row['storage_key'] : null,
			'original_mime' => (string) $row['original_mime'],
			'size_bytes'    => (int) $row['size_bytes'],
			'visibility'    => (string) $row['visibility'],
			'created_by'    => (int) $row['created_by'],
			'created_at'    => (string) $row['created_at'],
			'updated_at'    => (string) $row['updated_at'],
		];
	}
}
