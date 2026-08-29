<?php
/**
 * Featured Courses Grid — 4-column, 2-row (max 8 courses).
 *
 * Retrieves featured courses via Hedayati_Query::get_featured_courses().
 * Renders nothing if the plugin is inactive or no featured courses exist.
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Query' ) ) {
	// Plugin not active — cannot retrieve courses. Show nothing.
	return;
}

$featured_query = Hedayati_Query::get_featured_courses( 8 );

if ( ! $featured_query->have_posts() ) {
	// No featured courses published yet.
	// Show an admin-only hint; public visitors see nothing.
	if ( current_user_can( 'edit_posts' ) ) : ?>
		<section class="section featured-showcase" aria-label="<?php esc_attr_e( 'دوره‌های ویژه', 'hedayati' ); ?>">
			<div class="container">
				<div class="empty-state admin-hint">
					<p>
						<?php esc_html_e( '(مدیر) هنوز دوره‌ای به عنوان «ویژه صفحه اصلی» علامت‌گذاری نشده است.', 'hedayati' ); ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=course' ) ); ?>">
							<?php esc_html_e( 'مدیریت دوره‌ها', 'hedayati' ); ?>
						</a>
					</p>
				</div>
			</div>
		</section>
	<?php endif;
	return;
}
?>

<section class="section featured-showcase" aria-labelledby="featured-courses-heading">
	<div class="container">
		<div class="section-heading row">
			<div>
				<span><?php esc_html_e( 'دوره‌های منتخب', 'hedayati' ); ?></span>
				<h2 id="featured-courses-heading">
					<?php esc_html_e( 'مسیرهای پیشنهادی برای شروع یا ارتقای مهارت', 'hedayati' ); ?>
				</h2>
				<p>
					<?php esc_html_e( 'مجموعه‌ای از دوره‌های پرتقاضا در حوزه شبکه، برنامه‌نویسی، هوش مصنوعی، طراحی و مهارت‌های پایه.', 'hedayati' ); ?>
				</p>
			</div>
			<a
				href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"
				class="outline-btn"
			>
				<?php esc_html_e( 'مشاهده همه دوره‌ها', 'hedayati' ); ?>
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			</a>
		</div>

		<div class="featured-course-grid" role="list">
			<?php
			while ( $featured_query->have_posts() ) {
				$featured_query->the_post();
				echo '<div role="listitem">';
				get_template_part( 'template-parts/course-card' );
				echo '</div>';
			}
			wp_reset_postdata();
			?>
		</div>

	</div>
</section>
