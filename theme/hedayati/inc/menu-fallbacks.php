<?php
/**
 * Primary menu fallback: renders a minimal text menu when no menu is assigned.
 * Used by wp_nav_menu() in header.php.
 *
 * @package Hedayati
 */
function hedayati_primary_menu_fallback(): void {
	?>
	<ul class="primary-menu primary-menu--fallback">
		<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحه اصلی', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>"><?php esc_html_e( 'دوره‌ها', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'درباره مجتمع', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'تماس', 'hedayati' ); ?></a></li>
	</ul>
	<?php
}

/**
 * Footer "quick links" fallback: the same five links the footer used to
 * render as hardcoded markup, now the default content of the `footer` nav
 * menu location (registered in functions.php but unused until D55) when no
 * menu has been assigned to it yet. Used by wp_nav_menu() in footer.php.
 */
function hedayati_footer_menu_fallback(): void {
	?>
	<ul class="footer-links footer-links--fallback">
		<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'صفحه اصلی', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>"><?php esc_html_e( 'دوره‌های آموزشی', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>"><?php esc_html_e( 'درباره مجتمع', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'تماس با ما', 'hedayati' ); ?></a></li>
		<li><a href="<?php echo esc_url( home_url( '/consult/' ) ); ?>"><?php esc_html_e( 'مشاوره ثبت‌نام', 'hedayati' ); ?></a></li>
	</ul>
	<?php
}
