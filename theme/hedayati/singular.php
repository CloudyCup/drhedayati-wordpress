<?php
/**
 * Generic singular fallback — used for pages and non-course post types.
 * Courses use single-course.php instead.
 *
 * @package Hedayati
 */

get_header();

if ( have_posts() ) :
	the_post();
	?>
	<main id="site-main" class="singular-main section" role="main" tabindex="-1">
		<div class="container singular-container">
			<article <?php post_class( 'entry' ); ?> id="post-<?php the_ID(); ?>">
				<header class="entry-header">
					<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
					<?php if ( ! is_page() ) : ?>
						<div class="entry-meta">
							<time class="entry-date" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
								<?php echo esc_html( get_the_date() ); ?>
							</time>
						</div>
					<?php endif; ?>
				</header>
				<?php if ( has_post_thumbnail() ) : ?>
					<div class="entry-thumbnail">
						<?php the_post_thumbnail( 'course-hero' ); ?>
					</div>
				<?php endif; ?>
				<div class="entry-content">
					<?php the_content(); ?>
				</div>
			</article>
		</div>
	</main>
<?php endif;

get_footer(); ?>
