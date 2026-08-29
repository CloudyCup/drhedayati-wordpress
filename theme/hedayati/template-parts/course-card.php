<?php
/**
 * Course Card — reusable card partial.
 *
 * Must be called inside a WordPress loop (have_posts() + the_post()).
 * Reads all data from post meta and taxonomy — no hardcoded values.
 *
 * @package Hedayati
 */

$post_id       = get_the_ID();
$permalink     = get_permalink();
$title         = get_the_title();
$excerpt       = get_the_excerpt();
$english_name  = (string) get_post_meta( $post_id, '_course_english_name', true );
$duration      = (string) get_post_meta( $post_id, '_course_duration', true );
$level         = (string) get_post_meta( $post_id, '_course_level', true );
$reg_state_raw = (string) get_post_meta( $post_id, '_course_registration_state', true ) ?: 'soon';
$reg_display   = hedayati_registration_state_display( $reg_state_raw );
$monogram      = hedayati_course_monogram( $post_id );

// Course category — for topline badge
$terms         = get_the_terms( $post_id, 'course-category' );
$category_name = ( ! is_wp_error( $terms ) && ! empty( $terms ) )
	? $terms[0]->name
	: '';
$category_slug = ( ! is_wp_error( $terms ) && ! empty( $terms ) )
	? $terms[0]->slug
	: '';

// Thumbnail
$has_thumbnail = has_post_thumbnail( $post_id );
?>

<article
	class="course-card"
	aria-label="<?php echo esc_attr( $title ); ?>"
>
	<!-- Topline: category and English name -->
	<div class="course-topline">
		<?php if ( $category_name ) : ?>
			<span class="course-index-tag"><?php echo esc_html( strtoupper( $category_slug ) ); ?></span>
		<?php endif; ?>
		<?php if ( $english_name ) : ?>
			<span class="course-english-tag" dir="ltr"><?php echo esc_html( strtoupper( $english_name ) ); ?></span>
		<?php endif; ?>
	</div>

	<!-- Art panel: featured image or dark monogram panel -->
	<div class="course-art">
		<?php if ( $has_thumbnail ) : ?>
			<a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
				<?php the_post_thumbnail( 'course-card', [ 'class' => 'course-thumbnail', 'alt' => '' ] ); ?>
			</a>
		<?php else : ?>
			<div class="course-art-bg" aria-hidden="true"></div>
			<span class="course-monogram" aria-hidden="true"><?php echo $monogram; /* already escaped in helper */ ?></span>
		<?php endif; ?>
	</div>

	<!-- Card body -->
	<div class="course-body">
		<!-- Meta pills -->
		<?php if ( $level || $duration ) : ?>
			<div class="course-meta">
				<?php if ( $level ) : ?>
					<span class="meta-pill"><?php echo esc_html( $level ); ?></span>
				<?php endif; ?>
				<?php if ( $duration ) : ?>
					<span class="meta-pill"><?php echo esc_html( $duration ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h3 class="course-title">
			<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
		</h3>

		<?php if ( $excerpt ) : ?>
			<p class="course-excerpt"><?php echo esc_html( $excerpt ); ?></p>
		<?php endif; ?>

		<!-- Footer: registration state + CTA -->
		<div class="course-footer">
			<span class="seats-badge <?php echo esc_attr( $reg_display['class'] ); ?>">
				<i class="state-dot" aria-hidden="true"></i>
				<?php echo esc_html( $reg_display['label'] ); ?>
			</span>
			<a href="<?php echo esc_url( $permalink ); ?>" class="card-action-btn">
				<?php esc_html_e( 'مشاهده دوره', 'hedayati' ); ?>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			</a>
		</div>
	</div><!-- .course-body -->
</article>
