<?php
/**
 * Staff application shell (/panel/) — D58 AI-Studio layout.
 *
 * Authorization happens earlier (Hedayati_Staff_Portal::guard() on
 * template_redirect) and again inside the plugin renderer. The sidebar comes
 * from Hedayati_Staff_Portal::nav_groups(), which filters every core view and
 * every Hedayati_Staff_Portal::module_views() entry by the capability that
 * gates it — this template never adds a link the guard would refuse.
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Staff_Portal' ) ) { status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>پنل موقتاً در دسترس نیست.</p></main>'; get_footer(); return; }

$hd_is_manager = Hedayati_Staff_Portal::is_manager_workspace();
$hd_groups     = Hedayati_Staff_Portal::nav_groups();
$hd_user       = wp_get_current_user();
$hd_unread     = 0;
$hd_notif_url  = '';

foreach ( $hd_groups as $hd_group ) {
	foreach ( $hd_group['items'] as $hd_item ) {
		if ( 'notifications' === $hd_item['slug'] ) {
			$hd_unread    = (int) $hd_item['badge'];
			$hd_notif_url = $hd_item['url'];
		}
	}
}

$hd_bottom = [];
if ( current_user_can( 'manage_options' ) ) { // D57: administrator-only, deliberate route into native wp-admin.
	$hd_bottom[] = [ 'label' => 'پیشخوان وردپرس', 'url' => admin_url(), 'icon' => 'wordpress', 'class' => 'hd-portal-nav-wpadmin' ];
}
$hd_bottom[] = [ 'label' => 'مشاهدهٔ وب‌سایت', 'url' => home_url( '/' ), 'icon' => 'external' ];
$hd_bottom[] = [ 'label' => 'خروج از پنل', 'url' => wp_logout_url( home_url( '/' ) ), 'icon' => 'logout', 'class' => 'hd-portal-nav-logout' ];

get_header();

$hd_quick = [];
if ( current_user_can( 'hedayati_manage_courses' ) && $hd_is_manager ) {
	$hd_quick[] = [ 'label' => 'افزودن دورهٔ جدید', 'url' => Hedayati_Staff_Portal::url( [ 'view' => 'course-new' ] ), 'icon' => 'plus' ];
}
foreach ( $hd_groups as $hd_group ) {
	foreach ( $hd_group['items'] as $hd_item ) {
		if ( in_array( $hd_item['slug'], [ 'certificates', 'consultations', 'students', 'academic' ], true ) ) {
			$hd_quick[] = [ 'label' => $hd_item['label'], 'url' => $hd_item['url'], 'icon' => $hd_item['icon'] ];
		}
	}
}

hedayati_app_shell_open( [
	'kind'        => $hd_is_manager ? 'manager' : 'staff',
	'chip'        => $hd_is_manager ? 'پنل مدیریت مجتمع دکتر هدایتی' : 'پنل آموزش مجتمع دکتر هدایتی',
	'rail_label'  => $hd_is_manager ? 'منوی پنل مدیریت' : 'منوی پنل آموزش',
	'eyebrow'     => Hedayati_Staff_Portal::role_label( $hd_user ),
	'title'       => $hd_is_manager ? 'پنل مدیریت یکپارچه' : 'پنل آموزش',
	'avatar'      => Hedayati_Staff_Portal::role_avatar( $hd_user ),
	'groups'      => $hd_groups,
	'bottom'      => $hd_bottom,
	'notif_url'   => $hd_notif_url,
	'notif_count' => $hd_unread,
	'quick'       => $hd_quick,
] );
?>
		<main id="site-main" class="hd-app-content hd-portal-main<?php echo $hd_is_manager ? ' hd-manager-main' : ''; ?>" tabindex="-1">
			<div class="container hd-app-container hd-portal-content"><?php Hedayati_Staff_Portal::render(); ?></div>
		</main>
<?php
hedayati_app_shell_close();
get_footer();
