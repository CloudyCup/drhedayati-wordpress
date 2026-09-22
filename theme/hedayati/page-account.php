<?php
/**
 * Student self-service account (/account/) — D58 AI-Studio application shell.
 *
 * Access control, no-cache headers, and view resolution all happen earlier, in
 * `Hedayati_Student_Portal::guard_account_page()` (hooked on `template_redirect`,
 * so it runs before this template is ever reached) — this file only renders.
 * Every view is rendered by the plugin (`render_current_view()`); the template
 * holds no business logic, only the navigation/presentation around it.
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Student_Portal' ) ) {
	status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>حساب کاربری موقتاً در دسترس نیست.</p></main>'; get_footer(); return;
}

$hd_current_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard';
if ( ! in_array( $hd_current_view, Hedayati_Student_Portal::VIEWS, true ) ) {
	$hd_current_view = 'dashboard';
}

$hd_unread = class_exists( 'Hedayati_Notification_Service' )
	? Hedayati_Notification_Service::unread_count( get_current_user_id() )
	: 0;

// view => [ label, icon, badge ] grouped the way the AI Studio student rail is.
$hd_nav_sections = [
	''                  => [
		'dashboard' => [ __( 'داشبورد دانشجو', 'hedayati' ), 'dashboard', 0 ],
	],
	'یادگیری'           => [
		'enrollments'  => [ __( 'دوره‌های من', 'hedayati' ), 'book', 0 ],
		'schedule'     => [ __( 'برنامهٔ دوره‌های اجرایی', 'hedayati' ), 'calendar', 0 ],
		'certificates' => [ __( 'گواهینامه‌های من', 'hedayati' ), 'award', 0 ],
	],
	'ارتباط با مجتمع'   => [
		'support'       => [ __( 'پشتیبانی و تیکت', 'hedayati' ), 'lifebuoy', 0 ],
		'notifications' => [ __( 'اعلان‌ها', 'hedayati' ), 'bell', $hd_unread ],
	],
	'پرونده و حساب'     => [
		'verification' => [ __( 'احراز هویت', 'hedayati' ), 'id', 0 ],
		'documents'    => [ __( 'مدارک من', 'hedayati' ), 'file', 0 ],
		'profile'      => [ __( 'پروفایل و امنیت', 'hedayati' ), 'user', 0 ],
	],
];

$hd_groups = [];
$hd_title  = __( 'داشبورد دانشجو', 'hedayati' );
foreach ( $hd_nav_sections as $hd_label => $hd_views ) {
	$hd_items = [];
	foreach ( $hd_views as $hd_view_key => [ $hd_view_label, $hd_icon, $hd_badge ] ) {
		if ( ! in_array( $hd_view_key, Hedayati_Student_Portal::VIEWS, true ) ) {
			continue;
		}
		if ( $hd_view_key === $hd_current_view ) {
			$hd_title = $hd_view_label;
		}
		$hd_items[] = [
			'slug'   => $hd_view_key,
			'label'  => $hd_view_label,
			'url'    => Hedayati_Student_Portal::get_account_url( $hd_view_key ),
			'icon'   => $hd_icon,
			'badge'  => (int) $hd_badge,
			'active' => $hd_view_key === $hd_current_view,
		];
	}
	if ( ! empty( $hd_items ) ) {
		$hd_groups[] = [ 'key' => sanitize_title( $hd_label ), 'label' => $hd_label, 'items' => $hd_items ];
	}
}

get_header();

hedayati_app_shell_open( [
	'kind'          => 'student',
	'rail_title'    => __( 'پنل دانشجویی', 'hedayati' ),
	'rail_subtitle' => __( 'مجتمع آموزشی دکتر هدایتی', 'hedayati' ),
	'role'          => __( 'دانشجو', 'hedayati' ),
	'eyebrow'       => __( 'میز کار آموزشی', 'hedayati' ),
	'title'         => $hd_title,
	'groups'        => $hd_groups,
	'bottom'        => [
		[ 'label' => __( 'پشتیبانی و تماس', 'hedayati' ), 'url' => home_url( '/contact/' ), 'icon' => 'chat' ],
		[ 'label' => __( 'مشاهدهٔ وب‌سایت', 'hedayati' ), 'url' => home_url( '/' ), 'icon' => 'external' ],
		[ 'label' => __( 'خروج از پنل', 'hedayati' ), 'url' => wp_logout_url( home_url( '/' ) ), 'icon' => 'logout', 'class' => 'hd-portal-nav-logout' ],
	],
	'notif_url'     => Hedayati_Student_Portal::get_account_url( 'notifications' ),
	'notif_count'   => $hd_unread,
] );
?>
		<main id="site-main" class="hd-app-content hd-portal-main hd-student-main" role="main" tabindex="-1">
			<div class="container hd-app-container hd-portal-content">
				<?php echo Hedayati_Student_Portal::render_notice(); // phpcs:ignore -- pre-escaped by render_notice(). ?>
				<?php echo Hedayati_Student_Portal::render_current_view(); // phpcs:ignore -- pre-escaped by each render_*_view() method. ?>
			</div>
		</main>
<?php
hedayati_app_shell_close();
get_footer();
