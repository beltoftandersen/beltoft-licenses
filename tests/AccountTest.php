<?php
/**
 * My Account → Licenses, order pages and the license history.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\OrderLicenses;
use BeltoftLicenses\Account\View;
use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;

class AccountTest extends TestCase {

	private int $user;

	public function set_up(): void {
		parent::set_up();
		$this->user = self::factory()->user->create( array( 'user_email' => 'me@example.org', 'role' => 'customer' ) );
		if ( function_exists( 'wc_clear_notices' ) && WC()->session ) {
			wc_clear_notices();
		}
		WC()->initialize_session();
	}

	public function test_own_licenses_include_guest_purchases_by_email(): void {
		$linked  = $this->license( array( 'user_id' => $this->user, 'email' => 'other@example.org' ) );
		$guest   = $this->license( array( 'email' => 'me@example.org' ) );
		$someone = $this->license( array( 'email' => 'me@example.org', 'user_id' => self::factory()->user->create() ) );
		$this->license( array( 'email' => 'stranger@example.org' ) );

		$ids = array_column( Endpoint::licenses_for_user( $this->user ), 'id' );

		$this->assertSame( array( $linked['id'], $guest['id'] ), $ids );
		$this->assertNotContains( $someone['id'], $ids, 'a license linked to another account stays theirs' );
	}

	public function test_page_shows_key_sites_and_free_button(): void {
		$this->software( 'my-plugin', '2.1.0' );
		$l = $this->license( array( 'user_id' => $this->user, 'max_activations' => 3 ) );
		Licenses::activate( $l, 'shop.example' );
		wp_set_current_user( $this->user );

		ob_start();
		Endpoint::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $l['license_key'], $html );
		$this->assertStringContainsString( '1 of 3 sites', $html );
		$this->assertStringContainsString( 'shop.example', $html );
		$this->assertStringContainsString( 'Free this site', $html );
		$this->assertStringContainsString( 'Download 2.1.0', $html );
		$this->assertStringContainsString( 'Lifetime', $html );
	}

	public function test_feedback_is_shown_even_when_the_theme_prints_no_notices(): void {
		$l = $this->license( array( 'user_id' => $this->user ) );
		Licenses::activate( $l, 'a.example' );
		Endpoint::free_site( $l['id'], 'a.example', $this->user );
		wp_set_current_user( $this->user );

		ob_start();
		Endpoint::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'a.example was removed from the license', $html );
		$this->assertSame( 0, wc_notice_count(), 'printed once, then cleared' );
	}

	public function test_page_offers_an_extension_point_per_license(): void {
		$this->license( array( 'user_id' => $this->user ) );
		wp_set_current_user( $this->user );
		add_action( 'beltoft_licenses_account_license', static function ( $license ) {
			echo '<div class="service-panel">service for ' . (int) $license['id'] . '</div>';
		} );

		ob_start();
		Endpoint::render();
		$this->assertStringContainsString( 'service-panel', (string) ob_get_clean() );
	}

	public function test_free_site_only_for_the_owner(): void {
		$mine   = $this->license( array( 'user_id' => $this->user ) );
		$theirs = $this->license( array( 'user_id' => self::factory()->user->create() ) );
		Licenses::activate( $mine, 'a.example' );
		Licenses::activate( $theirs, 'b.example' );

		$this->assertFalse( Endpoint::free_site( $theirs['id'], 'b.example', $this->user ) );
		$this->assertCount( 1, Licenses::live_activations( $theirs['id'] ) );

		$this->assertTrue( Endpoint::free_site( $mine['id'], 'a.example', $this->user ) );
		$this->assertCount( 0, Licenses::live_activations( $mine['id'] ) );
	}

	public function test_download_needs_an_active_license_and_a_release(): void {
		$this->software( 'my-plugin', '1.0.0' );
		$active  = View::license( $this->license( array( 'user_id' => $this->user ) ) );
		$expired = View::license( $this->license( array( 'user_id' => $this->user, 'expires_at' => time() - 60 ) ) );
		$no_rel  = View::license( $this->license( array( 'user_id' => $this->user, 'product_slug' => 'not-released' ) ) );

		$this->assertTrue( $active['can_download'] );
		$this->assertFalse( $expired['can_download'] );
		$this->assertFalse( $no_rel['can_download'] );

		$this->settings( array( 'download_requires_domain' => 1 ) );
		$this->assertFalse( View::license( $active['license'] )['can_download'], 'setting: needs a live site' );
	}

	public function test_download_delivers_only_to_the_owner(): void {
		$this->software( 'my-plugin', '1.0.0' );
		$mine      = $this->license( array( 'user_id' => $this->user ) );
		$delivered = array();
		add_filter( 'beltoft_licenses_deliver_file', function ( $handled, $path, $filename ) use ( &$delivered ) {
			$delivered[] = $filename;
			return true;
		}, 10, 3 );
		add_filter( 'wp_redirect', static function () {
			throw new \RuntimeException( 'redirected' );
		} );

		Endpoint::download( $mine['id'], $this->user );
		$this->assertSame( array( 'my-plugin-1.0.0.zip' ), $delivered );

		$this->expectExceptionMessage( 'redirected' );
		Endpoint::download( $mine['id'], self::factory()->user->create() );
	}

	public function test_labels(): void {
		$this->assertSame( '2 sites (unlimited)', View::sites_label( 2, 0 ) );
		$this->assertSame( 'Renews with your subscription', View::expiry_label( array( 'expires_at' => null, 'subscription_id' => 5 ), 'active' ) );
		$this->assertStringStartsWith( 'Expired', View::expiry_label( array( 'expires_at' => '2020-01-01 00:00:00', 'subscription_id' => null ), 'expired' ) );
	}

	public function test_menu_and_endpoint_follow_settings(): void {
		$this->settings( array( 'account_endpoint' => 'my-keys', 'account_label' => 'My keys' ) );
		$items = Endpoint::menu( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );

		$this->assertSame( array( 'dashboard', 'my-keys', 'customer-logout' ), array_keys( $items ) );
		$this->assertSame( 'My keys', $items['my-keys'] );
		$this->assertArrayHasKey( 'my-keys', Endpoint::query_vars( array() ) );
	}

	public function test_order_pages_and_customer_emails_list_the_keys(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'me@example.org' );
		$order->save();
		$l = $this->license( array( 'order_id' => $order->get_id() ) );

		ob_start();
		OrderLicenses::html( $order );
		$this->assertStringContainsString( $l['license_key'], (string) ob_get_clean() );

		ob_start();
		OrderLicenses::email( $order, false, true );
		$this->assertSame( '', (string) ob_get_clean(), 'not before the order is paid' );

		$order->set_status( 'processing' );
		$order->save();
		ob_start();
		OrderLicenses::email( $order, false, true );
		$this->assertStringContainsString( $l['license_key'], (string) ob_get_clean() );

		ob_start();
		OrderLicenses::email( $order, true, false );
		$this->assertSame( '', (string) ob_get_clean(), 'never in the shop\'s copy' );
	}

	public function test_history_is_written_from_the_license_actions(): void {
		$l = $this->license( array( 'max_activations' => 0 ) );
		Licenses::activate( $l, 'a.example' );
		Licenses::deactivate( $l, 'a.example' );
		Licenses::update( $l['id'], array( 'status' => 'suspended', 'expires_at' => '2031-01-01 00:00:00' ) );
		Events::add( $l['id'], 'vm_suspended', 'A plugin on top logs here too.' );

		$types = array_column( Events::for_license( $l['id'] ), 'type' );
		$this->assertSame( array( 'vm_suspended', 'expiry', 'status', 'deactivated', 'activated', 'created' ), $types );
		$history = Events::for_license( $l['id'] );
		$this->assertSame( 'Created (manual), unlimited sites.', end( $history )['message'] );
	}
}
