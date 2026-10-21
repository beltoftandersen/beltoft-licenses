<?php
/**
 * The license API handlers.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Key;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * One method per route. The order of the checks in each is part of the contract: a client
 * sees the first failing check, so it matches the license backend this plugin stands in for.
 */
final class Controller {

	/**
	 * POST validate: license_key, domain?, plugin_slug?, version?
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function validate( \WP_REST_Request $request ): \WP_REST_Response {
		$key = Key::normalize( $request->get_param( 'license_key' ) );

		if ( '' === $key ) {
			return Responses::error( 'missing_license_key', array(), 'validate' );
		}

		$license = Licenses::find_by_key( $key );

		if ( ! $license ) {
			return new \WP_REST_Response(
				array(
					'valid'             => false,
					'status'            => 'not_found',
					'updates_available' => false,
				),
				200
			);
		}

		$requested = (string) $request->get_param( 'plugin_slug' );
		if ( self::mismatch( $license, $requested ) ) {
			return Responses::error( 'plugin_mismatch', array( $license['product_slug'], $requested ), 'validate' );
		}

		$domain           = Key::domain( $request->get_param( 'domain' ) );
		$domain_activated = '' !== $domain && Licenses::is_live_on( (int) $license['id'], $domain );
		$status           = Licenses::effective_status( $license );
		$active           = Licenses::STATUS_ACTIVE === $status;
		$current_version  = $active && '' !== $license['product_slug'] ? Software::current_version( $license['product_slug'] ) : null;
		$updates          = $active;

		if ( $active && 'version' === Settings::get( 'updates_mode' ) ) {
			$installed = (string) $request->get_param( 'version' );
			$updates   = null !== $current_version && '' !== $installed && version_compare( $current_version, $installed, '>' );
		}

		$body = array(
			'valid'             => ! in_array( $status, array( Licenses::STATUS_REVOKED, Licenses::STATUS_SUSPENDED ), true ),
			'license_active'    => $active,
			'status'            => $status,
			'updates_available' => $updates,
			'domain_activated'  => $domain_activated,
		);

		// Left out entirely when unknown, not sent as null: clients check for its presence.
		if ( null !== $current_version ) {
			$body['current_version'] = $current_version;
		}

		$body['expires_at']      = Licenses::iso( $license['expires_at'] );
		$body['max_activations'] = $license['max_activations'];

		return new \WP_REST_Response( apply_filters( 'beltoft_licenses_validate_response', $body, $license, $request ), 200 );
	}

	/**
	 * POST activate: license_key, domain, plugin_slug?
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function activate( \WP_REST_Request $request ): \WP_REST_Response {
		list( $license, $domain, $error ) = self::license_and_domain( $request, 'activate', true );

		if ( $error ) {
			return $error;
		}

		$result = Licenses::activate( $license, $domain );

		if ( ! $result['success'] ) {
			$args = 'license_not_active' === $result['code'] ? array( $license['status'] ) : ( 'activation_limit' === $result['code'] ? array( $license['max_activations'] ) : array() );
			return Responses::error( $result['code'], $args, 'activate' );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * POST deactivate: license_key, domain. No software check, as in the reference backend:
	 * a customer must always be able to free a site.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function deactivate( \WP_REST_Request $request ): \WP_REST_Response {
		list( $license, $domain, $error ) = self::license_and_domain( $request, 'deactivate', false );

		if ( $error ) {
			return $error;
		}

		$result = Licenses::deactivate( $license, $domain );

		if ( ! $result['success'] ) {
			return Responses::error( $result['code'], array(), 'deactivate' );
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => $result['message'],
			),
			200
		);
	}

	/**
	 * POST download: license_key, domain, plugin_slug? Returns a short-lived download link.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download( \WP_REST_Request $request ): \WP_REST_Response {
		list( $license, $domain, $error ) = self::license_and_domain( $request, 'download', true );

		if ( $error ) {
			return $error;
		}

		$failure = self::download_checks( $license, $domain, 'download' );
		if ( $failure ) {
			return $failure;
		}

		$version = (string) Software::current_version( $license['product_slug'] );
		$ttl     = Token::ttl();

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'download_url' => Router::url( 'download-file', array( 'token' => Token::create( (int) $license['id'], $domain, $ttl ) ) ),
				'version'      => $version,
				'filename'     => $license['product_slug'] . '-' . $version . '.zip',
				'expires_at'   => gmdate( 'Y-m-d\TH:i:s.000\Z', time() + $ttl ),
			),
			200
		);
	}

	/**
	 * GET download-file?token= Streams the zip of the current version.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function download_file( \WP_REST_Request $request ): \WP_REST_Response {
		$token = $request->get_param( 'token' );

		if ( ! is_string( $token ) || '' === $token ) {
			return Responses::error( 'token_required', array(), 'download-file' );
		}

		$payload = Token::verify( $token );
		$license = $payload ? Licenses::find( $payload['license_id'] ) : null;

		if ( ! $payload || ! $license ) {
			return Responses::error( 'token_invalid', array(), 'download-file' );
		}

		$failure = self::download_checks( $license, $payload['domain'], 'download-file' );
		if ( $failure ) {
			return $failure;
		}

		$version = (string) Software::current_version( $license['product_slug'] );
		$release = Software::release( $license['product_slug'], $version );
		$path    = $release ? Software::path( (string) $release['file'] ) : '';

		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return Responses::error( 'file_missing', array(), 'download-file' );
		}

		$filename = $license['product_slug'] . '-' . $version . '.zip';

		/**
		 * Lets tests and other integrations take over delivery. Return true when handled.
		 */
		if ( apply_filters( 'beltoft_licenses_deliver_file', false, $path, $filename, $license ) ) {
			return new \WP_REST_Response( null, 200 );
		}

		Download::send( $path, $filename );
	}

	/**
	 * GET versions?slug= The current version of a piece of software.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function versions( \WP_REST_Request $request ): \WP_REST_Response {
		$slug = strtolower( trim( (string) $request->get_param( 'slug' ) ) );

		if ( '' === $slug ) {
			return Responses::error( 'slug_required', array(), 'versions' );
		}

		$version = Software::current_version( $slug );

		if ( null === $version ) {
			return Responses::error( 'slug_not_found', array(), 'versions' );
		}

		return new \WP_REST_Response(
			array(
				'slug'    => $slug,
				'version' => $version,
			),
			200
		);
	}

	/**
	 * Shared start of activate, deactivate and download: both fields present, the license
	 * exists, and (where asked) it belongs to the requesting software.
	 *
	 * @param \WP_REST_Request $request    Request.
	 * @param string           $context    Route.
	 * @param bool             $check_slug Whether plugin_slug is checked.
	 * @return array{0: array<string, mixed>|null, 1: string, 2: \WP_REST_Response|null}
	 */
	private static function license_and_domain( \WP_REST_Request $request, string $context, bool $check_slug ): array {
		$key        = Key::normalize( $request->get_param( 'license_key' ) );
		$raw_domain = $request->get_param( 'domain' );

		if ( '' === $key || ! is_string( $raw_domain ) || '' === $raw_domain ) {
			return array( null, '', Responses::error( 'missing_key_domain', array(), $context ) );
		}

		$license = Licenses::find_by_key( $key );

		if ( ! $license ) {
			return array( null, '', Responses::error( 'not_found', array(), $context ) );
		}

		$requested = (string) $request->get_param( 'plugin_slug' );
		if ( $check_slug && self::mismatch( $license, $requested ) ) {
			return array( null, '', Responses::error( 'plugin_mismatch', array( $license['product_slug'], $requested ), $context ) );
		}

		return array( $license, Key::domain( $raw_domain ), null );
	}

	/**
	 * Checks shared by download and download-file, in the reference order.
	 *
	 * @param array<string, mixed> $license License.
	 * @param string               $domain  Normalised domain.
	 * @param string               $context Route.
	 */
	private static function download_checks( array $license, string $domain, string $context ): ?\WP_REST_Response {
		if ( Licenses::STATUS_ACTIVE !== $license['status'] ) {
			return Responses::error( 'download_not_active', array(), $context );
		}

		if ( Licenses::is_past( $license['expires_at'] ) ) {
			return Responses::error( 'download_expired', array(), $context );
		}

		if ( ! Licenses::is_live_on( (int) $license['id'], $domain ) ) {
			return Responses::error( 'domain_not_activated', array(), $context );
		}

		if ( '' === $license['product_slug'] ) {
			return Responses::error( 'no_software', array(), $context );
		}

		$version = Software::current_version( $license['product_slug'] );

		if ( null === $version ) {
			return Responses::error( 'software_not_found', array(), $context );
		}

		if ( '' === $version || ! Software::release( $license['product_slug'], $version ) ) {
			return Responses::error( 'no_release', array(), $context );
		}

		return null;
	}

	/**
	 * Does the request name different software than the license is for?
	 *
	 * @param array<string, mixed> $license   License.
	 * @param string               $requested plugin_slug from the request.
	 */
	private static function mismatch( array $license, string $requested ): bool {
		return '' !== $requested && '' !== $license['product_slug'] && $requested !== $license['product_slug'];
	}
}
