<?php
/**
 * Plugin bootstrap.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin components together and handles the lifecycle hooks.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Trade templates shipped as JSON files.
	 *
	 * @var Templates
	 */
	public $templates;

	/**
	 * Private folder holding the photos sent with the requests.
	 *
	 * @var Private_Uploads
	 */
	public $uploads;

	/**
	 * Markup of the visitor form.
	 *
	 * @var Renderer
	 */
	public $renderer;

	/**
	 * Notification and acknowledgement emails.
	 *
	 * @var Mailer
	 */
	public $mailer;

	/**
	 * Returns the shared instance, creating it on first use.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Builds the components and registers the hooks.
	 */
	private function __construct() {
		$this->templates = new Templates();
		$this->uploads   = new Private_Uploads();
		$this->renderer  = new Renderer( $this->templates );
		$this->mailer    = new Mailer();

		( new Post_Types() )->register_hooks();
		( new Shortcode( $this->renderer ) )->register_hooks();
		( new Block( $this->renderer ) )->register_hooks();
		( new Rest( new Submission( $this->templates, $this->uploads, $this->mailer ) ) )->register_hooks();
		( new Retention() )->register_hooks();
		( new Privacy() )->register_hooks();
		$this->renderer->register_hooks();
		$this->uploads->register_hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'primiko', CLI::class );
		}

		if ( is_admin() ) {
			( new Form_Settings( $this->templates ) )->register_hooks();
			( new Request_View( $this->uploads ) )->register_hooks();
			( new Request_List() )->register_hooks();
			( new Settings() )->register_hooks();
		}
	}

	/**
	 * Prepares the private folder and the nightly deletion on activation.
	 */
	public static function activate() {
		( new Private_Uploads() )->ensure();
		( new Retention() )->schedule();
		update_option( 'primiko_version', PRIMIKO_VERSION, false );
	}

	/**
	 * Stops the nightly deletion. Requests are kept until uninstall.
	 */
	public static function deactivate() {
		Retention::unschedule();
	}
}
