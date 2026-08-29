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
