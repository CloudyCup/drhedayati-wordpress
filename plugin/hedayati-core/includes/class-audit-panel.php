<?php
/**
 * Manager Experience (owner decision D53) — read-only audit history in `/panel/`.
 *
 * Replaces the `admin.php?page=hedayati-academic-audit` link with an in-panel
 * view. Strictly read-only: it calls only `Hedayati_Audit_Log::query()` /
 * `::count()` (there is no write/update/delete API) and renders the same
 * metadata-only columns the wp-admin viewer shows — actor, action, object,
 * time, note. No IP address, no user-agent, no request body: the audit policy
 * (D16) is preserved exactly, nothing is added.
 *
 * The wp-admin viewer (`Hedayati_Academic_Admin::render_audit_log()`) stays for
 * the administrator.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Audit_Panel {

	public const VIEW = 'audit';

	private const PER_PAGE = 30;

	public static function init(): void {
		add_filter( 'hedayati_panel_module_views', [ self::class, 'register_panel_view' ] );
	}

	/**
	 * @param array<string,array> $views
	 * @return array<string,array>
	 */
	public static function register_panel_view( array $views ): array {
		$views[ self::VIEW ] = [
			'capability' => Hedayati_Audit_Log::VIEW_CAPABILITY,
			'render'     => [ self::class, 'render_panel' ],
			'nav'        => __( 'گزارش فعالیت‌ها', 'hedayati-core' ),
			'title'      => __( 'گزارش فعالیت‌های مدیریتی', 'hedayati-core' ),
			'desc'       => __( 'رویدادهای حساس سامانه — بدون نمایش اطلاعات خصوصی', 'hedayati-core' ),
			'icon'       => 'shield',
		];

		return $views;
	}

	public static function render_panel(): void {
		if ( ! current_user_can( Hedayati_Audit_Log::VIEW_CAPABILITY ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'hedayati-core' ), '', [ 'response' => 403 ] );
		}

		$get = static fn( string $key ): string => isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] )
			? sanitize_text_field( wp_unslash( $_GET[ $key ] ) )
			: '';

		$object_type = sanitize_key( $get( 'object_type' ) );
		$action_f    = $get( 'audit_action' );
		$actor_id    = absint( $get( 'actor_id' ) );
		$page        = max( 1, absint( $get( 'audit_page' ) ) );

		$object_types = Hedayati_Audit_Log::object_types();
		$actions      = Hedayati_Audit_Log::actions();

		// Only accept a filter value that is a known, safe enum.
		if ( '' !== $object_type && ! in_array( $object_type, $object_types, true ) ) {
			$object_type = '';
		}
		if ( '' !== $action_f && ! in_array( $action_f, $actions, true ) ) {
			$action_f = '';
		}

		$filters = array_filter( [
			'object_type' => $object_type,
			'action'      => $action_f,
			'actor_id'    => $actor_id > 0 ? $actor_id : null,
		], static fn( $v ) => null !== $v && '' !== $v );

		$total = Hedayati_Audit_Log::count( $filters );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );

		$rows = Hedayati_Audit_Log::query( array_merge( $filters, [
			'per_page' => self::PER_PAGE,
			'page'     => $page,
		] ) );

		echo '<header class="hd-manager-heading"><div>';
		echo '<span class="hd-manager-eyebrow">' . esc_html__( 'حسابرسی', 'hedayati-core' ) . '</span>';
		echo '<h1 class="hd-portal-title">' . esc_html__( 'گزارش فعالیت‌های مدیریتی', 'hedayati-core' ) . '</h1>';
		printf(
			'<p class="hd-portal-note">%s</p>',
			esc_html( sprintf(
				/* translators: %s: total number of audit entries */
				__( 'مجموع %s رویداد ثبت‌شده. تنها فراداده ثبت می‌شود؛ نشانی IP یا مرورگر ذخیره نمی‌شود.', 'hedayati-core' ),
				Hedayati_Text::digits_to_persian( (string) $total )
			) )
		);
		echo '</div></header>';

		echo '<form class="hd-manager-toolbar" method="get" action="' . esc_url( Hedayati_Staff_Portal::url() ) . '">';
		echo '<input type="hidden" name="view" value="' . esc_attr( self::VIEW ) . '">';

		echo '<label class="hd-portal-field"><span>' . esc_html__( 'نوع موضوع', 'hedayati-core' ) . '</span><select name="object_type">';
		echo '<option value="">' . esc_html__( 'همه', 'hedayati-core' ) . '</option>';
		foreach ( $object_types as $type ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $object_type, $type, false ), esc_html( $type ) );
		}
		echo '</select></label>';

		echo '<label class="hd-portal-field"><span>' . esc_html__( 'رویداد', 'hedayati-core' ) . '</span><select name="audit_action">';
		echo '<option value="">' . esc_html__( 'همه', 'hedayati-core' ) . '</option>';
		foreach ( $actions as $act ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $act ), selected( $action_f, $act, false ), esc_html( $act ) );
		}
		echo '</select></label>';

		printf(
			'<label class="hd-portal-field"><span>%s</span><input type="number" name="actor_id" min="0" value="%s"></label>',
			esc_html__( 'شناسهٔ کاربر انجام‌دهنده', 'hedayati-core' ),
			esc_attr( $actor_id > 0 ? (string) $actor_id : '' )
		);

		printf( '<button class="hd-portal-btn" type="submit">%s</button>', esc_html__( 'اعمال فیلتر', 'hedayati-core' ) );
		echo '</form>';

		if ( empty( $rows ) ) {
			echo '<p class="hd-portal-note">' . esc_html__( 'رویدادی مطابق فیلتر یافت نشد.', 'hedayati-core' ) . '</p>';
			return;
		}

		echo '<div class="hd-manager-table" role="table">';
		echo '<div class="hd-manager-tr hd-manager-th" role="row">';
		foreach ( [
			__( 'زمان', 'hedayati-core' ),
			__( 'کاربر', 'hedayati-core' ),
			__( 'رویداد', 'hedayati-core' ),
			__( 'موضوع', 'hedayati-core' ),
			__( 'یادداشت', 'hedayati-core' ),
		] as $heading ) {
			echo '<span role="columnheader">' . esc_html( $heading ) . '</span>';
		}
		echo '</div>';

		foreach ( $rows as $row ) {
			$actor = $row['actor_id'] > 0 ? get_user_by( 'id', $row['actor_id'] ) : null;
			$actor_label = $actor
				? $actor->display_name . ' (#' . $row['actor_id'] . ')'
				: ( $row['actor_id'] > 0 ? '#' . $row['actor_id'] : __( 'سامانه', 'hedayati-core' ) );

			echo '<div class="hd-manager-tr" role="row">';
			echo '<span role="cell"><time dir="ltr">' . esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ) . '</time></span>';
			echo '<span role="cell">' . esc_html( $actor_label ) . '</span>';
			echo '<span role="cell"><code dir="ltr">' . esc_html( $row['action'] ) . '</code></span>';
			echo '<span role="cell"><bdi>' . esc_html( $row['object_type'] . ( $row['object_id'] > 0 ? ' #' . $row['object_id'] : '' ) ) . '</bdi></span>';
			echo '<span role="cell">' . ( '' !== $row['note'] ? esc_html( $row['note'] ) : '<span class="hd-portal-note">—</span>' ) . '</span>';
			echo '</div>';
		}
		echo '</div>';

		self::render_pagination( $page, $pages );
	}

	private static function render_pagination( int $page, int $pages ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$base = [ 'view' => self::VIEW ];
		foreach ( [ 'object_type', 'audit_action', 'actor_id' ] as $key ) {
			if ( isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) && '' !== $_GET[ $key ] ) {
				$base[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		echo '<nav class="hd-manager-pagination" aria-label="' . esc_attr__( 'صفحه‌بندی گزارش', 'hedayati-core' ) . '">';
		if ( $page > 1 ) {
			printf(
				'<a class="hd-portal-nav-link" href="%s">%s</a>',
				esc_url( Hedayati_Staff_Portal::url( array_merge( $base, [ 'audit_page' => $page - 1 ] ) ) ),
				esc_html__( 'صفحهٔ قبل', 'hedayati-core' )
			);
		}
		printf(
			'<span>%s</span>',
			esc_html( sprintf(
				/* translators: 1: current page, 2: total pages */
				__( 'صفحهٔ %1$s از %2$s', 'hedayati-core' ),
				Hedayati_Text::digits_to_persian( (string) $page ),
				Hedayati_Text::digits_to_persian( (string) $pages )
			) )
		);
		if ( $page < $pages ) {
			printf(
				'<a class="hd-portal-nav-link" href="%s">%s</a>',
				esc_url( Hedayati_Staff_Portal::url( array_merge( $base, [ 'audit_page' => $page + 1 ] ) ) ),
				esc_html__( 'صفحهٔ بعد', 'hedayati-core' )
			);
		}
		echo '</nav>';
	}
}
