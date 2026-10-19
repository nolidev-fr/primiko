<?php
/**
 * Plugin Name:       Primiko - Quote Requests by Trade
 * Plugin URI:        https://github.com/nolidev-fr/primiko
 * Description:       Quote request forms made for each trade: the right questions in the right order, with photos and the site address. Requests are saved in WordPress and sent by email.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Nolidev
 * Author URI:        https://nolidev.fr
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       primiko
 * Domain Path:       /languages
 *
 * @package Primiko
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'PRIMIKO_VERSION' ) ) {
	return;
}

define( 'PRIMIKO_VERSION', '0.1.0' );
define( 'PRIMIKO_FILE', __FILE__ );
define( 'PRIMIKO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRIMIKO_URL', plugin_dir_url( __FILE__ ) );
define( 'PRIMIKO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Loads plugin classes from the includes directory on demand.
 *
 * Primiko\Post_Types maps to includes/class-post-types.php.
 *
 * @param string $class_name Fully qualified class name.
 */
function primiko_autoload( $class_name ) {
	$prefix = 'Primiko\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$file     = PRIMIKO_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'primiko_autoload' );

/**
 * Returns the shared plugin instance.
 *
 * @return \Primiko\Plugin
 */
function primiko() {
	return \Primiko\Plugin::instance();
}

register_activation_hook( __FILE__, array( 'Primiko\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Primiko\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', 'primiko' );
