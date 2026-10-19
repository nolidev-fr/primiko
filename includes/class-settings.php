<?php
/**
 * Settings.
 *
 * @package Primiko
 */

namespace Primiko;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide settings, under Quote requests, Settings.
 */
final class Settings {

	const OPTION = 'primiko_settings';
	const PAGE   = 'primiko-settings';

	/**
	 * Default values.
	 */
	const DEFAULTS = array(
		// Three years, the period the CNIL accepts for prospect data in France.
		'retention_months' => 36,
		'captcha_provider' => '',
		'captcha_site_key' => '',
		'captcha_secret'   => '',
	);

	/**
	 * Retention periods offered, in months. 0 keeps requests until deleted.
	 */
	const RETENTION_CHOICES = array( 6, 12, 24, 36, 60, 0 );

	/**
	 * Returns one setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array_key_exists( $key, $saved ) ? $saved[ $key ] : self::DEFAULTS[ $key ];
	}

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
	}

	/**
	 * Adds the settings page to the Quote requests menu.
	 */
	public function add_page() {
		add_submenu_page(
			'edit.php?post_type=' . Post_Types::REQUEST,
			__( 'Quote request settings', 'primiko' ),
			__( 'Settings', 'primiko' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the setting with its sanitizer.
	 */
	public function register() {
		register_setting(
			self::PAGE,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::DEFAULTS,
			)
		);
	}

	/**
	 * Keeps only known settings with allowed values.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$months = isset( $input['retention_months'] ) ? absint( $input['retention_months'] ) : self::DEFAULTS['retention_months'];

		$provider = isset( $input['captcha_provider'] ) ? sanitize_key( $input['captcha_provider'] ) : '';
		$site_key = isset( $input['captcha_site_key'] ) ? self::key( $input['captcha_site_key'] ) : '';
		$secret   = isset( $input['captcha_secret'] ) ? self::key( $input['captcha_secret'] ) : '';

		// The secret is never printed back: an empty field keeps the saved one.
		if ( '' === $secret && empty( $input['captcha_secret_clear'] ) ) {
			$secret = (string) self::get( 'captcha_secret' );
		}

		return array(
			'retention_months' => in_array( $months, self::RETENTION_CHOICES, true ) ? $months : self::DEFAULTS['retention_months'],
			'captcha_provider' => isset( Captcha::PROVIDERS[ $provider ] ) ? $provider : '',
			'captcha_site_key' => $site_key,
			'captcha_secret'   => $secret,
		);
	}

	/**
	 * Cleans a captcha key: letters, digits, dashes and underscores only.
	 *
	 * @param mixed $value Submitted key.
	 * @return string
	 */
	private static function key( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';

		return preg_match( '/^[A-Za-z0-9_-]{8,200}$/', $value ) ? $value : '';
	}

	/**
	 * Prints the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current = (int) self::get( 'retention_months' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( self::behind_proxy() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Your site seems to be behind a proxy or a CDN (such as Cloudflare). All visitors may then reach it from the same address, and the limit of five requests per hour and per connection would apply to all of them together. Ask your developer to return the real address of visitors with the primiko_client_ip filter, or to raise the limit with primiko_hourly_limit.', 'primiko' ); ?></p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::PAGE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="primiko-retention"><?php esc_html_e( 'Keep requests for', 'primiko' ); ?></label></th>
						<td>
							<select id="primiko-retention" name="<?php echo esc_attr( self::OPTION ); ?>[retention_months]" aria-describedby="primiko-retention-help">
								<?php foreach ( self::RETENTION_CHOICES as $months ) : ?>
									<option value="<?php echo esc_attr( (string) $months ); ?>" <?php selected( $current, $months ); ?>><?php echo esc_html( self::retention_label( $months ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="primiko-retention-help">
								<?php esc_html_e( 'Older requests are deleted every night, with their photos. The data protection rules ask you to keep personal data no longer than needed: three years after the request is a common period for prospects in France.', 'primiko' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="primiko-captcha"><?php esc_html_e( 'Anti-spam service', 'primiko' ); ?></label></th>
						<td>
							<select id="primiko-captcha" name="<?php echo esc_attr( self::OPTION ); ?>[captcha_provider]" aria-describedby="primiko-captcha-help">
								<option value="" <?php selected( self::get( 'captcha_provider' ), '' ); ?>><?php esc_html_e( 'None (recommended)', 'primiko' ); ?></option>
								<option value="turnstile" <?php selected( self::get( 'captcha_provider' ), 'turnstile' ); ?>>Cloudflare Turnstile</option>
								<option value="recaptcha" <?php selected( self::get( 'captcha_provider' ), 'recaptcha' ); ?>>Google reCAPTCHA v3</option>
							</select>
							<p class="description" id="primiko-captcha-help">
								<?php esc_html_e( 'The form already stops most spam without any outside service. Choose a service only if you already use one and spam still gets through. It then loads on the pages that show a form, and receives the address of the visitor: mention it in your privacy policy.', 'primiko' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="primiko-captcha-site"><?php esc_html_e( 'Site key', 'primiko' ); ?></label></th>
						<td>
							<input type="text" class="regular-text code" id="primiko-captcha-site" name="<?php echo esc_attr( self::OPTION ); ?>[captcha_site_key]" value="<?php echo esc_attr( (string) self::get( 'captcha_site_key' ) ); ?>" autocomplete="off">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="primiko-captcha-secret"><?php esc_html_e( 'Secret key', 'primiko' ); ?></label></th>
						<td>
							<input type="password" class="regular-text code" id="primiko-captcha-secret" name="<?php echo esc_attr( self::OPTION ); ?>[captcha_secret]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( self::get( 'captcha_secret' ) ? __( 'Saved. Leave empty to keep it.', 'primiko' ) : '' ); ?>">
							<?php if ( self::get( 'captcha_secret' ) ) : ?>
								<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[captcha_secret_clear]" value="1"> <?php esc_html_e( 'Delete the saved secret key', 'primiko' ); ?></label></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Tells whether requests seem to come through a proxy that the limit per
	 * connection does not know about.
	 *
	 * @return bool
	 */
	private static function behind_proxy() {
		if ( has_filter( 'primiko_client_ip' ) ) {
			return false;
		}

		// Only the presence of the headers is read, never their value.
		return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || isset( $_SERVER['HTTP_X_REAL_IP'] );
	}

	/**
	 * Label of a retention period.
	 *
	 * @param int $months Months, 0 for no limit.
	 * @return string
	 */
	private static function retention_label( $months ) {
		if ( 0 === $months ) {
			return __( 'Until I delete them', 'primiko' );
		}

		if ( 0 === $months % 12 ) {
			/* translators: %d: number of years. */
			return sprintf( _n( '%d year', '%d years', $months / 12, 'primiko' ), $months / 12 );
		}

		/* translators: %d: number of months. */
		return sprintf( _n( '%d month', '%d months', $months, 'primiko' ), $months );
	}
}
