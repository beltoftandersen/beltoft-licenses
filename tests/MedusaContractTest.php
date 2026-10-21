<?php
/**
 * The "Medusa compatible" response format, checked against responses recorded from the
 * Medusa license backend (tests/fixtures/medusa). Same status, same JSON fields and values.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Api\Token;
use BeltoftLicenses\Licenses;

class MedusaContractTest extends TestCase {

	private const KEY    = 'BLTFT-AAAA-0000-1111-2222';
	private const SLUG   = 'woocommerce-subscriptions';
	private const DOMAIN = 'staging.beltoft.net';
	private const PK     = 'pk_test_value';

	/**
	 * The license the recordings were made with: active, no expiry, two sites, live on one.
	 *
	 * @var array<string, mixed>
	 */
	private $lic;

	public function set_up(): void {
		parent::set_up();

		$this->medusa_preset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->software( self::SLUG, '9.3.0' );
		$this->lic = $this->license(
			array(
				'license_key'     => self::KEY,
				'product_slug'    => self::SLUG,
				'max_activations' => 2,
			)
		);
		Licenses::activate( $this->lic, self::DOMAIN );
	}

	/**
	 * Every recorded request.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function recorded(): array {
		$cases = array();
		foreach ( glob( __DIR__ . '/fixtures/medusa/*.json' ) as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			if ( isset( $data['request'] ) ) {
				$cases[ basename( $file, '.json' ) ] = array( $file );
			}
		}

		return $cases;
	}

	/**
	 * @dataProvider recorded
	 */
	public function test_recorded_response( string $file ): void {
		$fixture = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$name    = basename( $file, '.json' );
		$req     = $fixture['request'];
		$path    = (string) wp_parse_url( $req['path'], PHP_URL_PATH );
		$query   = array();
		wp_parse_str( (string) wp_parse_url( $req['path'], PHP_URL_QUERY ), $query );

		if ( 0 === strpos( $path, '/store/plugin-versions' ) ) {
			$alias = 2;
			$route = 'versions';
		} else {
			$alias = 0 === strpos( $path, '/store/licenses/' ) ? 0 : 1;
			$route = substr( $path, strrpos( $path, '/' ) + 1 );
		}

		$headers = array();
		if ( 0 === $alias || 2 === $alias ) {
			if ( isset( $req['headers']['x-publishable-api-key'] ) ) {
				$headers['x-publishable-api-key'] = $req['headers']['x-publishable-api-key'];
			} elseif ( 'validate-no-pk-header' !== $name ) {
				$headers['x-publishable-api-key'] = self::PK;
			}
		}

		$params   = 'GET' === $req['method'] ? $query : (array) ( $req['body'] ?? array() );
		$response = $this->api( $req['method'], $route, $params, $headers, $alias, $path );
		$body     = $this->body( $response );
		$expected = $fixture['body'];

		$this->assertSame( $fixture['status'], $response->get_status(), $name . ': status' );

		if ( isset( $expected['download_url'] ) ) {
			$prefix = 0 === $alias ? '/store/licenses/' : '/license/';
			$this->assertStringStartsWith( home_url( $prefix . 'download-file?token=' ), $body['download_url'], $name );
			wp_parse_str( (string) wp_parse_url( $body['download_url'], PHP_URL_QUERY ), $q );
			$this->assertSame( (int) $this->lic['id'], Token::verify( $q['token'] )['license_id'], $name . ': token' );
			$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.000Z$/', $body['expires_at'] );
			unset( $expected['download_url'], $expected['expires_at'], $body['download_url'], $body['expires_at'] );
		}

		$this->assertSame( $this->sorted( $expected ), $this->sorted( $body ), $name . ': body' );
	}

	public function test_expired_validate_leaves_out_current_version(): void {
		$l    = $this->license( array( 'product_slug' => self::SLUG, 'max_activations' => 1, 'expires_at' => '2020-01-01 00:00:00' ) );
		$body = $this->body( $this->api( 'POST', 'validate', array( 'license_key' => $l['license_key'] ), array( 'x-publishable-api-key' => self::PK ), 0, '/store/licenses/validate' ) );

		$this->assertSame(
			$this->sorted( array( 'valid' => true, 'license_active' => false, 'status' => 'expired', 'updates_available' => false, 'domain_activated' => false, 'expires_at' => '2020-01-01T00:00:00.000Z', 'max_activations' => 1 ) ),
			$this->sorted( $body )
		);
	}

	public function test_revoked_and_suspended_are_not_valid(): void {
		foreach ( array( 'revoked', 'suspended' ) as $status ) {
			$l    = $this->license( array( 'product_slug' => self::SLUG, 'status' => $status ) );
			$body = $this->body( $this->api( 'POST', 'validate', array( 'license_key' => $l['license_key'] ), array(), 1, '/license/validate' ) );
			$this->assertFalse( $body['valid'] );
			$this->assertSame( $status, $body['status'] );
			$this->assertArrayNotHasKey( 'current_version', $body );
		}
	}

	/**
	 * @dataProvider derived_errors
	 */
	public function test_derived_error( string $fixture, callable $arrange, string $route, bool $with_domain ): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/medusa/' . $fixture . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$license  = $arrange( $this );
		$params   = array( 'license_key' => $license['license_key'] );
		if ( $with_domain ) {
			$params['domain'] = 'new.example';
		}

		$response = $this->api( 'POST', $route, $params, array(), 1, '/license/' . $route );

		$this->assertSame( $expected['status'], $response->get_status(), $fixture );
		$this->assertSame( $expected['body'], $this->body( $response ), $fixture );
	}

	public function derived_errors(): array {
		return array(
			'activate-not-active'      => array( 'activate-not-active', fn( $t ) => $t->license( array( 'product_slug' => self::SLUG, 'status' => 'revoked' ) ), 'activate', true ),
			'activate-expired-by-date' => array( 'activate-expired-by-date', fn( $t ) => $t->license( array( 'product_slug' => self::SLUG, 'expires_at' => '2020-01-01' ) ), 'activate', true ),
			'activate-success'         => array( 'activate-success', fn( $t ) => $t->license( array( 'product_slug' => self::SLUG ) ), 'activate', true ),
			'activate-limit-reached'   => array(
				'activate-limit-reached',
				function ( $t ) {
					$l = $t->license( array( 'product_slug' => self::SLUG, 'max_activations' => 1 ) );
					Licenses::activate( $l, 'first.example' );
					return $l;
				},
				'activate',
				true,
			),
			'deactivate-success'       => array(
				'deactivate-success',
				function ( $t ) {
					$l = $t->license( array( 'product_slug' => self::SLUG ) );
					Licenses::activate( $l, 'new.example' );
					return $l;
				},
				'deactivate',
				true,
			),
			'download-not-active'      => array( 'download-not-active', fn( $t ) => $t->license( array( 'product_slug' => self::SLUG, 'status' => 'suspended' ) ), 'download', true ),
			'download-expired'         => array( 'download-expired', fn( $t ) => $t->license( array( 'product_slug' => self::SLUG, 'expires_at' => '2020-01-01' ) ), 'download', true ),
			'download-no-plugin'       => array(
				'download-no-plugin',
				function ( $t ) {
					$l = $t->license( array( 'product_slug' => '' ) );
					Licenses::activate( $l, 'new.example' );
					return $l;
				},
				'download',
				true,
			),
			'download-no-product'      => array(
				'download-no-product',
				function ( $t ) {
					$l = $t->license( array( 'product_slug' => 'unknown-plugin' ) );
					Licenses::activate( $l, 'new.example' );
					return $l;
				},
				'download',
				true,
			),
		);
	}

	public function test_download_file_streams_through_the_delivery_filter(): void {
		$delivered = null;
		add_filter(
			'beltoft_licenses_deliver_file',
			function ( $handled, $path, $filename ) use ( &$delivered ) {
				$delivered = array( basename( $path ), $filename );
				return true;
			},
			10,
			3
		);

		$token    = Token::create( (int) $this->lic['id'], self::DOMAIN );
		$response = $this->api( 'GET', 'download-file', array( 'token' => $token ), array(), 1, '/license/download-file' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( self::SLUG . '-9.3.0.zip', self::SLUG . '-9.3.0.zip' ), $delivered );
	}

	public function test_download_file_after_deactivation_is_refused(): void {
		$token = Token::create( (int) $this->lic['id'], self::DOMAIN );
		Licenses::deactivate( $this->lic, self::DOMAIN );

		$response = $this->api( 'GET', 'download-file', array( 'token' => $token ), array(), 1, '/license/download-file' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'License is not activated on this domain.', $this->body( $response )['message'] );
	}

	public function test_rate_limited_body(): void {
		$expected = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/medusa/rate-limited.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
		$this->settings( array( 'rate_limit_requests' => 1 ) );
		$this->api( 'POST', 'validate', array( 'license_key' => 'x' ), array(), 1, '/license/validate' );
		$response = $this->api( 'POST', 'validate', array( 'license_key' => 'x' ), array(), 1, '/license/validate' );

		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( $expected['body'], $this->body( $response ) );
	}

	/**
	 * Arrays with keys sorted, recursively, so field order is ignored and types are not.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sorted( $value ) {
		if ( is_array( $value ) ) {
			ksort( $value );
			return array_map( array( $this, 'sorted' ), $value );
		}

		return $value;
	}
}
