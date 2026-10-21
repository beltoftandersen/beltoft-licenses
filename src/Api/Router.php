<?php
/**
 * REST routes, extra API paths, and the checks every API request goes through.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Api;

use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The API lives at /wp-json/beltoft-licenses/v1/<route>. "Extra API paths" in the settings
 * add more URLs for the same routes through rewrite rules, so plugins already in the field
 * can keep calling the paths they were built with, on any web server.
 */
final class Router {

	public const NAMESPACE   = 'beltoft-licenses/v1';
	public const QUERY_VAR   = 'beltoft_licenses_alias';
	public const ROUTES      = array( 'validate', 'activate', 'deactivate', 'download', 'download-file', 'versions' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), 0 );
	}

	/**
	 * REST routes. The API is public by design: the license key is the credential, and the
	 * rate limit and optional header check run in permission().
	 */
	public static function register_routes(): void {
		$routes = array(
			'validate'      => array( 'POST', 'validate' ),
			'activate'      => array( 'POST', 'activate' ),
			'deactivate'    => array( 'POST', 'deactivate' ),
			'download'      => array( 'POST', 'download' ),
			'download-file' => array( 'GET', 'download_file' ),
			'versions'      => array( 'GET', 'versions' ),
		);

		foreach ( $routes as $route => $spec ) {
			register_rest_route(
				self::NAMESPACE,
				'/' . $route,
				array(
					'methods'             => $spec[0],
					'callback'            => array( Controller::class, $spec[1] ),
					'permission_callback' => static function ( \WP_REST_Request $request ) use ( $route ) {
						return self::permission( $request, $route );
					},
				)
			);
		}
	}

	/**
	 * Rate limit, then the header check on paths marked "auth". Returns true or an error
	 * response; a WP_Error would be rendered in WordPress's own shape, not the profile's.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $route   Route.
	 * @return true|\WP_Error
	 */
	public static function permission( \WP_REST_Request $request, string $route ) {
		$alias = self::current_alias();
		$path  = $alias ? $alias['matched_path'] : '/' . self::NAMESPACE . '/' . $route;

		if ( RateLimit::exceeded( RateLimit::client_ip(), $path ) ) {
			return self::deny( Responses::error( 'rate_limited', array(), $route ) );
		}

		if ( $alias && $alias['auth'] && '' !== (string) Settings::get( 'auth_header' ) ) {
			$header = (string) Settings::get( 'auth_header' );
			$value  = (string) $request->get_header( $header );

			if ( '' === $value ) {
				return self::deny( Responses::error( 'auth_missing', array( $header ), $route ) );
			}

			$accepted = Settings::lines( 'auth_values' );
			$ok       = false;
			foreach ( $accepted as $candidate ) {
				$ok = $ok || hash_equals( $candidate, $value );
			}

			if ( ! $ok ) {
				return self::deny( Responses::error( 'auth_invalid', array(), $route ) );
			}
		}

		return true;
	}

	/**
	 * Turns a profile response into a permission failure that still renders in that profile's
	 * shape: rest_send_* would otherwise wrap it as {code, message, data}.
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private static function deny( \WP_REST_Response $response ): \WP_Error {
		$error = new \WP_Error(
			'beltoft_licenses_denied',
			'',
			array(
				'status'                    => $response->get_status(),
				'beltoft_licenses_response' => $response,
			)
		);

		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'render_denied' ), 10, 1 );

		return $error;
	}

	/**
	 * Replaces our permission error with the profile body it carries.
	 *
	 * @param mixed $response Response or error.
	 * @return mixed
	 */
	public static function render_denied( $response ) {
		if ( $response instanceof \WP_Error && 'beltoft_licenses_denied' === $response->get_error_code() ) {
			$data = $response->get_error_data();
			if ( is_array( $data ) && isset( $data['beltoft_licenses_response'] ) ) {
				return $data['beltoft_licenses_response'];
			}
		}

		return $response;
	}

	/**
	 * The configured extra paths.
	 *
	 * Lines look like "/store/licenses/{route} auth" or "/store/plugin-versions => versions auth".
	 *
	 * @return array<int, array{pattern:string, route:string|null, auth:bool}>
	 */
	public static function aliases(): array {
		$aliases = array();

		foreach ( Settings::lines( 'aliases' ) as $line ) {
			$auth = (bool) preg_match( '/\s+auth$/i', $line );
			$line = trim( preg_replace( '/\s+auth$/i', '', $line ) );
			$map  = null;

			if ( false !== strpos( $line, '=>' ) ) {
				list( $line, $map ) = array_map( 'trim', explode( '=>', $line, 2 ) );
				if ( ! in_array( $map, self::ROUTES, true ) ) {
					continue;
				}
			} elseif ( false === strpos( $line, '{route}' ) ) {
				continue;
			}

			$pattern = '/' . trim( $line, '/' );
			if ( '/' === $pattern ) {
				continue;
			}

			$aliases[] = array(
				'pattern' => $pattern,
				'route'   => $map,
				'auth'    => $auth,
			);
		}

		return $aliases;
	}

	/**
	 * Which extra path, and which route, a request path is.
	 *
	 * @param string $path Request path, without the query string.
	 * @return array{0:int, 1:string}|null Alias index and route.
	 */
	public static function match( string $path ): ?array {
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path = '/' . ltrim( $path, '/' );

		// A WordPress in a subdirectory: the configured paths are relative to it.
		if ( '/' !== $home && 0 === strpos( $path, untrailingslashit( $home ) . '/' ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home ) ) );
		}

		$path = '/' . trim( $path, '/' );

		foreach ( self::aliases() as $index => $alias ) {
			if ( null !== $alias['route'] ) {
				if ( $path === $alias['pattern'] ) {
					return array( $index, $alias['route'] );
				}
				continue;
			}

			list( $before, $after ) = explode( '{route}', $alias['pattern'], 2 );
			$regex                  = '#^' . preg_quote( $before, '#' ) . '(' . implode( '|', array_map( 'preg_quote', self::ROUTES ) ) . ')' . preg_quote( rtrim( $after, '/' ), '#' ) . '$#';

			if ( preg_match( $regex, $path, $m ) ) {
				return array( $index, $m[1] );
			}
		}

		return null;
	}

	/**
	 * Routes a request on an extra path to the REST API. Runs before WordPress's own
	 * rest_api_loaded on the same hook, and works with any permalink setting, because it
	 * looks at the requested path itself rather than at rewrite rules.
	 *
	 * @param \WP $wp The request.
	 */
	public static function parse_request( \WP $wp ): void {
		if ( ! self::aliases() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri   = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$match = self::match( (string) wp_parse_url( $uri, PHP_URL_PATH ) );

		if ( ! $match ) {
			return;
		}

		$wp->query_vars['rest_route']    = '/' . self::NAMESPACE . '/' . $match[1];
		$wp->query_vars[ self::QUERY_VAR ] = $match[0];
	}

	/**
	 * The extra path this request came in on, if any, with the path actually requested.
	 *
	 * @return array{pattern:string, route:string|null, auth:bool, matched_path:string}|null
	 */
	public static function current_alias(): ?array {
		global $wp;

		$index = isset( $wp->query_vars[ self::QUERY_VAR ] ) ? $wp->query_vars[ self::QUERY_VAR ] : null;
		$index = apply_filters( 'beltoft_licenses_current_alias_index', $index );

		if ( null === $index || '' === $index ) {
			return null;
		}

		$aliases = self::aliases();

		if ( ! isset( $aliases[ (int) $index ] ) ) {
			return null;
		}

		$alias                 = $aliases[ (int) $index ];
		$uri                   = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$alias['matched_path'] = (string) wp_parse_url( $uri, PHP_URL_PATH );

		return $alias;
	}

	/**
	 * The URL of a route as the client should call it: under the extra path the request came
	 * in on when that path covers every route, else the REST URL.
	 *
	 * @param string               $route Route.
	 * @param array<string, string> $query Query arguments.
	 */
	public static function url( string $route, array $query = array() ): string {
		$alias = self::current_alias();

		if ( $alias && null === $alias['route'] ) {
			$url = home_url( str_replace( '{route}', $route, $alias['pattern'] ) );
		} else {
			$url = rest_url( self::NAMESPACE . '/' . $route );
		}

		// Appended, not add_query_arg(): that re-encodes an existing ?rest_route=/… on sites
		// without pretty permalinks.
		if ( ! $query ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}
}
