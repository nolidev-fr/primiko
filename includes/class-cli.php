<?php
/**
 * WP-CLI commands.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, exports and deletes quote requests, and lists the templates.
 *
 * ## EXAMPLES
 *
 *     wp primiko list --status=new
 *     wp primiko export --since=2026-01-01 > requests.json
 *     wp primiko delete 123 --yes
 *     wp primiko purge --dry-run
 *     wp primiko templates
 */
final class CLI {

	/**
	 * Lists the requests.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only new or handled requests.
	 * ---
	 * options:
	 *   - new
	 *   - done
	 * ---
	 *
	 * [--since=<date>]
	 * : Only requests received on or after this date (YYYY-MM-DD).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - count
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ) {
		$rows = array();

		foreach ( $this->requests( $assoc_args ) as $id ) {
			$answers = (array) get_post_meta( $id, '_primiko_answers', true );
			$rows[]  = array(
				'id'     => $id,
				'date'   => get_the_date( 'Y-m-d H:i', $id ),
				'trade'  => (string) get_post_meta( $id, '_primiko_trade', true ),
				'name'   => isset( $answers['name'] ) ? $answers['name'] : '',
				'city'   => (string) get_post_meta( $id, '_primiko_city', true ),
				'email'  => (string) get_post_meta( $id, '_primiko_email', true ),
				'phone'  => isset( $answers['phone'] ) ? $answers['phone'] : '',
				'photos' => count( (array) get_post_meta( $id, '_primiko_photos', true ) ),
				'status' => Post_Types::STATUS_DONE === get_post_meta( $id, '_primiko_status', true ) ? 'done' : 'new',
			);
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'id', 'date', 'trade', 'name', 'city', 'email', 'phone', 'photos', 'status' ) );
	}

	/**
	 * Exports the requests as JSON, with every answer, for a backup or a move
	 * to another site. Photos are listed by path, relative to the private
	 * folder of the plugin.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Only new or handled requests.
	 * ---
	 * options:
	 *   - new
	 *   - done
	 * ---
	 *
	 * [--since=<date>]
	 * : Only requests received on or after this date (YYYY-MM-DD).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ) {
		$export = array();

		foreach ( $this->requests( $assoc_args ) as $id ) {
			$export[] = array(
				'id'         => $id,
				'date_gmt'   => get_post_field( 'post_date_gmt', $id ),
				'title'      => wp_specialchars_decode( get_post_field( 'post_title', $id ), ENT_QUOTES ),
				'form'       => (int) get_post_field( 'post_parent', $id ),
				'template'   => (string) get_post_meta( $id, '_primiko_template', true ),
				'trade'      => (string) get_post_meta( $id, '_primiko_trade', true ),
				'status'     => Post_Types::STATUS_DONE === get_post_meta( $id, '_primiko_status', true ) ? 'done' : 'new',
				'consent_at' => (string) get_post_meta( $id, '_primiko_consent_at', true ),
				'answers'    => (array) get_post_meta( $id, '_primiko_answers', true ),
				'entries'    => (array) get_post_meta( $id, '_primiko_entries', true ),
				'photos'     => array_values( (array) get_post_meta( $id, '_primiko_photos', true ) ),
			);
		}

		\WP_CLI::line( (string) wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Deletes requests for good, with their photos.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Request IDs.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Request IDs.
	 * @param array $assoc_args Options.
	 */
	public function delete( $args, $assoc_args ) {
		\WP_CLI::confirm( sprintf( 'Delete %d request(s) and their photos for good?', count( $args ) ), $assoc_args );

		foreach ( array_map( 'absint', $args ) as $id ) {
			if ( Post_Types::REQUEST !== get_post_type( $id ) ) {
				\WP_CLI::warning( sprintf( '%d is not a quote request.', $id ) );
				continue;
			}

			wp_delete_post( $id, true );
			\WP_CLI::log( sprintf( 'Deleted request %d.', $id ) );
		}

		\WP_CLI::success( 'Done.' );
	}

	/**
	 * Deletes the requests older than the retention period now, instead of
	 * waiting for the nightly run.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only tell how many requests would be deleted.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function purge( $args, $assoc_args ) {
		$months = (int) Settings::get( 'retention_months' );

		if ( $months <= 0 ) {
			\WP_CLI::success( 'Requests are kept until deleted by hand: nothing to purge.' );
			return;
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			$count = count(
				get_posts(
					array(
						'post_type'   => Post_Types::REQUEST,
						'post_status' => array( 'private', 'publish', 'trash' ),
						'fields'      => 'ids',
						'numberposts' => -1,
						'date_query'  => array(
							array(
								'column' => 'post_date_gmt',
								'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ),
							),
						),
					)
				)
			);

			\WP_CLI::success( sprintf( '%d request(s) older than %d months would be deleted.', $count, $months ) );
			return;
		}

		$total     = 0;
		$retention = new Retention();

		do {
			$deleted = $retention->purge();
			$total  += $deleted;
		} while ( $deleted >= Retention::BATCH );

		\WP_CLI::success( sprintf( '%d request(s) deleted.', $total ) );
	}

	/**
	 * Lists the trade templates.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function templates( $args, $assoc_args ) {
		$plugin = primiko();
		$rows   = array();

		foreach ( $plugin->templates->choices() as $id => $title ) {
			$template = $plugin->templates->localized( $id );
			$rows[]   = array(
				'id'        => $id,
				'title'     => $title,
				'category'  => $template['category'],
				'steps'     => count( $template['steps'] ),
				'questions' => array_sum( array_map( 'count', wp_list_pluck( $template['steps'], 'fields' ) ) ),
			);
		}

		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ), $rows, array( 'id', 'title', 'category', 'steps', 'questions' ) );
	}

	/**
	 * Request IDs matching the options, oldest first.
	 *
	 * @param array $assoc_args Options: status, since.
	 * @return int[]
	 */
	private function requests( $assoc_args ) {
		$query = array(
			'post_type'   => Post_Types::REQUEST,
			'post_status' => array( 'private', 'publish' ),
			'fields'      => 'ids',
			'numberposts' => -1,
			'orderby'     => 'date',
			'order'       => 'ASC',
		);

		if ( ! empty( $assoc_args['status'] ) ) {
			$query['meta_query'] = Post_Types::STATUS_DONE === $assoc_args['status'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Command line export.
				? array(
					array(
						'key'   => '_primiko_status',
						'value' => Post_Types::STATUS_DONE,
					),
				)
				: array(
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

		if ( ! empty( $assoc_args['since'] ) ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $assoc_args['since'] ) ) {
				\WP_CLI::error( '--since expects a date as YYYY-MM-DD.' );
			}

			$query['date_query'] = array(
				array(
					'after'     => $assoc_args['since'],
					'inclusive' => true,
				),
			);
		}

		return get_posts( $query );
	}
}
