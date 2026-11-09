<?php
/**
 * The translations loader is hooked.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

/**
 * Bundled translations are loaded on init, front end and admin.
 */
class TextdomainTest extends \WP_UnitTestCase {

	public function test_loader_is_hooked_on_init() {
		$this->assertSame( 0, has_action( 'init', 'beltoft_licenses_load_textdomain' ) );
	}
}
