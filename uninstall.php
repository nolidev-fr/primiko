<?php
/**
 * Removes everything the plugin created: the forms, the requests with their
 * photos, the private folder and the options.
 *
 * @package Primiko
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'primiko_request', 'primiko_form' ) as $primiko_type ) {
	$primiko_posts = get_posts(
		array(
			'post_type'   => $primiko_type,
			// 'any' leaves out the trash, where requests still hold personal data.
			'post_status' => array( 'publish', 'private', 'draft', 'pending', 'future', 'trash', 'auto-draft' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $primiko_posts as $primiko_post ) {
		wp_delete_post( $primiko_post, true );
	}
}

// Private photo folder, with anything left in it.
$primiko_uploads = wp_upload_dir( null, false );
$primiko_dir     = trailingslashit( $primiko_uploads['basedir'] ) . 'primiko';

if ( is_dir( $primiko_dir ) ) {
	global $wp_filesystem;

	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() && 'direct' === $wp_filesystem->method ) {
		$wp_filesystem->delete( $primiko_dir, true );
	}
}

// Short-lived counters and token claims: limits per connection, used tokens, copies per address.
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup, no API deletes transients by prefix.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_primiko_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_primiko_' ) . '%',
		$wpdb->esc_like( 'primiko_claim_' ) . '%'
	)
);

delete_option( 'primiko_version' );
delete_option( 'primiko_settings' );
delete_option( 'primiko_policy_covered' );
wp_clear_scheduled_hook( 'primiko_purge' );
