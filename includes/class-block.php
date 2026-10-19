<?php
/**
 * Block.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the quote form block. The block only stores the form ID and is
 * rendered on the server, like the shortcode.
 */
final class Block {

	/**
	 * Form markup.
	 *
	 * @var Renderer
	 */
	private $renderer;

	/**
	 * Sets up the block.
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
	 * Registers the block from its built metadata.
	 */
	public function register() {
		$dir = PRIMIKO_DIR . 'build/block';

		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$dir,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);

		wp_set_script_translations( 'primiko-form-editor-script', 'primiko', PRIMIKO_DIR . 'languages' );
	}

	/**
	 * Renders the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$html = $this->renderer->render(
			isset( $attributes['formId'] ) ? absint( $attributes['formId'] ) : 0,
			isset( $attributes['headingLevel'] ) ? (int) $attributes['headingLevel'] : 2
		);

		if ( '' === $html ) {
			return '';
		}

		$style = '';
		$color = isset( $attributes['accentColor'] ) ? sanitize_hex_color( $attributes['accentColor'] ) : '';

		if ( $color ) {
			$style .= '--primiko-accent:' . $color . ';';
		}

		if ( isset( $attributes['radius'] ) && is_numeric( $attributes['radius'] ) ) {
			$style .= '--primiko-radius:' . max( 0, min( 24, (int) $attributes['radius'] ) ) . 'px;';
		}

		// The variables sit on the wrapper and reach the form through inheritance.
		return '<div ' . get_block_wrapper_attributes( $style ? array( 'style' => $style ) : array() ) . '>' . $html . '</div>';
	}
}
