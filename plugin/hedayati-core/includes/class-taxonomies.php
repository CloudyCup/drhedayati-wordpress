<?php
/**
 * Registers the Course Category hierarchical taxonomy.
 *
 * @package Hedayati_Core
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Hedayati_Taxonomies {

	/**
	 * Register the `course-category` taxonomy.
	 */
	public static function register(): void {
		$labels = [
			'name'                       => 'دسته‌بندی دوره‌ها',
			'singular_name'              => 'دسته‌بندی دوره',
			'search_items'               => 'جستجو در دسته‌بندی‌ها',
			'all_items'                  => 'همه دسته‌بندی‌ها',
			'parent_item'                => 'دسته‌بندی مادر',
			'parent_item_colon'          => 'دسته‌بندی مادر:',
			'edit_item'                  => 'ویرایش دسته‌بندی',
			'view_item'                  => 'مشاهده دسته‌بندی',
			'update_item'                => 'به‌روزرسانی دسته‌بندی',
			'add_new_item'               => 'افزودن دسته‌بندی جدید',
			'new_item_name'              => 'نام دسته‌بندی جدید',
			'not_found'                  => 'دسته‌بندی یافت نشد.',
			'no_terms'                   => 'دسته‌بندی وجود ندارد.',
			'menu_name'                  => 'دسته‌بندی‌ها',
			'items_list_navigation'      => 'پیمایش دسته‌بندی‌ها',
			'items_list'                 => 'فهرست دسته‌بندی‌ها',
			'back_to_items'              => 'بازگشت به دسته‌بندی‌ها',
			'item_link'                  => 'پیوند دسته‌بندی دوره',
			'item_link_description'      => 'پیوند به یک دسته‌بندی دوره آموزشی.',
		];

		$args = [
			'labels'            => $labels,
			'hierarchical'      => true,   // Category-like, not tag-like
			'public'            => true,
			'show_ui'           => true,
			'show_in_menu'      => true,
			'show_in_nav_menus' => true,
			'show_in_rest'      => true,   // Block editor support
			'show_admin_column' => true,   // Column in CPT list table
			'query_var'         => true,
			'rewrite'           => [
				'slug'         => 'course-category',
				'with_front'   => false,
				'hierarchical' => true,
			],
			'sort'              => false,
		];

		register_taxonomy( 'course-category', [ 'course' ], $args );
	}
}
