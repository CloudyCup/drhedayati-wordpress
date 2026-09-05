<?php
/**
 * Phase 3 — public content surfaces (About / Contact / Consultation / Teachers)
 * and the explicit staff opt-in that controls what operational data, if any, a
 * public course page is allowed to show.
 *
 * Nothing here is public by default:
 *   - Teacher profiles appear on `/teachers/` only when a staff member ticks
 *     "publish this teacher" (`_hedayati_public_teacher`) AND the profile is a
 *     published post.
 *   - A course page shows a teacher name / fee / start date only when
 *     `_hedayati_public_catalog_details` is ticked (read by single-course.php).
 *   - A Course Run appears publicly (date + fee + registration status only,
 *     never roster / attendance / capacity / internal notes) only when its id
 *     is in the course's `_hedayati_public_run_ids` allow-list AND the run is
 *     still scheduled/in-progress.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Public_Content {

	public const META_PUBLIC_TEACHER  = '_hedayati_public_teacher';
	public const META_PUBLIC_CATALOG  = '_hedayati_public_catalog_details';
	public const META_PUBLIC_RUN_IDS  = '_hedayati_public_run_ids';

	private const NONCE_ACTION = 'hedayati_publication_save';
	private const NONCE_FIELD  = 'hedayati_publication_nonce';

	/** Pages the plugin makes sure exist; content is staff-editable afterwards. */
	private const PAGES = [
		'about'    => 'دربارهٔ مجتمع',
		'contact'  => 'تماس با ما',
		'consult'  => 'مشاورهٔ انتخاب دوره',
		'teachers' => 'مدرسان مجتمع',
	];

	private const RUN_STATUSES_PUBLIC = [ 'scheduled', 'in_progress' ];

	// ── Bootstrap ───────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'maybe_ensure_pages' ] );
		add_action( 'add_meta_boxes', [ self::class, 'register_box' ] );
		add_action( 'save_post', [ self::class, 'save_box' ], 20, 2 );
	}

	public static function ensure_pages(): void {
		foreach ( self::PAGES as $slug => $title ) {
			if ( ! get_page_by_path( $slug ) instanceof WP_Post ) {
				wp_insert_post( [
					'post_type'    => 'page',
					'post_name'    => $slug,
					'post_title'   => $title,
					'post_status'  => 'publish',
					'post_content' => '',
				] );
			}
		}
	}

	public static function maybe_ensure_pages(): void {
		if ( current_user_can( 'manage_options' ) ) {
			self::ensure_pages();
		}
	}

	// ── Publication meta box (course + teacher edit screens) ────────────────

	public static function register_box(): void {
		add_meta_box(
			'hedayati-publication',
			__( 'انتشار عمومی اطلاعات', 'hedayati-core' ),
			[ self::class, 'render_box' ],
			[ 'course', 'teacher' ],
			'normal'
		);
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		if ( 'teacher' === $post->post_type ) {
			$checked = checked( '1', get_post_meta( $post->ID, self::META_PUBLIC_TEACHER, true ), false );
			echo '<p><label><input type="checkbox" name="hd_public_teacher" value="1"' . $checked . '> '
				. esc_html__( 'انتشار نام، تصویر، عنوان و متن معرفی این مدرس در صفحهٔ مدرسان', 'hedayati-core' )
				. '</label></p>';
			echo '<p class="description">' . esc_html__( 'اطلاعات حساب کاربری و تخصیص‌های داخلی هرگز منتشر نمی‌شوند.', 'hedayati-core' ) . '</p>';
			return;
		}

		$catalog_checked = checked( '1', get_post_meta( $post->ID, self::META_PUBLIC_CATALOG, true ), false );
		echo '<p><label><input type="checkbox" name="hd_public_catalog_details" value="1"' . $catalog_checked . '> '
			. esc_html__( 'انتشار نام مدرس، شهریه و تاریخ درج‌شده در اطلاعات این دوره', 'hedayati-core' )
			. '</label></p>';
		echo '<p class="description">' . esc_html__( 'کلاس‌های تأییدشدهٔ زیر با تاریخ و شهریه در صفحهٔ عمومی دوره نمایش داده می‌شوند. موارد بدون تأیید خصوصی می‌مانند.', 'hedayati-core' ) . '</p>';

		$approved = array_map( 'intval', (array) get_post_meta( $post->ID, self::META_PUBLIC_RUN_IDS, true ) );

		foreach ( Hedayati_Course_Run_Service::query( [ 'course_id' => $post->ID, 'limit' => 500 ] ) as $run ) {
			$run_checked = checked( in_array( (int) $run['id'], $approved, true ), true, false );
			$label       = '#' . $run['id'] . ' — ' . ( $run['start_date']
				? Hedayati_Jalali::format( $run['start_date'] )
				: __( 'تاریخ تعیین نشده', 'hedayati-core' ) );

			echo '<p><label><input type="checkbox" name="hd_public_runs[]" value="' . esc_attr( (string) $run['id'] ) . '"'
				. $run_checked . '> ' . esc_html( $label ) . '</label></p>';
		}
	}

	public static function save_box( int $post_id, WP_Post $post ): void {
		if (
			! in_array( $post->post_type, [ 'course', 'teacher' ], true )
			|| wp_is_post_revision( $post_id )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( 'teacher' === $post->post_type ) {
			update_post_meta( $post_id, self::META_PUBLIC_TEACHER, isset( $_POST['hd_public_teacher'] ) ? '1' : '0' );
			return;
		}

		update_post_meta( $post_id, self::META_PUBLIC_CATALOG, isset( $_POST['hd_public_catalog_details'] ) ? '1' : '0' );

		$raw = isset( $_POST['hd_public_runs'] ) && is_array( $_POST['hd_public_runs'] ) ? $_POST['hd_public_runs'] : [];
		$valid_ids = [];

		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$run = Hedayati_Course_Run_Service::get( absint( $value ) );
			if ( $run && (int) $run['course_id'] === $post_id ) {
				$valid_ids[] = (int) $run['id'];
			}
		}

		update_post_meta( $post_id, self::META_PUBLIC_RUN_IDS, array_values( array_unique( $valid_ids ) ) );
	}

	// ── Read projections for the theme ─────────────────────────────────────

	/**
	 * Published, staff-approved teacher profiles for `/teachers/`.
	 *
	 * @return array<int, array{id:int,name:string,bio:string,headline:string,image:string}>
	 */
	public static function teachers(): array {
		$posts = get_posts( [
			'post_type'      => 'teacher',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_key'       => self::META_PUBLIC_TEACHER,
			'meta_value'     => '1',
		] );

		return array_map(
			static fn( WP_Post $p ): array => [
				'id'       => $p->ID,
				'name'     => $p->post_title,
				'bio'      => wp_kses_post( $p->post_content ),
				'headline' => (string) get_post_meta( $p->ID, Hedayati_Teacher::META_HEADLINE, true ),
				'image'    => get_the_post_thumbnail( $p->ID, 'medium', [ 'loading' => 'lazy' ] ),
			],
			$posts
		);
	}

	/**
	 * Staff-approved, still-active runs for a published course — projected down
	 * to the only three fields a public page may show.
	 *
	 * @return array<int, array{start_date:?string,tuition_rial:?int,registration_status:string}>
	 */
	public static function runs( int $course_id ): array {
		if ( 'publish' !== get_post_status( $course_id ) || 'course' !== get_post_type( $course_id ) ) {
			return [];
		}

		$ids = get_post_meta( $course_id, self::META_PUBLIC_RUN_IDS, true );
		if ( ! is_array( $ids ) ) {
			return [];
		}

		$out = [];
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			$run = Hedayati_Course_Run_Service::get( (int) $id );

			if (
				$run
				&& (int) $run['course_id'] === $course_id
				&& in_array( $run['run_status'], self::RUN_STATUSES_PUBLIC, true )
			) {
				$out[] = [
					'start_date'          => $run['start_date'],
					'tuition_rial'        => $run['tuition_rial'],
					'registration_status' => $run['registration_status'],
				];
			}
		}

		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $a['start_date'] ?? '9999' ), (string) ( $b['start_date'] ?? '9999' ) ) );

		return $out;
	}
}
