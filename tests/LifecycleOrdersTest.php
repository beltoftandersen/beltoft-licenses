<?php
/**
 * Licenses from orders.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Orders;
use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class LifecycleOrdersTest extends TestCase {

	private function licensed_product( array $meta = array(), string $slug = 'my-plugin' ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $slug );
		$product->set_regular_price( '10' );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, $slug );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();

		return $product;
	}

	private function order( \WC_Product $product, int $qty = 1, int $customer = 0, string $email = 'buyer@example.org' ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $product, $qty );
		$order->set_billing_email( $email );
		$order->set_customer_id( $customer );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	public function test_paid_order_issues_one_license_per_unit(): void {
		$product = $this->licensed_product( array( Product::META_MAX => '3' ) );
		$order   = $this->order( $product, 2 );
		$this->assertSame( array(), Licenses::where( 'order_id', $order->get_id() ), 'nothing before payment' );

		$order->update_status( 'processing' );
		$licenses = Licenses::where( 'order_id', $order->get_id() );

		$this->assertCount( 2, $licenses );
		$this->assertSame( 3, $licenses[0]['max_activations'] );
		$this->assertSame( 'my-plugin', $licenses[0]['product_slug'] );
		$this->assertSame( 'buyer@example.org', $licenses[0]['email'] );
		$this->assertSame( 'order', $licenses[0]['source'] );
	}

	public function test_issuing_is_idempotent(): void {
		$order = $this->order( $this->licensed_product() );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		Orders::issue( wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, Licenses::where( 'order_id', $order->get_id() ) );
	}

	public function test_unlicensed_products_get_nothing(): void {
		$plain = new \WC_Product_Simple();
		$plain->set_name( 'T-shirt' );
		$plain->set_regular_price( '5' );
		$plain->save();

		$licensed_without_slug = $this->licensed_product( array( Software::META_SLUG => '' ) );

		foreach ( array( $plain, $licensed_without_slug ) as $product ) {
			$order = $this->order( $product );
			$order->update_status( 'completed' );
			$this->assertSame( array(), Licenses::where( 'order_id', $order->get_id() ) );
		}
	}

	public function test_variation_limit_overrides_product(): void {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Tiered' );
		$parent->update_meta_data( Software::META_LICENSED, 'yes' );
		$parent->update_meta_data( Software::META_SLUG, 'tiered-plugin' );
		$parent->update_meta_data( Product::META_MAX, '1' );
		$parent->save();

		$three = new \WC_Product_Variation();
		$three->set_parent_id( $parent->get_id() );
		$three->set_regular_price( '30' );
		$three->update_meta_data( Product::META_MAX, '3' );
		$three->save();

		$inherit = new \WC_Product_Variation();
		$inherit->set_parent_id( $parent->get_id() );
		$inherit->set_regular_price( '10' );
		$inherit->save();

		$this->assertSame( 3, Product::max_activations( wc_get_product( $three->get_id() ) ) );
		$this->assertSame( 1, Product::max_activations( wc_get_product( $inherit->get_id() ) ) );

		$order = $this->order( wc_get_product( $three->get_id() ) );
		$order->update_status( 'processing' );
		$license = Licenses::where( 'order_id', $order->get_id() )[0];
		$this->assertSame( 3, $license['max_activations'] );
		$this->assertSame( 'tiered-plugin', $license['product_slug'] );
		$this->assertSame( $three->get_id(), $license['variation_id'] );
	}

	public function test_free_plan_is_claimed_once_per_customer(): void {
		$free  = $this->licensed_product( array( Product::META_FREE => 'yes' ), 'free-plugin' );
		$first = $this->order( $free, 3, 0, 'free@example.org' );
		$first->update_status( 'completed' );
		$this->assertCount( 1, Licenses::where( 'order_id', $first->get_id() ), 'a free plan gives one license whatever the quantity' );

		$second = $this->order( $free, 1, 0, 'free@example.org' );
		$second->update_status( 'completed' );
		$second->update_status( 'processing' );

		$this->assertSame( array(), Licenses::where( 'order_id', $second->get_id() ) );
		$this->assertCount( 1, Licenses::where( 'email', 'free@example.org' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $second->get_id() ) );
		$this->assertCount( 1, array_filter( $notes, static fn( $n ) => false !== strpos( $n->content, 'already claimed' ) ), 'noted once, not on every status change' );
		$this->assertSame( 'free', Licenses::where( 'email', 'free@example.org' )[0]['source'] );
	}

	public function test_full_refund_revokes(): void {
		$order = $this->order( $this->licensed_product() );
		$order->update_status( 'completed' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total() ) );

		$license = Licenses::where( 'order_id', $order->get_id() )[0];
		$this->assertSame( 'revoked', $license['status'] );
		$this->assertNotNull( $license['expires_at'] );
	}

	public function test_refund_revoke_can_be_turned_off(): void {
		$this->settings( array( 'revoke_on_refund' => 0 ) );
		$order = $this->order( $this->licensed_product() );
		$order->update_status( 'completed' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total() ) );

		$this->assertSame( 'active', Licenses::where( 'order_id', $order->get_id() )[0]['status'] );
	}

	public function test_issued_key_works_with_the_api(): void {
		$this->software( 'my-plugin', '1.0.0' );
		$order = $this->order( $this->licensed_product() );
		$order->update_status( 'completed' );
		$key = Licenses::where( 'order_id', $order->get_id() )[0]['license_key'];

		$body = $this->body( $this->api( 'POST', 'activate', array( 'license_key' => $key, 'domain' => 'customer.example' ) ) );
		$this->assertTrue( $body['success'] );
	}
}
