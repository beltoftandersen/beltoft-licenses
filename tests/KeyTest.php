<?php
/**
 * Keys and domains.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Key;

class KeyTest extends TestCase {

	public function test_default_format_is_four_hex_groups(): void {
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', Key::generate() );
	}

	public function test_prefix_and_template_come_from_settings(): void {
		$this->settings( array( 'key_prefix' => 'BLTFT' ) );
		$this->assertMatchesRegularExpression( '/^BLTFT-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', Key::generate() );

		$this->settings( array( 'key_prefix' => '', 'key_template' => 'K{X6}/{X3}' ) );
		$this->assertMatchesRegularExpression( '/^K[0-9A-F]{6}\/[0-9A-F]{3}$/', Key::generate() );
	}

	public function test_keys_are_random(): void {
		$keys = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$keys[] = Key::generate();
		}
		$this->assertCount( 200, array_unique( $keys ) );
	}

	public function test_normalize_trims_and_uppercases(): void {
		$this->assertSame( 'BLTFT-AB12-CD34-EF56-0000', Key::normalize( "  bltft-ab12-cd34-ef56-0000 \n" ) );
		$this->assertSame( '', Key::normalize( array( 'x' ) ) );
	}

	/**
	 * @dataProvider domains
	 */
	public function test_domain_normalisation( string $in, string $out ): void {
		$this->assertSame( $out, Key::domain( $in ) );
	}

	public function domains(): array {
		return array(
			array( 'https://www.Example.COM/', 'example.com' ),
			array( 'http://example.com', 'example.com' ),
			array( 'example.com:8080/shop/', 'example.com:8080/shop' ),
			array( 'www.example.com//', 'example.com/' ),
			array( 'sub.www.example.com', 'sub.www.example.com' ),
		);
	}

	public function test_domain_options_can_be_turned_off(): void {
		$this->settings( array( 'domain_strip_www' => 0, 'domain_strip_slash' => 0 ) );
		$this->assertSame( 'www.example.com/', Key::domain( 'https://www.example.com/' ) );
	}
}
