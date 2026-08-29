<?php
/**
 * Query helper functions for the Hedayati theme and other consumers.
 *
 * Provides a stable interface for course retrieval so that:
 *   - The theme can call Hedayati_Query::get_featured_courses() without
 *     knowing internal meta key names.
 *   - If meta key names change, only this class needs updating.
 *   - Course data persists independently of which theme is active.
 *
 * Category ordering: driven by Hedayati_Term_Meta::META_ORDER (course_cat_order)
 * integer term meta set in the admin. Lower number = displayed first.
 * Secondary sort: name ASC for deterministic output when order values are equal.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Query {

	/**
	 * Retrieve featured courses for homepage display.
	 *
	 * Returns published `course` posts where `_course_is_featured` is true,
	 * ordered by `menu_order` ascending (set via the meta box "ترتیب نمایش"
	 * field which writes to wp_posts.menu_order directly).
	 *
	 * Secondary order: date DESC — newest featured course first when two
	 * courses share the same menu_order value.
	 *
	 * Results are hard-capped at 8.
	 * The caller is responsible for calling wp_reset_postdata() after iterating.
	 *
	 * @param int $limit Number of courses to return. Maximum 8.
	 * @return WP_Query
	 */
	public static function get_featured_courses( int $limit = 8 ): WP_Query {
		$limit = min( max( 1, $limit ), 8 );

		return new WP_Query( [
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => [
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			],
			'no_found_rows'  => true, // Skip COUNT() — homepage doesn't need pagination
			'meta_query'     => [
				[
					'key'     => '_course_is_featured',
					'value'   => '1',
					'compare' => '=',
				],
			],
		] );
	}

	/**
	 * Retrieve courses by a course-category taxonomy term.
	 *
	 * @param string $slug           Term slug to filter by.
	 * @param int    $posts_per_page Max results.
	 * @param int    $paged          Page number (for archive pagination).
	 * @return WP_Query
	 */
	public static function get_courses_by_category(
		string $slug,
		int $posts_per_page = 12,
		int $paged = 1
	): WP_Query {
		return new WP_Query( [
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => $posts_per_page,
			'paged'          => $paged,
			'orderby'        => [
				'menu_order' => 'ASC',
				'date'       => 'DESC',
			],
			'tax_query'      => [
				[
					'taxonomy' => 'course-category',
					'field'    => 'slug',
					'terms'    => $slug,
				],
			],
		] );
	}

	/**
	 * Retrieve top-level course-category terms for navigation.
	 *
	 * Ordering: driven by the 'course_cat_order' integer term meta
	 * (set via Hedayati_Term_Meta). Lower number = displayed first.
	 * Terms with no order meta are treated as order 0.
	 * Secondary sort: name ASC for deterministic output.
	 *
	 * NOTE: WordPress core's get_terms() 'orderby' parameter does not support
	 * arbitrary term meta ordering natively. We retrieve terms and sort in PHP
	 * to avoid relying on 'term_order' (which maps to term_id when no
	 * object_ids are supplied).
	 *
	 * Returns an empty array if no terms exist.
	 *
	 * @param int $limit Maximum number of terms to return.
	 * @return WP_Term[]
	 */
	public static function get_nav_categories( int $limit = 8 ): array {
		$terms = get_terms( [
			'taxonomy'   => 'course-category',
			'parent'     => 0,        // top-level only
			'hide_empty' => false,    // show even if no courses assigned yet
			'orderby'    => 'name',   // base stable sort; we re-sort below
			'order'      => 'ASC',
			'number'     => 0,        // retrieve all; we apply $limit after sort
		] );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		// Attach display order from term meta
		foreach ( $terms as $term ) {
			$raw_order        = get_term_meta( $term->term_id, 'course_cat_order', true );
			$term->hd_display_order = is_numeric( $raw_order ) ? (int) $raw_order : 0;
		}

		// Sort: primary by display order ASC, secondary by name ASC
		usort( $terms, function ( WP_Term $a, WP_Term $b ): int {
			$order_diff = $a->hd_display_order <=> $b->hd_display_order;
			if ( 0 !== $order_diff ) {
				return $order_diff;
			}
			return strcmp( $a->name, $b->name );
		} );

		return array_slice( $terms, 0, $limit );
	}
}
