<?php
/**
 * License block in WCS's customer notifications.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Account\SubscriptionEmails;
use BeltoftLicenses\Licenses;

class SubscriptionEmailsTest extends TestCase {

	/**
	 * A stand-in for the subscription: WC_Subscription extends WC_Order.
	 *
	 * @var \WC_Order
	 */
	private $subscription;

	public function set_up(): void {
		parent::set_up();
		$this->subscription = wc_create_order();
	}

	private function email( string $id ): object {
		return (object) array( 'id' => $id );
	}

	private function render( $email, bool $plain = false, bool $admin = false ): string {
		ob_start();
		SubscriptionEmails::render( $this->subscription, $admin, $plain, $email );
		return (string) ob_get_clean();
	}

	public function test_renewal_notice_shows_keys_and_sites(): void {
		$license = $this->license( array( 'subscription_id' => $this->subscription->get_id(), 'max_activations' => 3 ) );
		Licenses::activate( $license, 'shop.example' );
		$this->license( array( 'subscription_id' => $this->subscription->get_id(), 'status' => 'revoked', 'license_key' => 'REVOKED-KEY-1' ) );
		$this->license( array( 'subscription_id' => $this->subscription->get_id() + 1000, 'license_key' => 'OTHER-SUB-KEY' ) );

		$html = $this->render( $this->email( 'customer_notification_auto_renewal' ) );

		$this->assertStringContainsString( $license['license_key'], $html );
		$this->assertStringContainsString( '1 of 3 sites: shop.example', $html );
		$this->assertStringContainsString( 'Manage your licenses', $html );
		$this->assertStringNotContainsString( 'REVOKED-KEY-1', $html );
		$this->assertStringNotContainsString( 'OTHER-SUB-KEY', $html );
	}

	public function test_plain_text(): void {
		$license = $this->license( array( 'subscription_id' => $this->subscription->get_id() ) );

		$text = $this->render( $this->email( 'customer_notification_subscription_expiry' ), true );

		$this->assertStringContainsString( 'LICENSES', $text );
		$this->assertStringContainsString( $license['license_key'], $text );
		$this->assertStringContainsString( '0 of 2 sites', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function test_only_in_the_notification_emails_and_never_to_the_shop(): void {
		$this->license( array( 'subscription_id' => $this->subscription->get_id() ) );

		$this->assertSame( '', $this->render( $this->email( 'cancelled_subscription' ) ), 'other subscription emails' );
		$this->assertSame( '', $this->render( $this->email( 'customer_notification_manual_renewal' ), false, true ), 'admin copy' );
		$this->assertSame( '', $this->render( null ) );

		add_filter( 'beltoft_licenses_subscription_email_ids', static fn( $ids ) => array_merge( $ids, array( 'cancelled_subscription' ) ) );
		$this->assertNotSame( '', $this->render( $this->email( 'cancelled_subscription' ) ), 'the filter adds emails' );
	}

	public function test_nothing_without_licenses(): void {
		$this->assertSame( '', $this->render( $this->email( 'customer_notification_auto_renewal' ) ) );
	}
}
