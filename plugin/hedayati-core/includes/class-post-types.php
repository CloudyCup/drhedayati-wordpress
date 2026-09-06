<?php
/**
 * Registers the Course custom post type.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Post_Types {

	/**
	 * Register the `course` CPT.
	 */
	public static function register(): void {
		$labels = [
			'name'                  => 'دوره‌های آموزشی',
			'singular_name'         => 'دوره آموزشی',
			'add_new'               => 'افزودن دوره',
			'add_new_item'          => 'افزودن دوره جدید',
			'edit_item'             => 'ویرایش دوره',
			'new_item'              => 'دوره جدید',
			'view_item'             => 'مشاهده دوره',
			'view_items'            => 'مشاهده دوره‌ها',
			'search_items'          => 'جستجو در دوره‌ها',
			'not_found'             => 'دوره‌ای یافت نشد.',
			'not_found_in_trash'    => 'موردی در زباله‌دان یافت نشد.',
			'all_items'             => 'همه دوره‌ها',
			'menu_name'             => 'دوره‌ها',
			'name_admin_bar'        => 'دوره آموزشی',
			'archives'              => 'آرشیو دوره‌ها',
			'attributes'            => 'ویژگی‌های دوره',
			'parent_item_colon'     => 'دوره مادر:',
			'item_updated'          => 'دوره به‌روز شد.',
			'filter_items_list'     => 'فیلتر دوره‌ها',
			'items_list_navigation' => 'پیمایش دوره‌ها',
			'items_list'            => 'فهرست دوره‌ها',
			'item_published'        => 'دوره منتشر شد.',
			'item_published_privately' => 'دوره به‌صورت خصوصی منتشر شد.',
			'item_reverted_to_draft'   => 'دوره به پیش‌نویس بازگردانده شد.',
			'item_scheduled'           => 'دوره زمان‌بندی شد.',
			'item_trashed'             => 'دوره به زباله‌دان منتقل شد.',
		];

		$args = [
			'labels'             => $labels,
			'description'        => 'دوره‌های آموزشی مجتمع دکتر هدایتی',
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => true,
			'show_in_admin_bar'  => true,
			'show_in_rest'       => true,         // Block editor + REST API
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-welcome-learn-more',
			'capability_type'    => [ 'hedayati_course', 'hedayati_courses' ],
			'map_meta_cap'       => true,
			'capabilities'       => [
				'edit_post'              => 'edit_hedayati_course',
				'read_post'              => 'read_hedayati_course',
				'delete_post'            => 'delete_hedayati_course',
				'edit_posts'             => 'hedayati_manage_courses',
				'edit_others_posts'      => 'hedayati_manage_courses',
				'publish_posts'          => 'hedayati_manage_courses',
				'read_private_posts'     => 'hedayati_manage_courses',
				'delete_posts'           => 'hedayati_manage_courses',
				'delete_private_posts'   => 'hedayati_manage_courses',
				'delete_published_posts' => 'hedayati_manage_courses',
				'delete_others_posts'    => 'hedayati_manage_courses',
				'edit_private_posts'     => 'hedayati_manage_courses',
				'edit_published_posts'   => 'hedayati_manage_courses',
				'create_posts'           => 'hedayati_manage_courses',
				'read'                   => 'read',
			],
			'hierarchical'       => false,
			'supports'           => [
				'title',
				'editor',
				'excerpt',
				'thumbnail',
				'custom-fields',
				'page-attributes', // Enables menu_order for admin-editable ordering
				'revisions',
			],
			'taxonomies'         => [ 'course-category' ],
			'has_archive'        => 'courses',
			'rewrite'            => [
				'slug'       => 'course',
				'with_front' => false,
				'feeds'      => false,
			],
			'query_var'          => true,
			'delete_with_user'   => false,
		];

		register_post_type( 'course', $args );
	}
}
