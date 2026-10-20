<?php
/**
 * Submissions.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what a visitor sends, saves the request and sends the emails.
 *
 * Nothing sent by the browser is trusted: the answers are read again against
 * the schema of the form, with the conditions evaluated on the server.
 */
final class Submission {

	/**
	 * Largest photo accepted, in bytes. The script resizes photos well below.
	 */
	const MAX_PHOTO_BYTES = 10485760;

	/**
	 * Largest total size of the photos of one request, in bytes.
	 */
	const MAX_PHOTOS_BYTES = 26214400;

	/**
	 * Largest size of the answers sent, in bytes of JSON. A full form is a
	 * few kilobytes.
	 */
	const MAX_ANSWERS_BYTES = 65536;

	/**
	 * Trade templates.
	 *
	 * @var Templates
	 */
	private $templates;

	/**
	 * Private photo folder.
	 *
	 * @var Private_Uploads
	 */
	private $uploads;

	/**
	 * Emails.
	 *
	 * @var Mailer
	 */
	private $mailer;

	/**
	 * Sets up the handler.
	 *
	 * @param Templates       $templates Trade templates.
	 * @param Private_Uploads $uploads   Private photo folder.
	 * @param Mailer          $mailer    Emails.
	 */
	public function __construct( Templates $templates, Private_Uploads $uploads, Mailer $mailer ) {
		$this->templates = $templates;
		$this->uploads   = $uploads;
		$this->mailer    = $mailer;
	}

	/**
	 * Tells whether a form exists and is published.
	 *
	 * @param int $form_id Form post ID.
	 * @return bool
	 */
	public static function is_open_form( $form_id ) {
		$form = get_post( $form_id );

		return $form && Post_Types::FORM === $form->post_type && 'publish' === $form->post_status;
	}

	/**
	 * Handles a submission from the REST route.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( $request ) {
		$form_id = (int) $request['form'];
		$schema  = self::is_open_form( $form_id ) ? $this->templates->form_schema( $form_id ) : null;

		if ( null === $schema ) {
			return new \WP_Error( 'primiko_no_form', __( 'This form is not available.', 'primiko' ), array( 'status' => 404 ) );
		}

		// A robot filled in the hidden field: pretend it worked, keep nothing.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return new \WP_REST_Response( array( 'message' => __( 'Thank you, your request has been sent.', 'primiko' ) ) );
		}

		$token = (string) $request->get_param( 'token' );
		$valid = Token::verify( $token, $form_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// One submission at a time per token: parallel copies of the same
		// request stop here instead of saving it twice.
		if ( ! Token::claim( $token ) ) {
			return new \WP_Error( 'primiko_token_used', __( 'This request has already been sent.', 'primiko' ), array( 'status' => 409 ) );
		}

		$result = $this->process( $request, $form_id, $schema );

		if ( is_wp_error( $result ) ) {
			Token::release( $token );
			return $result;
		}

		Token::consume( $token );

		return $result;
	}

	/**
	 * Checks and saves a submission whose token is valid and claimed.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $form_id Form post ID.
	 * @param array            $schema  Form schema.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function process( $request, $form_id, $schema ) {
		$captcha = Captcha::verify( (string) $request->get_param( 'captcha' ) );

		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}

		$rate_key = $this->limit_key();

		/**
		 * Filters how many requests one connection can send per hour.
		 *
		 * @param int $limit Requests per hour.
		 */
		if ( Limiter::reached( $rate_key, (int) apply_filters( 'primiko_hourly_limit', 5 ) ) ) {
			return new \WP_Error( 'primiko_limit', __( 'Too many requests were sent from your connection. Please try again later or contact us by phone.', 'primiko' ), array( 'status' => 429 ) );
		}

		// Counted before any work, so that parallel requests cannot all pass.
		// A request refused for its answers gives its attempt back.
		Limiter::hit( $rate_key, HOUR_IN_SECONDS );

		$raw = (string) $request->get_param( 'answers' );

		if ( strlen( $raw ) > self::MAX_ANSWERS_BYTES ) {
			return new \WP_Error( 'primiko_too_long', __( 'Your answers are too long. Please shorten them and try again.', 'primiko' ), array( 'status' => 413 ) );
		}

		$answers = json_decode( $raw, true, 8 );
		$files   = $this->photo_files( $request->get_file_params() );
		$result  = $this->validate( $schema, is_array( $answers ) ? $answers : array(), $files );

		if ( $result['errors'] ) {
			Limiter::undo( $rate_key, HOUR_IN_SECONDS );

			return new \WP_Error(
				'primiko_invalid',
				__( 'Some answers need your attention.', 'primiko' ),
				array(
					'status' => 400,
					'fields' => $result['errors'],
				)
			);
		}

		$request_id = $this->save( $form_id, $schema, $result );

		if ( is_wp_error( $request_id ) ) {
			return $request_id;
		}

		$this->mailer->notify( $request_id );
		$copied = $this->mailer->acknowledge( $request_id );

		/**
		 * Fires once a quote request is saved and its emails are sent.
		 *
		 * @param int $request_id Request post ID.
		 * @param int $form_id    Form post ID.
		 */
		do_action( 'primiko_request_created', $request_id, $form_id );

		return new \WP_REST_Response(
			array(
				// The usual message tells about the copy: not when none could be sent.
				'message'   => Mailer::fill( $this->message( $schema, $copied ? 'success' : 'success_no_copy' ), $request_id ),
				'reference' => Mailer::reference( $request_id ),
			),
			201
		);
	}

	/**
	 * Reads every answer in form order. Hidden fields are skipped, so they are
	 * never required and never saved.
	 *
	 * @param array $schema  Form schema.
	 * @param array $answers Answers sent by the browser.
	 * @param array $files   Photos sent, as normalized upload arrays.
	 * @return array{clean: array, errors: array<string, string>, photos: array}
	 */
	private function validate( $schema, $answers, $files ) {
		$clean  = array();
		$errors = array();
		$photos = array();

		foreach ( $schema['steps'] as $step ) {
			if ( ! Conditions::matches( isset( $step['show_if'] ) ? $step['show_if'] : null, $clean ) ) {
				continue;
			}

			foreach ( $step['fields'] as $field ) {
				if ( ! Conditions::matches( isset( $field['show_if'] ) ? $field['show_if'] : null, $clean ) ) {
					continue;
				}

				if ( 'photos' === $field['type'] ) {
					$checked = $this->check_photos( $field, $files );

					if ( is_string( $checked ) ) {
						$errors[ $field['id'] ] = $checked;
					} else {
						$photos = $checked;

						if ( $photos ) {
							$clean[ $field['id'] ] = (string) count( $photos );
						}
					}
					continue;
				}

				$value = $this->sanitize( $field, isset( $answers[ $field['id'] ] ) ? $answers[ $field['id'] ] : null );

				if ( is_wp_error( $value ) ) {
					$errors[ $field['id'] ] = $value->get_error_message();
				} elseif ( self::is_empty( $value ) ) {
					if ( ! empty( $field['required'] ) ) {
						$errors[ $field['id'] ] = $this->required_message( $field );
					}
				} else {
					$clean[ $field['id'] ] = $value;
				}
			}
		}

		// "Phone or email": both are optional, but one of them is needed to answer.
		if ( ! empty( $schema['contact_either'] ) && ! isset( $clean['phone'] ) && ! isset( $clean['email'] ) && ! isset( $errors['phone'] ) && ! isset( $errors['email'] ) ) {
			$errors['phone'] = __( 'Please give a phone number or an email address.', 'primiko' );
		}

		return array(
			'clean'  => $clean,
			'errors' => $errors,
			'photos' => $photos,
		);
	}

	/**
	 * Cleans one answer for its field type.
	 *
	 * @param array $field Field.
	 * @param mixed $value Raw answer.
	 * @return mixed|\WP_Error Clean answer, empty when not given.
	 */
	private function sanitize( $field, $value ) {
		switch ( $field['type'] ) {
			case 'choice':
				$allowed = wp_list_pluck( $field['options'], 'value' );

				if ( ! empty( $field['multiple'] ) ) {
					$values = array_values( array_unique( array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) ) );

					return array_values( array_intersect( $values, $allowed ) );
				}

				return is_scalar( $value ) && in_array( (string) $value, $allowed, true ) ? (string) $value : '';

			case 'select':
				return is_scalar( $value ) && in_array( (string) $value, wp_list_pluck( $field['options'], 'value' ), true ) ? (string) $value : '';

			case 'yesno':
				return in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';

			case 'consent':
				return in_array( $value, array( '1', 1, true ), true ) ? '1' : '';

			case 'address':
				$address = array();

				foreach ( array( 'street', 'postcode', 'city' ) as $part ) {
					$address[ $part ] = is_array( $value ) && isset( $value[ $part ] ) && is_scalar( $value[ $part ] )
						? self::cut( sanitize_text_field( (string) $value[ $part ] ), 200 )
						: '';
				}

				if ( ! array_filter( $address ) ) {
					return '';
				}

				if ( ! empty( $field['required'] ) && in_array( '', $address, true ) ) {
					return new \WP_Error( 'address', __( 'Please fill in the street, postcode and town.', 'primiko' ) );
				}

				return $address;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = (string) $value;

		switch ( $field['type'] ) {
			case 'textarea':
				return self::cut( sanitize_textarea_field( $value ), isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 5000 );

			case 'email':
				$value = trim( $value );

				if ( '' !== $value && ! is_email( $value ) ) {
					return new \WP_Error( 'email', __( 'Please enter a valid email address, such as name@example.com.', 'primiko' ) );
				}

				return sanitize_email( $value );

			case 'tel':
				$value = trim( sanitize_text_field( $value ) );

				if ( '' !== $value && ( ! preg_match( '/^[0-9+().\s-]{6,30}$/', $value ) || preg_match_all( '/[0-9]/', $value ) < 6 ) ) {
					return new \WP_Error( 'phone', __( 'Please enter a valid phone number.', 'primiko' ) );
				}

				return $value;

			case 'number':
				$value = str_replace( array( ',', ' ' ), array( '.', '' ), trim( $value ) );

				if ( '' === $value ) {
					return '';
				}

				if ( ! is_numeric( $value ) ) {
					return new \WP_Error( 'number', __( 'Please enter a number.', 'primiko' ) );
				}

				if ( ( isset( $field['min'] ) && (float) $value < (float) $field['min'] ) || ( isset( $field['max'] ) && (float) $value > (float) $field['max'] ) ) {
					return new \WP_Error(
						'range',
						sprintf(
							/* translators: 1: minimum, 2: maximum. */
							__( 'Please enter a number between %1$s and %2$s.', 'primiko' ),
							isset( $field['min'] ) ? $field['min'] : '-',
							isset( $field['max'] ) ? $field['max'] : '-'
						)
					);
				}

				return (string) ( 0 + $value );

			case 'date':
				$value = trim( $value );

				if ( '' !== $value && ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) ) {
					return new \WP_Error( 'date', __( 'Please enter a valid date.', 'primiko' ) );
				}

				return $value;

			default:
				return self::cut( sanitize_text_field( $value ), isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 200 );
		}
	}

	/**
	 * Message shown when a required field is left empty.
	 *
	 * @param array $field Field.
	 * @return string
	 */
	private function required_message( $field ) {
		if ( 'consent' === $field['type'] ) {
			return __( 'Please tick this box to send your request.', 'primiko' );
		}

		if ( in_array( $field['type'], array( 'choice', 'select', 'yesno' ), true ) ) {
			return __( 'Please choose an answer.', 'primiko' );
		}

		if ( 'address' === $field['type'] ) {
			return __( 'Please fill in the street, postcode and town.', 'primiko' );
		}

		return __( 'This field is required.', 'primiko' );
	}

	/**
	 * Lists the photos sent, whatever the shape PHP gave the upload array.
	 *
	 * @param array $file_params Uploaded files from the request.
	 * @return array[] One upload array per photo.
	 */
	private function photo_files( $file_params ) {
		if ( empty( $file_params['photos'] ) || ! is_array( $file_params['photos'] ) ) {
			return array();
		}

		$photos = $file_params['photos'];

		if ( ! is_array( $photos['name'] ) ) {
			return array( $photos );
		}

		$files = array();

		foreach ( array_keys( $photos['name'] ) as $index ) {
			$file = array();

			foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $key ) {
				$file[ $key ] = isset( $photos[ $key ][ $index ] ) ? $photos[ $key ][ $index ] : '';
			}

			$files[] = $file;
		}

		return $files;
	}

	/**
	 * Checks the photos against the field: number, size, and that each one is
	 * an actual image.
	 *
	 * @param array   $field Photos field.
	 * @param array[] $files Upload arrays.
	 * @return array[]|string The photos to keep, or an error message.
	 */
	private function check_photos( $field, $files ) {
		if ( count( $files ) > $field['max'] ) {
			/* translators: %d: maximum number of photos. */
			return sprintf( __( 'You can add up to %d photos.', 'primiko' ), $field['max'] );
		}

		if ( array_sum( array_map( 'intval', wp_list_pluck( $files, 'size' ) ) ) > self::MAX_PHOTOS_BYTES ) {
			return __( 'The photos are too large together. Please send fewer or smaller photos.', 'primiko' );
		}

		foreach ( $files as $file ) {
			$error = (int) $file['error'];

			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || (int) $file['size'] > self::MAX_PHOTO_BYTES ) {
				return __( 'A photo is too large. Please choose a smaller one.', 'primiko' );
			}

			$size = UPLOAD_ERR_OK === $error && is_uploaded_file( $file['tmp_name'] ) ? wp_getimagesize( $file['tmp_name'] ) : false;

			if ( ! $size || ! in_array( $size['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
				return __( 'A photo could not be read. Please choose a JPEG, PNG or WebP photo.', 'primiko' );
			}

			if ( $size[0] * $size[1] > Private_Uploads::MAX_PIXELS ) {
				return __( 'A photo is too large. Please choose a smaller one.', 'primiko' );
			}
		}

		if ( ! $files && ! empty( $field['required'] ) ) {
			return __( 'Please add at least one photo.', 'primiko' );
		}

		return $files;
	}

	/**
	 * Saves the request as a private post, then its photos.
	 *
	 * @param int   $form_id Form post ID.
	 * @param array $schema  Form schema.
	 * @param array $result  Result of validate().
	 * @return int|\WP_Error Request post ID.
	 */
	private function save( $form_id, $schema, $result ) {
		$clean = $result['clean'];
		// WordPress encodes < and > in titles saved by visitors: keep them out.
		$name = str_replace( array( '<', '>' ), '', isset( $clean['name'] ) ? $clean['name'] : __( 'Quote request', 'primiko' ) );
		$city = '';

		$title = $name;

		foreach ( Templates::fields( $schema ) as $id => $field ) {
			if ( 'address' === $field['type'] && ! empty( $clean[ $id ]['city'] ) ) {
				$city  = str_replace( array( '<', '>' ), '', $clean[ $id ]['city'] );
				$title = $name . ', ' . $city;
				break;
			}

			// A simple message is titled with the start of the message.
			if ( ! empty( $schema['single'] ) && 'textarea' === $field['type'] && ! empty( $clean[ $id ] ) ) {
				$start = trim( str_replace( array( '<', '>', "\r", "\n" ), array( '', '', '', ' ' ), $clean[ $id ] ) );
				$cut   = self::cut( $start, 60 );
				$title = $name . ', ' . ( $cut !== $start ? rtrim( $cut ) . '…' : $cut );
				break;
			}
		}

		$request_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::REQUEST,
				'post_status' => 'private',
				'post_title'  => $title,
				'post_parent' => $form_id,
				'post_author' => 0,
			),
			true
		);

		if ( is_wp_error( $request_id ) ) {
			return new \WP_Error( 'primiko_save', __( 'Your request could not be saved. Please try again or contact us by phone.', 'primiko' ), array( 'status' => 500 ) );
		}

		$photos = array();

		if ( $result['photos'] ) {
			$photos = $this->uploads->store( $request_id, $result['photos'] );

			if ( is_wp_error( $photos ) ) {
				wp_delete_post( $request_id, true );

				return new \WP_Error( 'primiko_photos', __( 'Your photos could not be saved. Please try again, or send the request without photos.', 'primiko' ), array( 'status' => 500 ) );
			}
		}

		$meta = array(
			'_primiko_status'     => Post_Types::STATUS_NEW,
			'_primiko_template'   => $schema['id'],
			'_primiko_trade'      => $schema['title'],
			'_primiko_answers'    => $clean,
			'_primiko_entries'    => Entries::build( $schema, $clean ),
			'_primiko_photos'     => $photos,
			'_primiko_email'      => isset( $clean['email'] ) ? $clean['email'] : '',
			'_primiko_city'       => $city,
			'_primiko_consent_at' => gmdate( 'c' ),
			'_primiko_reference'  => self::new_reference(),
			'_primiko_single'     => empty( $schema['single'] ) ? '' : '1',
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $request_id, $key, wp_slash( $value ) );
		}

		return $request_id;
	}

	/**
	 * Returns a message of the template, or the default one.
	 *
	 * @param array  $schema Form schema.
	 * @param string $key    Message key.
	 * @return string
	 */
	private function message( $schema, $key ) {
		if ( ! empty( $schema['messages'][ $key ] ) ) {
			return $schema['messages'][ $key ];
		}

		return Mailer::default_message( $key );
	}

	/**
	 * Transient key for the connection of the visitor. The address itself is
	 * never stored, only a salted hash of it, for an hour.
	 *
	 * @return string
	 */
	private function limit_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the address of the visitor used for the limit per connection.
		 *
		 * Behind a proxy or a CDN, every visitor may reach the site from the
		 * same address. Forwarded headers can be forged by anyone, so the
		 * plugin does not read them: a site that trusts its proxy can return
		 * the real address here.
		 *
		 * @param string $ip Address seen by the server.
		 */
		$ip = (string) apply_filters( 'primiko_client_ip', $ip );

		// One IPv6 subscriber holds a whole /64 block: count the block.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$ip = bin2hex( substr( (string) inet_pton( $ip ), 0, 8 ) ) . '/64';
		}

		return Limiter::key( 'rate', $ip );
	}

	/**
	 * Random reference of a request, for the visitor and the business. The
	 * post ID would tell anyone how much is published on the site.
	 *
	 * @return string Such as "DK7Q2M".
	 */
	private static function new_reference() {
		$alphabet  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$reference = '';

		for ( $i = 0; $i < 6; $i++ ) {
			$reference .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $reference;
	}

	/**
	 * Tells whether a clean answer is empty.
	 *
	 * @param mixed $value Clean answer.
	 * @return bool
	 */
	private static function is_empty( $value ) {
		return '' === $value || array() === $value || null === $value;
	}

	/**
	 * Cuts a text to a number of characters.
	 *
	 * @param string $text   Text.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function cut( $text, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
