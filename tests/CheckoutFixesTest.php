<?php
/**
 * The 0.7.1 checkout fixes: keys in the first email, refunds, resubscribe, reactivation, emails.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Subscriptions;
use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class CheckoutFixesTest extends TestCase {

	/**
	 * Subscriptions the order "has", by order type.
	 *
	 * @var array<string, array<int, object>>
	 */
	protected $linked = array();

	public function set_up(): void {
		parent::set_up();
		$this->linked = array();
		// Products with the meta below count as subscription products.
		add_filter(
			'beltoft_licenses_is_subscription_product',
			static function ( $is, $product ) {
				return $is || 'yes' === $product->get_meta( '_test_subscription' );
			},
			10,
			2
		);
		add_filter(
			'beltoft_licenses_order_subscriptions',
			function ( $subs, $order, $types ) {
				$found = array();
				foreach ( $types as $type ) {
					foreach ( $this->linked[ $order->get_id() ][ $type ] ?? array() as $sub ) {
						$found[ $sub->get_id() ] = $sub;
					}
				}
				return array_values( $found );
			},
			10,
			3
		);
	}

	protected function licensed_product( string $slug = 'my-plugin', array $meta = array() ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $slug );
		$product->set_regular_price( '10' );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, $slug );
		$product->update_meta_data( '_test_subscription', 'yes' );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();

		return $product;
	}

	protected function order( \WC_Product $product, int $qty = 1, string $email = 'buyer@example.org' ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $product, $qty );
		$order->set_billing_email( $email );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * A fake subscription for the product, linked to the order as the given type.
	 */
	protected function subscription_for( \WC_Order $order, \WC_Product $product, int $qty = 1, string $type = 'parent' ): FakeSubscription {
		$sub            = new FakeSubscription( wp_rand( 1, 999999 ) );
		$sub->parent_id = $order->get_id();
		$sub->email     = $order->get_billing_email();
		$item           = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( $qty );
		$sub->items[] = $item;
		$this->linked[ $order->get_id() ][ $type ][] = $sub;

		return $sub;
	}

	/**
	 * Messages of the emails sent while the callback runs.
	 *
	 * @return array<int, array{to: mixed, subject: string, message: string}>
	 */
	protected function capture_mail( callable $run ): array {
		$sent   = array();
		$filter = static function ( $short_circuit, $atts ) use ( &$sent ) {
			$sent[] = array(
				'to'      => $atts['to'],
				'subject' => (string) $atts['subject'],
				'message' => (string) $atts['message'],
			);
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		$run();
		remove_filter( 'pre_wp_mail', $filter, 10 );

		return $sent;
	}

	/**
	 * The test case restores hooks after each test but WooCommerce keeps its mailer singleton,
	 * so build a new one to get the email hooks back.
	 */
	protected function fresh_mailer(): void {
		$instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		WC()->mailer();
	}


	// Task 1.

	public function test_subscription_licenses_are_issued_when_the_order_is_paid(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );

		$order->update_status( 'processing' );
		$this->assertCount( 1, Licenses::where( 'subscription_id', $sub->get_id() ) );

		// Activation, payment_complete and a later completed status find them already there.
		Subscriptions::activated( $sub );
		do_action( 'woocommerce_payment_complete', $order->get_id() );
		$order->update_status( 'completed' );
		$this->assertCount( 1, Licenses::where( 'subscription_id', $sub->get_id() ) );
		$this->assertCount( 1, $sub->notes );
	}

	public function test_first_processing_email_contains_the_key(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );

		$this->fresh_mailer();
		$sent = $this->capture_mail(
			static function () use ( $order ) {
				$order->update_status( 'processing' );
			}
		);

		$key = Licenses::where( 'subscription_id', $sub->get_id() )[0]['license_key'];
		$to_customer = array_values( array_filter( $sent, static fn( $m ) => 'buyer@example.org' === ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] ) ) );
		$this->assertNotEmpty( $to_customer, 'the customer processing email was sent' );
		$this->assertStringContainsString( $key, $to_customer[0]['message'] );
	}

	public function test_renewal_orders_issue_nothing(): void {
		$product = $this->licensed_product();
		$parent  = $this->order( $product );
		$sub     = $this->subscription_for( $parent, $product );
		$parent->update_status( 'processing' );

		// A renewal order is a different order: it is not the parent of any subscription.
		$renewal = $this->order( $product );
		$this->linked[ $renewal->get_id() ]['renewal'][] = $sub;
		$renewal->update_status( 'processing' );
		$renewal->update_status( 'completed' );

		$this->assertCount( 1, Licenses::where( 'subscription_id', $sub->get_id() ) );
		$this->assertSame( array(), Licenses::where( 'order_id', $renewal->get_id() ) );
	}

	// Task 2.

	private function refund_fully( \WC_Order $order ): void {
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total() ) );
	}

	public function test_full_refund_cancels_the_parent_subscription(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );
		$order->update_status( 'processing' );

		$this->refund_fully( $order );

		$this->assertSame( 'cancelled', $sub->status );
		$this->assertContains( 'Cancelled: the order was fully refunded.', $sub->notes );
		$this->assertSame( 'revoked', Licenses::where( 'subscription_id', $sub->get_id() )[0]['status'] );
	}

	public function test_full_refund_of_a_renewal_cancels_its_subscription(): void {
		$product = $this->licensed_product();
		$parent  = $this->order( $product );
		$sub     = $this->subscription_for( $parent, $product );
		$parent->update_status( 'processing' );

		$renewal = $this->order( $product );
		$this->linked[ $renewal->get_id() ]['renewal'][] = $sub;
		$renewal->update_status( 'processing' );
		$this->refund_fully( $renewal );

		$this->assertSame( 'cancelled', $sub->status );
	}

	public function test_refund_cancel_can_be_turned_off(): void {
		$this->settings( array( 'cancel_subscription_on_refund' => 0 ) );
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );
		$order->update_status( 'processing' );
		$this->refund_fully( $order );

		$this->assertSame( 'active', $sub->status );
	}

	public function test_refund_leaves_ended_subscriptions_alone(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );
		$sub->status = 'expired';
		$order->update_status( 'processing' );
		$this->refund_fully( $order );

		$this->assertSame( 'expired', $sub->status );
		$this->assertNotContains( 'Cancelled: the order was fully refunded.', $sub->notes );
	}

	public function test_partial_refund_does_not_cancel(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product );
		$sub     = $this->subscription_for( $order, $product );
		$order->update_status( 'processing' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 1 ) );

		$this->assertSame( 'active', $sub->status );
	}

	// Task 3.

	/**
	 * Refunds units of the order's first line, with the money for them.
	 */
	private function refund_units( \WC_Order $order, int $units ): void {
		$item = array_values( $order->get_items() )[0];
		wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => 10 * $units,
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => $units,
						'refund_total' => 10 * $units,
					),
				),
			)
		);
	}

	private function statuses( array $licenses ): array {
		return array_map( static fn( $l ) => Licenses::find( (int) $l['id'] )['status'], $licenses );
	}

	public function test_refunding_one_of_two_units_revokes_one_license(): void {
		$order = $this->order( $this->licensed_product( 'one-off', array( '_test_subscription' => 'no' ) ), 2 );
		$order->update_status( 'processing' );
		$licenses = Licenses::where( 'order_id', $order->get_id() );
		$this->assertCount( 2, $licenses );

		$this->refund_units( $order, 1 );
		$statuses = $this->statuses( $licenses );
		sort( $statuses );
		$this->assertSame( array( 'active', 'revoked' ), $statuses );
		$this->assertSame( 'revoked', Licenses::find( max( array_column( $licenses, 'id' ) ) )['status'], 'newest first' );

		// The rest of the line later: the last one goes, the revoked one is not counted twice.
		$this->refund_units( $order, 1 );
		$this->assertSame( array( 'revoked', 'revoked' ), $this->statuses( $licenses ) );
	}

	public function test_amount_only_refund_revokes_nothing(): void {
		$order = $this->order( $this->licensed_product( 'one-off', array( '_test_subscription' => 'no' ) ), 2 );
		$order->update_status( 'processing' );
		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 5 ) );

		$this->assertSame( array( 'active', 'active' ), $this->statuses( Licenses::where( 'order_id', $order->get_id() ) ) );
	}

	public function test_line_refund_revoke_can_be_turned_off(): void {
		$this->settings( array( 'revoke_on_refund' => 0 ) );
		$order = $this->order( $this->licensed_product( 'one-off', array( '_test_subscription' => 'no' ) ), 2 );
		$order->update_status( 'processing' );
		$this->refund_units( $order, 1 );

		$this->assertSame( array( 'active', 'active' ), $this->statuses( Licenses::where( 'order_id', $order->get_id() ) ) );
	}

	public function test_refunding_a_subscription_line_revokes_that_subscriptions_licenses(): void {
		$product = $this->licensed_product();
		$order   = $this->order( $product, 2 );
		$sub     = $this->subscription_for( $order, $product, 2 );
		$order->update_status( 'processing' );
		$licenses = Licenses::where( 'subscription_id', $sub->get_id() );
		$this->assertCount( 2, $licenses );

		$this->refund_units( $order, 1 );
		$statuses = $this->statuses( $licenses );
		sort( $statuses );
		$this->assertSame( array( 'active', 'revoked' ), $statuses );
	}
}
