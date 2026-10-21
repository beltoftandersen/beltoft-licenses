<?php
/**
 * The license API with default settings, as any store gets it on a fresh install.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;

class ApiStandardTest extends TestCase {

	public function test_full_flow_on_rest_urls(): void {
		$this->software( 'my-plugin', '2.0.0' );
		$l = $this->license( array( 'max_activations' => 1 ) );

		$v = $this->body( $this->api( 'POST', 'validate', array( 'license_key' => $l['license_key'], 'domain' => 'https://shop.example/' ) ) );
		$this->assertTrue( $v['valid'] );
		$this->assertFalse( $v['domain_activated'] );
		$this->assertSame( '2.0.0', $v['current_version'] );

		$a = $this->api( 'POST', 'activate', array( 'license_key' => $l['license_key'], 'domain' => 'shop.example' ) );
		$this->assertSame( array( 'success' => true, 'message' => 'Activation successful' ), $this->body( $a ) );

		$d = $this->body( $this->api( 'POST', 'download', array( 'license_key' => $l['license_key'], 'domain' => 'www.shop.example' ) ) );
		$this->assertSame( 'my-plugin-2.0.0.zip', $d['filename'] );
		$this->assertSame( rest_url( 'beltoft-licenses/v1/download-file' ) . '&token=' . rawurlencode( substr( $d['download_url'], strpos( $d['download_url'], 'token=' ) + 6 ) ), $d['download_url'], 'rest_route stays readable on plain permalinks' );

		$this->assertSame( array( 'slug' => 'my-plugin', 'version' => '2.0.0' ), $this->body( $this->api( 'GET', 'versions', array( 'slug' => 'My-Plugin' ) ) ) );
	}

	public function test_errors_carry_a_code(): void {
		$r = $this->api( 'POST', 'activate', array( 'license_key' => 'NOPE', 'domain' => 'x.example' ) );
		$this->assertSame( 404, $r->get_status() );
		$this->assertSame( array( 'success' => false, 'code' => 'not_found', 'message' => 'License not found' ), $this->body( $r ) );

		$r = $this->api( 'POST', 'validate', array() );
		$this->assertSame( 'missing_license_key', $this->body( $r )['code'] );
	}

	public function test_version_mode_compares_versions(): void {
		$this->settings( array( 'updates_mode' => 'version' ) );
		$this->software( 'my-plugin', '2.0.0' );
		$l = $this->license();

		$this->assertTrue( $this->body( $this->api( 'POST', 'validate', array( 'license_key' => $l['license_key'], 'version' => '1.9.0' ) ) )['updates_available'] );
		$this->assertFalse( $this->body( $this->api( 'POST', 'validate', array( 'license_key' => $l['license_key'], 'version' => '2.0.0' ) ) )['updates_available'] );
	}

	public function test_no_header_required_without_configuration(): void {
		$this->settings( array( 'aliases' => "/store/licenses/{route} auth" ) );
		$this->assertSame( 200, $this->api( 'POST', 'validate', array( 'license_key' => 'X' ), array(), 0, '/store/licenses/validate' )->get_status(), 'auth marker without a header name requires nothing' );
	}

	public function test_activation_records_history(): void {
		$l = $this->license();
		$this->api( 'POST', 'activate', array( 'license_key' => $l['license_key'], 'domain' => 'a.example' ) );
		$this->api( 'POST', 'deactivate', array( 'license_key' => $l['license_key'], 'domain' => 'a.example' ) );
		$this->api( 'POST', 'activate', array( 'license_key' => $l['license_key'], 'domain' => 'a.example' ) );

		global $wpdb;
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE license_id = %d', $wpdb->prefix . 'beltoft_licenses_activations', $l['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 2, $rows );
		$this->assertCount( 1, Licenses::live_activations( $l['id'] ) );
	}
}
