<?php
/**
 * Content types for the forms and the requests.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the form and request post types.
 *
 * Forms are set up by the site team. Requests are only created by visitors
 * through the REST route, saved as private posts, and hold personal data:
 * they are never public and never exposed in the REST API.
 */
final class Post_Types {

	const FORM    = 'primiko_form';
	const REQUEST = 'primiko_request';

	/**
	 * Request statuses, stored in post meta.
	 */
	const STATUS_NEW  = 'new';
	const STATUS_DONE = 'done';

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * The Primiko spirit level in one colour, for the admin menu. WordPress
	 * recolours it to match the admin colour scheme.
	 *
	 * @return string Data URI.
	 */
	private static function menu_icon() {
		return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTEuNSA2LjVBMSAxIDAgMCAxIDIuNSA1LjVIMTcuNUExIDEgMCAwIDEgMTguNSA2LjVWMTMuNUExIDEgMCAwIDEgMTcuNSAxNC41SDIuNUExIDEgMCAwIDEgMS41IDEzLjVaTTcgNy43NUEyLjI1IDIuMjUgMCAwIDAgNyAxMi4yNUgxM0EyLjI1IDIuMjUgMCAwIDAgMTMgNy43NVoiLz48ZWxsaXBzZSBmaWxsPSJibGFjayIgY3g9IjEwIiBjeT0iMTAiIHJ4PSIxLjQiIHJ5PSIxIi8+PC9zdmc+';
	}

	/**
	 * Registers both post types.
	 */
	public function register() {
		register_post_type(
			self::REQUEST,
			array(
				'labels'          => array(
					'name'               => __( 'Quote requests', 'primiko' ),
					'singular_name'      => __( 'Quote request', 'primiko' ),
					'menu_name'          => _x( 'Quotes', 'admin menu', 'primiko' ),
					'all_items'          => __( 'Requests', 'primiko' ),
					'edit_item'          => __( 'Quote request', 'primiko' ),
					'search_items'       => __( 'Search requests', 'primiko' ),
					'not_found'          => __( 'No request yet.', 'primiko' ),
					'not_found_in_trash' => __( 'No request in the trash.', 'primiko' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'show_in_rest'    => false,
				'menu_icon'       => self::menu_icon(),
				'menu_position'   => 26,
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'capabilities'    => array(
					'create_posts' => 'do_not_allow',
				),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);

		register_post_type(
			self::FORM,
			array(
				'labels'          => array(
					'name'          => __( 'Quote forms', 'primiko' ),
					'singular_name' => __( 'Quote form', 'primiko' ),
					'all_items'     => __( 'Forms', 'primiko' ),
					'add_new'       => __( 'Add form', 'primiko' ),
					'add_new_item'  => __( 'Add a quote form', 'primiko' ),
					'edit_item'     => __( 'Edit quote form', 'primiko' ),
					'new_item'      => __( 'New quote form', 'primiko' ),
					'search_items'  => __( 'Search forms', 'primiko' ),
					'not_found'     => __( 'No form yet.', 'primiko' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::REQUEST,
				// The block lists the forms through its own route, for editors only.
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}
}
