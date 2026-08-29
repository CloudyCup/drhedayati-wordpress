<?php
/**
 * Front Page Template — Concept C / Navigator layout.
 *
 * WordPress calls this template when:
 *   a) A static front page is set in Settings > Reading, OR
 *   b) The latest posts are shown (before front-page is overridden).
 *
 * @package Hedayati
 */

get_header();
?>

<main id="site-main" role="main">

	<?php get_template_part( 'template-parts/hero-navigator' ); ?>

	<?php get_template_part( 'template-parts/category-strip' ); ?>

	<?php get_template_part( 'template-parts/featured-courses' ); ?>

	<?php get_template_part( 'template-parts/impact-section' ); ?>

	<?php get_template_part( 'template-parts/cta-band' ); ?>

</main>

<?php get_footer(); ?>
