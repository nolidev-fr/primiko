<?php
/**
 * Shortcode.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Registers [primiko form="123"], for page builders and classic themes.
 */
final class Shortcode {

	const TAG = 'primiko';

	/**
	 * Form markup.
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * Sets up the shortcode.
	 *
	 * @param Renderer $renderer Form markup.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Registers the shortcode.
	 */
	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Renders the shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( array( 'form' => 0 ), $atts, self::TAG );

		return $this->renderer->render( absint( $atts['form'] ) );
	}
}
