<?php
/**
 * Extra API paths, header check and rate limit.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Api\Router;

class RouterTest extends TestCase {

	public function test_alias_lines_are_parsed(): void {
		$this->settings( array( 'aliases' => "/store/licenses/{route} auth\n/license/{route}/\n/store/plugin-versions => versions auth\n/bad => nope\n/no-placeholder\n" ) );

		$this->assertSame(
			array(
				array( 'pattern' => '/store/licenses/{route}', 'route' => null, 'auth' => true ),
				array( 'pattern' => '/license/{route}', 'route' => null, 'auth' => false ),
				array( 'pattern' => '/store/plugin-versions', 'route' => 'versions', 'auth' => true ),
			),
			Router::aliases()
		);
	}

	public function test_paths_are_matched_with_any_permalink_setting(): void {
		$this->settings( array( 'aliases' => "/store/licenses/{route} auth\n/license/{route}\n/store/plugin-versions => versions" ) );

		foreach ( array( '', '/%postname%/' ) as $structure ) {
			$this->set_permalink_structure( $structure );
			$this->assertSame( array( 0, 'download-file' ), Router::match( '/store/licenses/download-file' ), $structure );
			$this->assertSame( array( 1, 'validate' ), Router::match( '/license/validate/' ), $structure );
			$this->assertSame( array( 2, 'versions' ), Router::match( '/store/plugin-versions' ), $structure );
			$this->assertNull( Router::match( '/store/licenses/nope' ), $structure );
			$this->assertNull( Router::match( '/license/validate/extra' ), $structure );
		}
	}

	public function test_parse_request_hands_over_to_rest(): void {
		$this->settings( array( 'aliases' => '/license/{route}' ) );
		$_SERVER['REQUEST_URI'] = '/license/activate?x=1';
		$wp                     = new \WP();
		Router::parse_request( $wp );

		$this->assertSame( '/beltoft-licenses/v1/activate', $wp->query_vars['rest_route'] );
		$this->assertSame( 0, $wp->query_vars[ Router::QUERY_VAR ] );

		$_SERVER['REQUEST_URI'] = '/some-page/';
		$other                  = new \WP();
		Router::parse_request( $other );
		$this->assertArrayNotHasKey( 'rest_route', $other->query_vars );
	}

	public function test_paths_are_relative_to_a_subdirectory_install(): void {
		$this->settings( array( 'aliases' => '/license/{route}' ) );
		update_option( 'home', 'http://example.org/shop' );
		$this->assertSame( array( 0, 'validate' ), Router::match( '/shop/license/validate' ) );
	}

	public function test_header_required_only_on_auth_paths(): void {
		$this->medusa_preset();
		$params = array( 'license_key' => 'NOPE' );

		$missing = $this->api( 'POST', 'validate', $params, array(), 0, '/store/licenses/validate' );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'not_allowed', $this->body( $missing )['type'] );

		$wrong = $this->api( 'POST', 'validate', $params, array( 'x-publishable-api-key' => 'pk_other' ), 0, '/store/licenses/validate' );
		$this->assertSame( 'A valid publishable key is required to proceed with the request', $this->body( $wrong )['message'] );

		$this->assertSame( 200, $this->api( 'POST', 'validate', $params, array( 'x-publishable-api-key' => 'pk_test_value' ), 0, '/store/licenses/validate' )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', 'validate', $params, array(), 1, '/license/validate' )->get_status() );
		$this->assertSame( 200, $this->api( 'POST', 'validate', $params )->get_status() );
	}

	public function test_rate_limit_per_path(): void {
		$this->settings( array( 'rate_limit_requests' => 3, 'rate_limit_window' => 60 ) );
		$codes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = $this->api( 'POST', 'validate', array( 'license_key' => 'X' ) )->get_status();
		}
		$this->assertSame( array( 200, 200, 200, 429 ), $codes );
		$this->assertSame( 400, $this->api( 'POST', 'activate', array() )->get_status(), 'another path has its own budget' );
	}

	public function test_download_url_follows_the_path_used(): void {
		$this->medusa_preset();
		$this->set_permalink_structure( '/%postname%/' );
		$this->alias_index      = 1;
		$_SERVER['REQUEST_URI'] = '/license/download';
		$this->assertStringStartsWith( home_url( '/license/download-file?token=' ), Router::url( 'download-file', array( 'token' => 'a.b' ) ) );

		$this->alias_index = null;
		$this->assertStringStartsWith( rest_url( 'beltoft-licenses/v1/download-file' ), Router::url( 'download-file', array( 'token' => 'a.b' ) ) );
	}
}
