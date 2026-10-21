<?php
/**
 * Releases from GitHub's "release published" webhook.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Releases;

use BeltoftLicenses\Api\Router;
use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub calls the webhook; the request is checked (signature, repository, release type)
 * and answered at once, and the download and publishing run in the background through
 * Action Scheduler, because GitHub gives up on a webhook after ten seconds.
 *
 * An attached .zip asset is used when the release has one (a built plugin); otherwise the
 * source zip, repackaged so its root folder is the software slug.
 */
final class GitHub {

	public const JOB        = 'beltoft_licenses_github_release';
	public const GROUP      = 'beltoft-licenses';
	public const OPTION_LOG = 'beltoft_licenses_github_log';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( self::JOB, array( __CLASS__, 'process' ) );
	}

	/**
	 * POST /wp-json/beltoft-licenses/v1/github. Public: GitHub's signature is the credential.
	 */
	public static function route(): void {
		register_rest_route(
			Router::NAMESPACE,
			'/github',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * owner/repository => software slug, from the settings.
	 *
	 * @return array<string, string>
	 */
	public static function repositories(): array {
		$map = array();

		foreach ( Settings::lines( 'github_repos' ) as $line ) {
			if ( false === strpos( $line, '=>' ) ) {
				continue;
			}

			list( $repo, $slug ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			$repo                = strtolower( $repo );
			$slug                = sanitize_title( $slug );

			if ( preg_match( '#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $repo ) && '' !== $slug ) {
				$map[ $repo ] = $slug;
			}
		}

		return $map;
	}

	/**
	 * The webhook.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function webhook( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = (string) Settings::get( 'github_secret' );

		if ( '' === $secret ) {
			return self::reply( 403, 'not_configured', 'No webhook secret is set under Settings → Licenses.' );
		}

		$body      = (string) $request->get_body();
		$signature = (string) $request->get_header( 'x_hub_signature_256' );

		if ( ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $signature ) ) {
			return self::reply( 401, 'bad_signature', 'The signature does not match the webhook secret.' );
		}

		$event = (string) $request->get_header( 'x_github_event' );

		if ( 'ping' === $event ) {
			return self::reply( 200, 'pong', 'Webhook connected.' );
		}

		$payload = json_decode( $body, true );

		if ( 'release' !== $event || ! is_array( $payload ) || 'published' !== ( $payload['action'] ?? '' ) ) {
			return self::reply( 200, 'ignored', 'Only "release published" events are used.' );
		}

		$release = (array) ( $payload['release'] ?? array() );
		$repo    = strtolower( (string) ( $payload['repository']['full_name'] ?? '' ) );
		$slug    = self::repositories()[ $repo ] ?? '';

		if ( '' === $slug ) {
			return self::reply( 200, 'ignored', 'This repository is not listed under Settings → Licenses → GitHub releases.' );
		}

		if ( ! empty( $release['draft'] ) || ( ! empty( $release['prerelease'] ) && ! Settings::get( 'github_prereleases' ) ) ) {
			return self::reply( 200, 'ignored', 'Drafts and pre-releases are not used.' );
		}

		$delivery = sanitize_key( (string) $request->get_header( 'x_github_delivery' ) );
		if ( '' !== $delivery ) {
			if ( get_transient( 'beltoft_licenses_gh_' . $delivery ) ) {
				return self::reply( 200, 'duplicate', 'This delivery was already received.' );
			}
			set_transient( 'beltoft_licenses_gh_' . $delivery, 1, DAY_IN_SECONDS );
		}

		$job = array(
			'repo'      => $repo,
			'slug'      => $slug,
			'version'   => ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' ),
			'changelog' => (string) ( $release['body'] ?? '' ),
			'asset'     => self::zip_asset( (array) ( $release['assets'] ?? array() ) ),
			'source'    => (string) ( $release['zipball_url'] ?? '' ),
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::JOB, array( $job ), self::GROUP );
		} else {
			self::process( $job );
		}

		return self::reply( 202, 'queued', sprintf( 'Release %s of %s is being published.', $job['version'], $slug ) );
	}

	/**
	 * The background job: download and publish.
	 *
	 * @param array<string, mixed> $job Job.
	 */
	public static function process( array $job ): void {
		$use_asset = ! empty( $job['asset'] );
		$url       = $use_asset ? (string) $job['asset']['url'] : (string) $job['source'];

		if ( '' === $url ) {
			self::record( sprintf( '%1$s %2$s: the release has no zip to download.', $job['slug'], $job['version'] ) );
			return;
		}

		$file = self::download( $url, $use_asset );

		if ( is_wp_error( $file ) ) {
			self::record( sprintf( '%1$s %2$s: %3$s', $job['slug'], $job['version'], $file->get_error_message() ) );
			return;
		}

		$result = Publisher::publish( (string) $job['slug'], (string) $job['version'], $file, (string) $job['changelog'], 'github', (bool) Settings::get( 'github_auto_publish' ), true );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $file );
			self::record( sprintf( '%1$s %2$s: %3$s', $job['slug'], $job['version'], $result->get_error_message() ) );
			return;
		}

		self::record(
			sprintf(
				'%1$s %2$s published from %3$s (%4$s)%5$s.',
				$job['slug'],
				$job['version'],
				$job['repo'],
				$use_asset ? 'attached zip' : 'source zip',
				Settings::get( 'github_auto_publish' ) ? ' and made current' : ', not yet current'
			)
		);
	}

	/**
	 * The first .zip asset: its API URL (works for private repositories with a token) and
	 * its public download URL.
	 *
	 * @param array<int, array<string, mixed>> $assets Release assets.
	 * @return array{url:string}|null
	 */
	private static function zip_asset( array $assets ): ?array {
		foreach ( $assets as $asset ) {
			if ( '.zip' === strtolower( substr( (string) ( $asset['name'] ?? '' ), -4 ) ) ) {
				$api = (string) ( $asset['url'] ?? '' );
				return array( 'url' => '' !== $api ? $api : (string) ( $asset['browser_download_url'] ?? '' ) );
			}
		}

		return null;
	}

	/**
	 * Downloads to a temporary file, following GitHub's redirect to its file storage.
	 *
	 * @param string $url   URL.
	 * @param bool   $asset Whether it is a release asset (needs the octet-stream Accept header).
	 * @return string|\WP_Error Path.
	 */
	private static function download( string $url, bool $asset ) {
		$file    = wp_tempnam( 'beltoft-licenses-release.zip' );
		$headers = array(
			'Accept'     => $asset ? 'application/octet-stream' : 'application/vnd.github+json',
			'User-Agent' => 'beltoft-licenses/' . BELTOFT_LICENSES_VERSION,
		);
		$token   = (string) Settings::get( 'github_token' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 120,
				'stream'      => true,
				'filename'    => $file,
				'headers'     => $headers,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $file );
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'beltoft_licenses_download', sprintf( __( 'GitHub answered HTTP %d to the download.', 'beltoft-licenses' ), $code ) );
		}

		return $file;
	}

	/**
	 * Keeps the last 20 results for the Releases screen.
	 *
	 * @param string $message Result.
	 */
	public static function record( string $message ): void {
		$log = self::log();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'message' => $message,
			)
		);
		update_option( self::OPTION_LOG, array_slice( $log, 0, 20 ), false );
	}

	/**
	 * The recorded results, newest first.
	 *
	 * @return array<int, array{time:int, message:string}>
	 */
	public static function log(): array {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * A JSON answer for GitHub's delivery log.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	private static function reply( int $status, string $code, string $message ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
	}
}
