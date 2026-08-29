<?php
/**
 * Fallback index template — WordPress requires this file.
 * Used only when no more specific template is found in the template hierarchy.
 *
 * @package Hedayati
 */

get_header();
?>

<main id="site-main" class="site-main section" role="main">
	<div class="container">
		<?php if ( have_posts() ) : ?>
			<div class="posts-grid">
				<?php while ( have_posts() ) : the_post(); ?>
					<article <?php post_class( 'post-card' ); ?> id="post-<?php the_ID(); ?>">
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="post-thumbnail" aria-hidden="true" tabindex="-1">
								<?php the_post_thumbnail( 'medium' ); ?>
							</a>
						<?php endif; ?>
						<div class="post-card-body">
							<header class="post-card-header">
								<h2 class="post-card-title">
									<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
								</h2>
								<time class="post-card-date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
									<?php echo esc_html( get_the_date() ); ?>
								</time>
							</header>
							<div class="post-card-excerpt">
								<?php the_excerpt(); ?>
							</div>
							<a href="<?php the_permalink(); ?>" class="read-more-link">
								<?php esc_html_e( 'ادامه مطلب', 'hedayati' ); ?>
							</a>
						</div>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination( [
				'prev_text' => esc_html__( 'قبلی', 'hedayati' ),
				'next_text' => esc_html__( 'بعدی', 'hedayati' ),
			] ); ?>
		<?php else : ?>
			<div class="empty-state">
				<p><?php esc_html_e( 'محتوایی یافت نشد.', 'hedayati' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</main>

<?php get_footer(); ?>
