<?php
/**
 * Licenses following a subscription. Uses a stand-in with WC_Subscription's methods, so the
 * suite does not need WooCommerce Subscriptions installed.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Subscriptions;
use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class FakeSubscription {
	public $id;
	public $items = array();
	public $end   = 0;
	public $notes = array();
	public function __construct( int $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_items() { return $this->items; }
	public function get_customer_id() { return 7; }
	public function get_billing_email() { return 'sub@example.org'; }
	public function get_parent_id() { return 1000 + $this->id; }
	public function get_time( $type ) { return 'end' === $type ? $this->end : 0; }
	public function add_order_note( $note ) { $this->notes[] = $note; }
}

class LifecycleSubscriptionsTest extends TestCase {

	private function product_with_limit( int $max, string $slug = 'sub-plugin' ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $slug );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, $slug );
		$product->update_meta_data( Product::META_MAX, (string) $max );
		$product->save();

		return $product;
	}

	private function subscription( \WC_Product $product, int $qty = 1 ): FakeSubscription {
		$sub  = new FakeSubscription( wp_rand( 1, 999999 ) );
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$sub->items[] = $item;

		return $sub;
	}

	private function licenses( FakeSubscription $sub ): array {
		return Licenses::where( 'subscription_id', $sub->get_id() );
	}

	public function test_activation_issues_licenses_once(): void {
		$sub = $this->subscription( $this->product_with_limit( 2 ), 2 );
		Subscriptions::activated( $sub );
		Subscriptions::activated( $sub );
		Subscriptions::renewed( $sub );

		$licenses = $this->licenses( $sub );
		$this->assertCount( 2, $licenses );
		$this->assertSame( 2, $licenses[0]['max_activations'] );
		$this->assertSame( 7, $licenses[0]['user_id'] );
		$this->assertSame( 1000 + $sub->get_id(), $licenses[0]['order_id'] );
		$this->assertNull( $licenses[0]['expires_at'] );
		$this->assertCount( 2, $sub->notes );
	}

	public function test_on_hold_starts_the_grace_period(): void {
		$sub = $this->subscription( $this->product_with_limit( 1 ) );
		Subscriptions::activated( $sub );
		Subscriptions::on_hold( $sub );

		$expires = strtotime( $this->licenses( $sub )[0]['expires_at'] . ' UTC' );
		$this->assertEqualsWithDelta( time() + 3 * DAY_IN_SECONDS, $expires, 5, 'default grace is 3 days' );
		$this->assertSame( 'active', Licenses::effective_status( $this->licenses( $sub )[0] ) );
	}

	public function test_grace_period_is_a_setting(): void {
		$this->settings( array( 'grace_days' => 0 ) );
		$sub = $this->subscription( $this->product_with_limit( 1 ) );
		Subscriptions::activated( $sub );
		Subscriptions::on_hold( $sub );
		$this->assertEqualsWithDelta( time(), strtotime( $this->licenses( $sub )[0]['expires_at'] . ' UTC' ), 5 );
	}

	public function test_back_to_active_clears_expiry_and_revives_expired(): void {
		$sub = $this->subscription( $this->product_with_limit( 1 ) );
		Subscriptions::activated( $sub );
		$id = $this->licenses( $sub )[0]['id'];
		Licenses::update( $id, array( 'status' => 'expired', 'expires_at' => time() - 60 ) );

		Subscriptions::renewed( $sub );
		$license = Licenses::find( $id );
		$this->assertSame( 'active', $license['status'] );
		$this->assertNull( $license['expires_at'] );
	}

	public function test_revoked_and_suspended_stay_as_set(): void {
		$sub = $this->subscription( $this->product_with_limit( 1 ) );
		Subscriptions::activated( $sub );
		$id = $this->licenses( $sub )[0]['id'];
		Licenses::update( $id, array( 'status' => 'suspended' ) );

		Subscriptions::renewed( $sub );
		$this->assertSame( 'suspended', Licenses::find( $id )['status'] );
	}

	public function test_pending_cancel_runs_to_end_of_paid_period(): void {
		$sub      = $this->subscription( $this->product_with_limit( 1 ) );
		$sub->end = time() + 20 * DAY_IN_SECONDS;
		Subscriptions::activated( $sub );
		Subscriptions::pending_cancel( $sub );

		$this->assertSame( gmdate( 'Y-m-d H:i:s', $sub->end ), $this->licenses( $sub )[0]['expires_at'] );
	}

	public function test_cancelled_or_expired_ends_now(): void {
		$sub = $this->subscription( $this->product_with_limit( 1 ) );
		Subscriptions::activated( $sub );
		Subscriptions::ended( $sub );

		$license = $this->licenses( $sub )[0];
		$this->assertSame( 'expired', Licenses::effective_status( array_merge( $license, array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ) ) );
		$this->assertEqualsWithDelta( time(), strtotime( $license['expires_at'] . ' UTC' ), 5 );
	}

	public function test_tier_switch_changes_limit_and_keeps_key(): void {
		$sub = $this->subscription( $this->product_with_limit( 1, 'tier-plugin' ) );
		Subscriptions::activated( $sub );
		$before = $this->licenses( $sub )[0];
		Licenses::activate( $before, 'one.example' );

		$sub->items = $this->subscription( $this->product_with_limit( 3, 'tier-plugin' ) )->items;
		Subscriptions::sync_limits( $sub );
		$after = Licenses::find( $before['id'] );

		$this->assertSame( $before['license_key'], $after['license_key'] );
		$this->assertSame( 3, $after['max_activations'] );

		// Downgrade below the live sites: the sites stay, new ones are refused.
		Licenses::activate( $after, 'two.example' );
		$sub->items = $this->subscription( $this->product_with_limit( 1, 'tier-plugin' ) )->items;
		Subscriptions::sync_limits( $sub );
		$down = Licenses::find( $before['id'] );
		$this->assertCount( 2, Licenses::live_activations( $down['id'] ) );
		$this->assertSame( 'activation_limit', Licenses::activate( $down, 'three.example' )['code'] );
	}

	public function test_subscription_products_are_not_issued_by_the_order(): void {
		$this->assertFalse( Product::is_subscription( $this->product_with_limit( 1 ) ), 'without WooCommerce Subscriptions nothing is a subscription product' );
	}
}
