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

get_header();

$hd_current_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'dashboard';
if ( ! in_array( $hd_current_view, Hedayati_Student_Portal::VIEWS, true ) ) {
	$hd_current_view = 'dashboard';
}

$hd_nav_items = [
	'dashboard'    => __( 'داشبورد', 'hedayati' ),
	'profile'      => __( 'پروفایل', 'hedayati' ),
	'verification' => __( 'احراز هویت', 'hedayati' ),
	'enrollments'  => __( 'دوره‌های من', 'hedayati' ),
	'documents'    => __( 'مدارک', 'hedayati' ),
];
?>
<main id="site-main" class="hd-portal-main section" role="main" tabindex="-1">
	<div class="container hd-portal-shell">

		<nav class="hd-portal-sidebar" aria-label="<?php esc_attr_e( 'منوی حساب کاربری', 'hedayati' ); ?>">
			<ul class="hd-portal-nav">
				<?php foreach ( $hd_nav_items as $hd_view_key => $hd_view_label ) : ?>
					<li>
						<a
							href="<?php echo esc_url( Hedayati_Student_Portal::get_account_url( $hd_view_key ) ); ?>"
							class="hd-portal-nav-link<?php echo $hd_view_key === $hd_current_view ? ' is-active' : ''; ?>"
							<?php echo $hd_view_key === $hd_current_view ? ' aria-current="page"' : ''; ?>
						>
							<?php echo esc_html( $hd_view_label ); ?>
						</a>
					</li>
				<?php endforeach; ?>
				<li>
					<a class="hd-portal-nav-link hd-portal-nav-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
						<?php esc_html_e( 'خروج', 'hedayati' ); ?>
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
