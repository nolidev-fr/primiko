<?php
/**
 * Trade templates.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Loads, checks and translates the trade templates.
 *
 * A template is a JSON file: steps, fields, display conditions, help texts
 * and the messages sent to the visitor. The format is described in
 * docs/modele-metier.md. Nothing in the code depends on a given trade.
 *
 * Texts are written in English in the files and translated on load, through
 * the table in includes/template-strings.php.
 */
final class Templates {

	/**
	 * Version of the template format this code reads.
	 */
	const SCHEMA = 1;

	/**
	 * Field types a template can use.
	 */
	const FIELD_TYPES = array( 'text', 'textarea', 'number', 'choice', 'select', 'yesno', 'date', 'address', 'photos' );

	/**
	 * Field types only the built-in steps use.
	 */
	const BUILT_IN_TYPES = array( 'email', 'tel', 'consent' );

	/**
	 * Field identifiers taken by the built-in steps.
	 */
	const RESERVED_IDS = array( 'name', 'email', 'phone', 'consent' );

	/**
	 * Categories a template can declare (labels in categories()).
	 */
	const CATEGORY_IDS = array( 'building', 'outdoor', 'home', 'professional', 'general', 'other' );

	/**
	 * Step identifiers taken by the built-in steps.
	 */
	const RESERVED_STEPS = array( 'contact', 'review' );

	/**
	 * Returns the categories templates are grouped in, translated, in display
	 * order. A template without a known category goes to "other".
	 *
	 * @return array<string, string>
	 */
	public static function categories() {
		return array(
			'building'     => __( 'Building trades', 'primiko' ),
			'outdoor'      => __( 'Outdoor and earthworks', 'primiko' ),
			'home'         => __( 'Home services', 'primiko' ),
			'professional' => __( 'Professional services and advice', 'primiko' ),
			'general'      => __( 'General templates', 'primiko' ),
			'other'        => __( 'Other', 'primiko' ),
		);
	}

	/**
	 * Valid templates, keyed by identifier, as read from the files.
	 *
	 * @var array<string, array>|null
	 */
	private $templates = null;

	/**
	 * Returns every valid template, untranslated.
	 *
	 * @return array<string, array>
	 */
	public function all() {
		if ( null !== $this->templates ) {
			return $this->templates;
		}

		$this->templates = array();

		/**
		 * Filters the folders the trade templates are read from.
		 *
		 * A theme or another plugin can add its own folder of JSON files. A
		 * template with the same identifier as a bundled one replaces it.
		 *
		 * @param string[] $dirs Absolute paths.
		 */
		$dirs = (array) apply_filters( 'primiko_template_dirs', array( PRIMIKO_DIR . 'templates' ) );

		foreach ( $dirs as $dir ) {
			$files = glob( trailingslashit( $dir ) . '*.json' );

			foreach ( $files ? $files : array() as $file ) {
				$template = $this->read( $file );

				if ( null !== $template ) {
					$this->templates[ $template['id'] ] = $template;
				}
			}
		}

		return $this->templates;
	}

	/**
	 * Returns one template, untranslated.
	 *
	 * @param string $id Template identifier.
	 * @return array|null
	 */
	public function get( $id ) {
		$all = $this->all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Returns the translated title of each template, sorted by title.
	 *
	 * @return array<string, string>
	 */
	public function choices() {
		$choices = array();

		foreach ( $this->all() as $id => $template ) {
			$choices[ $id ] = $this->translate( $template['title'], $template );
		}

		// Sorted as a reader expects: "Électricien" with the E, not after Z.
		uasort(
			$choices,
			static function ( $a, $b ) {
				return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
			}
		);

		return $choices;
	}

	/**
	 * Returns one template, translated, without the customizations of any
	 * form and without the built-in steps.
	 *
	 * @param string $id Template identifier.
	 * @return array|null
	 */
	public function localized( $id ) {
		$template = $this->get( $id );

		return null === $template ? null : $this->localize( $template );
	}

	/**
	 * Returns the full schema of a form: its template, translated, with the
	 * customizations of the business, followed by the built-in contact and
	 * review steps.
	 *
	 * @param int $form_id Form post ID.
	 * @return array|null Null when the form has no valid template.
	 */
	public function form_schema( $form_id ) {
		$template = $this->localized( (string) get_post_meta( $form_id, '_primiko_template', true ) );

		if ( null === $template ) {
			return null;
		}

		$schema          = Customizations::apply( $template, Customizations::get( $form_id, $template['id'] ) );
		$notice          = ! empty( $schema['messages']['privacy_notice'] ) ? $schema['messages']['privacy_notice'] : Mailer::default_message( 'privacy_notice' );
		$schema['steps'] = array_merge(
			$schema['steps'],
			$this->built_in_steps(
				(bool) get_post_meta( $form_id, '_primiko_email_optional', true ),
				strtr( $notice, array( '{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) )
			)
		);

		/**
		 * Filters the schema of a form before it is shown or checked.
		 *
		 * The same schema is used to render the form and to validate what the
		 * visitor sends, so a change here applies to both.
		 *
		 * @param array $schema  Translated template with the built-in steps.
		 * @param int   $form_id Form post ID.
		 */
		return apply_filters( 'primiko_form_schema', $schema, $form_id );
	}

	/**
	 * Returns every field of a schema, keyed by identifier, with the
	 * identifier of its step.
	 *
	 * @param array $schema Form schema.
	 * @return array<string, array>
	 */
	public static function fields( $schema ) {
		$fields = array();

		foreach ( $schema['steps'] as $step ) {
			foreach ( $step['fields'] as $field ) {
				$field['step']          = $step['id'];
				$fields[ $field['id'] ] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Contact and review steps, added at the end of every form so that no
	 * template can leave out the contact details or the consent.
	 *
	 * @param bool   $email_optional Whether the visitor may leave the email
	 *                               empty: the phone number then stays the
	 *                               only way back, and no copy is sent.
	 * @param string $notice         Privacy notice shown under the consent box.
	 * @return array[]
	 */
	private function built_in_steps( $email_optional = false, $notice = '' ) {
		return array(
			array(
				'id'     => 'contact',
				'title'  => __( 'Your contact details', 'primiko' ),
				'fields' => array(
					array(
						'id'           => 'name',
						'type'         => 'text',
						'label'        => __( 'Your name', 'primiko' ),
						'required'     => true,
						'autocomplete' => 'name',
						'maxlength'    => 100,
					),
					array(
						'id'           => 'email',
						'type'         => 'email',
						'label'        => __( 'Email', 'primiko' ),
						'help'         => $email_optional
							? __( 'Optional. A copy of your request will be sent to this address.', 'primiko' )
							: __( 'A copy of your request will be sent to this address.', 'primiko' ),
						'required'     => ! $email_optional,
						'autocomplete' => 'email',
					),
					array(
						'id'           => 'phone',
						'type'         => 'tel',
						'label'        => __( 'Phone', 'primiko' ),
						'help'         => __( 'To call you back about your project.', 'primiko' ),
						'required'     => true,
						'autocomplete' => 'tel',
					),
				),
			),
			array(
				'id'     => 'review',
				'title'  => __( 'Review and send', 'primiko' ),
				'review' => true,
				'fields' => array(
					array(
						'id'       => 'consent',
						'type'     => 'consent',
						'label'    => sprintf(
							/* translators: %s: site name. */
							__( 'I agree that %s uses the information in this form to answer my request and contact me about it.', 'primiko' ),
							wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
						),
						'required' => true,
						'notice'   => $notice,
					),
				),
			),
		);
	}

	/**
	 * Reads and checks one template file.
	 *
	 * @param string $file Absolute path.
	 * @return array|null Null when the file is not a valid template.
	 */
	private function read( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local JSON file shipped with the plugin.
		$json = file_get_contents( $file );
		$data = false === $json ? null : json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			$this->report( $file, 'not valid JSON' );
			return null;
		}

		$error = $this->validate( $data );

		if ( '' !== $error ) {
			$this->report( $file, $error );
			return null;
		}

		return $this->normalize( $data );
	}

	/**
	 * Checks the structure of a template.
	 *
	 * @param array $data Decoded JSON.
	 * @return string Empty when valid, otherwise what is wrong.
	 */
	private function validate( $data ) {
		if ( ! isset( $data['schema'] ) || self::SCHEMA !== $data['schema'] ) {
			return 'schema must be ' . self::SCHEMA;
		}

		if ( empty( $data['id'] ) || ! is_string( $data['id'] ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $data['id'] ) ) {
			return 'id must be lowercase letters, digits, dashes or underscores';
		}

		if ( empty( $data['title'] ) || ! is_string( $data['title'] ) ) {
			return 'title is missing';
		}

		if ( empty( $data['steps'] ) || ! is_array( $data['steps'] ) ) {
			return 'steps is missing';
		}

		$step_ids  = array();
		$field_ids = array();

		foreach ( $data['steps'] as $index => $step ) {
			$where = 'step ' . ( $index + 1 );

			if ( ! is_array( $step ) || empty( $step['id'] ) || ! is_string( $step['id'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $step['id'] ) ) {
				return $where . ': id must be lowercase letters, digits or underscores';
			}

			if ( in_array( $step['id'], $step_ids, true ) || in_array( $step['id'], self::RESERVED_STEPS, true ) ) {
				return $where . ': id "' . $step['id'] . '" is already used or reserved';
			}

			if ( empty( $step['title'] ) || ! is_string( $step['title'] ) ) {
				return $where . ': title is missing';
			}

			if ( empty( $step['fields'] ) || ! is_array( $step['fields'] ) ) {
				return $where . ': fields is missing';
			}

			if ( isset( $step['show_if'] ) ) {
				$error = $this->validate_condition( $step['show_if'], $field_ids );

				if ( '' !== $error ) {
					return $where . ': ' . $error;
				}
			}

			foreach ( $step['fields'] as $field ) {
				$error = $this->validate_field( $field, $field_ids );

				if ( '' !== $error ) {
					return $where . ': ' . $error;
				}

				$field_ids[] = $field['id'];
			}

			$step_ids[] = $step['id'];
		}

		return '';
	}

	/**
	 * Checks one field.
	 *
	 * @param mixed    $field     Field definition.
	 * @param string[] $known_ids Fields defined before this one.
	 * @return string Empty when valid, otherwise what is wrong.
	 */
	private function validate_field( $field, $known_ids ) {
		if ( ! is_array( $field ) || empty( $field['id'] ) || ! is_string( $field['id'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $field['id'] ) ) {
			return 'field id must be lowercase letters, digits or underscores';
		}

		$where = 'field "' . $field['id'] . '"';

		if ( in_array( $field['id'], $known_ids, true ) || in_array( $field['id'], self::RESERVED_IDS, true ) ) {
			return $where . ': id is already used or reserved';
		}

		if ( empty( $field['type'] ) || ! in_array( $field['type'], self::FIELD_TYPES, true ) ) {
			return $where . ': unknown type';
		}

		if ( empty( $field['label'] ) || ! is_string( $field['label'] ) ) {
			return $where . ': label is missing';
		}

		if ( in_array( $field['type'], array( 'choice', 'select' ), true ) ) {
			if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
				return $where . ': options is missing';
			}

			$values = array();

			foreach ( $field['options'] as $option ) {
				if ( ! is_array( $option ) || ! isset( $option['value'], $option['label'] ) || ! is_string( $option['value'] ) || '' === $option['value'] || ! is_string( $option['label'] ) ) {
					return $where . ': each option needs a value and a label';
				}

				if ( in_array( $option['value'], $values, true ) ) {
					return $where . ': option "' . $option['value'] . '" is listed twice';
				}

				$values[] = $option['value'];
			}
		}

		if ( isset( $field['show_if'] ) ) {
			$error = $this->validate_condition( $field['show_if'], $known_ids );

			if ( '' !== $error ) {
				return $where . ': ' . $error;
			}
		}

		return '';
	}

	/**
	 * Checks a display condition. A condition can only look at fields defined
	 * before it, which keeps the evaluation a single pass in form order.
	 *
	 * @param mixed    $condition Condition.
	 * @param string[] $known_ids Fields defined before.
	 * @return string Empty when valid, otherwise what is wrong.
	 */
	private function validate_condition( $condition, $known_ids ) {
		if ( ! is_array( $condition ) ) {
			return 'show_if must be an object';
		}

		foreach ( array( 'all', 'any' ) as $group ) {
			if ( isset( $condition[ $group ] ) ) {
				if ( ! is_array( $condition[ $group ] ) || ! $condition[ $group ] ) {
					return 'show_if.' . $group . ' must list conditions';
				}

				foreach ( $condition[ $group ] as $inner ) {
					$error = $this->validate_condition( $inner, $known_ids );

					if ( '' !== $error ) {
						return $error;
					}
				}

				return '';
			}
		}

		if ( empty( $condition['field'] ) || ! in_array( $condition['field'], $known_ids, true ) ) {
			return 'show_if must refer to a field defined earlier';
		}

		$operators = array_intersect( array_keys( $condition ), Conditions::OPERATORS );

		if ( 1 !== count( $operators ) ) {
			return 'show_if needs exactly one of ' . implode( ', ', Conditions::OPERATORS );
		}

		return '';
	}

	/**
	 * Fills in the optional keys so that the rest of the code can rely on them.
	 *
	 * @param array $data Valid template.
	 * @return array
	 */
	private function normalize( $data ) {
		$data['description'] = isset( $data['description'] ) ? (string) $data['description'] : '';
		$data['keywords']    = isset( $data['keywords'] ) && is_string( $data['keywords'] ) ? $data['keywords'] : '';
		$data['category']    = isset( $data['category'] ) && in_array( $data['category'], self::CATEGORY_IDS, true ) ? $data['category'] : 'other';
		$data['messages']    = isset( $data['messages'] ) && is_array( $data['messages'] ) ? $data['messages'] : array();

		foreach ( $data['steps'] as &$step ) {
			$step['description'] = isset( $step['description'] ) ? (string) $step['description'] : '';

			foreach ( $step['fields'] as &$field ) {
				$field['required'] = ! empty( $field['required'] );
				$field['help']     = isset( $field['help'] ) ? (string) $field['help'] : '';

				if ( 'choice' === $field['type'] ) {
					$field['multiple'] = ! empty( $field['multiple'] );
				}

				if ( 'photos' === $field['type'] ) {
					$field['max'] = isset( $field['max'] ) ? max( 1, min( 10, (int) $field['max'] ) ) : 6;
				}
			}
			unset( $field );
		}
		unset( $step );

		return $data;
	}

	/**
	 * Translates every text of a template.
	 *
	 * @param array $template Template.
	 * @return array
	 */
	private function localize( $template ) {
		foreach ( array( 'title', 'description', 'keywords' ) as $key ) {
			$template[ $key ] = $this->translate( $template[ $key ], $template );
		}

		foreach ( $template['messages'] as $key => $text ) {
			$template['messages'][ $key ] = is_string( $text ) ? $this->translate( $text, $template ) : '';
		}

		foreach ( $template['steps'] as &$step ) {
			$step['title']       = $this->translate( $step['title'], $template );
			$step['description'] = $this->translate( $step['description'], $template );

			foreach ( $step['fields'] as &$field ) {
				foreach ( array( 'label', 'help', 'placeholder', 'unit' ) as $key ) {
					if ( isset( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
						$field[ $key ] = $this->translate( $field[ $key ], $template );
					}
				}

				if ( isset( $field['options'] ) ) {
					foreach ( $field['options'] as &$option ) {
						$option['label'] = $this->translate( $option['label'], $template );

						if ( isset( $option['help'] ) && is_string( $option['help'] ) ) {
							$option['help'] = $this->translate( $option['help'], $template );
						}
					}
					unset( $option );
				}
			}
			unset( $field );
		}
		unset( $step );

		return $template;
	}

	/**
	 * Translates one text of a template.
	 *
	 * The texts of the bundled templates are translated through the table in
	 * includes/template-strings.php, generated from the templates. A template
	 * from a theme or another plugin is translated with the filter.
	 *
	 * @param string $text     Text in English.
	 * @param array  $template Template the text belongs to.
	 * @return string
	 */
	private function translate( $text, $template ) {
		static $table = null;

		if ( '' === $text ) {
			return '';
		}

		if ( null === $table ) {
			require_once PRIMIKO_DIR . 'includes/template-strings.php';
			$table = primiko_template_strings();
		}

		$translated = isset( $table[ $text ] ) ? $table[ $text ] : $text;

		/**
		 * Filters the translation of a template text.
		 *
		 * @param string $translated Translated text, or the English text when unknown.
		 * @param string $text       Text in English, as written in the template.
		 * @param string $template   Template identifier.
		 */
		return (string) apply_filters( 'primiko_template_text', $translated, $text, $template['id'] );
	}

	/**
	 * Tells developers why a template was skipped.
	 *
	 * @param string $file  Template file.
	 * @param string $error What is wrong.
	 */
	private function report( $file, $error ) {
		_doing_it_wrong(
			__METHOD__,
			esc_html( sprintf( 'Quote template %1$s skipped: %2$s.', basename( $file ), $error ) ),
			'1.0.0'
		);
	}
}
