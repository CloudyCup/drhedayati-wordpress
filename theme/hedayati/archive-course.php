<?php
/**
 * Course Archive Template — primary template for:
 *   - /courses/             (post_type_archive)
 *   - /course-category/*   (tax archive)
 *
 * @package Hedayati
 */

get_header();

$current_term  = is_tax( 'course-category' ) ? get_queried_object() : null;
$archive_title = $current_term
	? esc_html( single_term_title( '', false ) )
	: esc_html__( 'همه دوره‌های آموزشی', 'hedayati' );
$archive_desc  = $current_term && $current_term->description
	? esc_html( $current_term->description )
	: '';
?>

<main id="site-main" class="course-archive-main" role="main">

	<!-- Archive page hero -->
	<section class="page-hero" aria-labelledby="archive-title">
		<div class="container">
			<nav class="breadcrumb" aria-label="<?php esc_attr_e( 'مسیر صفحه', 'hedayati' ); ?>">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'خانه', 'hedayati' ); ?></a>
				<span aria-hidden="true">›</span>
				<?php if ( $current_term ) : ?>
					<a href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"><?php esc_html_e( 'دوره‌ها', 'hedayati' ); ?></a>
					<span aria-hidden="true">›</span>
					<span aria-current="page"><?php echo $archive_title; ?></span>
				<?php else : ?>
					<span aria-current="page"><?php echo $archive_title; ?></span>
				<?php endif; ?>
			</nav>

			<h1 id="archive-title"><?php echo $archive_title; ?></h1>
			<?php if ( $archive_desc ) : ?>
				<p class="archive-description"><?php echo $archive_desc; ?></p>
			<?php endif; ?>
		</div>
	</section>

	<!-- Category filter strip -->
	<?php if ( class_exists( 'Hedayati_Query' ) ) :
		$nav_terms = Hedayati_Query::get_nav_categories();
		if ( ! empty( $nav_terms ) ) : ?>
			<section class="archive-filter-bar" aria-label="<?php esc_attr_e( 'فیلتر دسته‌بندی', 'hedayati' ); ?>">
				<div class="container">
					<div class="filter-row" role="list">
						<a
							href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>"
							class="filter-chip <?php echo ! $current_term ? 'active' : ''; ?>"
							role="listitem"
							aria-current="<?php echo ! $current_term ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'همه', 'hedayati' ); ?>
						</a>
						<?php foreach ( $nav_terms as $term ) : ?>
							<a
								href="<?php echo esc_url( get_term_link( $term ) ); ?>"
								class="filter-chip <?php echo ( $current_term && $current_term->term_id === $term->term_id ) ? 'active' : ''; ?>"
								role="listitem"
								aria-current="<?php echo ( $current_term && $current_term->term_id === $term->term_id ) ? 'true' : 'false'; ?>"
							>
								<?php echo esc_html( $term->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
		<?php endif;
	endif; ?>

	<!-- Course grid -->
	<section class="course-browser section">
		<div class="container">
			<?php if ( have_posts() ) : ?>
				<div class="courses-grid" role="list">
					<?php while ( have_posts() ) :
						the_post();
						?>
						<div role="listitem">
							<?php get_template_part( 'template-parts/course-card' ); ?>
						</div>
					<?php endwhile; ?>
				</div>

				<!-- Pagination -->
				<nav class="archive-pagination" aria-label="<?php esc_attr_e( 'صفحات آرشیو', 'hedayati' ); ?>">
					<?php
					the_posts_pagination( [
						'mid_size'  => 2,
						'prev_text' => esc_html__( 'قبلی', 'hedayati' ),
						'next_text' => esc_html__( 'بعدی', 'hedayati' ),
					] );
					?>
				</nav>

			<?php else : ?>
				<div class="empty-state" role="status">
					<p><?php esc_html_e( 'دوره‌ای در این دسته‌بندی یافت نشد.', 'hedayati' ); ?></p>
					<a href="<?php echo esc_url( get_post_type_archive_link( 'course' ) ); ?>" class="outline-btn">
						<?php esc_html_e( 'مشاهده همه دوره‌ها', 'hedayati' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</section>

</main>

<?php get_footer(); ?>
