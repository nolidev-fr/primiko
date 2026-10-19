<?php
/**
 * Emails.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the notification to the business and the acknowledgement to the
 * visitor, both through wp_mail() so that SMTP plugins apply.
 */
final class Mailer {

	/**
	 * Total size of the photos attached to the notification, in bytes. Above
	 * it, the photos are only reachable from the request screen.
	 */
	const MAX_ATTACHMENTS_BYTES = 8388608;

	/**
	 * Sends the new request to the recipients of the form.
	 *
	 * @param int $request_id Request post ID.
	 * @return bool Whether wp_mail() accepted the email.
	 */
	public function notify( $request_id ) {
		$request = get_post( $request_id );
		$answers = (array) get_post_meta( $request_id, '_primiko_answers', true );
		$name    = isset( $answers['name'] ) ? $answers['name'] : '';
		$email   = isset( $answers['email'] ) ? $answers['email'] : '';

		$subject = sprintf(
			/* translators: 1: trade, such as Roofer, 2: request title (name and town). */
			__( 'New quote request (%1$s): %2$s', 'primiko' ),
			get_post_meta( $request_id, '_primiko_trade', true ),
			wp_specialchars_decode( $request->post_title, ENT_QUOTES )
		);

		$body = '<p>' . esc_html(
			sprintf(
				/* translators: %s: reference of the request. */
				__( 'A new quote request was sent from your website, reference %s.', 'primiko' ),
				self::reference( $request_id )
			)
		) . '</p>';
		$body .= Entries::html( (array) get_post_meta( $request_id, '_primiko_entries', true ) );
		$body .= '<p style="margin-top:24px;"><a href="' . esc_url( admin_url( 'post.php?post=' . $request_id . '&action=edit' ) ) . '">' . esc_html__( 'Open the request on the website', 'primiko' ) . '</a></p>';

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( is_email( $email ) ) {
			$headers[] = 'Reply-To: ' . self::address( $name, $email );
		}

		$attachments = $this->attachments( $request_id );

		if ( $attachments ) {
			$body .= '<p>' . esc_html__( 'The photos are attached to this email.', 'primiko' ) . '</p>';
		} elseif ( get_post_meta( $request_id, '_primiko_photos', true ) ) {
			$body .= '<p>' . esc_html__( 'The photos are too large to be attached: open the request on the website to see them.', 'primiko' ) . '</p>';
		}

		return wp_mail( $this->recipients( (int) $request->post_parent ), $subject, $this->wrap( $body ), $headers, $attachments );
	}

	/**
	 * Sends the visitor a copy of the request.
	 *
	 * @param int $request_id Request post ID.
	 * @return bool Whether wp_mail() accepted the email.
	 */
	public function acknowledge( $request_id ) {
		$email = (string) get_post_meta( $request_id, '_primiko_email', true );

		/**
		 * Filters how many copies the whole site can send per hour.
		 *
		 * @param int $limit Copies per hour, 0 for no limit.
		 */
		$site_limit = (int) apply_filters( 'primiko_hourly_copies', 20 );
		$site_key   = Limiter::key( 'copies', 'site' );

		if ( ! is_email( $email ) || Limiter::reached( self::copy_key( $email ), self::daily_copies() ) || Limiter::reached( $site_key, $site_limit ) ) {
			return false;
		}

		$messages = $this->messages( $request_id );
		$subject  = self::fill( $messages['acknowledgement_subject'], $request_id );
		$text     = self::fill( $messages['acknowledgement_body'], $request_id );

		$body = '';

		foreach ( preg_split( "/\n\s*\n/", $text ) as $paragraph ) {
			$body .= '<p>' . nl2br( esc_html( trim( $paragraph ) ) ) . '</p>';
		}

		$body .= Entries::html( (array) get_post_meta( $request_id, '_primiko_entries', true ) );
		$body .= '<p style="margin-top:24px;">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p>';

		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$recipients = $this->recipients( (int) get_post_field( 'post_parent', $request_id ) );

		// Replies from the visitor go to the business, not to the sender address of the site.
		if ( ! empty( $recipients[0] ) && is_email( $recipients[0] ) ) {
			$headers[] = 'Reply-To: ' . self::address( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $recipients[0] );
		}

		$sent = wp_mail( $email, $subject, $this->wrap( $body ), $headers );

		// Only a copy that left counts against the daily limit.
		if ( $sent ) {
			Limiter::hit( self::copy_key( $email ), DAY_IN_SECONDS );
			Limiter::hit( $site_key, HOUR_IN_SECONDS );
		}

		return $sent;
	}

	/**
	 * Counter of the copies sent to one address. The visitor types the
	 * address of the copy: without a limit, a robot could use the form to
	 * flood someone else's mailbox in the name of the site. Plus-tags and
	 * dots in Gmail addresses reach the same mailbox, so they share one
	 * counter.
	 *
	 * @param string $email Address of the copy.
	 * @return string Transient key.
	 */
	private static function copy_key( $email ) {
		$email = strtolower( trim( $email ) );
		$at    = strrpos( $email, '@' );
		$local = substr( $email, 0, $at );
		$host  = substr( $email, $at + 1 );
		$local = preg_replace( '/\+.*$/', '', $local );

		if ( in_array( $host, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local = str_replace( '.', '', $local );
			$host  = 'gmail.com';
		}

		return Limiter::key( 'copy', $local . '@' . $host );
	}

	/**
	 * How many copies one address can receive per day.
	 *
	 * @return int 0 for no limit.
	 */
	private static function daily_copies() {
		/**
		 * Filters how many copies one address can receive per day.
		 *
		 * @param int $limit Copies per day, 0 for no limit.
		 */
		return (int) apply_filters( 'primiko_daily_copies', 3 );
	}

	/**
	 * Replaces the placeholders of a message: {name}, {email}, {site_name},
	 * {reference}.
	 *
	 * @param string $text       Message.
	 * @param int    $request_id Request post ID.
	 * @return string Plain text, not escaped.
	 */
	public static function fill( $text, $request_id ) {
		$answers = (array) get_post_meta( $request_id, '_primiko_answers', true );

		return strtr(
			$text,
			array(
				'{name}'      => isset( $answers['name'] ) ? $answers['name'] : '',
				'{email}'     => isset( $answers['email'] ) ? $answers['email'] : '',
				'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{reference}' => self::reference( $request_id ),
			)
		);
	}

	/**
	 * Reference of a request, shown to the visitor and the business.
	 *
	 * @param int $request_id Request post ID.
	 * @return string The random reference, or the ID for older requests.
	 */
	public static function reference( $request_id ) {
		$reference = (string) get_post_meta( $request_id, '_primiko_reference', true );

		return '' !== $reference ? $reference : (string) $request_id;
	}

	/**
	 * Default messages, used when a template leaves one out.
	 *
	 * @param string $key Message key.
	 * @return string
	 */
	public static function default_message( $key ) {
		$messages = array(
			'success'                 => __( 'Thank you {name}, your request has been sent. A copy has been sent to {email}.', 'primiko' ),
			'success_no_copy'         => __( 'Thank you {name}, your request has been sent. We will get back to you soon.', 'primiko' ),
			'acknowledgement_subject' => __( 'Your quote request to {site_name}', 'primiko' ),
			'acknowledgement_body'    => __( "Hello {name},\n\nThank you for your request. We have received it and will get back to you soon.\n\nHere is a copy of what you sent us.", 'primiko' ),
		);

		return isset( $messages[ $key ] ) ? $messages[ $key ] : '';
	}

	/**
	 * Recipients of the notifications of a form, or the site admin email.
	 *
	 * @param int $form_id Form post ID.
	 * @return string[]
	 */
	public function recipients( $form_id ) {
		$recipients = array_values( array_filter( (array) get_post_meta( $form_id, '_primiko_recipients', true ), 'is_email' ) );

		if ( ! $recipients ) {
			$recipients = array( get_option( 'admin_email' ) );
		}

		/**
		 * Filters who receives the new requests of a form.
		 *
		 * @param string[] $recipients Email addresses.
		 * @param int      $form_id    Form post ID.
		 */
		return (array) apply_filters( 'primiko_recipients', $recipients, $form_id );
	}

	/**
	 * Messages of the template of a request, translated.
	 *
	 * @param int $request_id Request post ID.
	 * @return array<string, string>
	 */
	private function messages( $request_id ) {
		$schema   = primiko()->templates->form_schema( (int) get_post_field( 'post_parent', $request_id ) );
		$messages = array();

		foreach ( array( 'acknowledgement_subject', 'acknowledgement_body' ) as $key ) {
			$messages[ $key ] = ! empty( $schema['messages'][ $key ] ) ? $schema['messages'][ $key ] : self::default_message( $key );
		}

		return $messages;
	}

	/**
	 * Absolute paths of the photos to attach, when they are small enough.
	 *
	 * @param int $request_id Request post ID.
	 * @return string[]
	 */
	private function attachments( $request_id ) {
		$paths = array();
		$total = 0;

		foreach ( (array) get_post_meta( $request_id, '_primiko_photos', true ) as $relative ) {
			$path = primiko()->uploads->path( (string) $relative );

			if ( '' !== $path ) {
				$paths[] = $path;
				$total  += (int) filesize( $path );
			}
		}

		/**
		 * Filters the largest total size of the photos attached to the
		 * notification, in bytes. 0 never attaches them.
		 *
		 * @param int $bytes Largest total size.
		 */
		$limit = (int) apply_filters( 'primiko_max_attachments_bytes', self::MAX_ATTACHMENTS_BYTES );

		return $total <= $limit ? $paths : array();
	}

	/**
	 * Name and address for an email header, without characters that could
	 * break the header.
	 *
	 * @param string $name  Display name.
	 * @param string $email Email address.
	 * @return string
	 */
	private static function address( $name, $email ) {
		$name = trim( preg_replace( '/[\r\n"<>,;]+/', ' ', $name ) );

		return '' === $name ? $email : '"' . $name . '" <' . $email . '>';
	}

	/**
	 * Wraps the body in a plain HTML document.
	 *
	 * @param string $body Escaped HTML.
	 * @return string
	 */
	private function wrap( $body ) {
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222;">' . $body . '</body></html>';
	}
}
