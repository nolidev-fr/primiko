<?php
/**
 * REST routes.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Routes used by the visitor form, and the list of forms for the block.
 *
 * The token and submit routes are open to anyone, since visitors are not
 * logged in. They only
 * accept a published form, and the submission is protected by a signed,
 * single use token, a honeypot field, a minimum filling time and a limit per
 * visitor (see Token and Submission).
 */
final class Rest {

	const NAMESPACE = 'primiko/v1';

	/**
	 * Handles the submissions.
	 *
	 * @var Submission
	 */
	private $submission;

	/**
	 * Sets up the routes.
	 *
	 * @param Submission $submission Handles the submissions.
	 */
	public function __construct( Submission $submission ) {
		$this->submission = $submission;
	}

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$form_arg = array(
			'form' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/token',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true', // Public form: visitors are not logged in.
				'args'                => $form_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'forms' ),
				'permission_callback' => static function () {
					return current_user_can( 'edit_pages' );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/submit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this->submission, 'handle' ),
				'permission_callback' => '__return_true', // Public form, protected by a signed single use token.
				'args'                => $form_arg,
			)
		);
	}

	/**
	 * Lists the published forms, for the block editor.
	 *
	 * @return \WP_REST_Response
	 */
	public function forms() {
		$forms = get_posts(
			array(
				'post_type'   => Post_Types::FORM,
				'post_status' => 'publish',
				'numberposts' => 100,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);

		return new \WP_REST_Response(
			array_map(
				static function ( $form ) {
					return array(
						'id'    => $form->ID,
						'title' => wp_specialchars_decode( get_the_title( $form ), ENT_QUOTES ),
					);
				},
				$forms
			)
		);
	}

	/**
	 * Issues a token for a published form.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function token( $request ) {
		if ( ! Submission::is_open_form( $request['form'] ) ) {
			return new \WP_Error( 'primiko_no_form', __( 'This form is not available.', 'primiko' ), array( 'status' => 404 ) );
		}

		$response = new \WP_REST_Response( array( 'token' => Token::issue( $request['form'] ) ) );
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}
}
