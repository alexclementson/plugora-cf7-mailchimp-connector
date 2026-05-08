<?php
/**
 * Plugora CF7 → Mailchimp Connector — clean uninstall.
 *
 * Removes every option, transient and DB table the plugin created.
 * Triggered when a site admin deletes the plugin from Plugins → Installed Plugins.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Drop log table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}plugora_cf7mc_logs" );

// Plugin options.
$options = [
	'plugora_cf7mc_version',
	'plugora_cf7mc_settings',
	'plugora_cf7mc_license_key',
	'plugora_cf7mc_license_state',
];
foreach ( $options as $opt ) delete_option( $opt );

// Per-form post meta (Mailchimp config attached to each CF7 form).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		'_plugora_cf7mc_%'
	)
);

// Throttle transient.
delete_transient( 'plugora_cf7mc_license_recheck' );
delete_transient( 'plugora_cf7mc_audiences_cache' );
