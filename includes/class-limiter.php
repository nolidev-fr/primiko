<?php
/**
 * Counters with a time window.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Counts events per key over a period, in transients: requests per
 * connection, copies per email address. Keys are hashed with the site
 * salts, so neither addresses nor emails are stored.
 */
final class Limiter {

	/**
	 * Transient key for a value.
	 *
	 * @param string $scope Kind of counter, such as "rate" or "copy".
	 * @param string $value Value counted (address, email).
	 * @return string
	 */
	public static function key( $scope, $value ) {
		return 'primiko_' . $scope . '_' . substr( wp_hash( $value, 'nonce' ), 0, 32 );
	}

	/**
	 * Tells whether a counter reached its limit.
	 *
	 * @param string $key   Transient key, from key().
	 * @param int    $limit Limit, 0 or less for none.
	 * @return bool
	 */
	public static function reached( $key, $limit ) {
		return $limit > 0 && (int) get_transient( $key ) >= $limit;
	}

	/**
	 * Adds one to a counter. The period starts again with each event.
	 *
	 * @param string $key     Transient key, from key().
	 * @param int    $seconds Period of the counter.
	 */
	public static function hit( $key, $seconds ) {
		set_transient( $key, (int) get_transient( $key ) + 1, $seconds );
	}

	/**
	 * Takes one back from a counter, for an attempt that did not count.
	 *
	 * @param string $key     Transient key, from key().
	 * @param int    $seconds Period of the counter.
	 */
	public static function undo( $key, $seconds ) {
		$count = (int) get_transient( $key );

		if ( $count > 0 ) {
			set_transient( $key, $count - 1, $seconds );
		}
	}
}
