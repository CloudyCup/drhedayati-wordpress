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

// view => [ label, icon, badge ] — the AI Studio student rail (flat, in its
// order), then one collapsible group for the file/notification screens. Every
// Hedayati_Student_Portal::VIEWS entry stays reachable.
$hd_nav_sections = [
	'' => [
		'dashboard'    => [ __( 'داشبورد دانشجو', 'hedayati' ), 'dashboard', 0 ],
		'enrollments'  => [ __( 'دوره‌های من', 'hedayati' ), 'book', 0 ],
		'schedule'     => [ __( 'برنامهٔ دوره‌های اجرایی', 'hedayati' ), 'calendar', 0 ],
		'certificates' => [ __( 'گواهینامه‌های من', 'hedayati' ), 'shield', 0 ],
		'support'      => [ __( 'پشتیبانی و تیکت', 'hedayati' ), 'help', 0 ],
		'profile'      => [ __( 'پروفایل کاربری', 'hedayati' ), 'user', 0 ],
	],
	'پرونده و اعلان‌ها' => [
		'notifications' => [ __( 'اعلان‌ها', 'hedayati' ), 'bell', $hd_unread ],
		'verification'  => [ __( 'احراز هویت', 'hedayati' ), 'id', 0 ],
		'documents'     => [ __( 'مدارک من', 'hedayati' ), 'file', 0 ],
	],
];

$hd_groups = [];
foreach ( $hd_nav_sections as $hd_label => $hd_views ) {
	$hd_items = [];
	$hd_open  = false;
	foreach ( $hd_views as $hd_view_key => [ $hd_view_label, $hd_icon, $hd_badge ] ) {
		if ( ! in_array( $hd_view_key, Hedayati_Student_Portal::VIEWS, true ) ) {
			continue;
		}
		$hd_open    = $hd_open || $hd_view_key === $hd_current_view;
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
		$hd_groups[] = [ 'key' => '' === $hd_label ? 'primary' : 'records', 'label' => $hd_label, 'collapsible' => '' !== $hd_label, 'open' => $hd_open, 'items' => $hd_items ];
	}
}

$hd_user = wp_get_current_user();

get_header();

hedayati_app_shell_open( [
	'kind'        => 'student',
	'chip'        => __( 'پنل دانشجویی مجتمع دکتر هدایتی', 'hedayati' ),
	'rail_label'  => __( 'منوی حساب کاربری', 'hedayati' ),
	'eyebrow'     => __( 'خوش آمدید', 'hedayati' ),
	'title'       => '' !== trim( (string) $hd_user->display_name ) ? $hd_user->display_name : $hd_user->user_login,
	'avatar'      => __( 'دانشجو', 'hedayati' ),
	'groups'      => $hd_groups,
	'bottom'      => [
		[ 'label' => __( 'مشاهدهٔ وب‌سایت', 'hedayati' ), 'url' => home_url( '/' ), 'icon' => 'external' ],
		[ 'label' => __( 'خروج', 'hedayati' ), 'url' => wp_logout_url( home_url( '/' ) ), 'icon' => 'logout', 'class' => 'hd-portal-nav-logout' ],
	],
	'notif_url'   => Hedayati_Student_Portal::get_account_url( 'notifications' ),
	'notif_count' => $hd_unread,
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
