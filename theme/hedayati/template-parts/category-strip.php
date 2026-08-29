<?php
/**
 * Category Strip — 4-column horizontal navigation bar.
 *
 * Displays all top-level course-category terms.
 * Renders nothing if no terms exist (no hardcoded fallback categories).
 *
 * @package Hedayati
 */

if ( ! class_exists( 'Hedayati_Query' ) ) {
	return;
}

$terms = Hedayati_Query::get_nav_categories( 8 );

if ( empty( $terms ) ) {
	// No terms exist yet — render nothing.
	// The hero console already shows an admin-friendly empty state.
	return;
}

$archive_url = get_post_type_archive_link( 'course' );
?>

<section class="navigator-quick" aria-label="<?php esc_attr_e( 'دسته‌بندی دوره‌ها', 'hedayati' ); ?>">
	<div class="container">
		<nav class="category-strip" aria-label="<?php esc_attr_e( 'فیلتر دپارتمان‌ها', 'hedayati' ); ?>">
			<?php foreach ( $terms as $term ) :
				$term_link = get_term_link( $term );
				if ( is_wp_error( $term_link ) ) continue;

				// Icon: uses term_meta 'course_cat_icon' if available, falls back to initials
				$icon_char = get_term_meta( $term->term_id, 'course_cat_icon', true );
				if ( ! $icon_char ) {
					// Use first character of term name as a text icon
					$icon_char = mb_substr( $term->name, 0, 1 );
				}

				// English label from term_meta (optional, for display alongside Persian)
				$english_label = get_term_meta( $term->term_id, 'course_cat_english', true );

				$is_current = is_tax( 'course-category', $term->term_id );
				?>
				<a
					href="<?php echo esc_url( $term_link ); ?>"
					class="category-strip-item<?php echo $is_current ? ' is-current' : ''; ?>"
					aria-current="<?php echo $is_current ? 'page' : 'false'; ?>"
				>
					<span class="cat-icon" aria-hidden="true"><?php echo esc_html( $icon_char ); ?></span>
					<span class="cat-label">
						<b><?php echo esc_html( $term->name ); ?></b>
						<?php if ( $english_label ) : ?>
							<small><?php echo esc_html( $english_label ); ?></small>
						<?php endif; ?>
					</span>
					<svg class="cat-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
				</a>
			<?php endforeach; ?>
		</nav>
	</div>
</section>
