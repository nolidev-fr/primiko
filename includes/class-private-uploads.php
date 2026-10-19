<?php
/**
 * Private photo folder.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the photos of the requests out of the media library, in a folder of
 * the uploads directory that refuses web access.
 *
 * Every photo is decoded and saved again as a new JPEG: only the pixels are
 * kept, so a file that hides code after valid image data, and the EXIF data
 * with the GPS position of the phone, never reach the disk for good.
 *
 * The .htaccess file covers Apache and LiteSpeed: no web access, no PHP, no
 * listing. On other servers the folder and file names are random, so a photo
 * cannot be guessed. Photos are shown in the admin through a route that
 * checks the rights of the user.
 */
final class Private_Uploads {

	/**
	 * Largest side of a stored photo, in pixels.
	 */
	const MAX_SIDE = 2000;

	/**
	 * Largest image accepted before decoding, in pixels (width x height).
	 * Stops images that are small files but huge once decoded. The form
	 * resizes photos to 2000 pixels on the phone, about 4 million pixels.
	 */
	const MAX_PIXELS = 7000000;

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'before_delete_post', array( $this, 'delete_request_photos' ) );
	}

	/**
	 * Absolute path of the private folder.
	 *
	 * @return string
	 */
	public function base_dir() {
		$uploads = wp_upload_dir( null, false );

		return trailingslashit( $uploads['basedir'] ) . 'primiko';
	}

	/**
	 * Creates the private folder and its protection files when missing.
	 *
	 * @return bool Whether the folder is ready.
	 */
	public function ensure() {
		$dir = $this->base_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$files = array(
			'.htaccess' => implode(
				"\n",
				array(
					'# Primiko: photos of the quote requests, never served directly.',
					'<IfModule mod_authz_core.c>',
					"\tRequire all denied",
					'</IfModule>',
					'<IfModule !mod_authz_core.c>',
					"\tOrder allow,deny",
					"\tDeny from all",
					'</IfModule>',
					'# In case the rules above are ever lifted: no listing, no scripts.',
					'Options -Indexes -ExecCGI',
					'<IfModule mod_php.c>',
					"\tphp_flag engine off",
					'</IfModule>',
					'<IfModule mod_php7.c>',
					"\tphp_flag engine off",
					'</IfModule>',
					'<FilesMatch "\\.(?i:php\\d?|phtml|phar|pht|cgi|pl|py|sh)$">',
					"\tSetHandler none",
					"\tForceType text/plain",
					'</FilesMatch>',
					'',
				)
			),
			'index.php' => "<?php\n// Silence is golden.\n",
		);

		$filesystem = $this->filesystem();

		foreach ( $files as $name => $content ) {
			$path = $dir . '/' . $name;

			// Rewritten when a new version of the plugin changes the rules.
			if ( file_exists( $path ) && $filesystem && $content === $filesystem->get_contents( $path ) ) {
				continue;
			}

			if ( ! $filesystem || ! $filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Saves the photos of a request in its own folder, under random names,
	 * each one decoded and encoded again as a JPEG.
	 *
	 * @param int     $request_id Request post ID.
	 * @param array[] $files      Upload arrays, already checked.
	 * @return string[]|\WP_Error Paths relative to the private folder.
	 */
	public function store( $request_id, $files ) {
		if ( ! $this->ensure() ) {
			return new \WP_Error( 'primiko_folder', 'The private folder cannot be created.' );
		}

		$folder = $request_id . '-' . strtolower( wp_generate_password( 16, false ) );
		$dir    = $this->base_dir() . '/' . $folder;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'primiko_folder', 'The request folder cannot be created.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$redirect = static function ( $uploads ) use ( $dir ) {
			$uploads['path']   = $dir;
			$uploads['url']    = '';
			$uploads['subdir'] = '';
			$uploads['error']  = false;

			return $uploads;
		};

		// wp_handle_upload() checks the real type of the file, not only its
		// name, against this list.
		$overrides = array(
			'test_form'                => false,
			'mimes'                    => array(
				'jpg|jpeg|jpe' => 'image/jpeg',
				'png'          => 'image/png',
				'webp'         => 'image/webp',
			),
			'unique_filename_callback' => static function () {
				return self::random_name( '.upload.jpg' );
			},
		);

		$stored = array();

		add_filter( 'upload_dir', $redirect );

		foreach ( $files as $file ) {
			$moved = wp_handle_upload( $file, $overrides );

			if ( isset( $moved['error'] ) || empty( $moved['file'] ) ) {
				break;
			}

			$clean = $this->reencode( $moved['file'], $dir );

			// The uploaded file is never kept, whatever happened.
			wp_delete_file( $moved['file'] );

			if ( '' === $clean ) {
				break;
			}

			$stored[] = $folder . '/' . $clean;
		}

		remove_filter( 'upload_dir', $redirect );

		if ( count( $stored ) !== count( $files ) ) {
			$this->delete_folder( $dir );

			return new \WP_Error( 'primiko_photo', 'A photo could not be saved.' );
		}

		return $stored;
	}

	/**
	 * Decodes an uploaded image and saves its pixels as a new JPEG, turned
	 * the right way up, no larger than MAX_SIDE, without any metadata.
	 *
	 * Refuses the image when it cannot be decoded: an image that cannot be
	 * cleaned is not kept.
	 *
	 * @param string $source Uploaded file.
	 * @param string $dir    Folder of the request.
	 * @return string File name of the clean image, empty on failure.
	 */
	private function reencode( $source, $dir ) {
		$size = wp_getimagesize( $source );

		if ( ! $size || empty( $size[0] ) || empty( $size[1] ) || $size[0] * $size[1] > self::MAX_PIXELS ) {
			return '';
		}

		// GD rebuilds the image from its pixels and drops every metadata.
		$prefer_gd = static function ( $editors ) {
			return array_unique( array_merge( array( 'WP_Image_Editor_GD' ), (array) $editors ) );
		};

		add_filter( 'wp_image_editors', $prefer_gd );
		$editor = wp_get_image_editor( $source, array( 'mime_type' => $size['mime'] ) );
		remove_filter( 'wp_image_editors', $prefer_gd );

		if ( is_wp_error( $editor ) ) {
			return '';
		}

		$editor->maybe_exif_rotate();

		$current = $editor->get_size();
		$longest = max( $current['width'], $current['height'] );

		// Imagick only drops the metadata while resizing, so the image is
		// always resized with it, by a pixel when it is already small enough.
		if ( $longest > self::MAX_SIDE ) {
			$resized = $editor->resize( self::MAX_SIDE, self::MAX_SIDE, false );
		} elseif ( ! $editor instanceof \WP_Image_Editor_GD ) {
			$resized = $editor->resize( $longest - 1, $longest - 1, false );
		} else {
			$resized = true;
		}

		if ( is_wp_error( $resized ) ) {
			return '';
		}

		$editor->set_quality( 82 );
		$saved = $editor->save( $dir . '/' . self::random_name( '.jpg' ), 'image/jpeg' );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! wp_getimagesize( $saved['path'] ) ) {
			return '';
		}

		return basename( $saved['path'] );
	}

	/**
	 * Random file name that cannot be guessed.
	 *
	 * @param string $suffix Extension, with its dot.
	 * @return string
	 */
	private static function random_name( $suffix ) {
		return strtolower( wp_generate_password( 24, false ) ) . $suffix;
	}

	/**
	 * Returns the absolute path of a stored photo, only if it lies inside
	 * the private folder.
	 *
	 * @param string $relative Path relative to the private folder.
	 * @return string Empty when the file is missing or outside the folder.
	 */
	public function path( $relative ) {
		$base = realpath( $this->base_dir() );
		$path = realpath( $this->base_dir() . '/' . $relative );

		if ( ! $base || ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			return '';
		}

		return $path;
	}

	/**
	 * Deletes the photos of a request when the request is deleted for good.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_request_photos( $post_id ) {
		if ( Post_Types::REQUEST !== get_post_type( $post_id ) ) {
			return;
		}

		$folders = array();

		foreach ( (array) get_post_meta( $post_id, '_primiko_photos', true ) as $relative ) {
			$path = $this->path( (string) $relative );

			if ( '' !== $path ) {
				$folders[ dirname( $path ) ] = true;
			}
		}

		foreach ( array_keys( $folders ) as $folder ) {
			$this->delete_folder( $folder );
		}
	}

	/**
	 * Deletes a request folder and what it holds.
	 *
	 * @param string $dir Absolute path, inside the private folder.
	 */
	private function delete_folder( $dir ) {
		$filesystem = $this->filesystem();
		$base       = realpath( $this->base_dir() );
		$real       = realpath( $dir );

		if ( $filesystem && $base && $real && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			$filesystem->delete( $real, true );
		}
	}

	/**
	 * Returns the direct filesystem, or null when WordPress would need
	 * credentials to write files.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private function filesystem() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() || 'direct' !== $wp_filesystem->method ) {
			return null;
		}

		return $wp_filesystem;
	}
}
