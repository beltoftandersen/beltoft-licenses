<?php
/**
 * Fixed-window rate limit for the public API.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per IP address and path in the persistent object cache when there is one,
 * otherwise in transients. If counting fails, the request is let through: an outage of the
 * cache must not lock every site out of its updates.
 */
final class RateLimit {

	private const GROUP = 'beltoft_licenses_rl';

	/**
	 * Counts this request. True when it is over the limit.
	 *
	 * @param string $ip   Client IP.
	 * @param string $path Request path.
	 */
	public static function exceeded( string $ip, string $path ): bool {
		$max    = (int) Settings::get( 'rate_limit_requests' );
		$window = max( 1, (int) Settings::get( 'rate_limit_window' ) );

		if ( $max <= 0 ) {
			return false;
		}

		$key = 'rl_' . md5( $ip . '|' . $path . '|' . (int) floor( time() / $window ) );

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, $window );
			$count = wp_cache_incr( $key, 1, self::GROUP );
		} else {
			$count = (int) get_transient( 'beltoft_licenses_' . $key ) + 1;
			set_transient( 'beltoft_licenses_' . $key, $count, $window );
		}

		return false !== $count && (int) $count > $max;
	}

	/**
	 * The client address. REMOTE_ADDR only: proxies are the web server's job (real_ip), so a
	 * client cannot dodge the limit by sending its own forwarding header.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip ? $ip : 'unknown';
	}
}
