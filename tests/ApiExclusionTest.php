<?php
/**
 * The beltoft_licenses_api_license filter: a license it hides is unknown to the public API.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;

class ApiExclusionTest extends TestCase {

	private const SLUG = 'excl-plugin';
	private const PK   = 'pk_test_value';

	/**
	 * @var array<string, mixed>
	 */
	private $hidden;

	/**
	 * @var array<string, mixed>
	 */
	private $shown;

	public function set_up(): void {
		parent::set_up();

		$this->medusa_preset();
		$this->software( self::SLUG, '2.0.0' );
		$this->hidden = $this->license( array( 'product_slug' => self::SLUG ) );
		$this->shown  = $this->license( array( 'product_slug' => self::SLUG ) );

		$hidden_key = $this->hidden['license_key'];
		add_filter(
			'beltoft_licenses_api_license',
			static function ( $license, $key ) use ( $hidden_key ) {
				return $key === $hidden_key ? null : $license;
			},
			10,
			2
		);
	}

	private function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/medusa/' . $name . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
	}

	private function sorted( array $body ): array {
		ksort( $body );

		return $body;
	}

	private function call( string $route, array $params ): \WP_REST_Response {
		return $this->api( 'POST', $route, $params, array( 'x-publishable-api-key' => self::PK ), 0, '/store/licenses/' . $route );
	}

	public function test_excluded_license_validates_as_not_found_in_the_medusa_profile(): void {
		$fixture  = $this->fixture( 'validate-not-found' );
		$response = $this->call( 'validate', array( 'license_key' => $this->hidden['license_key'] ) );

		$this->assertSame( $fixture['status'], $response->get_status() );
		$this->assertSame( $this->sorted( $fixture['body'] ), $this->sorted( $this->body( $response ) ) );
	}

	public function test_excluded_license_cannot_activate_or_download(): void {
		$params = array(
			'license_key' => $this->hidden['license_key'],
			'domain'      => 'example.com',
		);

		foreach ( array( 'activate' => 'activate-not-found', 'download' => 'download-not-found', 'deactivate' => 'deactivate-not-found' ) as $route => $name ) {
			$fixture  = $this->fixture( $name );
			$response = $this->call( $route, $params );

			$this->assertSame( $fixture['status'], $response->get_status(), $route );
			$this->assertSame( $this->sorted( $fixture['body'] ), $this->sorted( $this->body( $response ) ), $route );
		}

		$this->assertSame( array(), Licenses::live_activations( (int) $this->hidden['id'] ) );
	}

	public function test_other_licenses_are_unaffected(): void {
		$validate = $this->body( $this->call( 'validate', array( 'license_key' => $this->shown['license_key'] ) ) );
		$this->assertTrue( $validate['valid'] );

		$activate = $this->call( 'activate', array( 'license_key' => $this->shown['license_key'], 'domain' => 'example.com' ) );
		$this->assertSame( 200, $activate->get_status() );
		$this->assertCount( 1, Licenses::live_activations( (int) $this->shown['id'] ) );
	}
}
