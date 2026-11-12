<?php
/**
 * A license is reused, and its key shown, only for the customer it belongs to.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Account\OrderLicenses;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Orders;
use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class EmailFallbackTest extends TestCase {

	private function free_product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'free-plugin' );
		$product->set_regular_price( '0' );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, 'free-plugin' );
		$product->update_meta_data( Product::META_FREE, 'yes' );
		$product->save();

		return $product;
	}

	private function order( \WC_Product $product, int $customer, string $email ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_email( $email );
		$order->set_customer_id( $customer );
		$order->calculate_totals();
		$order->save();
		$order->update_status( 'completed' );

		return wc_get_order( $order->get_id() );
	}

	private function page( \WC_Order $order ): string {
		ob_start();
		OrderLicenses::html( $order );
		return (string) ob_get_clean();
	}

	private function mail( \WC_Order $order ): string {
		ob_start();
		OrderLicenses::email( $order, false, true );
		return (string) ob_get_clean();
	}

	private function victim( ?int $user = null ): array {
		return $this->license(
			array(
				'product_slug' => 'free-plugin',
				'user_id'      => $user,
				'email'        => 'victim@example.org',
				'source'       => 'free',
			)
		);
	}

	public function test_signed_in_buyer_with_a_victims_email_gets_their_own_license(): void {
		$victim_user = self::factory()->user->create( array( 'user_email' => 'victim@example.org' ) );
		$attacker    = self::factory()->user->create( array( 'user_email' => 'attacker@example.org' ) );
		$theirs      = $this->victim( $victim_user );

		$order = $this->order( $this->free_product(), $attacker, 'victim@example.org' );
		$own   = Licenses::where( 'order_id', $order->get_id() );

		$this->assertCount( 1, $own );
		$this->assertNotSame( $theirs['id'], $own[0]['id'] );
		$this->assertSame( $attacker, $own[0]['user_id'] );
		$this->assertStringNotContainsString( $theirs['license_key'], $this->page( $order ) );
		$this->assertStringNotContainsString( $theirs['license_key'], $this->mail( $order ) );
		$this->assertStringContainsString( $own[0]['license_key'], $this->page( $order ) );
	}

	public function test_guest_order_with_a_victims_email_never_gets_a_linked_license(): void {
		$victim_user = self::factory()->user->create( array( 'user_email' => 'victim@example.org' ) );
		$theirs      = $this->victim( $victim_user );

		$order = $this->order( $this->free_product(), 0, 'victim@example.org' );

		$this->assertCount( 1, Licenses::where( 'order_id', $order->get_id() ) );
		$this->assertStringNotContainsString( $theirs['license_key'], $this->page( $order ) );
		$this->assertStringNotContainsString( $theirs['license_key'], $this->mail( $order ) );
	}

	public function test_guest_order_with_a_victims_guest_email_does_not_reuse_for_a_signed_in_buyer(): void {
		$attacker = self::factory()->user->create( array( 'user_email' => 'attacker@example.org' ) );
		$guest    = $this->victim();

		$order = $this->order( $this->free_product(), $attacker, 'victim@example.org' );

		$this->assertCount( 1, Licenses::where( 'order_id', $order->get_id() ) );
		$this->assertStringNotContainsString( $guest['license_key'], $this->page( $order ) );
	}

	public function test_the_free_plan_is_still_claimed_once_per_email_for_guests(): void {
		$victim_user = self::factory()->user->create( array( 'user_email' => 'victim@example.org' ) );
		$this->victim( $victim_user );
		$product = $this->free_product();

		$first  = $this->order( $product, 0, 'victim@example.org' );
		$second = $this->order( $product, 0, 'victim@example.org' );

		$this->assertCount( 1, Licenses::where( 'order_id', $first->get_id() ) );
		$this->assertSame( array(), Licenses::where( 'order_id', $second->get_id() ), 'the guest reuses the license the first guest order made' );
		$this->assertCount( 2, Licenses::where( 'email', 'victim@example.org' ), 'the victim\'s own plus one guest license, no more' );
	}

	public function test_signed_in_customer_reorders_and_keeps_their_license(): void {
		$user    = self::factory()->user->create( array( 'user_email' => 'me@example.org' ) );
		$product = $this->free_product();

		$first  = $this->order( $product, $user, 'me@example.org' );
		$second = $this->order( $product, $user, 'other@example.org' );
		$own    = Licenses::where( 'order_id', $first->get_id() )[0];

		$this->assertSame( array(), Licenses::where( 'order_id', $second->get_id() ) );
		$this->assertStringContainsString( $own['license_key'], $this->page( $second ) );
		$this->assertCount( 1, Licenses::where( 'user_id', $user ) );
	}

	public function test_guest_reorders_with_the_same_email_and_keeps_their_license(): void {
		$product = $this->free_product();

		$first  = $this->order( $product, 0, 'Guest@Example.org' );
		$second = $this->order( $product, 0, 'guest@example.org' );
		$own    = Licenses::where( 'order_id', $first->get_id() )[0];

		$this->assertSame( array(), Licenses::where( 'order_id', $second->get_id() ) );
		$this->assertStringContainsString( $own['license_key'], $this->page( $second ) );
		$this->assertStringContainsString( $own['license_key'], $this->mail( $second ) );
	}

	public function test_an_order_already_pointing_at_a_foreign_license_prints_no_key(): void {
		$victim_user = self::factory()->user->create( array( 'user_email' => 'victim@example.org' ) );
		$attacker    = self::factory()->user->create( array( 'user_email' => 'attacker@example.org' ) );
		$linked      = $this->victim( $victim_user );
		$guest       = $this->license( array( 'product_slug' => 'free-plugin', 'email' => 'someone@example.org' ) );
		$product     = $this->free_product();

		foreach ( array( array( $attacker, 'attacker@example.org' ), array( 0, 'victim@example.org' ), array( 0, 'x@example.org' ) ) as $case ) {
			$order = wc_create_order();
			$order->add_product( $product, 1 );
			$order->set_customer_id( $case[0] );
			$order->set_billing_email( $case[1] );
			$order->save();
			foreach ( $order->get_items() as $item ) {
				$item->update_meta_data( Orders::ITEM_META, array( $linked['id'], $guest['id'] ) );
				$item->save();
			}
			$order = wc_get_order( $order->get_id() );
			$order->set_status( 'processing' );
			$order->save();

			foreach ( array( $this->page( $order ), $this->mail( $order ) ) as $out ) {
				$this->assertStringNotContainsString( $linked['license_key'], $out );
				$this->assertStringNotContainsString( $guest['license_key'], $out );
			}
		}
	}

	public function test_a_guest_order_may_show_a_guest_license_with_the_same_email_only(): void {
		$guest = $this->license( array( 'product_slug' => 'free-plugin', 'email' => 'guest@example.org' ) );
		$order = wc_create_order();
		$order->add_product( $this->free_product(), 1 );
		$order->set_billing_email( 'GUEST@example.org' );
		$order->save();
		foreach ( $order->get_items() as $item ) {
			$item->update_meta_data( Orders::ITEM_META, array( $guest['id'] ) );
			$item->save();
		}

		$this->assertStringContainsString( $guest['license_key'], $this->page( wc_get_order( $order->get_id() ) ) );
	}
}
