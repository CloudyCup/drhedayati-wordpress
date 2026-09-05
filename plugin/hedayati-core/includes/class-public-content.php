<?php
/** Public publishing uses explicit opt-in; operational records stay private. */
declare( strict_types=1 );
if ( ! defined( 'ABSPATH' ) ) { exit; }
class Hedayati_Public_Content {
	public static function init(): void {
		add_action( 'admin_init', [ self::class, 'ensure_pages' ] );
		add_action( 'add_meta_boxes', [ self::class, 'boxes' ] );
		add_action( 'save_post', [ self::class, 'save' ], 20, 2 );
	}
	public static function ensure_pages(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		foreach ( [ 'about' => 'درباره مجتمع', 'contact' => 'تماس با ما', 'consult' => 'مشاوره انتخاب دوره', 'teachers' => 'مدرسان مجتمع' ] as $slug => $title ) {
			if ( ! get_page_by_path( $slug ) ) { wp_insert_post( [ 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_status' => 'publish' ] ); }
		}
	}
	public static function boxes(): void {
		add_meta_box( 'hedayati-publication', 'انتشار عمومی اطلاعات', [ self::class, 'box' ], [ 'course', 'teacher' ], 'normal' );
	}
	public static function box( WP_Post $post ): void {
		wp_nonce_field( 'hedayati_publication_' . $post->ID, 'hedayati_publication_nonce' );
		if ( 'teacher' === $post->post_type ) {
			echo '<p><label><input type="checkbox" name="hd_public_teacher" value="1"' . checked( '1', get_post_meta( $post->ID, '_hedayati_public_teacher', true ), false ) . '> انتشار نام، تصویر، عنوان و متن معرفی این مدرس در صفحه مدرسان</label></p><p>اطلاعات حساب کاربری و تخصیص‌های داخلی منتشر نمی‌شوند.</p>';
			return;
		}
		echo '<p><label><input type="checkbox" name="hd_public_catalog_details" value="1"' . checked( '1', get_post_meta( $post->ID, '_hedayati_public_catalog_details', true ), false ) . '> انتشار نام مدرس، شهریه و تاریخ درج‌شده در اطلاعات این دوره</label></p><p>کلاس‌های تأییدشده زیر با تاریخ و شهریه در صفحه عمومی دوره نمایش داده می‌شوند. موارد بدون تأیید خصوصی می‌مانند.</p>';
		$approved = (array) get_post_meta( $post->ID, '_hedayati_public_run_ids', true );
		foreach ( Hedayati_Course_Run_Service::query( [ 'course_id' => $post->ID, 'limit' => 500 ] ) as $run ) {
			echo '<p><label><input type="checkbox" name="hd_public_runs[]" value="' . esc_attr( (string) $run['id'] ) . '"' . checked( in_array( $run['id'], array_map( 'intval', $approved ), true ), true, false ) . '> ' . esc_html( '#' . $run['id'] . ' — ' . ( $run['start_date'] ? Hedayati_Jalali::format( $run['start_date'] ) : 'تاریخ تعیین نشده' ) ) . '</label></p>';
		}
	}
	public static function save( int $id, WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ 'course', 'teacher' ], true ) || wp_is_post_revision( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $id ) ) { return; }
		$nonce = $_POST['hedayati_publication_nonce'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'hedayati_publication_' . $id ) ) { return; }
		if ( 'teacher' === $post->post_type ) { update_post_meta( $id, '_hedayati_public_teacher', isset( $_POST['hd_public_teacher'] ) ? '1' : '0' ); return; }
		update_post_meta( $id, '_hedayati_public_catalog_details', isset( $_POST['hd_public_catalog_details'] ) ? '1' : '0' );
		$raw = isset( $_POST['hd_public_runs'] ) && is_array( $_POST['hd_public_runs'] ) ? $_POST['hd_public_runs'] : [];
		$ids = [];
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) { continue; }
			$run = Hedayati_Course_Run_Service::get( absint( $value ) );
			if ( $run && $run['course_id'] === $id ) { $ids[] = $run['id']; }
		}
		update_post_meta( $id, '_hedayati_public_run_ids', array_values( array_unique( $ids ) ) );
	}
	public static function teachers(): array {
		$posts = get_posts( [ 'post_type' => 'teacher', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_hedayati_public_teacher', 'meta_value' => '1' ] );
		return array_map( static fn( $p ) => [ 'id' => $p->ID, 'name' => $p->post_title, 'bio' => wp_kses_post( $p->post_content ), 'headline' => (string) get_post_meta( $p->ID, Hedayati_Teacher::META_HEADLINE, true ), 'image' => get_the_post_thumbnail( $p->ID, 'medium', [ 'loading' => 'lazy' ] ) ], $posts );
	}
	public static function runs( int $course_id ): array {
		if ( 'publish' !== get_post_status( $course_id ) || 'course' !== get_post_type( $course_id ) ) { return []; }
		$ids = get_post_meta( $course_id, '_hedayati_public_run_ids', true );
		if ( ! is_array( $ids ) ) { return []; }
		$out = [];
		foreach ( array_slice( $ids, 0, 500 ) as $id ) {
			$run = Hedayati_Course_Run_Service::get( (int) $id );
			if ( $run && $run['course_id'] === $course_id && in_array( $run['run_status'], [ 'scheduled', 'in_progress' ], true ) ) {
				// Projection: never expose operational staff, capacity or student data.
				$out[] = [ 'start_date' => $run['start_date'], 'tuition_rial' => $run['tuition_rial'], 'registration_status' => $run['registration_status'] ];
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['start_date'] ?? '9999', $b['start_date'] ?? '9999' ) );
		return $out;
	}
}
