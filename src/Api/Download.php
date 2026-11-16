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

		$plan = self::plan( $path, $filename );

		foreach ( $plan['headers'] as $header ) {
			header( $header );
		}

		if ( $plan['stream'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a large binary to the client; WP_Filesystem would load it all into memory.
			readfile( $path );
		}

		exit;
	}

	/**
	 * The headers to send and whether PHP streams the body itself. Pure, so a test can check
	 * the file name, the storage prefix cut and the delivery mode without ending the request.
	 *
	 * @param string $path     Absolute path inside the storage directory.
	 * @param string $filename Download filename.
	 * @return array{headers:string[],stream:bool}
	 */
	public static function plan( string $path, string $filename ): array {
		$headers = array(
			'Content-Type: application/zip',
			'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"',
			'Cache-Control: no-store',
			'X-Robots-Tag: noindex, nofollow',
		);

		$delivery = (string) Settings::get( 'delivery' );

		if ( 'x-accel' === $delivery && '' !== (string) Settings::get( 'x_accel_prefix' ) ) {
			$relative  = ltrim( substr( $path, strlen( Software::storage_dir() ) ), '/' );
			$headers[] = 'X-Accel-Redirect: ' . trailingslashit( (string) Settings::get( 'x_accel_prefix' ) ) . $relative;

			return array( 'headers' => $headers, 'stream' => false );
		}

		if ( 'x-sendfile' === $delivery ) {
			$headers[] = 'X-Sendfile: ' . $path;

			return array( 'headers' => $headers, 'stream' => false );
		}

		$headers[] = 'Content-Length: ' . (string) filesize( $path );

		return array( 'headers' => $headers, 'stream' => true );
	}
}
