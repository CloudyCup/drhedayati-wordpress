<?php
/**
 * Phase 2D — student self-service account shell.
 *
 * Auto-selected by WordPress's template hierarchy for the `account` page
 * (`page-{slug}.php` — no `Template Name:` header needed). Access control,
 * no-cache headers, and view resolution all happen earlier, in
 * `Hedayati_Student_Portal::guard_account_page()` (hooked on `template_redirect`,
 * so it runs before this template is ever reached) — this file only renders.
 *
 * Reuses the theme's existing chrome (`get_header()`/`get_footer()`, the same
 * `#site-main` skip-link target and `.container` wrapper every other template
 * uses) rather than a bespoke wp-admin-like layout, per
 * docs/PHASE_2D_PLANNING.md §1 ("consistent with the existing Navigator
 * design", "should not need to use or resemble wp-admin").
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Student_Portal' ) ) {
	status_header( 503 ); get_header(); echo '<main id="site-main" class="section container"><p>حساب کاربری موقتاً در دسترس نیست.</p></main>'; get_footer(); return;
}
get_header();

$hd_current_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard';
if ( ! in_array( $hd_current_view, Hedayati_Student_Portal::VIEWS, true ) ) {
	$hd_current_view = 'dashboard';
}

$hd_nav_items = [
	'dashboard'     => __( 'داشبورد دانشجو', 'hedayati' ),
	'enrollments'   => __( 'دوره‌های من', 'hedayati' ),
	'schedule'      => __( 'برنامهٔ کلاس‌ها', 'hedayati' ),
	'certificates'  => __( 'گواهینامه‌های من', 'hedayati' ),
	'support'       => __( 'پشتیبانی و تیکت', 'hedayati' ),
	'notifications' => __( 'اعلان‌ها', 'hedayati' ),
	'verification'  => __( 'احراز هویت', 'hedayati' ),
	'documents'     => __( 'مدارک من', 'hedayati' ),
	'profile'       => __( 'پروفایل کاربری', 'hedayati' ),
];

$hd_unread = class_exists( 'Hedayati_Notification_Service' )
	? Hedayati_Notification_Service::unread_count( get_current_user_id() )
	: 0;
?>
<main id="site-main" class="hd-portal-main section hd-student-main" role="main" tabindex="-1">
	<div class="container hd-portal-shell hd-student-shell">

		<nav class="hd-portal-sidebar hd-student-sidebar" aria-label="<?php esc_attr_e( 'منوی حساب کاربری', 'hedayati' ); ?>">
			<div class="hd-manager-brand"><span aria-hidden="true">هـ</span><div><strong><?php esc_html_e( 'پنل دانشجویی', 'hedayati' ); ?></strong><small><?php esc_html_e( 'مجتمع دکتر هدایتی', 'hedayati' ); ?></small></div></div>
			<ul class="hd-portal-nav">
				<?php foreach ( $hd_nav_items as $hd_view_key => $hd_view_label ) : ?>
					<li>
						<a
							href="<?php echo esc_url( Hedayati_Student_Portal::get_account_url( $hd_view_key ) ); ?>"
							class="hd-portal-nav-link<?php echo $hd_view_key === $hd_current_view ? ' is-active' : ''; ?>"
							<?php echo $hd_view_key === $hd_current_view ? ' aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $hd_view_label ); ?>
							<?php if ( 'notifications' === $hd_view_key && $hd_unread > 0 ) : ?>
								<b class="hd-nav-badge"><?php echo esc_html( Hedayati_Text::digits_to_persian( (string) $hd_unread ) ); ?></b>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
				<li class="hd-portal-nav-site"><a class="hd-portal-nav-link" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'پشتیبانی و تماس', 'hedayati' ); ?></a></li>
				<li><a class="hd-portal-nav-link" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'مشاهدهٔ وب‌سایت', 'hedayati' ); ?></a></li>
				<li>
					<a class="hd-portal-nav-link hd-portal-nav-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
						<?php esc_html_e( 'خروج از پنل', 'hedayati' ); ?>
					</a>
				</li>
			</ul>
		</nav>

		<div class="hd-portal-content">
			<?php echo Hedayati_Student_Portal::render_notice(); // phpcs:ignore -- pre-escaped by render_notice(). ?>
			<?php echo Hedayati_Student_Portal::render_current_view(); // phpcs:ignore -- pre-escaped by each render_*_view() method. ?>
		</div>

	</div>
</main>
<?php
get_footer();
