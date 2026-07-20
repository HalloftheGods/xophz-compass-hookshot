<?php

/**
 * Registers the Custom Post Types for Hookshot.
 *
 * @link       https://www.hallofthegods.com
 * @since      1.0.0
 * @package    Xophz_Compass_Hookshot
 * @subpackage Xophz_Compass_Hookshot/includes
 */

class Xophz_Compass_Hookshot_CPT {

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function init() {
		add_action( 'init', [ $this, 'register_webhooks_cpt' ], 0 );
		add_action( 'init', [ $this, 'register_webhook_logs_cpt' ], 0 );
		add_action( 'init', [ $this, 'register_taxonomy' ], 0 );
	}

	public function register_taxonomy() {
		register_taxonomy( 'hookshot_category', 'compass_webhook', [
			'labels'            => [
				'name'          => 'Webhook Categories',
				'singular_name' => 'Category',
				'search_items'  => 'Search Categories',
				'all_items'     => 'All Categories',
				'edit_item'     => 'Edit Category',
				'update_item'   => 'Update Category',
				'add_new_item'  => 'Add New Category',
				'new_item_name' => 'New Category Name',
				'menu_name'     => 'Categories',
			],
			'hierarchical'      => true,
			'public'            => false,
			'show_ui'           => true,
			'show_in_menu'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
		] );
	}

	/**
	 * Register the Webhook Configuration CPT.
	 */
	public function register_webhooks_cpt() {
		$labels = [
			'name'                  => _x( 'Webhooks', 'Post Type General Name', 'xophz-compass-hookshot' ),
			'singular_name'         => _x( 'Webhook', 'Post Type Singular Name', 'xophz-compass-hookshot' ),
			'menu_name'             => __( 'Webhooks', 'xophz-compass-hookshot' ),
			'name_admin_bar'        => __( 'Webhook', 'xophz-compass-hookshot' ),
			'archives'              => __( 'Webhook Archives', 'xophz-compass-hookshot' ),
			'attributes'            => __( 'Webhook Attributes', 'xophz-compass-hookshot' ),
			'parent_item_colon'     => __( 'Parent Webhook:', 'xophz-compass-hookshot' ),
			'all_items'             => __( 'All Webhooks', 'xophz-compass-hookshot' ),
			'add_new_item'          => __( 'Add New Webhook', 'xophz-compass-hookshot' ),
			'add_new'               => __( 'Add New', 'xophz-compass-hookshot' ),
			'new_item'              => __( 'New Webhook', 'xophz-compass-hookshot' ),
			'edit_item'             => __( 'Edit Webhook', 'xophz-compass-hookshot' ),
			'update_item'           => __( 'Update Webhook', 'xophz-compass-hookshot' ),
			'view_item'             => __( 'View Webhook', 'xophz-compass-hookshot' ),
			'view_items'            => __( 'View Webhooks', 'xophz-compass-hookshot' ),
			'search_items'          => __( 'Search Webhook', 'xophz-compass-hookshot' ),
			'not_found'             => __( 'Not found', 'xophz-compass-hookshot' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'xophz-compass-hookshot' ),
		];

		$args = [
			'label'                 => __( 'Webhook', 'xophz-compass-hookshot' ),
			'description'           => __( 'Hookshot Webhook Configurations', 'xophz-compass-hookshot' ),
			'labels'                => $labels,
			'supports'              => [ 'title' ],
			'hierarchical'          => false,
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => 'xophz-compass', // Put it under the main COMPASS menu
			'menu_position'         => 30,
			'show_in_admin_bar'     => false,
			'show_in_nav_menus'     => false,
			'can_export'            => true,
			'has_archive'           => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => false,
			'rewrite'               => false,
			'capability_type'       => 'post',
			'show_in_rest'          => true, // Allow REST API access for the editor if needed
		];

		register_post_type( 'compass_webhook', $args );
	}

	/**
	 * Register the Webhook Logs CPT.
	 */
	public function register_webhook_logs_cpt() {
		$labels = [
			'name'                  => _x( 'Webhook Logs', 'Post Type General Name', 'xophz-compass-hookshot' ),
			'singular_name'         => _x( 'Webhook Log', 'Post Type Singular Name', 'xophz-compass-hookshot' ),
			'menu_name'             => __( 'Webhook Logs', 'xophz-compass-hookshot' ),
			'all_items'             => __( 'Webhook Logs', 'xophz-compass-hookshot' ),
			'view_item'             => __( 'View Log', 'xophz-compass-hookshot' ),
			'search_items'          => __( 'Search Logs', 'xophz-compass-hookshot' ),
			'not_found'             => __( 'No logs found', 'xophz-compass-hookshot' ),
			'not_found_in_trash'    => __( 'No logs found in Trash', 'xophz-compass-hookshot' ),
		];

		$args = [
			'label'                 => __( 'Webhook Log', 'xophz-compass-hookshot' ),
			'description'           => __( 'Logs for incoming and outgoing Magic Hookshot.', 'xophz-compass-hookshot' ),
			'labels'                => $labels,
			'supports'              => [ 'title' ],
			'hierarchical'          => false,
			'public'                => false,
			'show_ui'               => true,
			'show_in_menu'          => 'xophz-compass', // Under COMPASS menu
			'show_in_admin_bar'     => false,
			'show_in_nav_menus'     => false,
			'can_export'            => false,
			'has_archive'           => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => false,
			'rewrite'               => false,
			'capability_type'       => 'post',
			'capabilities'          => [
				'create_posts' => 'do_not_allow', // Prevents manual creation of logs via UI
			],
			'map_meta_cap'          => true,
		];

		register_post_type( 'compass_wh_log', $args );
	}
}
