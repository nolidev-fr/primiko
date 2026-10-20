<?php
/**
 * Optional captcha.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Cloudflare Turnstile or Google reCAPTCHA v3, for sites that already use
 * one. Off by default: the form is protected without any outside service
 * (signed token, honeypot, minimum filling time, limit per connection).
 *
 * When it is on, the script of the provider loads on the pages that show a
 * form, and each submission is checked with the provider. A clear refusal
 * blocks the request; an unreachable provider does not, so that an outage
 * on their side does not cost the business its requests.
 */
final class Captcha {

	/**
	 * Providers and their endpoints.
	 */
	const PROVIDERS = array(
		// The captcha services only work with their own script, loaded from them,
		// and only once the site owner turns them on (see External services in readme.txt).
		'turnstile' => array(
			'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
			'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify', // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
		),
		'recaptcha' => array(
			'script' => 'https://www.google.com/recaptcha/api.js?render=',
			'verify' => 'https://www.google.com/recaptcha/api/siteverify',
		),
	);

	/**
	 * Active provider, or an empty string when the captcha is off or its keys
	 * are missing.
	 *
	 * @return string
	 */
	public static function provider() {
		$provider = (string) Settings::get( 'captcha_provider' );

		if ( ! isset( self::PROVIDERS[ $provider ] ) || '' === Settings::get( 'captcha_site_key' ) || '' === Settings::get( 'captcha_secret' ) ) {
			return '';
		}

		return $provider;
	}

	/**
	 * Settings read by assets/form.js, or null when the captcha is off.
	 *
	 * @return array|null
	 */
	public static function config() {
		$provider = self::provider();

		if ( '' === $provider ) {
			return null;
		}

		return array(
			'provider' => $provider,
			'siteKey'  => (string) Settings::get( 'captcha_site_key' ),
		);
	}

	/**
	 * Loads the script of the provider, on a page that shows a form.
	 */
	public static function enqueue() {
		$provider = self::provider();

		if ( '' === $provider ) {
			return;
		}

		$src = self::PROVIDERS[ $provider ]['script'];

		if ( 'recaptcha' === $provider ) {
			$src .= rawurlencode( (string) Settings::get( 'captcha_site_key' ) );

			// The badge Google fixes at the bottom of the screen hides under the
			// call bars many sites show on phones. Google allows hiding it when
			// the form shows its notice instead, which the consent box does.
			wp_add_inline_style( 'primiko-form', '.grecaptcha-badge{visibility:hidden}' );
		}

		// The provider serves its own current version: no version string.
		wp_enqueue_script( 'primiko-captcha', $src, array(), null, array( 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	}

	/**
	 * Checks the answer of the captcha with the provider.
	 *
	 * @param string $response Token the provider gave the browser.
	 * @return true|\WP_Error
	 */
	public static function verify( $response ) {
		$provider = self::provider();

		if ( '' === $provider ) {
			return true;
		}

		$refused = new \WP_Error( 'primiko_captcha', __( 'The anti-spam check failed. Please send your request again: your answers are kept.', 'primiko' ), array( 'status' => 403 ) );

		if ( '' === $response || strlen( $response ) > 4096 ) {
			return $refused;
		}

		$reply = wp_remote_post(
			self::PROVIDERS[ $provider ]['verify'],
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => (string) Settings::get( 'captcha_secret' ),
					'response' => $response,
				),
			)
		);

		// Provider unreachable: the other protections stay, the request goes through.
		if ( is_wp_error( $reply ) || 200 !== (int) wp_remote_retrieve_response_code( $reply ) ) {
			return true;
		}

		$result = json_decode( wp_remote_retrieve_body( $reply ), true );

		if ( ! is_array( $result ) || empty( $result['success'] ) ) {
			return $refused;
		}

		// The answer must come from this site (and, for reCAPTCHA, from the form).
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		/**
		 * Filters whether the host name returned by the provider must be the
		 * host of the site. The test keys of the providers return another host.
		 *
		 * @param bool $check Whether to check the host name.
		 */
		if ( apply_filters( 'primiko_captcha_check_hostname', true ) && ! empty( $result['hostname'] ) && strtolower( $result['hostname'] ) !== strtolower( (string) $host ) ) {
			return $refused;
		}

		if ( 'recaptcha' === $provider && isset( $result['action'] ) && 'submit' !== $result['action'] ) {
			return $refused;
		}

		/**
		 * Filters the lowest reCAPTCHA v3 score accepted, from 0 (robot) to 1.
		 *
		 * @param float $score Lowest score.
		 */
		if ( 'recaptcha' === $provider && isset( $result['score'] ) && (float) $result['score'] < (float) apply_filters( 'primiko_recaptcha_min_score', 0.5 ) ) {
			return $refused;
		}

		return true;
	}

	/**
	 * Name of the active provider, for texts.
	 *
	 * @return string Empty when the captcha is off.
	 */
	public static function provider_name() {
		$names = array(
			'turnstile' => 'Cloudflare Turnstile',
			'recaptcha' => 'Google reCAPTCHA',
		);
		$key   = self::provider();

		return isset( $names[ $key ] ) ? $names[ $key ] : '';
	}
}
