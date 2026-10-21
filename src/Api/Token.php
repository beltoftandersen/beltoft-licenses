<?php
/**
 * Signed, short-lived download tokens.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Install;
use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * base64url(JSON payload) . "." . base64url(HMAC-SHA256), so a download link can be handed
 * to WordPress's updater without carrying the license key itself.
 */
final class Token {

	/**
	 * A token for one license on one domain.
	 *
	 * @param int      $license_id License ID.
	 * @param string   $domain     Normalised domain.
	 * @param int|null $ttl        Seconds; the setting when null.
	 */
	public static function create( int $license_id, string $domain, ?int $ttl = null ): string {
		$ttl     = $ttl ?? self::ttl();
		$payload = self::b64( (string) wp_json_encode( array( 'license_id' => $license_id, 'domain' => $domain, 'exp' => time() + $ttl ) ) );

		return $payload . '.' . self::b64( hash_hmac( 'sha256', $payload, Install::secret(), true ) );
	}

	/**
	 * The payload of a valid, unexpired token, or null.
	 *
	 * @param mixed $token Token.
	 * @return array{license_id:int, domain:string, exp:int}|null
	 */
	public static function verify( $token ): ?array {
		if ( ! is_string( $token ) || 2 !== count( explode( '.', $token ) ) ) {
			return null;
		}

		list( $payload, $signature ) = explode( '.', $token );
		$expected                    = self::b64( hash_hmac( 'sha256', $payload, Install::secret(), true ) );

		if ( '' === $payload || ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$data = json_decode( (string) base64_decode( strtr( $payload, '-_', '+/' ), true ), true );

		if ( ! is_array( $data ) || empty( $data['license_id'] ) || empty( $data['domain'] ) || empty( $data['exp'] ) ) {
			return null;
		}

		if ( (int) $data['exp'] < time() ) {
			return null;
		}

		return array(
			'license_id' => (int) $data['license_id'],
			'domain'     => (string) $data['domain'],
			'exp'        => (int) $data['exp'],
		);
	}

	/**
	 * Token lifetime in seconds.
	 */
	public static function ttl(): int {
		return max( 30, (int) Settings::get( 'token_ttl' ) );
	}

	/**
	 * base64url without padding.
	 *
	 * @param string $data Bytes.
	 */
	private static function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
