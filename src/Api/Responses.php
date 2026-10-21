<?php
/**
 * Response bodies for each response profile.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Success bodies are the same in every profile. Errors differ: "standard" answers
 * {success:false, code, message}; "medusa" answers byte for byte what a Medusa license
 * backend answers, so plugins built against one keep working unchanged.
 *
 * Messages stay in English on purpose: API clients compare and log them.
 */
final class Responses {

	/**
	 * Error code => [HTTP status, message].
	 *
	 * @return array<string, array{0:int, 1:string}>
	 */
	public static function errors(): array {
		return array(
			'missing_license_key'  => array( 400, 'license_key is required' ),
			'missing_key_domain'   => array( 400, 'license_key and domain are required' ),
			'not_found'            => array( 404, 'License not found' ),
			'plugin_mismatch'      => array( 403, 'This license is for %1$s, not %2$s.' ),
			'license_not_active'   => array( 400, 'License is %s' ),
			'license_expired'      => array( 400, 'License has expired' ),
			'activation_limit'     => array( 400, 'Maximum activations reached (%d)' ),
			'not_activated'        => array( 400, 'No active activation found for this domain' ),
			'download_not_active'  => array( 403, 'License is not active. Renew your subscription to download updates.' ),
			'download_expired'     => array( 403, 'License has expired. Renew your subscription to download updates.' ),
			'domain_not_activated' => array( 403, 'License is not activated on this domain.' ),
			'no_software'          => array( 404, 'No plugin associated with this license' ),
			'software_not_found'   => array( 404, 'Digital product not found' ),
			'no_release'           => array( 404, 'No download available for current version' ),
			'file_missing'         => array( 404, 'Download file is unavailable' ),
			'token_required'       => array( 400, 'token is required' ),
			'token_invalid'        => array( 401, 'Invalid or expired download token' ),
			'rate_limited'         => array( 429, 'Too many requests, please try again later.' ),
			'auth_missing'         => array( 400, 'Publishable API key required in the request header: %s. You can manage your keys in settings in the dashboard.' ),
			'auth_invalid'         => array( 400, 'A valid publishable key is required to proceed with the request' ),
			'slug_required'        => array( 400, 'slug is required' ),
			'slug_not_found'       => array( 404, 'Plugin not found' ),
		);
	}

	/**
	 * The response for an error.
	 *
	 * @param string            $code    Error code.
	 * @param array<int, mixed> $args    sprintf arguments for the message.
	 * @param string            $context validate, activate, deactivate, download, download-file, versions, or '' for any.
	 */
	public static function error( string $code, array $args = array(), string $context = '' ): \WP_REST_Response {
		list( $status, $message ) = self::errors()[ $code ];
		$message                  = $args ? vsprintf( $message, $args ) : $message;

		if ( 'medusa' !== Settings::get( 'response_profile' ) ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'code'    => $code,
					'message' => $message,
				),
				$status
			);
		}

		return new \WP_REST_Response( self::medusa_body( $code, $message, $context ), $status );
	}

	/**
	 * Medusa's exact error bodies.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param string $context Route.
	 * @return array<string, mixed>
	 */
	private static function medusa_body( string $code, string $message, string $context ): array {
		switch ( $code ) {
			case 'missing_license_key':
			case 'missing_key_domain':
			case 'rate_limited':
			case 'slug_required':
			case 'slug_not_found':
				return array( 'message' => $message );
			case 'auth_missing':
			case 'auth_invalid':
				return array(
					'type'    => 'not_allowed',
					'message' => $message,
				);
			case 'plugin_mismatch':
				if ( 'validate' === $context ) {
					return array(
						'valid'             => false,
						'status'            => 'plugin_mismatch',
						'updates_available' => false,
						'message'           => $message,
					);
				}
				return array(
					'success' => false,
					'message' => $message,
				);
			default:
				return array(
					'success' => false,
					'message' => $message,
				);
		}
	}
}
