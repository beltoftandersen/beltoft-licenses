<?php
/**
 * The License tab on products.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class ProductTest extends TestCase {

	public function test_save_reads_and_sanitises_the_tab(): void {
		$product = new \WC_Product_Simple();
		$product->save();

		$_POST = array(
			Software::META_LICENSED => 'yes',
			Software::META_SLUG     => 'My Plugin!',
			Software::META_VERSION  => ' 1.2.3 ',
			Product::META_MAX       => '-4',
		);
		Product::save( $product );
		$product->save();
		$_POST = array();

		$product = wc_get_product( $product->get_id() );
		$this->assertTrue( Product::is_licensed( $product ) );
		$this->assertSame( 'my-plugin', Product::slug( $product ) );
		$this->assertSame( '1.2.3', $product->get_meta( Software::META_VERSION ) );
		$this->assertSame( 4, Product::max_activations( $product ) );
		$this->assertFalse( Product::is_free_plan( $product ) );
	}

	public function test_empty_limit_falls_back_to_one_and_zero_is_unlimited(): void {
		$product = new \WC_Product_Simple();
		$this->assertSame( 1, Product::max_activations( $product ) );
		$product->update_meta_data( Product::META_MAX, '0' );
		$this->assertSame( 0, Product::max_activations( $product ) );
	}

	public function test_tab_is_added(): void {
		$this->assertArrayHasKey( 'beltoft_licenses', Product::tab( array() ) );
	}
}
