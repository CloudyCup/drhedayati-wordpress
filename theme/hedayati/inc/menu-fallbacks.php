<?php
/**
 * Primary menu fallback: renders a minimal text menu when no menu is assigned.
 * Used by wp_nav_menu() in header.php.
 *
 * @package Hedayati
 */
function hedayati_primary_menu_fallback(): void {
	?>
	<ul class="primary-menu primary-menu--fallback" role="menubar">
		<li role="none"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" role="menuitem"><?php esc_html_e( 'صفحه اصلی', 'hedayati' ); ?></a></li>
		<li role="none"><a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>" role="menuitem"><?php esc_html_e( 'دوره‌ها', 'hedayati' ); ?></a></li>
		<li role="none"><a href="<?php echo esc_url( home_url( '/about/' ) ); ?>" role="menuitem"><?php esc_html_e( 'درباره مجتمع', 'hedayati' ); ?></a></li>
		<li role="none"><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>" role="menuitem"><?php esc_html_e( 'تماس', 'hedayati' ); ?></a></li>
	</ul>
	<?php
}
