<?php
/**
 * Generic archive fallback — used for post archives, author, date, etc.
 * Course archives use archive-course.php instead.
 *
 * @package Hedayati
 */

get_header();
?>
<main id="site-main" class="archive-main section" role="main" tabindex="-1">
	<div class="container">
		<header class="page-hero">
			<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		</header>
		<?php if ( have_posts() ) : ?>
			<div class="posts-grid">
				<?php while ( have_posts() ) : the_post(); ?>
					<article <?php post_class( 'post-card' ); ?>>
						<h2 class="post-card-title">
							<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
						</h2>
						<div class="post-card-excerpt"><?php the_excerpt(); ?></div>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<div class="empty-state">
				<p><?php esc_html_e( 'محتوایی یافت نشد.', 'hedayati' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php get_footer(); ?>
