<?php
/**
 * Retention.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes requests older than the retention period, every night, with their
 * photos (Private_Uploads removes the files when a request is deleted).
 */
final class Retention {

	const HOOK = 'primiko_purge';

	/**
	 * Requests deleted per run. A larger backlog continues in a run a few
	 * minutes later.
	 */
	const BATCH = 100;

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( self::HOOK, array( $this, 'purge' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	/**
	 * Schedules the nightly run when it is missing (after an update, the
	 * activation hook does not run again).
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$next = new \DateTimeImmutable( 'tomorrow 03:00', wp_timezone() );

			wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
		}
	}

	/**
	 * Deletes the token claims left by submissions that stopped halfway
	 * (a crash, a timeout). A claim only lives for the few seconds of a
	 * submission; after a day, its token has expired anyway.
	 */
	private function clear_stale_claims() {
		global $wpdb;

		$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Nightly cleanup by prefix, no API for it.
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( 'primiko_claim_' ) . '%',
				time() - DAY_IN_SECONDS
			)
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Removes the scheduled run.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Deletes the requests older than the retention period.
	 *
	 * @return int Number of requests deleted.
	 */
	public function purge() {
		$months = (int) Settings::get( 'retention_months' );

		if ( $months <= 0 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'   => Post_Types::REQUEST,
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => self::BATCH,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'date_query'  => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ),
					),
				),
			)
		);

		// Trashed requests are included: 'any' leaves them out.
		$ids = array_merge(
			$ids,
			get_posts(
				array(
					'post_type'   => Post_Types::REQUEST,
					'post_status' => 'trash',
					'fields'      => 'ids',
					'numberposts' => self::BATCH,
					'date_query'  => array(
						array(
							'column' => 'post_date_gmt',
							'before' => gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ),
						),
					),
				)
			)
		);

		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}

		$this->clear_stale_claims();

		// WordPress refuses a second identical event within ten minutes.
		if ( count( $ids ) >= self::BATCH ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::HOOK );
		}

		return count( $ids );
	}
}
