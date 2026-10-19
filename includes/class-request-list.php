<?php
/**
 * Request list.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Columns, status views and bulk actions of the request list, and the count
 * of new requests in the menu.
 */
final class Request_List {

	const STATUS_ARG = 'primiko_status';

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		$type = Post_Types::REQUEST;

		add_filter( "manage_{$type}_posts_columns", array( $this, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		add_filter( "views_edit-{$type}", array( $this, 'views' ) );
		add_filter( "bulk_actions-edit-{$type}", array( $this, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$type}", array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'pre_get_posts', array( $this, 'filter_status' ) );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_action( 'admin_menu', array( $this, 'menu_count' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ) );
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'post_date_column_status', array( $this, 'date_label' ), 10, 2 );
	}

	/**
	 * Drops the Private mark after each request: every request is private.
	 *
	 * @param string[] $states Post states.
	 * @param \WP_Post $post   Post.
	 * @return string[]
	 */
	public function post_states( $states, $post ) {
		if ( Post_Types::REQUEST === $post->post_type ) {
			unset( $states['private'] );
		}

		return $states;
	}

	/**
	 * Labels the date as the day the request was received, not modified.
	 *
	 * @param string   $label Date label.
	 * @param \WP_Post $post  Post.
	 * @return string
	 */
	public function date_label( $label, $post ) {
		return Post_Types::REQUEST === $post->post_type ? __( 'Received', 'primiko' ) : $label;
	}

	/**
	 * Adds the columns after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		$added  = array(
			'primiko_trade'  => __( 'Trade', 'primiko' ),
			'primiko_city'   => __( 'Town', 'primiko' ),
			'primiko_photos' => __( 'Photos', 'primiko' ),
			'primiko_status' => __( 'Status', 'primiko' ),
		);
		$result = array();

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( 'title' === $key ) {
				$result = array_merge( $result, $added );
			}
		}

		return $result;
	}

	/**
	 * Prints a column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Request post ID.
	 */
	public function column( $column, $post_id ) {
		switch ( $column ) {
			case 'primiko_trade':
				// Current name of the template, or the name saved with the request.
				$choices  = primiko()->templates->choices();
				$template = (string) get_post_meta( $post_id, '_primiko_template', true );

				echo esc_html( isset( $choices[ $template ] ) ? $choices[ $template ] : (string) get_post_meta( $post_id, '_primiko_trade', true ) );
				break;

			case 'primiko_city':
				echo esc_html( (string) get_post_meta( $post_id, '_primiko_city', true ) );
				break;

			case 'primiko_photos':
				$count = count( (array) get_post_meta( $post_id, '_primiko_photos', true ) );
				echo $count ? esc_html( number_format_i18n( $count ) ) : '<span aria-hidden="true">-</span><span class="screen-reader-text">' . esc_html__( 'None', 'primiko' ) . '</span>';
				break;

			case 'primiko_status':
				$new = Post_Types::STATUS_DONE !== get_post_meta( $post_id, '_primiko_status', true );

				printf(
					'<span class="primiko-status primiko-status--%1$s">%2$s</span>',
					esc_attr( $new ? 'new' : 'done' ),
					esc_html( $new ? __( 'New', 'primiko' ) : __( 'Handled', 'primiko' ) )
				);
				break;
		}
	}

	/**
	 * Adds the New and Handled views above the list.
	 *
	 * @param array $views Views.
	 * @return array
	 */
	public function views( $views ) {
		$current = $this->current_status();
		$base    = admin_url( 'edit.php?post_type=' . Post_Types::REQUEST );

		foreach ( array(
			Post_Types::STATUS_NEW  => _x( 'New', 'view of the request list', 'primiko' ),
			Post_Types::STATUS_DONE => _x( 'Handled', 'view of the request list', 'primiko' ),
		) as $status => $label ) {
			$views[ 'primiko_' . $status ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( self::STATUS_ARG, $status, $base ) ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( self::count( $status ) ) )
			);
		}

		// Every request is private: the Private view repeats All.
		unset( $views['private'] );

		if ( $current && isset( $views['all'] ) ) {
			$views['all'] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $views['all'] );
		}

		return $views;
	}

	/**
	 * Filters the list by status when a status view is open.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filter_status( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Post_Types::REQUEST !== $query->get( 'post_type' ) ) {
			return;
		}

		$status = $this->current_status();

		if ( '' !== $status ) {
			$query->set( 'meta_query', self::status_query( $status ) );
		}
	}

	/**
	 * Adds the status bulk actions.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		$actions['primiko_done'] = __( 'Mark as handled', 'primiko' );
		$actions['primiko_new']  = __( 'Mark as new', 'primiko' );

		return $actions;
	}

	/**
	 * Applies a status bulk action. WordPress has checked the nonce of the
	 * list; each request is checked against the rights of the user.
	 *
	 * @param string $redirect Address to go back to.
	 * @param string $action   Bulk action.
	 * @param int[]  $ids      Selected request IDs.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		$statuses = array(
			'primiko_done' => Post_Types::STATUS_DONE,
			'primiko_new'  => Post_Types::STATUS_NEW,
		);

		if ( ! isset( $statuses[ $action ] ) ) {
			return $redirect;
		}

		$changed = 0;

		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( Post_Types::REQUEST === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
				update_post_meta( $id, '_primiko_status', $statuses[ $action ] );
				++$changed;
			}
		}

		return add_query_arg( 'primiko_changed', $changed, $redirect );
	}

	/**
	 * Confirms a bulk status change.
	 */
	public function bulk_notice() {
		$screen = get_current_screen();

		// Only a count read from our own redirect, to show a message.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $screen || 'edit-' . Post_Types::REQUEST !== $screen->id || ! isset( $_GET['primiko_changed'] ) ) {
			return;
		}

		$count = absint( $_GET['primiko_changed'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of requests. */
					_n( '%d request updated.', '%d requests updated.', $count, 'primiko' ),
					$count
				)
			)
		);
	}

	/**
	 * Shows the number of new requests next to the menu, like pending
	 * comments.
	 */
	public function menu_count() {
		global $menu;

		$count = self::count( Post_Types::STATUS_NEW );

		if ( ! $count || ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $index => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . Post_Types::REQUEST === $item[2] ) {
				$menu[ $index ][0] .= sprintf( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Adds the count bubble, as core does for comments.
					' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
					$count,
					esc_html( number_format_i18n( $count ) ),
					esc_html(
						sprintf(
							/* translators: %d: number of new requests. */
							_n( '%d new request', '%d new requests', $count, 'primiko' ),
							$count
						)
					)
				);
				break;
			}
		}
	}

	/**
	 * Styles of the status column.
	 *
	 * @param string $hook Admin page.
	 */
	public function styles( $hook ) {
		$screen = get_current_screen();

		if ( 'edit.php' !== $hook || ! $screen || 'edit-' . Post_Types::REQUEST !== $screen->id ) {
			return;
		}

		wp_add_inline_style( 'common', '.column-primiko_status{width:8em}.column-primiko_photos{width:5em}.primiko-status{display:inline-block;padding:1px 8px;border-radius:2px;font-size:12px;line-height:1.8}.primiko-status--new{background:#dff0e4;color:#14532d;font-weight:600}.primiko-status--done{background:#f0f0f1;color:#50575e}' );
	}

	/**
	 * Number of requests with a status, in every visible state.
	 *
	 * @param string $status Status.
	 * @return int
	 */
	public static function count( $status ) {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::REQUEST,
				'post_status'            => array( 'private', 'publish' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => self::status_query( $status ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small table, admin only.
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Meta query for a status. Requests without a status count as new.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function status_query( $status ) {
		if ( Post_Types::STATUS_DONE === $status ) {
			return array(
				array(
					'key'   => '_primiko_status',
					'value' => Post_Types::STATUS_DONE,
				),
			);
		}

		return array(
			'relation' => 'OR',
			array(
				'key'     => '_primiko_status',
				'value'   => Post_Types::STATUS_DONE,
				'compare' => '!=',
			),
			array(
				'key'     => '_primiko_status',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Status view open in the list, or an empty string.
	 *
	 * @return string
	 */
	private function current_status() {
		// A filter of the list, not a change: no nonce needed.
		$status = isset( $_GET[ self::STATUS_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::STATUS_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $status, array( Post_Types::STATUS_NEW, Post_Types::STATUS_DONE ), true ) ? $status : '';
	}
}
