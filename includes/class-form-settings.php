<?php
/**
 * Form settings box.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Settings of a form on its edit screen: the trade template and who receives
 * the requests. Also shows the shortcode to paste in a page builder.
 */
final class Form_Settings {

	const NONCE = 'primiko_form_settings';

	/**
	 * Trade templates.
	 *
	 * @var Templates
	 */
	private $templates;

	/**
	 * Sets up the box.
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
		add_action( 'add_meta_boxes_' . Post_Types::FORM, array( $this, 'add_box' ) );
		add_action( 'save_post_' . Post_Types::FORM, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Adds the settings box.
	 */
	public function add_box() {
		add_meta_box( 'primiko-form-settings', __( 'Form settings', 'primiko' ), array( $this, 'render' ), Post_Types::FORM, 'normal', 'high' );
		add_meta_box( 'primiko-questions', __( 'Questions and messages', 'primiko' ), array( $this, 'render_questions' ), Post_Types::FORM, 'normal', 'default' );
	}

	/**
	 * Loads the questions editor on the form screens.
	 *
	 * @param string $hook Admin page.
	 */
	public function enqueue( $hook ) {
		$screen = get_current_screen();
		$asset  = PRIMIKO_DIR . 'build/admin/index.asset.php';

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || Post_Types::FORM !== $screen->post_type || ! is_readable( $asset ) ) {
			return;
		}

		$asset = require $asset;

		wp_enqueue_script( 'primiko-admin', PRIMIKO_URL . 'build/admin/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'primiko-admin', 'primiko', PRIMIKO_DIR . 'languages' );
		wp_enqueue_style( 'wp-components' );

		if ( is_readable( PRIMIKO_DIR . 'build/admin/style-index.css' ) ) {
			wp_enqueue_style( 'primiko-admin', PRIMIKO_URL . 'build/admin/style-index.css', array( 'wp-components' ), $asset['version'] );
		}
	}

	/**
	 * Prints the mount point of the questions editor, with what it needs:
	 * every template, translated, and the customizations already saved.
	 * The editor writes its result in a hidden field saved with the form.
	 *
	 * @param \WP_Post $post Form post.
	 */
	public function render_questions( $post ) {
		$templates = array();

		foreach ( array_keys( $this->templates->choices() ) as $id ) {
			$template = $this->templates->localized( $id );

			foreach ( Customizations::MESSAGES as $key ) {
				if ( empty( $template['messages'][ $key ] ) ) {
					$template['messages'][ $key ] = Mailer::default_message( $key );
				}
			}

			$templates[ $id ] = $template;
		}

		$saved  = get_post_meta( $post->ID, Customizations::META, true );
		$config = array(
			'templates'      => $templates,
			'customizations' => is_array( $saved ) ? $saved : array(),
		);
		?>
		<div id="primiko-questions-editor" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
			<p><?php esc_html_e( 'Loading the questions...', 'primiko' ); ?></p>
		</div>
		<input type="hidden" name="primiko_customizations" id="primiko-customizations" value="">
		<?php
	}

	/**
	 * Prints the settings box.
	 *
	 * @param \WP_Post $post Form post.
	 */
	public function render( $post ) {
		$current    = (string) get_post_meta( $post->ID, '_primiko_template', true );
		$recipients = implode( "\n", (array) get_post_meta( $post->ID, '_primiko_recipients', true ) );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="primiko-template"><?php esc_html_e( 'Trade', 'primiko' ); ?></label></th>
				<td>
					<select id="primiko-template" name="primiko_template">
						<?php if ( '' === $current ) : ?>
							<option value=""><?php esc_html_e( 'Choose a trade', 'primiko' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $this->templates->choices() as $id => $title ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
					<div id="primiko-template-picker" data-config="<?php echo esc_attr( wp_json_encode( $this->picker_config() ) ); ?>"></div>
					<p class="description"><?php esc_html_e( 'The questions of the form come from this trade template. You can adapt them below.', 'primiko' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="primiko-recipients"><?php esc_html_e( 'Send requests to', 'primiko' ); ?></label></th>
				<td>
					<textarea id="primiko-recipients" name="primiko_recipients" rows="3" class="large-text code" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"><?php echo esc_textarea( $recipients ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One email address per line. Left empty, requests go to the administration email address of the site.', 'primiko' ); ?></p>
				</td>
			</tr>
			<?php if ( 'auto-draft' !== $post->post_status ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Shortcode', 'primiko' ); ?></th>
					<td>
						<code>[<?php echo esc_html( Shortcode::TAG ); ?> form="<?php echo esc_html( (string) $post->ID ); ?>"]</code>
						<p class="description"><?php esc_html_e( 'In the block editor, add the Quote request form block instead. The shortcode is for page builders and classic themes.', 'primiko' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * What the trade picker needs: each template with the texts it searches,
	 * and the categories in display order.
	 *
	 * @return array
	 */
	private function picker_config() {
		$templates = array();

		foreach ( array_keys( $this->templates->choices() ) as $id ) {
			$template    = $this->templates->localized( $id );
			$templates[] = array(
				'id'          => $id,
				'title'       => $template['title'],
				'description' => $template['description'],
				'keywords'    => $template['keywords'],
				'category'    => $template['category'],
			);
		}

		return array(
			'templates'  => $templates,
			'categories' => Templates::categories(),
		);
	}

	/**
	 * Saves the settings.
	 *
	 * @param int $post_id Form post ID.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$template = isset( $_POST['primiko_template'] ) ? sanitize_key( wp_unslash( $_POST['primiko_template'] ) ) : '';

		if ( null !== $this->templates->get( $template ) ) {
			update_post_meta( $post_id, '_primiko_template', $template );
		}

		$raw        = isset( $_POST['primiko_recipients'] ) ? sanitize_textarea_field( wp_unslash( $_POST['primiko_recipients'] ) ) : '';
		$recipients = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) as $email ) {
			$email = sanitize_email( $email );

			if ( is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		update_post_meta( $post_id, '_primiko_recipients', array_values( array_unique( $recipients ) ) );

		$this->save_customizations( $post_id );
	}

	/**
	 * Saves what the questions editor sent. An empty field means the editor
	 * did not load: the saved customizations stay as they are.
	 *
	 * @param int $post_id Form post ID.
	 */
	private function save_customizations( $post_id ) {
		// Decoded below, then every value is checked and sanitized in Customizations::sanitize().
		$json = isset( $_POST['primiko_customizations'] ) ? wp_unslash( $_POST['primiko_customizations'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonce checked in save().

		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > 262144 ) {
			return;
		}

		$data     = json_decode( $json, true, 16 );
		$template = is_array( $data ) && isset( $data['template'] ) && is_string( $data['template'] ) ? $this->templates->localized( sanitize_key( $data['template'] ) ) : null;

		if ( null === $template ) {
			return;
		}

		$custom = Customizations::sanitize( isset( $data['custom'] ) ? $data['custom'] : array(), $template );

		Customizations::save( $post_id, $template['id'], $custom );
	}
}
