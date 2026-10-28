<?php
/**
 * Issuance is atomic per order item and per subscription: a second request at the same moment
 * neither duplicates a license nor blocks.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Duplicates;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Orders;
use BeltoftLicenses\Lifecycle\Subscriptions;
use BeltoftLicenses\Locks;
use BeltoftLicenses\Product;
use BeltoftLicenses\Software;

class IssueLockTest extends TestCase {

	/**
	 * A second database connection, standing in for the other request that holds a lock.
	 *
	 * @var \mysqli|null
	 */
	private $other = null;

	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		// The test database can hold committed rows of other suites; order and subscription IDs
		// repeat across runs. Inside the test's transaction, so rolled back after it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test setup.
		$wpdb->query( 'DELETE FROM ' . Install::tables()['licenses'] );
		// Likewise stale order items would attach themselves to a new order that reuses an ID.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test setup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
	}

	public function tear_down(): void {
		if ( $this->other ) {
			$this->other->close();
			$this->other = null;
		}

		parent::tear_down();
	}

	private function product(): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( 'lock-plugin' );
		$product->set_regular_price( '10' );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, 'lock-plugin' );
		$product->update_meta_data( Product::META_MAX, '2' );
		$product->save();

		return $product;
	}

	private function order( int $qty = 1 ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $this->product(), $qty );
		$order->set_billing_email( 'buyer@example.org' );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	private function hold( string $name ): void {
		$this->other = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$got         = $this->other->query( "SELECT GET_LOCK('" . $this->other->real_escape_string( $name ) . "', 0)" )->fetch_row();
		$this->assertSame( '1', (string) $got[0], 'the other connection holds the lock' );
	}

	private function release(): void {
		$this->other->close();
		$this->other = null;
	}

	private function item_lock( \WC_Order $order ): string {
		$items = array_values( $order->get_items() );

		return Locks::name( 'item', (int) $items[0]->get_id() );
	}

	private function scheduled( string $hook ): array {
		return as_get_scheduled_actions( array( 'hook' => $hook, 'status' => \ActionScheduler_Store::STATUS_PENDING ) );
	}

	public function test_lock_names_are_scoped_to_this_site(): void {
		global $wpdb;

		$this->assertSame( 'bl_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 10 ) . '_item_5', Locks::name( 'item', 5 ) );
		$this->assertLessThanOrEqual( 64, strlen( Locks::name( 'sub', PHP_INT_MAX ) ) );
	}

	public function test_a_busy_lock_creates_nothing_and_schedules_a_retry(): void {
		$this->settings( array() );
		add_filter( 'beltoft_licenses_lock_wait', '__return_zero' );
		$order = $this->order();
		$this->hold( $this->item_lock( $order ) );

		$issued = Orders::issue( $order );

		$this->assertSame( array(), $issued );
		$this->assertSame( array(), Licenses::where( 'order_id', $order->get_id() ), 'never created without the lock' );
		$this->assertCount( 1, $this->scheduled( Orders::RETRY_HOOK ) );

		// The same moment again does not queue a second retry.
		Orders::issue( $order );
		$this->assertCount( 1, $this->scheduled( Orders::RETRY_HOOK ) );
	}

	public function test_the_retry_issues_exactly_one_license_once_the_lock_is_free(): void {
		add_filter( 'beltoft_licenses_lock_wait', '__return_zero' );
		$order = $this->order();
		$this->hold( $this->item_lock( $order ) );
		// The status hooks fire as the order is paid, while the other request holds the lock.
		$order->set_status( 'processing' );
		$order->save();

		Orders::issue_for_order_id( $order->get_id() );
		$this->assertCount( 0, Licenses::where( 'order_id', $order->get_id() ) );

		$this->release();

		// Both the retry and a late duplicate hook run now: one license in all.
		Orders::retry( $order->get_id(), 1 );
		Orders::issue_for_order_id( $order->get_id() );
		Orders::retry( $order->get_id(), 2 );

		$this->assertCount( 1, Licenses::where( 'order_id', $order->get_id() ) );
	}

	public function test_a_stale_item_does_not_issue_again(): void {
		$order = $this->order( 2 );
		$stale = wc_get_order( $order->get_id() );

		Orders::issue( $order );
		// The second request loaded the order before the first finished: its item has no meta yet.
		$again = Orders::issue( $stale );

		$this->assertSame( array(), $again );
		$this->assertCount( 2, Licenses::where( 'order_id', $order->get_id() ) );
	}

	public function test_the_unit_marker_refuses_a_second_license_even_without_the_lock(): void {
		$first  = Licenses::create( array( 'product_slug' => 'x', 'issue_key' => 'oi99-1' ) );
		$second = Licenses::create( array( 'product_slug' => 'x', 'issue_key' => 'oi99-1' ) );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
		$this->assertSame( $first['id'], Licenses::find_by_issue_key( 'oi99-1' )['id'] );
		$this->assertArrayNotHasKey( 'issue_key', $first, 'the marker is not part of the license shape' );
		$this->assertNotNull( Licenses::create( array( 'product_slug' => 'x' ) ), 'no marker, no restriction' );
		$this->assertNotNull( Licenses::create( array( 'product_slug' => 'x' ) ) );
	}

	public function test_a_lost_item_meta_cannot_duplicate_a_unit(): void {
		$order = $this->order();
		Orders::issue( $order );

		// The item forgets its licenses (a crashed save): the marker still says unit 1 exists.
		$item = array_values( $order->get_items() )[0];
		$item->delete_meta_data( Orders::ITEM_META );
		$item->save();

		Orders::issue( wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, Licenses::where( 'order_id', $order->get_id() ) );
		$ids = array_values( wc_get_order( $order->get_id() )->get_items() )[0]->get_meta( Orders::ITEM_META );
		$this->assertSame( array( Licenses::where( 'order_id', $order->get_id() )[0]['id'] ), array_map( 'intval', $ids ), 'the item is pointed at it again' );
	}

	public function test_busy_retries_stop_with_a_note(): void {
		add_filter( 'beltoft_licenses_lock_wait', '__return_zero' );
		$order = $this->order();
		$order->set_status( 'processing' );
		$order->save();
		$this->hold( $this->item_lock( $order ) );

		Orders::retry( $order->get_id(), Orders::MAX_RETRIES );

		$notes = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
		$this->assertNotEmpty( array_filter( $notes, static fn( $n ) => false !== strpos( $n, 'could not be issued' ) ) );
	}

	public function test_a_busy_subscription_lock_creates_nothing_and_a_renewal_does_not_duplicate(): void {
		add_filter( 'beltoft_licenses_lock_wait', '__return_zero' );
		$sub  = new FakeSubscription( wp_rand( 1, 999999 ) );
		$item = new \WC_Order_Item_Product();
		$item->set_product( $this->product() );
		$item->set_quantity( 1 );
		$sub->items[] = $item;

		$this->hold( Locks::name( 'sub', $sub->get_id() ) );
		$this->assertSame( array(), Subscriptions::issue( $sub ) );
		$this->assertCount( 0, Licenses::where( 'subscription_id', $sub->get_id() ) );
		$this->release();

		Subscriptions::activated( $sub );
		Subscriptions::renewed( $sub );
		Subscriptions::renewed( $sub );

		$this->assertCount( 1, Licenses::where( 'subscription_id', $sub->get_id() ) );
	}

	public function test_a_subscription_unit_marker_blocks_a_second_license(): void {
		$sub  = new FakeSubscription( wp_rand( 1, 999999 ) );
		$item = new \WC_Order_Item_Product();
		$item->set_product( $this->product() );
		$item->set_quantity( 1 );
		$sub->items[] = $item;

		Subscriptions::activated( $sub );
		$license = Licenses::where( 'subscription_id', $sub->get_id() )[0];

		// Someone moved the license off the subscription: its unit's marker still stands, so
		// issuing again does not make a second key for the same unit.
		Licenses::update( $license['id'], array( 'subscription_id' => 0 ) );
		Subscriptions::renewed( $sub );

		$this->assertCount( 0, Licenses::where( 'subscription_id', $sub->get_id() ) );
		$this->assertNotNull( Licenses::find_by_issue_key( 's' . $sub->get_id() . '-i0-1' ) );
	}

	public function test_the_upgrade_reports_existing_duplicates_and_changes_nothing(): void {
		$order = $this->order( 1 );
		$item  = array_values( $order->get_items() )[0];
		$row   = array(
			'product_slug' => 'lock-plugin',
			'product_id'   => (int) $item->get_product_id(),
			'order_id'     => $order->get_id(),
			'source'       => 'order',
		);
		// Two licenses for a one-unit item, as a past race left them, and a legitimate pair on another order.
		$a = Licenses::create( $row );
		$b = Licenses::create( $row );
		$ok = $this->order( 2 );
		$ok_item = array_values( $ok->get_items() )[0];
		Licenses::create( array_merge( $row, array( 'order_id' => $ok->get_id(), 'product_id' => (int) $ok_item->get_product_id() ) ) );
		Licenses::create( array_merge( $row, array( 'order_id' => $ok->get_id(), 'product_id' => (int) $ok_item->get_product_id() ) ) );

		$before = Licenses::query( array() );
		update_option( Install::OPTION_DB_VERSION, '3' );
		Install::maybe_upgrade();

		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION_DB_VERSION ), 'the upgrade does not fail' );
		$groups = get_option( Duplicates::OPTION );
		$this->assertCount( 1, $groups );
		$this->assertSame( 'order', $groups[0]['owner'] );
		$this->assertSame( $order->get_id(), $groups[0]['owner_id'] );
		$this->assertSame( array( $a['id'], $b['id'] ), $groups[0]['license_ids'] );
		$this->assertSame( 1, $groups[0]['expected'] );
		$this->assertSame( $before, Licenses::query( array() ), 'no license changed or deleted' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Duplicates::notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( '#' . $order->get_id(), $html );
	}

	public function test_no_duplicates_clears_the_report(): void {
		update_option( Duplicates::OPTION, array( array( 'owner' => 'order' ) ) );
		$this->assertSame( array(), Duplicates::scan_and_store() );
		$this->assertFalse( get_option( Duplicates::OPTION ) );
	}
}
