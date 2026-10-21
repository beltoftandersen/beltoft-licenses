<?php
/**
 * Shared helpers for the test suite.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

/**
 * Base test case: settings, fixture data and REST dispatch, including through an extra API path.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * The alias index the next dispatch pretends to have come in on.
	 *
	 * @var int|null
	 */
	protected $alias_index = null;

	public function set_up(): void {
		parent::set_up();

		$this->alias_index = null;
		add_filter(
			'beltoft_licenses_current_alias_index',
			function () {
				return $this->alias_index;
			}
		);

		// No rate limiting unless a test asks for it.
		$this->settings( array( 'rate_limit_requests' => 0 ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server         = null;
		$_SERVER['REQUEST_URI'] = '';
		unset( $_SERVER['REMOTE_ADDR'] );

		parent::tear_down();
	}

	/**
	 * Merges settings over the defaults.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	protected function settings( array $values ): void {
		$current = get_option( Settings::OPTION, array() );
		update_option( Settings::OPTION, array_merge( is_array( $current ) ? $current : array(), $values ) );
	}

	/**
	 * The beltoft.net configuration: Medusa response format, BLTFT keys, and the Medusa paths.
	 */
	protected function medusa_preset(): void {
		$this->settings(
			array(
				'key_prefix'       => 'BLTFT',
				'response_profile' => 'medusa',
				'aliases'          => "/store/licenses/{route} auth\n/license/{route}\n/store/plugin-versions => versions auth",
				'auth_header'      => 'x-publishable-api-key',
				'auth_values'      => 'pk_test_value',
			)
		);
	}

	/**
	 * A license.
	 *
	 * @param array<string, mixed> $data Columns.
	 * @return array<string, mixed>
	 */
	protected function license( array $data = array() ): array {
		$license = Licenses::create(
			array_merge(
				array(
					'product_slug'    => 'my-plugin',
					'max_activations' => 2,
					'email'           => 'customer@example.org',
				),
				$data
			)
		);

		$this->assertIsArray( $license );

		return $license;
	}

	/**
	 * A licensed product with a stored release file for its current version.
	 *
	 * @param string $slug    Software slug.
	 * @param string $version Version.
	 */
	protected function software( string $slug = 'my-plugin', string $version = '1.2.3' ): int {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => $slug,
			)
		);
		update_post_meta( $product_id, Software::META_LICENSED, 'yes' );
		update_post_meta( $product_id, Software::META_SLUG, $slug );

		$file = $slug . '/' . $slug . '-' . $version . '.zip';
		wp_mkdir_p( dirname( Software::path( $file ) ) );
		file_put_contents( Software::path( $file ), 'PK-test-zip-' . $version ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		Software::add_release( $slug, $version, $file, 'Changes.' );

		return $product_id;
	}

	/**
	 * Dispatches an API request.
	 *
	 * @param string                $method  GET or POST.
	 * @param string                $route   Route.
	 * @param array<string, mixed>  $params  JSON body (POST) or query (GET).
	 * @param array<string, string> $headers Headers.
	 * @param int|null              $alias   Extra API path index, or null for the REST URL.
	 * @param string                $path    The requested path, for an extra API path.
	 */
	protected function api( string $method, string $route, array $params = array(), array $headers = array(), ?int $alias = null, string $path = '' ): \WP_REST_Response {
		$this->alias_index      = $alias;
		$_SERVER['REQUEST_URI'] = '' !== $path ? $path : '/wp-json/beltoft-licenses/v1/' . $route;
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		$request = new \WP_REST_Request( $method, '/beltoft-licenses/v1/' . $route );

		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The body as a client decodes it.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @return mixed
	 */
	protected function body( \WP_REST_Response $response ) {
		return json_decode( (string) wp_json_encode( rest_get_server()->response_to_data( $response, false ) ), true );
	}
}
