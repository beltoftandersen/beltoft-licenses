<?php
/**
 * What the plugin leaves in wp_options and on disk, and how uninstall removes it.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded by uninstall.php, where the rest of the plugin is not. Every option and transient the
 * plugin writes starts with beltoft_licenses_, so one prefix rule removes them all, including
 * the ones with a changing name (alerts per order, GitHub delivery ids, rate limit counters).
 */
final class Uninstall {

	/**
	 * Deletes options, transients and the default release folder.
	 */
	public static function purge(): void {
		global $wpdb;

		// The default release folder is named by the stored suffix: remove it before the suffix is deleted.
		self::remove_release_folder();

		$patterns = array(
			$wpdb->esc_like( 'beltoft_licenses_' ) . '%',
			$wpdb->esc_like( '_transient_beltoft_licenses_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_beltoft_licenses_' ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- removing our own options on uninstall.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern ) );
		}

		wp_cache_flush();
	}

	/**
	 * Removes uploads/beltoft-licenses-private/<suffix>, the folder used when no storage directory
	 * is set. A directory chosen in the settings is the site owner's and is left alone.
	 */
	public static function remove_release_folder(): void {
		$settings = get_option( 'beltoft_licenses_settings', array() );
		$suffix   = (string) get_option( 'beltoft_licenses_storage_suffix', '' );

		if ( ! empty( $settings['storage_dir'] ) || '' === $suffix || $suffix !== sanitize_file_name( $suffix ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'beltoft-licenses-private/' . $suffix;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;
		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
