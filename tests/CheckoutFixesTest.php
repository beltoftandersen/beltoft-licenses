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

	// Task 4.

	/**
	 * An old subscription with an expired license, and the new one that resubscribes it.
	 *
	 * @return array{0: FakeSubscription, 1: FakeSubscription, 2: array<string, mixed>}
	 */
	private function resubscribe_pair( string $old_status = 'expired' ): array {
		$product = $this->licensed_product();
		$old     = new FakeSubscription( 500 );
		$item    = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$old->items[] = $item;
		Subscriptions::activated( $old );
		$license = Licenses::where( 'subscription_id', 500 )[0];
		Licenses::activate( $license, 'keep.example' );
		Licenses::update( (int) $license['id'], array( 'status' => $old_status, 'expires_at' => time() - DAY_IN_SECONDS ) );

		add_filter(
			'beltoft_licenses_resubscribed_subscription',
			static fn( $found, $id ) => 500 === $id ? $old : $found,
			10,
			2
		);

		$new       = new FakeSubscription( 501 );
		$new->meta = array( '_subscription_resubscribe' => 500 );
		$new_item  = new \WC_Order_Item_Product();
		$new_item->set_product( $product );
		$new_item->set_quantity( 1 );
		$new->items[] = $new_item;

		return array( $old, $new, $license );
	}

	public function test_resubscribe_takes_back_the_expired_key_and_its_sites(): void {
		list( $old, $new, $license ) = $this->resubscribe_pair();

		Subscriptions::activated( $new );

		$this->assertSame( array(), Licenses::where( 'subscription_id', 500 ) );
		$moved = Licenses::where( 'subscription_id', 501 );
		$this->assertCount( 1, $moved, 'no new key' );
		$this->assertSame( $license['license_key'], $moved[0]['license_key'] );
		$this->assertSame( 'active', $moved[0]['status'] );
		$this->assertNull( $moved[0]['expires_at'] );
		$this->assertSame( 1501, $moved[0]['order_id'] );
		$this->assertCount( 1, Licenses::live_activations( $moved[0]['id'] ), 'the live site stays attached' );
		$this->assertNotEmpty( array_filter( $old->notes, static fn( $n ) => false !== strpos( $n, 'moved to subscription #501' ) ) );
		$this->assertNotEmpty( array_filter( $new->notes, static fn( $n ) => false !== strpos( $n, 'taken over from subscription #500' ) ) );
	}

	public function test_resubscribe_does_not_reuse_a_revoked_license(): void {
		list( , $new, $license ) = $this->resubscribe_pair( 'revoked' );

		Subscriptions::activated( $new );

		$issued = Licenses::where( 'subscription_id', 501 );
		$this->assertCount( 1, $issued );
		$this->assertNotSame( $license['license_key'], $issued[0]['license_key'] );
		$this->assertSame( 'revoked', Licenses::find( (int) $license['id'] )['status'] );
	}

	public function test_resubscribe_key_reuse_can_be_turned_off(): void {
		$this->settings( array( 'resubscribe_keeps_key' => 0 ) );
		list( , $new, $license ) = $this->resubscribe_pair();

		Subscriptions::activated( $new );

		$issued = Licenses::where( 'subscription_id', 501 );
		$this->assertNotSame( $license['license_key'], $issued[0]['license_key'] );
		$this->assertCount( 1, Licenses::where( 'subscription_id', 500 ) );
	}

	// Task 6.

	private function ending_mail( callable $run ): array {
		$this->fresh_mailer();
		$sent = $this->capture_mail( $run );

		return array_values( array_filter( $sent, static fn( $m ) => false !== strpos( $m['subject'], 'license has' ) ) );
	}

	public function test_revoking_emails_the_customer_once_with_a_masked_key(): void {
		$license = $this->license( array( 'email' => 'cust@example.org', 'product_slug' => 'my-plugin' ) );

		$sent = $this->ending_mail(
			static function () use ( $license ) {
				Licenses::update( $license['id'], array( 'status' => 'revoked' ) );
				Licenses::update( $license['id'], array( 'expires_at' => time() ) );
				Licenses::update( $license['id'], array( 'status' => 'revoked' ) );
			}
		);

		$this->assertCount( 1, $sent );
		$this->assertSame( 'cust@example.org', is_array( $sent[0]['to'] ) ? $sent[0]['to'][0] : $sent[0]['to'] );
		$this->assertStringContainsString( 'revoked', $sent[0]['subject'] );
		$this->assertStringContainsString( substr( $license['license_key'], -4 ), $sent[0]['message'] );
		$this->assertStringNotContainsString( $license['license_key'], $sent[0]['message'], 'the key is masked' );
	}

	public function test_expiry_emails_once_whether_by_date_or_by_status(): void {
		$license = $this->license( array( 'email' => 'cust@example.org' ) );

		$sent = $this->ending_mail(
			static function () use ( $license ) {
				// An expiry already past is an expired license at once; the daily job then marks it.
				Licenses::update( $license['id'], array( 'expires_at' => time() - 60 ) );
				\BeltoftLicenses\Lifecycle\Expiry::run();
			}
		);

		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'expired', $sent[0]['subject'] );
	}

	public function test_a_license_that_ends_again_after_coming_back_is_told_again(): void {
		$license = $this->license( array( 'email' => 'cust@example.org' ) );

		$sent = $this->ending_mail(
			static function () use ( $license ) {
				Licenses::update( $license['id'], array( 'expires_at' => time() - 60 ) );
				Licenses::update( $license['id'], array( 'expires_at' => null ) );
				Licenses::update( $license['id'], array( 'expires_at' => time() - 30 ) );
			}
		);

		$this->assertCount( 2, $sent );
	}

	public function test_no_email_for_suspended_or_during_an_import(): void {
		$one = $this->license( array( 'email' => 'cust@example.org' ) );
		$two = $this->license( array( 'email' => 'cust@example.org' ) );

		$sent = $this->ending_mail(
			function () use ( $one, $two ) {
				Licenses::update( $one['id'], array( 'status' => 'suspended' ) );

				$running = new \ReflectionProperty( \BeltoftLicenses\Import::class, 'running' );
				$running->setAccessible( true );
				$running->setValue( null, true );
				Licenses::update( $two['id'], array( 'status' => 'revoked' ) );
				$running->setValue( null, false );
			}
		);

		$this->assertSame( array(), $sent );
	}

	public function test_emails_can_be_switched_off_and_have_templates(): void {
		update_option( 'woocommerce_beltoft_licenses_revoked_settings', array( 'enabled' => 'no' ) );
		$license = $this->license( array( 'email' => 'cust@example.org' ) );

		$sent = $this->ending_mail(
			static function () use ( $license ) {
				Licenses::update( $license['id'], array( 'status' => 'revoked' ) );
			}
		);
		$this->assertSame( array(), $sent );

		foreach ( array( 'revoked', 'expired' ) as $name ) {
			$this->assertFileExists( BELTOFT_LICENSES_PATH . "templates/emails/license-$name.php" );
			$this->assertFileExists( BELTOFT_LICENSES_PATH . "templates/emails/plain/license-$name.php" );
		}
		$emails = WC()->mailer()->get_emails();
		$this->assertArrayHasKey( 'Beltoft_Licenses_Email_Expired', $emails );
		$this->assertStringContainsString( 'expired', $emails['Beltoft_Licenses_Email_Expired']->get_default_heading() );
	}
}
