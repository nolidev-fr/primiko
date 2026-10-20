<?php
/**
 * Visitor form markup.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML of a form, shared by the block and the shortcode.
 *
 * Every step is rendered on the server. assets/form.js then shows one step at
 * a time, applies the conditions and sends the request through the REST API.
 * The form stays hidden without JavaScript, since it could not be sent.
 */
final class Renderer {

	/**
	 * Trade templates.
	 *
	 * @var Templates
	 */
	private $templates;

	/**
	 * Number of forms rendered on the page, to keep element IDs unique.
	 *
	 * @var int
	 */
	private $count = 0;

	/**
	 * Heading level of the step titles of the form being rendered.
	 *
	 * @var int
	 */
	private $heading = 3;

	/**
	 * Sets up the renderer.
	 *
	 * @param Templates $templates Trade templates.
	 */
	public function __construct( Templates $templates ) {
		$this->templates = $templates;
	}

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_filter( 'style_loader_tag', array( $this, 'async_style' ), 10, 2 );
	}

	/**
	 * Loads the form stylesheet without holding the page: the form is often
	 * far down the page, and the script shows it once the stylesheet applies.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Style handle.
	 * @return string
	 */
	public function async_style( $tag, $handle ) {
		if ( 'primiko-form' !== $handle || false !== strpos( $tag, 'onload=' ) ) {
			return $tag;
		}

		return preg_replace( "/media=(['\"])all\\1/", 'media="print" onload="this.media=\'all\'"', $tag, 1 );
	}

	/**
	 * Registers the visitor script and style. They are only enqueued on pages
	 * that show a form.
	 */
	public function register_assets() {
		// The minified build of assets/form.js, versioned by its content.
		$asset = file_exists( PRIMIKO_DIR . 'build/form/form.asset.php' )
			? require PRIMIKO_DIR . 'build/form/form.asset.php'
			: array( 'version' => PRIMIKO_VERSION );

		wp_register_script(
			'primiko-form',
			PRIMIKO_URL . 'build/form/form.js',
			array(),
			$asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_style( 'primiko-form', PRIMIKO_URL . 'assets/form.css', array(), PRIMIKO_VERSION . '-' . filemtime( PRIMIKO_DIR . 'assets/form.css' ) );
	}

	/**
	 * Returns the HTML of a form.
	 *
	 * @param int $form_id Form post ID.
	 * @param int $heading Heading level of the step titles, 2 to 4. 3 by
	 *                     default: the form usually sits under the title
	 *                     of its section.
	 * @return string Empty when the form cannot be shown.
	 */
	public function render( $form_id, $heading = 3 ) {
		$this->heading = max( 2, min( 4, (int) $heading ) );

		$form   = get_post( $form_id );
		$schema = $form && Post_Types::FORM === $form->post_type && 'publish' === $form->post_status
			? $this->templates->form_schema( $form->ID )
			: null;

		if ( null === $schema ) {
			return $this->unavailable_notice();
		}

		++$this->count;
		$prefix = 'primiko-' . $this->count . '-';

		wp_enqueue_script( 'primiko-form' );
		wp_enqueue_style( 'primiko-form' );
		Captcha::enqueue();

		ob_start();
		?>
		<div class="primiko" data-primiko="<?php echo esc_attr( wp_json_encode( $this->config( $form->ID, $schema, $prefix ) ) ); ?>">
			<form class="primiko__form" novalidate hidden aria-label="<?php echo esc_attr( get_the_title( $form ) ); ?>">
				<?php if ( empty( $schema['single'] ) ) : ?>
					<div class="primiko__progress" aria-hidden="true">
						<div class="primiko__bar"><span></span></div>
					</div>
				<?php endif; ?>
				<p class="primiko__sr" aria-live="polite" data-live></p>

				<div class="primiko__alert" role="alert" hidden></div>

				<?php
				foreach ( $schema['steps'] as $step ) {
					$this->step( $step, $prefix );
				}
				?>

				<div class="primiko__trap" aria-hidden="true">
					<label for="<?php echo esc_attr( $prefix . 'website' ); ?>"><?php esc_html_e( 'Leave this field empty', 'primiko' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $prefix . 'website' ); ?>" name="website" tabindex="-1" autocomplete="off">
				</div>

				<div class="primiko__nav">
					<button type="button" class="primiko__button primiko__button--secondary" data-action="prev"><?php esc_html_e( 'Back', 'primiko' ); ?></button>
					<button type="button" class="primiko__button wp-element-button" data-action="next"><?php esc_html_e( 'Next', 'primiko' ); ?></button>
					<button type="submit" class="primiko__button wp-element-button" data-action="submit" hidden><?php empty( $schema['single'] ) ? esc_html_e( 'Send my request', 'primiko' ) : esc_html_e( 'Send my message', 'primiko' ); ?></button>
				</div>
			</form>

			<div class="primiko__done" tabindex="-1" hidden></div>

			<noscript>
				<p class="primiko__noscript"><?php esc_html_e( 'This form needs JavaScript. Please enable it in your browser, or use the phone number or email address shown on this site.', 'primiko' ); ?></p>
			</noscript>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Tells editors why a form is missing. Visitors see nothing.
	 *
	 * @return string
	 */
	private function unavailable_notice() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return '';
		}

		return '<p class="primiko__notice">' . esc_html__( 'This quote form cannot be shown: it is not published or its trade template is missing. Only editors see this message.', 'primiko' ) . '</p>';
	}

	/**
	 * Settings read by assets/form.js.
	 *
	 * @param int    $form_id Form post ID.
	 * @param array  $schema  Form schema.
	 * @param string $prefix  Element ID prefix.
	 * @return array
	 */
	private function config( $form_id, $schema, $prefix ) {
		$steps  = array();
		$fields = array();

		foreach ( $schema['steps'] as $step ) {
			$steps[] = array(
				'id'     => $step['id'],
				'title'  => $step['title'],
				'showIf' => isset( $step['show_if'] ) ? $step['show_if'] : null,
				'review' => ! empty( $step['review'] ),
			);

			foreach ( $step['fields'] as $field ) {
				$entry = array(
					'type'     => $field['type'],
					'step'     => $step['id'],
					'label'    => $field['label'],
					'required' => ! empty( $field['required'] ),
					'showIf'   => isset( $field['show_if'] ) ? $field['show_if'] : null,
				);

				foreach ( array( 'multiple', 'min', 'max', 'unit', 'maxlength' ) as $key ) {
					if ( isset( $field[ $key ] ) ) {
						$entry[ $key ] = $field[ $key ];
					}
				}

				if ( isset( $field['options'] ) ) {
					$entry['options'] = wp_list_pluck( $field['options'], 'label', 'value' );
				}

				$fields[ $field['id'] ] = $entry;
			}
		}

		return array(
			'form'      => $form_id,
			'prefix'    => $prefix,
			'heading'   => $this->heading,
			'tokenUrl'  => rest_url( Rest::NAMESPACE . '/token' ),
			'submitUrl' => rest_url( Rest::NAMESPACE . '/submit' ),
			'steps'     => $steps,
			'fields'    => $fields,
			'photoSize' => 2000,
			'minFill'   => Token::min_fill_time(),
			'single'    => ! empty( $schema['single'] ),
			'either'    => ! empty( $schema['contact_either'] ),
			'captcha'   => Captcha::config(),
			'icons'     => array(
				'close' => self::icon( 'close' ),
				'check' => self::icon( 'check' ),
			),
			'i18n'      => array(
				/* translators: 1: current step number, 2: number of steps, 3: step title. */
				'progress'      => __( 'Step %1$d of %2$d: %3$s', 'primiko' ),
				'required'      => __( 'This field is required.', 'primiko' ),
				'requiredOne'   => __( 'Please choose an answer.', 'primiko' ),
				'email'         => __( 'Please enter a valid email address, such as name@example.com.', 'primiko' ),
				'phone'         => __( 'Please enter a valid phone number.', 'primiko' ),
				'number'        => __( 'Please enter a number.', 'primiko' ),
				/* translators: 1: minimum, 2: maximum. */
				'range'         => __( 'Please enter a number between %1$s and %2$s.', 'primiko' ),
				'address'       => __( 'Please fill in the street, postcode and town.', 'primiko' ),
				'consent'       => __( 'Please tick this box to send your request.', 'primiko' ),
				'photosNeeded'  => __( 'Please add at least one photo.', 'primiko' ),
				/* translators: %d: maximum number of photos. */
				'photosMax'     => __( 'You can add up to %d photos.', 'primiko' ),
				'photoBad'      => __( 'Your browser could not read this photo. Please choose a JPEG, PNG or WebP photo. An iPhone photo in HEIC format can be sent from the iPhone itself.', 'primiko' ),
				/* translators: %d: photo number. */
				'photoRemove'   => __( 'Remove photo %d', 'primiko' ),
				'photoWorking'  => __( 'Preparing the photos...', 'primiko' ),
				'checkFields'   => __( 'Some answers need your attention. Please check the fields marked below.', 'primiko' ),
				'notSpecified'  => __( 'Not specified', 'primiko' ),
				'yes'           => __( 'Yes', 'primiko' ),
				'no'            => __( 'No', 'primiko' ),
				'edit'          => __( 'Edit', 'primiko' ),
				/* translators: %s: step title. */
				'editStep'      => __( 'Edit: %s', 'primiko' ),
				'sending'       => __( 'Sending...', 'primiko' ),
				'sendFailed'    => __( 'Your request could not be sent. Please check your connection and try again.', 'primiko' ),
				'captchaWait'   => __( 'Please complete the anti-spam check above the button, then send again.', 'primiko' ),
				'contactEither' => __( 'Please give a phone number or an email address.', 'primiko' ),
				'photoCounts'   => $this->photo_strings( 'count' ),
				'photosSummary' => $this->photo_strings( 'summary' ),
			),
		);
	}

	/**
	 * Photo counts with their plural form, one string per number of photos
	 * (0 to the largest a field allows): the script has no plural rules of
	 * its own.
	 *
	 * @param string $kind 'count' for the counter under the picker, 'summary'
	 *                     for the review step.
	 * @return string[] Strings indexed by number of photos.
	 */
	private function photo_strings( $kind ) {
		$strings = array();

		for ( $number = 0; $number <= 10; $number++ ) {
			$strings[] = 'count' === $kind
				/* translators: 1: number of photos added, 2: maximum. */
				? _n( '%1$d of %2$d photo', '%1$d of %2$d photos', $number, 'primiko' )
				/* translators: %d: number of photos. */
				: _n( '%d photo', '%d photos', $number, 'primiko' );
		}

		return $strings;
	}

	/**
	 * Prints one step.
	 *
	 * @param array  $step   Step.
	 * @param string $prefix Element ID prefix.
	 */
	private function step( $step, $prefix ) {
		$id = $prefix . 'step-' . $step['id'];
		?>
		<fieldset class="primiko__step" data-step="<?php echo esc_attr( $step['id'] ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>" hidden>
			<h<?php echo (int) $this->heading; ?> class="primiko__step-title" id="<?php echo esc_attr( $id ); ?>" tabindex="-1"><?php echo esc_html( $step['title'] ); ?></h<?php echo (int) $this->heading; ?>>

			<?php if ( ! empty( $step['description'] ) ) : ?>
				<p class="primiko__step-description"><?php echo esc_html( $step['description'] ); ?></p>
			<?php endif; ?>

			<?php if ( ! empty( $step['review'] ) ) : ?>
				<div class="primiko__summary" data-summary></div>
			<?php endif; ?>

			<?php
			foreach ( $step['fields'] as $field ) {
				$this->field( $field, $prefix );
			}
			?>

			<?php if ( ! empty( $step['review'] ) && Captcha::provider() ) : ?>
				<div class="primiko__captcha" data-captcha></div>
			<?php endif; ?>
		</fieldset>
		<?php
	}

	/**
	 * Prints one field with its label, help and error message.
	 *
	 * @param array  $field  Field.
	 * @param string $prefix Element ID prefix.
	 */
	private function field( $field, $prefix ) {
		$id      = $prefix . $field['id'];
		$help    = empty( $field['help'] ) ? '' : $id . '-help';
		$grouped = in_array( $field['type'], array( 'choice', 'yesno', 'address', 'consent' ), true );
		$classes = 'primiko__field primiko__field--' . $field['type'];
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-field="<?php echo esc_attr( $field['id'] ); ?>">
			<?php if ( $grouped && 'consent' !== $field['type'] ) : ?>
				<fieldset class="primiko__group"<?php echo $help ? ' aria-describedby="' . esc_attr( $help ) . '"' : ''; ?>>
					<legend class="primiko__label"><?php $this->label_text( $field ); ?></legend>
					<?php $this->help( $field, $help ); ?>
					<?php $this->control( $field, $id, $help ); ?>
				</fieldset>
			<?php elseif ( 'consent' === $field['type'] ) : ?>
				<?php $this->control( $field, $id, $help ); ?>
			<?php else : ?>
				<label class="primiko__label" for="<?php echo esc_attr( $id ); ?>"><?php $this->label_text( $field ); ?></label>
				<?php $this->help( $field, $help ); ?>
				<?php $this->control( $field, $id, $help ); ?>
			<?php endif; ?>
			<p class="primiko__error" id="<?php echo esc_attr( $id . '-error' ); ?>" hidden></p>
		</div>
		<?php
	}

	/**
	 * Prints the label text, with a mark on required fields.
	 *
	 * @param array $field Field.
	 */
	private function label_text( $field ) {
		echo esc_html( $field['label'] );

		if ( ! empty( $field['required'] ) ) {
			echo ' <span class="primiko__required" aria-hidden="true">*</span>';
			echo '<span class="screen-reader-text primiko__sr"> ' . esc_html__( '(required)', 'primiko' ) . '</span>';
		}
	}

	/**
	 * Prints the help text.
	 *
	 * @param array  $field   Field.
	 * @param string $help_id Help element ID, empty when there is none.
	 */
	private function help( $field, $help_id ) {
		if ( '' !== $help_id ) {
			echo '<p class="primiko__help" id="' . esc_attr( $help_id ) . '">' . esc_html( $field['help'] ) . '</p>';
		}
	}

	/**
	 * Prints the input of a field.
	 *
	 * @param array  $field   Field.
	 * @param string $id      Element ID.
	 * @param string $help_id Help element ID, empty when there is none.
	 */
	private function control( $field, $id, $help_id ) {
		$describedby = $help_id ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
		$required    = ! empty( $field['required'] ) ? ' aria-required="true"' : '';
		$placeholder = ! empty( $field['placeholder'] ) ? ' placeholder="' . esc_attr( $field['placeholder'] ) . '"' : '';
		$name        = $field['id'];

		switch ( $field['type'] ) {
			case 'textarea':
				printf(
					'<textarea class="primiko__input" id="%1$s" name="%2$s" rows="4" maxlength="%3$d"%4$s%5$s%6$s></textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 5000,
					$describedby, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
					$placeholder // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
				break;

			case 'number':
				echo '<span class="primiko__unit-wrap">';
				printf(
					'<input class="primiko__input primiko__input--number" type="text" inputmode="decimal" id="%1$s" name="%2$s" autocomplete="off"%3$s%4$s%5$s>',
					esc_attr( $id ),
					esc_attr( $name ),
					$describedby, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
					$placeholder // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);

				if ( ! empty( $field['unit'] ) ) {
					echo '<span class="primiko__unit" aria-hidden="true">' . esc_html( $field['unit'] ) . '</span>';
				}
				echo '</span>';
				break;

			case 'choice':
			case 'yesno':
				$this->options( $field, $id );
				break;

			case 'select':
				printf( '<select class="primiko__input" id="%1$s" name="%2$s"%3$s%4$s>', esc_attr( $id ), esc_attr( $name ), $describedby, $required ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				echo '<option value="">' . esc_html__( 'Choose...', 'primiko' ) . '</option>';

				foreach ( $field['options'] as $option ) {
					echo '<option value="' . esc_attr( $option['value'] ) . '">' . esc_html( $option['label'] ) . '</option>';
				}
				echo '</select>';
				break;

			case 'address':
				$this->address( $field, $id );
				break;

			case 'photos':
				$this->photos( $field, $id, $describedby );
				break;

			case 'consent':
				$this->consent( $field, $id );
				break;

			default:
				$types = array(
					'email' => 'email',
					'tel'   => 'tel',
					'date'  => 'date',
				);
				$type  = isset( $types[ $field['type'] ] ) ? $types[ $field['type'] ] : 'text';

				printf(
					'<input class="primiko__input" type="%1$s" id="%2$s" name="%3$s" maxlength="%4$d"%5$s%6$s%7$s%8$s>',
					esc_attr( $type ),
					esc_attr( $id ),
					esc_attr( $name ),
					isset( $field['maxlength'] ) ? (int) $field['maxlength'] : 200,
					! empty( $field['autocomplete'] ) ? ' autocomplete="' . esc_attr( $field['autocomplete'] ) . '"' : '',
					$describedby, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					$required, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static attribute.
					$placeholder // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
		}
	}

	/**
	 * Prints the options of a choice or yes/no field as cards.
	 *
	 * @param array  $field Field.
	 * @param string $id    Element ID.
	 */
	private function options( $field, $id ) {
		$options = 'yesno' === $field['type']
			? array(
				array(
					'value' => 'yes',
					'label' => __( 'Yes', 'primiko' ),
				),
				array(
					'value' => 'no',
					'label' => __( 'No', 'primiko' ),
				),
			)
			: $field['options'];
		$type    = ! empty( $field['multiple'] ) ? 'checkbox' : 'radio';
		$layout  = 'yesno' === $field['type'] ? ' primiko__options--inline' : '';

		// Many short answers fit on two columns when the form is wide enough.
		if ( '' === $layout && count( $options ) >= 6 && max( array_map( 'mb_strlen', wp_list_pluck( $options, 'label' ) ) ) <= 32 ) {
			$layout = ' primiko__options--columns';
		}

		echo '<div class="primiko__options' . esc_attr( $layout ) . '">';

		foreach ( $options as $index => $option ) {
			$option_id = $id . '-' . $index;
			?>
			<label class="primiko__option" for="<?php echo esc_attr( $option_id ); ?>">
				<input type="<?php echo esc_attr( $type ); ?>" id="<?php echo esc_attr( $option_id ); ?>" name="<?php echo esc_attr( $field['id'] ); ?>" value="<?php echo esc_attr( $option['value'] ); ?>">
				<span class="primiko__option-text">
					<span class="primiko__option-label"><?php echo esc_html( $option['label'] ); ?></span>
					<?php if ( ! empty( $option['help'] ) ) : ?>
						<span class="primiko__option-help"><?php echo esc_html( $option['help'] ); ?></span>
					<?php endif; ?>
				</span>
			</label>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Prints the three inputs of an address.
	 *
	 * @param array  $field Field.
	 * @param string $id    Element ID.
	 */
	private function address( $field, $id ) {
		$parts = array(
			'street'   => array( __( 'Street and number', 'primiko' ), 'address-line1', 'text' ),
			'postcode' => array( __( 'Postcode', 'primiko' ), 'postal-code', 'numeric' ),
			'city'     => array( __( 'Town', 'primiko' ), 'address-level2', 'text' ),
		);

		echo '<div class="primiko__address">';

		foreach ( $parts as $part => $settings ) {
			$part_id = $id . '-' . $part;
			?>
			<div class="primiko__address-part primiko__address-part--<?php echo esc_attr( $part ); ?>">
				<label class="primiko__sublabel" for="<?php echo esc_attr( $part_id ); ?>"><?php echo esc_html( $settings[0] ); ?></label>
				<input class="primiko__input" type="text" id="<?php echo esc_attr( $part_id ); ?>" name="<?php echo esc_attr( $field['id'] . '[' . $part . ']' ); ?>" data-part="<?php echo esc_attr( $part ); ?>" autocomplete="<?php echo esc_attr( $settings[1] ); ?>" inputmode="<?php echo esc_attr( $settings[2] ); ?>" maxlength="200"<?php echo ! empty( $field['required'] ) ? ' aria-required="true"' : ''; ?>>
			</div>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Prints the photo picker. The file input opens the camera or the photo
	 * library on a phone. The script keeps the chosen photos, resized, until
	 * the request is sent.
	 *
	 * @param array  $field       Field.
	 * @param string $id          Element ID.
	 * @param string $describedby Escaped aria-describedby attribute, or empty.
	 */
	private function photos( $field, $id, $describedby ) {
		?>
		<div class="primiko__photos" data-max="<?php echo esc_attr( (string) $field['max'] ); ?>">
			<span class="primiko__picker">
				<span class="primiko__button primiko__button--secondary" aria-hidden="true"><?php echo self::icon( 'camera' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><?php esc_html_e( 'Add photos', 'primiko' ); ?></span>
				<input class="primiko__file" type="file" id="<?php echo esc_attr( $id ); ?>" accept="image/*" multiple<?php echo $describedby; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the caller. ?>>
			</span>
			<p class="primiko__photo-count" aria-live="polite"></p>
			<ul class="primiko__previews"></ul>
		</div>
		<?php
	}

	/**
	 * Prints the consent checkbox, with a link to the privacy policy when the
	 * site has one.
	 *
	 * @param array  $field Field.
	 * @param string $id    Element ID.
	 */
	private function consent( $field, $id ) {
		$policy = get_privacy_policy_url();
		?>
		<div class="primiko__consent">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['id'] ); ?>" value="1" aria-required="true">
			<label for="<?php echo esc_attr( $id ); ?>"><?php $this->label_text( $field ); ?></label>
		</div>
		<?php if ( ! empty( $field['notice'] ) ) : ?>
			<p class="primiko__help primiko__privacy"><?php echo nl2br( esc_html( $field['notice'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( 'recaptcha' === Captcha::provider() ) : ?>
			<p class="primiko__help">
				<?php
				printf(
					/* translators: 1: link to the Google privacy policy, 2: link to the Google terms of service. */
					esc_html__( 'This form is protected by reCAPTCHA: the Google %1$s and %2$s apply.', 'primiko' ),
					'<a href="https://policies.google.com/privacy" target="_blank" rel="noopener">' . esc_html__( 'privacy policy', 'primiko' ) . '</a>',
					'<a href="https://policies.google.com/terms" target="_blank" rel="noopener">' . esc_html__( 'terms of service', 'primiko' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $policy ) : ?>
			<p class="primiko__help">
				<a href="<?php echo esc_url( $policy ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy (opens in a new tab)', 'primiko' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}
	/**
	 * Returns an inline SVG icon, drawn with the text color.
	 *
	 * @param string $name Icon name: camera, close or check.
	 * @return string SVG markup, hidden from screen readers.
	 */
	public static function icon( $name ) {
		$paths = array(
			'camera' => '<path d="M4 8.5A2.5 2.5 0 0 1 6.5 6h1.6l1.2-1.8A1.5 1.5 0 0 1 10.5 3.5h3a1.5 1.5 0 0 1 1.2.7L15.9 6h1.6A2.5 2.5 0 0 1 20 8.5v8a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 16.5z"/><circle cx="12" cy="12.5" r="3.5"/>',
			'close'  => '<path d="M6 6l12 12M18 6L6 18"/>',
			'check'  => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 9.8"/>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="primiko__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
