<?php
/**
 * Settings defaults and sanitising.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Settings;

class SettingsTest extends TestCase {

	public function test_defaults_work_on_a_fresh_install(): void {
		delete_option( Settings::OPTION );
		$this->assertSame( 'standard', Settings::get( 'response_profile' ) );
		$this->assertSame( 3, Settings::get( 'grace_days' ) );
		$this->assertSame( 'licenses', Settings::get( 'account_endpoint' ) );
		$this->assertSame( array(), Settings::lines( 'aliases' ) );
	}

	public function test_sanitize(): void {
		$clean = Settings::sanitize(
			array(
				'key_prefix'       => 'bl-tft!',
				'key_template'     => 'no placeholders',
				'response_profile' => 'evil',
				'grace_days'       => '-5',
				'auth_header'      => 'X-Publishable-API-Key: x',
				'account_endpoint' => 'My Licenses',
				'unknown'          => 'dropped',
			)
		);

		$this->assertSame( 'BLTFT', $clean['key_prefix'] );
		$this->assertSame( '{X4}-{X4}-{X4}-{X4}', $clean['key_template'] );
		$this->assertSame( 'standard', $clean['response_profile'] );
		$this->assertSame( 5, $clean['grace_days'] );
		$this->assertSame( 'x-publishable-api-keyx', $clean['auth_header'] );
		$this->assertSame( 'my-licenses', $clean['account_endpoint'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
	}

	public function test_page_is_registered_for_admins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::add_page();
		$this->assertStringContainsString( 'options-general.php?page=' . Settings::PAGE, menu_page_url( Settings::PAGE, false ) );

		Settings::register_setting();
		ob_start();
		Settings::render_page();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="beltoft_licenses_settings[response_profile]"', $html );
		$this->assertStringContainsString( 'name="beltoft_licenses_settings[grace_days]"', $html );
	}
}
