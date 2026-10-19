<?php
/**
 * Form tokens.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Signed tokens that stand in for a nonce on the public form.
 *
 * A nonce printed in the page would expire inside a page cache. The script
 * asks for a token when the form loads instead, through a route that is
 * never cached. The token also carries the time it was issued, which gives
 * the minimum filling time that stops most robots, and it can only be used
 * once.
 */
final class Token {

	/**
	 * Seconds a token stays valid.
	 */
	const LIFETIME = DAY_IN_SECONDS;

	/**
	 * Issues a token for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @return string
	 */
	public static function issue( $form_id ) {
		$time = time();
		$salt = wp_generate_password( 12, false );

		return $time . '.' . $salt . '.' . self::sign( $form_id, $time, $salt );
	}

	/**
	 * Checks a token. It is only marked as used once the request is saved, so
	 * that a visitor can fix an answer the server refused and send again.
	 *
	 * @param string $token   Token sent by the browser.
	 * @param int    $form_id Form post ID.
	 * @return true|\WP_Error
	 */
	public static function verify( $token, $form_id ) {
		$parts = explode( '.', (string) $token );

		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! hash_equals( self::sign( $form_id, (int) $parts[0], $parts[1] ), $parts[2] ) ) {
			return new \WP_Error( 'primiko_token_invalid', __( 'This form has expired. Please reload the page and try again.', 'primiko' ), array( 'status' => 403 ) );
		}

		$age = time() - (int) $parts[0];

		if ( $age > self::LIFETIME ) {
			return new \WP_Error( 'primiko_token_expired', __( 'This form has expired. Please try again.', 'primiko' ), array( 'status' => 403 ) );
		}

		/**
		 * Filters the minimum time, in seconds, between the display of the form
		 * and its submission. Robots usually send forms at once.
		 *
		 * @param int $seconds Minimum filling time.
		 */
		if ( $age < (int) apply_filters( 'primiko_min_fill_time', 5 ) ) {
			return new \WP_Error( 'primiko_too_fast', __( 'Your request could not be sent. Please try again in a moment.', 'primiko' ), array( 'status' => 400 ) );
		}

		if ( get_transient( self::used_key( $parts[2] ) ) ) {
			return new \WP_Error( 'primiko_token_used', __( 'This request has already been sent.', 'primiko' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Claims a token for the submission being processed. add_option() is
	 * atomic in the database: of two parallel requests with the same token,
	 * only one gets it.
	 *
	 * @param string $token Token checked with verify().
	 * @return bool Whether this request got the token.
	 */
	public static function claim( $token ) {
		return add_option( self::claim_key( $token ), time(), '', false );
	}

	/**
	 * Gives a claimed token back, after a refusal the visitor can fix.
	 *
	 * @param string $token Claimed token.
	 */
	public static function release( $token ) {
		delete_option( self::claim_key( $token ) );
	}

	/**
	 * Option key that claims a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function claim_key( $token ) {
		$parts = explode( '.', (string) $token );

		return 'primiko_claim_' . substr( (string) end( $parts ), 0, 32 );
	}

	/**
	 * Marks a token as used, so that the same request cannot be saved twice,
	 * and frees its claim.
	 *
	 * @param string $token Token checked with verify().
	 */
	public static function consume( $token ) {
		$parts = explode( '.', (string) $token );

		set_transient( self::used_key( end( $parts ) ), 1, self::LIFETIME );
		self::release( $token );
	}

	/**
	 * Transient key that remembers a used token.
	 *
	 * @param string $signature Signature part of the token.
	 * @return string
	 */
	private static function used_key( $signature ) {
		return 'primiko_token_' . substr( $signature, 0, 32 );
	}

	/**
	 * Signs the parts of a token with the site salts.
	 *
	 * @param int    $form_id Form post ID.
	 * @param int    $time    Time the token was issued.
	 * @param string $salt    Random part.
	 * @return string
	 */
	private static function sign( $form_id, $time, $salt ) {
		return hash_hmac( 'sha256', 'primiko|' . (int) $form_id . '|' . $time . '|' . $salt, wp_salt( 'nonce' ) );
	}
}
