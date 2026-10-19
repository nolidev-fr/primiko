<?php
/**
 * Display conditions.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the show_if conditions of steps and fields.
 *
 * The browser script, assets/form.js, holds the same rules. The server evaluates
 * them again on submission: a hidden field is never required, and its answer
 * is dropped.
 */
final class Conditions {

	/**
	 * Operators a condition can use.
	 */
	const OPERATORS = array( 'equals', 'in', 'not_in', 'filled' );

	/**
	 * Tells whether a condition is met.
	 *
	 * @param array|null $condition Condition, or null for always.
	 * @param array      $answers   Answers of the visible fields so far.
	 * @return bool
	 */
	public static function matches( $condition, $answers ) {
		if ( ! is_array( $condition ) ) {
			return true;
		}

		if ( isset( $condition['all'] ) ) {
			foreach ( (array) $condition['all'] as $inner ) {
				if ( ! self::matches( $inner, $answers ) ) {
					return false;
				}
			}

			return true;
		}

		if ( isset( $condition['any'] ) ) {
			foreach ( (array) $condition['any'] as $inner ) {
				if ( self::matches( $inner, $answers ) ) {
					return true;
				}
			}

			return false;
		}

		$field  = isset( $condition['field'] ) ? $condition['field'] : '';
		$values = self::values( isset( $answers[ $field ] ) ? $answers[ $field ] : null );

		if ( array_key_exists( 'equals', $condition ) ) {
			return in_array( (string) $condition['equals'], $values, true );
		}

		if ( isset( $condition['in'] ) ) {
			return (bool) array_intersect( array_map( 'strval', (array) $condition['in'] ), $values );
		}

		if ( isset( $condition['not_in'] ) ) {
			return ! array_intersect( array_map( 'strval', (array) $condition['not_in'] ), $values );
		}

		if ( isset( $condition['filled'] ) ) {
			$filled = array() !== $values;

			return $condition['filled'] ? $filled : ! $filled;
		}

		return true;
	}

	/**
	 * Tells whether each step and field is shown, in form order. A field
	 * hidden by its own condition or by its step counts as empty for the
	 * conditions that follow.
	 *
	 * @param array $schema  Form schema.
	 * @param array $answers Answers keyed by field identifier.
	 * @return array{steps: string[], fields: string[]} Identifiers shown.
	 */
	public static function visible( $schema, $answers ) {
		$known   = array();
		$visible = array(
			'steps'  => array(),
			'fields' => array(),
		);

		foreach ( $schema['steps'] as $step ) {
			if ( ! self::matches( isset( $step['show_if'] ) ? $step['show_if'] : null, $known ) ) {
				continue;
			}

			$visible['steps'][] = $step['id'];

			foreach ( $step['fields'] as $field ) {
				if ( ! self::matches( isset( $field['show_if'] ) ? $field['show_if'] : null, $known ) ) {
					continue;
				}

				$visible['fields'][] = $field['id'];

				if ( isset( $answers[ $field['id'] ] ) ) {
					$known[ $field['id'] ] = $answers[ $field['id'] ];
				}
			}
		}

		return $visible;
	}

	/**
	 * Turns an answer into the list of non-empty strings it holds.
	 *
	 * @param mixed $answer Answer: string, list of strings, or address.
	 * @return string[]
	 */
	private static function values( $answer ) {
		if ( null === $answer ) {
			return array();
		}

		$values = array();

		foreach ( (array) $answer as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$values[] = (string) $value;
			}
		}

		return $values;
	}
}
