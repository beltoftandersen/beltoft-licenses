<?php
/**
 * License keys and site addresses.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Generates keys from the configured format, and normalises keys and domains the same way
 * on every path, so a key or a site typed differently still matches.
 */
final class Key {

	/**
	 * A new random key in the configured format.
	 *
	 * @param string|null $template Format with {Xn} placeholders; the setting when null.
	 * @param string|null $prefix   Prefix; the setting when null.
	 */
	public static function generate( ?string $template = null, ?string $prefix = null ): string {
		$template = $template ?? (string) Settings::get( 'key_template' );
		$prefix   = $prefix ?? (string) Settings::get( 'key_prefix' );

		$key = preg_replace_callback(
			'/\{X(\d{1,2})\}/',
			static function ( array $m ): string {
				$length = max( 1, (int) $m[1] );
				return substr( strtoupper( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ) ), 0, $length );
			},
			$template
		);

		return '' !== $prefix ? $prefix . '-' . $key : $key;
	}

	/**
	 * A key as stored: trimmed and uppercased.
	 *
	 * @param mixed $key Raw input.
	 */
	public static function normalize( $key ): string {
		return is_string( $key ) ? strtoupper( trim( $key ) ) : '';
	}

	/**
	 * A site address as stored: lowercase, no scheme, and (by default) no leading "www."
	 * and no single trailing slash. Ports and paths are kept, so two installs on one host
	 * count as two sites.
	 *
	 * @param mixed $domain Raw input.
	 */
	public static function domain( $domain ): string {
		if ( ! is_string( $domain ) ) {
			return '';
		}

		$domain = preg_replace( '#^https?://#', '', strtolower( trim( $domain ) ) );

		if ( Settings::get( 'domain_strip_www' ) ) {
			$domain = preg_replace( '/^www\./', '', $domain );
		}

		if ( Settings::get( 'domain_strip_slash' ) ) {
			$domain = preg_replace( '#/$#', '', $domain );
		}

		return (string) $domain;
	}
}
