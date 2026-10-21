<?php
/**
 * Removes the plugin's data, but only when "Delete all … when the plugin is deleted" is on.
 * Licenses are customer records: deleting the plugin must not silently destroy them.
 *
 * @package BeltoftLicenses
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$beltoft_licenses_settings = get_option( 'beltoft_licenses_settings', array() );

if ( empty( $beltoft_licenses_settings['delete_data'] ) ) {
	return;
}

global $wpdb;

foreach ( array( 'beltoft_licenses', 'beltoft_licenses_activations', 'beltoft_licenses_releases' ) as $beltoft_licenses_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $beltoft_licenses_table ) );
}

foreach ( array( 'beltoft_licenses_settings', 'beltoft_licenses_db_version', 'beltoft_licenses_token_secret', 'beltoft_licenses_storage_suffix' ) as $beltoft_licenses_option ) {
	delete_option( $beltoft_licenses_option );
}
