<?php
/**
 * Sends a release file to the client.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the zip from PHP, or hands it to the web server (X-Accel-Redirect for nginx,
 * X-Sendfile for Apache) so large files don't hold a PHP worker.
 */
final class Download {

	/**
	 * Sends the file and ends the request.
	 *
	 * @param string $path     Absolute path inside the storage directory.
	 * @param string $filename Download filename.
	 */
	public static function send( string $path, string $filename ): never {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		$delivery = (string) Settings::get( 'delivery' );

		if ( 'x-accel' === $delivery && '' !== (string) Settings::get( 'x_accel_prefix' ) ) {
			$relative = ltrim( substr( $path, strlen( Software::storage_dir() ) ), '/' );
			header( 'X-Accel-Redirect: ' . trailingslashit( (string) Settings::get( 'x_accel_prefix' ) ) . $relative );
			exit;
		}

		if ( 'x-sendfile' === $delivery ) {
			header( 'X-Sendfile: ' . $path );
			exit;
		}

		header( 'Content-Length: ' . (string) filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a large binary to the client; WP_Filesystem would load it all into memory.
		readfile( $path );
		exit;
	}
}
