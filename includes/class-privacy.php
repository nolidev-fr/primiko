<?php
/**
 * Privacy tools.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs the requests into the personal data tools of WordPress (Tools,
 * Export Personal Data and Erase Personal Data), found by the email of the
 * visitor, and suggests a paragraph for the privacy policy.
 */
final class Privacy {

	/**
	 * Requests handled per page of the export or erase process.
	 */
	const PER_PAGE = 20;

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ) {
		$exporters['primiko'] = array(
			'exporter_friendly_name' => __( 'Quote requests', 'primiko' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ) {
		$erasers['primiko'] = array(
			'eraser_friendly_name' => __( 'Quote requests', 'primiko' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Exports the requests sent with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page of the process, from 1.
	 * @return array
	 */
	public function export( $email, $page = 1 ) {
		$ids  = $this->requests( $email, $page );
		$data = array();

		foreach ( $ids as $id ) {
			$items = array(
				array(
					'name'  => __( 'Sent on', 'primiko' ),
					'value' => get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $id ),
				),
				array(
					'name'  => __( 'Form', 'primiko' ),
					'value' => (string) get_post_meta( $id, '_primiko_trade', true ),
				),
			);

			foreach ( (array) get_post_meta( $id, '_primiko_entries', true ) as $step ) {
				foreach ( isset( $step['items'] ) ? (array) $step['items'] : array() as $item ) {
					$items[] = array(
						'name'  => (string) $item['label'],
						'value' => (string) $item['value'],
					);
				}
			}

			$items[] = array(
				'name'  => __( 'Consent given on', 'primiko' ),
				'value' => (string) get_post_meta( $id, '_primiko_consent_at', true ),
			);

			$data[] = array(
				'group_id'    => 'primiko',
				'group_label' => __( 'Quote requests', 'primiko' ),
				'item_id'     => 'primiko-request-' . $id,
				'data'        => $items,
			);
		}

		return array(
			'data' => $data,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Deletes the requests sent with an email address, with their photos.
	 *
	 * @param string $email Email address.
	 * @return array
	 */
	public function erase( $email ) {
		// Always the first page: the requests of the previous page are gone.
		$ids     = $this->requests( $email, 1 );
		$removed = false;

		foreach ( $ids as $id ) {
			$removed = (bool) wp_delete_post( $id, true ) || $removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Suggests a paragraph for the privacy policy of the site.
	 */
	public function add_policy_text() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$months = (int) Settings::get( 'retention_months' );
		$keep   = $months
			? sprintf(
				/* translators: %d: number of months. */
				_n( 'They are deleted automatically %d month after they are sent.', 'They are deleted automatically %d months after they are sent.', $months, 'primiko' ),
				$months
			)
			: __( 'They are kept until we delete them.', 'primiko' );

		$text  = '<p>' . esc_html__( 'When you send a quote request through our form, we receive your name, email address, phone number, the address of the work, your answers about the project and the photos you add. We use them only to answer your request and prepare a quote.', 'primiko' ) . '</p>';
		$text .= '<p>' . esc_html__( 'Your request is saved on this website and sent to us by email. A copy is sent to you. Photos are kept in a folder that cannot be reached from the web, without their location data.', 'primiko' ) . ' ' . esc_html( $keep ) . '</p>';
		if ( Captcha::provider_name() ) {
			$text .= '<p>' . esc_html(
				sprintf(
					/* translators: %s: name of the anti-spam service, such as Cloudflare Turnstile. */
					__( 'To protect the form against spam, the page that shows it uses %s, which receives technical data about your browser and your connection.', 'primiko' ),
					Captcha::provider_name()
				)
			) . '</p>';
		}

		$text .= '<p>' . esc_html__( 'You can ask for a copy of your requests or for their deletion at any time.', 'primiko' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Quote requests', 'primiko' ), wp_kses_post( $text ) );
	}

	/**
	 * IDs of the requests sent with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return int[]
	 */
	private function requests( $email, $page ) {
		$email = sanitize_email( $email );

		if ( ! is_email( $email ) ) {
			return array();
		}

		return get_posts(
			array(
				'post_type'   => Post_Types::REQUEST,
				'post_status' => array( 'private', 'publish', 'draft', 'pending', 'trash' ),
				'fields'      => 'ids',
				'numberposts' => self::PER_PAGE,
				'paged'       => max( 1, (int) $page ),
				'orderby'     => 'ID',
				'order'       => 'ASC',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Rare admin action, on one email address.
				'meta_query'  => array(
					array(
						'key'   => '_primiko_email',
						'value' => $email,
					),
				),
			)
		);
	}
}
