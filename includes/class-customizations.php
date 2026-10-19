<?php
/**
 * Form customizations.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * What the business changed on its form: steps, questions and answers it
 * hides, texts it renames, messages it rewrites.
 *
 * Only the differences with the template are saved, keyed by template, so
 * that an improved template in a plugin update still reaches the form while
 * the choices of the business stay. A customization that refers to a step,
 * question or answer the template no longer has is ignored.
 *
 * Saved shape, in the _primiko_customizations post meta:
 *
 *     array(
 *         '<template id>' => array(
 *             'steps'    => array( '<step id>' => array( 'hidden' => true, 'title' => '...' ) ),
 *             'fields'   => array(
 *                 '<field id>' => array(
 *                     'hidden'  => true,
 *                     'label'   => '...',
 *                     'help'    => '...',
 *                     'options' => array( '<value>' => array( 'hidden' => true, 'label' => '...' ) ),
 *                 ),
 *             ),
 *             'messages' => array( 'success' => '...', 'acknowledgement_subject' => '...', 'acknowledgement_body' => '...' ),
 *             'added'    => array(
 *                 array( 'id' => 'custom_ab12cd', 'step' => '<step id>', 'type' => 'choice', 'label' => '...', 'help' => '...',
 *                        'required' => true, 'multiple' => false, 'options' => array( array( 'value' => 'o1', 'label' => '...' ) ) ),
 *             ),
 *             'order'    => array( '<step id>' => array( '<field id>', '<field id>' ) ),
 *         ),
 *     )
 *
 * Questions added by the business are written in its own words: they are
 * not translated. The order applies within a step, and is ignored when it
 * would put a question before the question its display condition reads.
 */
final class Customizations {

	const META = '_primiko_customizations';

	/**
	 * Messages the business can rewrite.
	 */
	const MESSAGES = array( 'success', 'acknowledgement_subject', 'acknowledgement_body' );

	/**
	 * Longest text accepted for a title, a question or an answer.
	 */
	const MAX_LABEL = 200;

	/**
	 * Longest text accepted for a help text or a message.
	 */
	const MAX_TEXT = 2000;

	/**
	 * Field types a business can add.
	 */
	const ADDED_TYPES = array( 'text', 'textarea', 'number', 'choice', 'yesno', 'date' );

	/**
	 * Most questions a business can add to a form, and most answers per
	 * added choice question.
	 */
	const MAX_ADDED   = 30;
	const MAX_OPTIONS = 20;

	/**
	 * Returns the customizations of a form for a template.
	 *
	 * @param int    $form_id     Form post ID.
	 * @param string $template_id Template identifier.
	 * @return array
	 */
	public static function get( $form_id, $template_id ) {
		$all = get_post_meta( $form_id, self::META, true );

		return is_array( $all ) && isset( $all[ $template_id ] ) && is_array( $all[ $template_id ] ) ? $all[ $template_id ] : array();
	}

	/**
	 * Saves the customizations of a form for a template, keeping those made
	 * for other templates (the business may switch back).
	 *
	 * @param int    $form_id     Form post ID.
	 * @param string $template_id Template identifier.
	 * @param array  $custom      Clean customizations, from sanitize().
	 */
	public static function save( $form_id, $template_id, $custom ) {
		$all = get_post_meta( $form_id, self::META, true );
		$all = is_array( $all ) ? $all : array();

		if ( $custom ) {
			$all[ $template_id ] = $custom;
		} else {
			unset( $all[ $template_id ] );
		}

		update_post_meta( $form_id, self::META, wp_slash( $all ) );
	}

	/**
	 * Keeps only valid customizations: identifiers that exist in the template,
	 * clean texts, and only what differs from the template.
	 *
	 * @param mixed $raw      Decoded customizations from the edit screen.
	 * @param array $template Template, translated (texts compared to it).
	 * @return array
	 */
	public static function sanitize( $raw, $template ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $template['steps'] as $step ) {
			$entry = array();
			$given = isset( $raw['steps'][ $step['id'] ] ) && is_array( $raw['steps'][ $step['id'] ] ) ? $raw['steps'][ $step['id'] ] : array();

			if ( ! empty( $given['hidden'] ) ) {
				$entry['hidden'] = true;
			}

			$title = self::text( $given, 'title', self::MAX_LABEL, $step['title'] );

			if ( '' !== $title ) {
				$entry['title'] = $title;
			}

			if ( $entry ) {
				$clean['steps'][ $step['id'] ] = $entry;
			}

			foreach ( $step['fields'] as $field ) {
				$field_entry = self::sanitize_field( isset( $raw['fields'][ $field['id'] ] ) ? $raw['fields'][ $field['id'] ] : array(), $field );

				if ( $field_entry ) {
					$clean['fields'][ $field['id'] ] = $field_entry;
				}
			}
		}

		$added = self::sanitize_added( isset( $raw['added'] ) ? $raw['added'] : array(), $template );

		if ( $added ) {
			$clean['added'] = $added;
		}

		$order = self::sanitize_order( isset( $raw['order'] ) ? $raw['order'] : array(), $template, $added );

		if ( $order ) {
			$clean['order'] = $order;
		}

		foreach ( self::MESSAGES as $key ) {
			$default = isset( $template['messages'][ $key ] ) ? $template['messages'][ $key ] : Mailer::default_message( $key );
			$max     = 'acknowledgement_subject' === $key ? self::MAX_LABEL : self::MAX_TEXT;
			$message = self::text( isset( $raw['messages'] ) && is_array( $raw['messages'] ) ? $raw['messages'] : array(), $key, $max, $default, 'acknowledgement_body' === $key );

			if ( '' !== $message ) {
				$clean['messages'][ $key ] = $message;
			}
		}

		return $clean;
	}

	/**
	 * Applies customizations to a translated template. Hidden steps, questions
	 * and answers are removed, so they are neither shown nor accepted on
	 * submission. A question left without answers to choose from is removed
	 * too, and so is a step left without questions.
	 *
	 * @param array $template Template, translated.
	 * @param array $custom   Customizations.
	 * @return array
	 */
	public static function apply( $template, $custom ) {
		if ( ! $custom ) {
			return $template;
		}

		$steps = array();

		foreach ( $template['steps'] as $step ) {
			$step_custom    = isset( $custom['steps'][ $step['id'] ] ) ? $custom['steps'][ $step['id'] ] : array();
			$step['fields'] = self::step_fields( $step, $custom );

			if ( ! empty( $step_custom['hidden'] ) ) {
				continue;
			}

			if ( ! empty( $step_custom['title'] ) ) {
				$step['title'] = $step_custom['title'];
			}

			$fields = array();

			foreach ( $step['fields'] as $field ) {
				$field = self::apply_field( $field, isset( $custom['fields'][ $field['id'] ] ) ? $custom['fields'][ $field['id'] ] : array() );

				if ( null !== $field ) {
					$fields[] = $field;
				}
			}

			if ( $fields ) {
				$step['fields'] = $fields;
				$steps[]        = $step;
			}
		}

		$template['steps'] = $steps;

		foreach ( self::MESSAGES as $key ) {
			if ( ! empty( $custom['messages'][ $key ] ) ) {
				$template['messages'][ $key ] = $custom['messages'][ $key ];
			}
		}

		return $template;
	}

	/**
	 * Fields of a step: those of the template followed by those the business
	 * added, in the order it chose when that order keeps every display
	 * condition after the question it reads.
	 *
	 * @param array $step   Step of the template, translated.
	 * @param array $custom Customizations.
	 * @return array[]
	 */
	public static function step_fields( $step, $custom ) {
		$fields = $step['fields'];

		foreach ( isset( $custom['added'] ) ? (array) $custom['added'] : array() as $added ) {
			if ( isset( $added['step'] ) && $added['step'] === $step['id'] ) {
				$fields[] = self::added_field( $added );
			}
		}

		$order = isset( $custom['order'][ $step['id'] ] ) ? (array) $custom['order'][ $step['id'] ] : array();

		if ( ! $order ) {
			return $fields;
		}

		$position = array_flip( array_values( $order ) );
		$count    = count( $position );
		$keys     = array();

		// Fields missing from the saved order (new in the template) go last, in their own order.
		foreach ( $fields as $index => $field ) {
			$keys[ $index ] = isset( $position[ $field['id'] ] ) ? $position[ $field['id'] ] : $count + $index;
		}

		$sorted = $fields;
		array_multisort( $keys, SORT_NUMERIC, $sorted );

		return self::conditions_in_order( $sorted ) ? $sorted : $fields;
	}

	/**
	 * Turns a saved added question into a field of the schema.
	 *
	 * @param array $added Saved added question.
	 * @return array
	 */
	private static function added_field( $added ) {
		$field = array(
			'id'       => $added['id'],
			'type'     => $added['type'],
			'label'    => $added['label'],
			'help'     => isset( $added['help'] ) ? $added['help'] : '',
			'required' => ! empty( $added['required'] ),
			'added'    => true,
		);

		if ( 'choice' === $added['type'] ) {
			$field['options']  = $added['options'];
			$field['multiple'] = ! empty( $added['multiple'] );
		}

		if ( 'number' === $added['type'] && ! empty( $added['unit'] ) ) {
			$field['unit'] = $added['unit'];
		}

		return $field;
	}

	/**
	 * Tells whether every display condition of a list of fields reads only
	 * fields placed before it (or in earlier steps).
	 *
	 * @param array[] $fields Fields of one step, in order.
	 * @return bool
	 */
	private static function conditions_in_order( $fields ) {
		$step_ids = wp_list_pluck( $fields, 'id' );
		$seen     = array();

		foreach ( $fields as $field ) {
			foreach ( self::condition_fields( isset( $field['show_if'] ) ? $field['show_if'] : null ) as $read ) {
				if ( in_array( $read, $step_ids, true ) && ! in_array( $read, $seen, true ) ) {
					return false;
				}
			}

			$seen[] = $field['id'];
		}

		return true;
	}

	/**
	 * Identifiers of the fields a condition reads.
	 *
	 * @param mixed $condition Condition.
	 * @return string[]
	 */
	private static function condition_fields( $condition ) {
		if ( ! is_array( $condition ) ) {
			return array();
		}

		$fields = array();

		foreach ( array( 'all', 'any' ) as $group ) {
			if ( isset( $condition[ $group ] ) ) {
				foreach ( (array) $condition[ $group ] as $inner ) {
					$fields = array_merge( $fields, self::condition_fields( $inner ) );
				}
			}
		}

		if ( isset( $condition['field'] ) && is_string( $condition['field'] ) ) {
			$fields[] = $condition['field'];
		}

		return $fields;
	}

	/**
	 * Keeps the valid questions added by the business.
	 *
	 * @param mixed $raw      Added questions sent by the edit screen.
	 * @param array $template Template, translated.
	 * @return array[]
	 */
	private static function sanitize_added( $raw, $template ) {
		$step_ids = wp_list_pluck( $template['steps'], 'id' );
		$clean    = array();
		$ids      = array();

		foreach ( is_array( $raw ) ? $raw : array() as $given ) {
			if ( count( $clean ) >= self::MAX_ADDED ) {
				break;
			}

			if ( ! is_array( $given ) || ! isset( $given['id'], $given['step'], $given['type'] ) || ! is_string( $given['id'] ) ) {
				continue;
			}

			$label = self::text( $given, 'label', self::MAX_LABEL, '' );

			if ( ! preg_match( '/^custom_[a-z0-9]{4,12}$/', $given['id'] ) || in_array( $given['id'], $ids, true )
				|| ! in_array( $given['step'], $step_ids, true ) || ! in_array( $given['type'], self::ADDED_TYPES, true ) || '' === $label ) {
				continue;
			}

			$field = array(
				'id'       => $given['id'],
				'step'     => $given['step'],
				'type'     => $given['type'],
				'label'    => $label,
				'help'     => self::text( $given, 'help', self::MAX_TEXT, '', true ),
				'required' => ! empty( $given['required'] ),
			);

			if ( 'choice' === $given['type'] ) {
				$field['options']  = self::sanitize_options( isset( $given['options'] ) ? $given['options'] : array() );
				$field['multiple'] = ! empty( $given['multiple'] );

				if ( ! $field['options'] ) {
					continue;
				}
			}

			if ( 'number' === $given['type'] ) {
				$unit = self::text( $given, 'unit', 10, '' );

				if ( '' !== $unit ) {
					$field['unit'] = $unit;
				}
			}

			$clean[] = $field;
			$ids[]   = $given['id'];
		}

		return $clean;
	}

	/**
	 * Keeps the valid answers of an added choice question.
	 *
	 * @param mixed $raw Answers sent.
	 * @return array[]
	 */
	private static function sanitize_options( $raw ) {
		$options = array();
		$values  = array();

		foreach ( is_array( $raw ) ? $raw : array() as $given ) {
			if ( count( $options ) >= self::MAX_OPTIONS ) {
				break;
			}

			$value = is_array( $given ) && isset( $given['value'] ) && is_string( $given['value'] ) ? $given['value'] : '';
			$label = is_array( $given ) ? self::text( $given, 'label', self::MAX_LABEL, '' ) : '';

			if ( ! preg_match( '/^[a-z0-9_]{1,20}$/', $value ) || in_array( $value, $values, true ) || '' === $label ) {
				continue;
			}

			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
			$values[]  = $value;
		}

		return $options;
	}

	/**
	 * Keeps the valid order of each step: known field identifiers, once.
	 *
	 * @param mixed   $raw      Order sent, keyed by step.
	 * @param array   $template Template, translated.
	 * @param array[] $added    Clean added questions.
	 * @return array<string, string[]>
	 */
	private static function sanitize_order( $raw, $template, $added ) {
		$clean = array();

		if ( ! is_array( $raw ) ) {
			return $clean;
		}

		foreach ( $template['steps'] as $step ) {
			if ( empty( $raw[ $step['id'] ] ) || ! is_array( $raw[ $step['id'] ] ) ) {
				continue;
			}

			$known = wp_list_pluck( $step['fields'], 'id' );

			foreach ( $added as $field ) {
				if ( $field['step'] === $step['id'] ) {
					$known[] = $field['id'];
				}
			}

			$order = array_values( array_unique( array_intersect( array_filter( $raw[ $step['id'] ], 'is_string' ), $known ) ) );

			// The natural order needs no saving.
			$natural = array_slice( $known, 0, count( $order ) );

			if ( $order && $natural !== $order ) {
				$clean[ $step['id'] ] = $order;
			}
		}

		return $clean;
	}

	/**
	 * Keeps the valid customizations of one question.
	 *
	 * @param mixed $given Customizations sent for the question.
	 * @param array $field Question, translated.
	 * @return array
	 */
	private static function sanitize_field( $given, $field ) {
		$given = is_array( $given ) ? $given : array();
		$entry = array();

		if ( ! empty( $given['hidden'] ) ) {
			$entry['hidden'] = true;
		}

		$label = self::text( $given, 'label', self::MAX_LABEL, $field['label'] );
		$help  = self::text( $given, 'help', self::MAX_TEXT, $field['help'], true );

		if ( '' !== $label ) {
			$entry['label'] = $label;
		}

		if ( '' !== $help ) {
			$entry['help'] = $help;
		}

		foreach ( isset( $field['options'] ) ? $field['options'] : array() as $option ) {
			$option_given = isset( $given['options'][ $option['value'] ] ) && is_array( $given['options'][ $option['value'] ] ) ? $given['options'][ $option['value'] ] : array();
			$option_entry = array();

			if ( ! empty( $option_given['hidden'] ) ) {
				$option_entry['hidden'] = true;
			}

			$option_label = self::text( $option_given, 'label', self::MAX_LABEL, $option['label'] );

			if ( '' !== $option_label ) {
				$option_entry['label'] = $option_label;
			}

			if ( $option_entry ) {
				$entry['options'][ $option['value'] ] = $option_entry;
			}
		}

		return $entry;
	}

	/**
	 * Applies the customizations of one question.
	 *
	 * @param array $field  Question, translated.
	 * @param array $custom Customizations of the question.
	 * @return array|null The question, or null when it is hidden.
	 */
	private static function apply_field( $field, $custom ) {
		if ( ! empty( $custom['hidden'] ) ) {
			return null;
		}

		foreach ( array( 'label', 'help' ) as $key ) {
			if ( ! empty( $custom[ $key ] ) ) {
				$field[ $key ] = $custom[ $key ];
			}
		}

		if ( ! isset( $field['options'] ) ) {
			return $field;
		}

		$options = array();

		foreach ( $field['options'] as $option ) {
			$option_custom = isset( $custom['options'][ $option['value'] ] ) ? $custom['options'][ $option['value'] ] : array();

			if ( ! empty( $option_custom['hidden'] ) ) {
				continue;
			}

			if ( ! empty( $option_custom['label'] ) ) {
				$option['label'] = $option_custom['label'];
			}

			$options[] = $option;
		}

		if ( ! $options ) {
			return null;
		}

		$field['options'] = $options;

		return $field;
	}

	/**
	 * Reads one text of the customizations, cleaned and cut. Returns an
	 * empty string when it is empty or equal to the template text, so that
	 * only real changes are saved.
	 *
	 * @param array  $given     Customizations of one item.
	 * @param string $key       Key of the text.
	 * @param int    $max       Longest length.
	 * @param string $original  Text of the template.
	 * @param bool   $multiline Whether line breaks are kept.
	 * @return string
	 */
	private static function text( $given, $key, $max, $original, $multiline = false ) {
		if ( ! isset( $given[ $key ] ) || ! is_string( $given[ $key ] ) ) {
			return '';
		}

		$text = $multiline ? sanitize_textarea_field( $given[ $key ] ) : sanitize_text_field( $given[ $key ] );
		$text = trim( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max ) );

		return $text === $original ? '' : $text;
	}
}
