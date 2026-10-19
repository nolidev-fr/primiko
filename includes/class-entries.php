<?php
/**
 * Readable answers.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the answers into labelled entries, grouped by step.
 *
 * The entries are saved with the request, so that it stays readable even if
 * the template changes later. Emails and the request screen show them.
 */
final class Entries {

	/**
	 * Builds the entries of the visible answers.
	 *
	 * @param array $schema Form schema.
	 * @param array $clean  Clean answers of the visible fields.
	 * @return array[] List of steps: title and items (label, value).
	 */
	public static function build( $schema, $clean ) {
		$entries = array();

		foreach ( $schema['steps'] as $step ) {
			if ( ! empty( $step['review'] ) ) {
				continue;
			}

			$items = array();

			foreach ( $step['fields'] as $field ) {
				if ( ! isset( $clean[ $field['id'] ] ) ) {
					continue;
				}

				$items[] = array(
					'id'    => $field['id'],
					'type'  => $field['type'],
					'label' => $field['label'],
					'value' => self::display( $field, $clean[ $field['id'] ] ),
				);
			}

			if ( $items ) {
				$entries[] = array(
					'title' => $step['title'],
					'items' => $items,
				);
			}
		}

		return $entries;
	}

	/**
	 * Returns an answer as text.
	 *
	 * @param array $field Field.
	 * @param mixed $value Clean answer.
	 * @return string
	 */
	public static function display( $field, $value ) {
		switch ( $field['type'] ) {
			case 'choice':
			case 'select':
				$labels = wp_list_pluck( $field['options'], 'label', 'value' );
				$shown  = array();

				foreach ( (array) $value as $one ) {
					$shown[] = isset( $labels[ $one ] ) ? $labels[ $one ] : $one;
				}

				return implode( ', ', $shown );

			case 'yesno':
				return 'yes' === $value ? __( 'Yes', 'primiko' ) : __( 'No', 'primiko' );

			case 'address':
				return trim( $value['street'] . ', ' . trim( $value['postcode'] . ' ' . $value['city'] ), ', ' );

			case 'photos':
				/* translators: %d: number of photos. */
				return sprintf( _n( '%d photo', '%d photos', (int) $value, 'primiko' ), (int) $value );

			case 'number':
				$decimals = false === strpos( $value, '.' ) ? 0 : strlen( substr( strrchr( $value, '.' ), 1 ) );
				$number   = number_format_i18n( (float) $value, $decimals );

				return empty( $field['unit'] ) ? $number : $number . ' ' . $field['unit'];

			case 'date':
				$time = strtotime( $value . ' 12:00:00' );

				return $time ? date_i18n( get_option( 'date_format' ), $time ) : $value;

			default:
				return (string) $value;
		}
	}

	/**
	 * Returns the entries as HTML tables, for emails and the request screen.
	 *
	 * @param array[] $entries Entries.
	 * @return string Escaped HTML.
	 */
	public static function html( $entries ) {
		$html = '';

		foreach ( $entries as $step ) {
			$html .= '<h3 style="margin:24px 0 8px;font-size:16px;">' . esc_html( $step['title'] ) . '</h3>';
			$html .= '<table role="presentation" style="border-collapse:collapse;width:100%;max-width:640px;">';

			foreach ( $step['items'] as $item ) {
				$html .= '<tr>';
				$html .= '<th scope="row" style="text-align:left;vertical-align:top;padding:6px 12px 6px 0;width:40%;font-weight:normal;color:#555;">' . esc_html( $item['label'] ) . '</th>';
				$html .= '<td style="vertical-align:top;padding:6px 0;">' . self::value_html( $item ) . '</td>';
				$html .= '</tr>';
			}

			$html .= '</table>';
		}

		return $html;
	}

	/**
	 * Returns one answer as HTML. The phone number calls, the email address
	 * writes and the address opens a map: the business often reads its
	 * requests on a phone.
	 *
	 * @param array $item Entry item: id, type (since 1.0.1), label, value.
	 * @return string Escaped HTML.
	 */
	private static function value_html( $item ) {
		$value = (string) $item['value'];
		$type  = isset( $item['type'] ) ? $item['type'] : '';

		// Entries saved before 1.0.1 have no type: the contact fields keep fixed IDs.
		if ( '' === $type && isset( $item['id'] ) ) {
			$type = array(
				'phone' => 'tel',
				'email' => 'email',
			)[ $item['id'] ] ?? '';
		}

		if ( '' === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'tel':
				$number = preg_replace( '/[^0-9+]/', '', $value );

				return '' === $number ? esc_html( $value ) : '<a href="' . esc_url( 'tel:' . $number, array( 'tel' ) ) . '">' . esc_html( $value ) . '</a>';

			case 'email':
				return is_email( $value ) ? '<a href="' . esc_url( 'mailto:' . $value, array( 'mailto' ) ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );

			case 'address':
				return '<a href="' . esc_url( 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $value ) ) . '">' . esc_html( $value ) . '</a>';

			default:
				return nl2br( esc_html( $value ) );
		}
	}
}
