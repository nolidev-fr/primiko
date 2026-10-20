<?php
/**
 * Request screen.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a request on its edit screen: the answers, the photos, a reply
 * button and the status. Also serves the photos to users allowed to edit the
 * request, since the private folder refuses web access.
 */
final class Request_View {

	const NONCE        = 'primiko_request_status';
	const PHOTO_ACTION = 'primiko_photo';

	/**
	 * Private photo folder.
	 *
	 * @var Private_Uploads
	 */
	private $uploads;

	/**
	 * Sets up the screen.
	 *
	 * @param Private_Uploads $uploads Private photo folder.
	 */
	public function __construct( Private_Uploads $uploads ) {
		$this->uploads = $uploads;
	}

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'add_meta_boxes_' . Post_Types::REQUEST, array( $this, 'add_boxes' ) );
		add_action( 'save_post_' . Post_Types::REQUEST, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::PHOTO_ACTION, array( $this, 'serve_photo' ) );
	}

	/**
	 * Adds the boxes.
	 */
	public function add_boxes() {
		add_meta_box( 'primiko-request', __( 'Request', 'primiko' ), array( $this, 'render' ), Post_Types::REQUEST, 'normal', 'high' );
		add_meta_box( 'primiko-request-status', __( 'Status', 'primiko' ), array( $this, 'render_status' ), Post_Types::REQUEST, 'side', 'high' );
	}

	/**
	 * Prints the answers and the photos.
	 *
	 * @param \WP_Post $post Request post.
	 */
	public function render( $post ) {
		$email  = (string) get_post_meta( $post->ID, '_primiko_email', true );
		$photos = (array) get_post_meta( $post->ID, '_primiko_photos', true );
		$trade  = (string) get_post_meta( $post->ID, '_primiko_trade', true );
		$form   = get_post( (int) $post->post_parent );
		$single = (bool) get_post_meta( $post->ID, '_primiko_single', true );

		// The name the business gave its form says more than the template name.
		if ( $form && Post_Types::FORM === $form->post_type && '' !== $form->post_title ) {
			$trade = $form->post_title;
		}

		$reply = $single
			/* translators: %s: site name. */
			? __( 'Your message to %s', 'primiko' )
			/* translators: %s: site name. */
			: __( 'Your quote request to %s', 'primiko' );
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date, 2: name of the form, 3: reference of the request. */
					__( 'Sent on %1$s with the form "%2$s", reference %3$s.', 'primiko' ),
					get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ),
					$trade,
					Mailer::reference( $post->ID )
				)
			);
			?>
			<?php if ( is_email( $email ) ) : ?>
				<a class="button button-primary" style="margin-left:8px;" href="<?php echo esc_url( 'mailto:' . $email . '?subject=' . rawurlencode( sprintf( $reply, wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) ) ); ?>"><?php esc_html_e( 'Reply by email', 'primiko' ); ?></a>
			<?php endif; ?>
		</p>

		<?php echo Entries::html( (array) get_post_meta( $post->ID, '_primiko_entries', true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Entries::html(). ?>

		<?php if ( $photos ) : ?>
			<h3><?php esc_html_e( 'Photos', 'primiko' ); ?></h3>
			<p style="display:flex;flex-wrap:wrap;gap:12px;">
				<?php foreach ( array_keys( $photos ) as $index ) : ?>
					<?php $url = $this->photo_url( $post->ID, $index ); ?>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
						<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( sprintf( /* translators: %d: photo number. */ __( 'Photo %d', 'primiko' ), $index + 1 ) ); ?>" style="width:180px;height:180px;object-fit:cover;border-radius:4px;" loading="lazy">
					</a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints the status choice.
	 *
	 * @param \WP_Post $post Request post.
	 */
	public function render_status( $post ) {
		$status = (string) get_post_meta( $post->ID, '_primiko_status', true );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<label class="screen-reader-text" for="primiko-status"><?php esc_html_e( 'Status', 'primiko' ); ?></label>
		<select id="primiko-status" name="primiko_status">
			<option value="<?php echo esc_attr( Post_Types::STATUS_NEW ); ?>" <?php selected( $status, Post_Types::STATUS_NEW ); ?>><?php esc_html_e( 'New', 'primiko' ); ?></option>
			<option value="<?php echo esc_attr( Post_Types::STATUS_DONE ); ?>" <?php selected( $status, Post_Types::STATUS_DONE ); ?>><?php esc_html_e( 'Handled', 'primiko' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Saves the status.
	 *
	 * @param int $post_id Request post ID.
	 */
	public function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$status = isset( $_POST['primiko_status'] ) ? sanitize_key( wp_unslash( $_POST['primiko_status'] ) ) : '';

		if ( in_array( $status, array( Post_Types::STATUS_NEW, Post_Types::STATUS_DONE ), true ) ) {
			update_post_meta( $post_id, '_primiko_status', $status );
		}
	}

	/**
	 * Address of a photo, signed for the current user.
	 *
	 * @param int $request_id Request post ID.
	 * @param int $index      Photo index.
	 * @return string
	 */
	private function photo_url( $request_id, $index ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::PHOTO_ACTION,
					'request' => $request_id,
					'photo'   => $index,
				),
				admin_url( 'admin-post.php' )
			),
			self::PHOTO_ACTION . '_' . $request_id
		);
	}

	/**
	 * Sends a photo to a user allowed to edit its request.
	 */
	public function serve_photo() {
		$request_id = isset( $_GET['request'] ) ? absint( $_GET['request'] ) : 0;
		$index      = isset( $_GET['photo'] ) ? absint( $_GET['photo'] ) : 0;

		check_admin_referer( self::PHOTO_ACTION . '_' . $request_id );

		if ( Post_Types::REQUEST !== get_post_type( $request_id ) || ! current_user_can( 'edit_post', $request_id ) ) {
			wp_die( esc_html__( 'You are not allowed to see this photo.', 'primiko' ), '', array( 'response' => 403 ) );
		}

		$photos = (array) get_post_meta( $request_id, '_primiko_photos', true );
		$path   = isset( $photos[ $index ] ) ? $this->uploads->path( (string) $photos[ $index ] ) : '';
		$type   = '' !== $path ? wp_check_filetype( $path ) : array( 'type' => false );

		if ( '' === $path || ! in_array( $type['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			wp_die( esc_html__( 'This photo cannot be found.', 'primiko' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $type['type'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox" );
		header( 'Cross-Origin-Resource-Policy: same-origin' );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a private image to an authorized user.
		exit;
	}
}
