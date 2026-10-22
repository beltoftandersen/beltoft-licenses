<?php
/**
 * Admin license screens: list query, bulk actions, edit, manual issue, export, order box.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Admin\ListTable;
use BeltoftLicenses\Admin\OrderBox;
use BeltoftLicenses\Admin\Screen;
use BeltoftLicenses\Events;
use BeltoftLicenses\Licenses;

class AdminTest extends TestCase {

	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();
		parent::tear_down();
	}

	public function test_query_filters_searches_and_pages(): void {
		$a = $this->license( array( 'email' => 'alice@example.org' ) );
		$b = $this->license( array( 'email' => 'bob@example.org', 'product_slug' => 'other-plugin' ) );
		$c = $this->license( array( 'email' => 'carol@example.org', 'status' => 'revoked' ) );
		Licenses::activate( $a, 'shop.alice.example' );

		$keys = static fn( array $result ) => array_column( $result[0], 'id' );

		$this->assertSame( array( $c['id'], $b['id'], $a['id'] ), $keys( Licenses::query() ), 'newest first' );
		$this->assertSame( 3, Licenses::query()[1] );
		$this->assertSame( array( $c['id'] ), $keys( Licenses::query( array( 'status' => 'revoked' ) ) ) );
		$this->assertSame( array( $b['id'] ), $keys( Licenses::query( array( 'product_slug' => 'other-plugin' ) ) ) );
		$this->assertSame( array( $b['id'] ), $keys( Licenses::query( array( 'search' => 'bob@' ) ) ), 'by email' );
		$this->assertSame( array( $a['id'] ), $keys( Licenses::query( array( 'search' => 'alice.example' ) ) ), 'by site' );
		$this->assertSame( array( $c['id'] ), $keys( Licenses::query( array( 'search' => substr( $c['license_key'], -9 ) ) ) ), 'by key' );
		$this->assertSame( array(), $keys( Licenses::query( array( 'search' => '100%' ) ) ), 'LIKE wildcards are literal' );

		$page2 = Licenses::query( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( array( $a['id'] ), $keys( $page2 ) );
		$this->assertSame( 3, $page2[1] );
	}

	public function test_filters_and_urls(): void {
		$_GET = array(
			'status'       => 'suspended',
			'product_slug' => 'My Plugin',
			's'            => ' bob ',
		);
		$this->assertSame( array( 'status' => 'suspended', 'product_slug' => 'my-plugin', 'search' => 'bob' ), Screen::filters() );

		$_GET['status'] = 'nonsense';
		$this->assertSame( '', Screen::filters()['status'] );

		$this->assertSame( admin_url( 'admin.php?page=beltoft-licenses-list&license=7' ), Screen::url( array( 'license' => 7, 'status' => '' ) ) );
		$this->assertStringContainsString( 'error=Not%20valid', Screen::url( array( 'error' => 'Not valid' ) ) );
		$this->assertStringContainsString( 'action=beltoft_licenses_export', Screen::export_url() );
		$this->assertStringContainsString( 'product_slug=my-plugin', Screen::export_url() );
		$this->assertStringContainsString( '_wpnonce=', Screen::export_url() );
	}

	public function test_bulk_changes_status_and_counts_only_changes(): void {
		$a = $this->license();
		$b = $this->license( array( 'status' => 'suspended' ) );

		$this->assertSame( 1, Screen::bulk( 'suspend', array( $a['id'], $b['id'], 999999 ) ) );
		$this->assertSame( 'suspended', Licenses::find( $a['id'] )['status'] );

		$this->assertSame( 2, Screen::bulk( 'reactivate', array( $a['id'], $b['id'], $b['id'] ) ) );
		$this->assertSame( 'active', Licenses::find( $b['id'] )['status'] );

		$this->assertSame( 2, Screen::bulk( 'revoke', array( (string) $a['id'], $b['id'] ) ) );
		$this->assertSame( 0, Screen::bulk( 'delete', array( $a['id'] ) ), 'unknown action' );
	}

	public function test_save_changes_status_limit_and_expiry_and_records_history(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$license = $this->license( array( 'max_activations' => 2 ) );

		$this->assertTrue(
			Screen::save(
				$license['id'],
				array(
					'status'          => 'suspended',
					'expires'         => '2030-06-15',
					'max_activations' => 5,
				)
			)
		);

		$saved = Licenses::find( $license['id'] );
		$this->assertSame( 'suspended', $saved['status'] );
		$this->assertSame( 5, $saved['max_activations'] );
		$end_of_day = ( new \DateTimeImmutable( '2030-06-15 23:59:59', wp_timezone() ) )->getTimestamp();
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $end_of_day ), $saved['expires_at'] );

		$types = array_column( Events::for_license( $license['id'] ), 'type' );
		$this->assertContains( 'status', $types );
		$this->assertContains( 'expiry', $types );
		$this->assertContains( 'limit', $types );
		$this->assertSame( get_current_user_id(), (int) Events::for_license( $license['id'] )[0]['user_id'], 'the admin is recorded' );

		$this->assertTrue( Screen::save( $license['id'], array( 'status' => 'active', 'expires' => '', 'max_activations' => 5 ) ) );
		$this->assertNull( Licenses::find( $license['id'] )['expires_at'], 'empty date clears the expiry' );
	}

	public function test_save_keeps_an_exact_expiry_when_the_date_is_unchanged(): void {
		$license = $this->license( array( 'expires_at' => '2031-03-10 08:15:00' ) );
		$shown   = wp_date( 'Y-m-d', strtotime( '2031-03-10 08:15:00 UTC' ) );

		$this->assertTrue( Screen::save( $license['id'], array( 'status' => 'active', 'expires' => $shown, 'max_activations' => 2 ) ) );
		$this->assertSame( '2031-03-10 08:15:00', Licenses::find( $license['id'] )['expires_at'] );
		$this->assertSame( array( 'created' ), array_column( Events::for_license( $license['id'] ), 'type' ), 'nothing changed, nothing recorded' );
	}

	public function test_save_refusals(): void {
		$license = $this->license();

		$this->assertWPError( Screen::save( 999999, array() ) );
		$this->assertWPError( Screen::save( $license['id'], array( 'expires' => '2030-02-31' ) ) );
		$this->assertWPError( Screen::save( $license['id'], array( 'expires' => 'tomorrow' ) ) );
		$this->assertNull( Licenses::find( $license['id'] )['expires_at'] );

		$this->assertTrue( Screen::save( $license['id'], array( 'status' => 'bogus', 'max_activations' => 2 ) ) );
		$this->assertSame( 'active', Licenses::find( $license['id'] )['status'] );
	}

	public function test_deactivate_site(): void {
		$license = $this->license();
		Licenses::activate( $license, 'shop.example' );

		$this->assertTrue( Screen::deactivate_site( $license['id'], 'https://Shop.example/' ) );
		$this->assertSame( array(), Licenses::live_activations( $license['id'] ) );
		$this->assertFalse( Screen::deactivate_site( $license['id'], 'shop.example' ), 'already free' );
		$this->assertFalse( Screen::deactivate_site( 999999, 'shop.example' ) );
	}

	public function test_issue_by_hand(): void {
		$product_id = $this->software( 'my-plugin', '1.0.0' );
		$user_id    = self::factory()->user->create( array( 'user_email' => 'known@example.org' ) );
		$order      = wc_create_order();

		$license = Screen::issue(
			array(
				'product_slug'    => 'my-plugin',
				'email'           => 'known@example.org',
				'max_activations' => 3,
				'expires'         => '2032-01-01',
				'order_id'        => $order->get_id(),
			)
		);

		$this->assertIsArray( $license );
		$this->assertSame( 'manual', $license['source'] );
		$this->assertSame( $user_id, $license['user_id'], 'linked to the user with that email' );
		$this->assertSame( $product_id, $license['product_id'] );
		$this->assertSame( $order->get_id(), $license['order_id'] );
		$this->assertSame( 3, $license['max_activations'] );
		$this->assertNotNull( $license['expires_at'] );

		$guest = Screen::issue( array( 'product_slug' => 'my-plugin', 'email' => 'guest@example.org', 'order_id' => 999999 ) );
		$this->assertNull( $guest['user_id'] );
		$this->assertNull( $guest['order_id'], 'a missing order is not linked' );
		$this->assertNull( $guest['expires_at'] );
		$this->assertSame( 1, $guest['max_activations'] );
	}

	public function test_issue_refusals(): void {
		$this->software( 'my-plugin', '1.0.0' );

		$this->assertSame( 'beltoft_licenses_product', Screen::issue( array( 'product_slug' => 'unknown', 'email' => 'a@example.org' ) )->get_error_code() );
		$this->assertSame( 'beltoft_licenses_email', Screen::issue( array( 'product_slug' => 'my-plugin', 'email' => 'nope' ) )->get_error_code() );
		$this->assertSame( 'beltoft_licenses_date', Screen::issue( array( 'product_slug' => 'my-plugin', 'email' => 'a@example.org', 'expires' => '13/01/2030' ) )->get_error_code() );
		$this->assertSame( 0, Licenses::query()[1] );
	}

	public function test_csv_export_follows_filters_and_guards_formulas(): void {
		$a = $this->license( array( 'email' => '=cmd@example.org' ) );
		$this->license( array( 'status' => 'revoked' ) );
		Licenses::activate( $a, 'one.example' );
		Licenses::activate( $a, 'two.example' );

		$rows = array_map( 'str_getcsv', array_filter( explode( "\n", Screen::csv( array( 'status' => 'active' ) ) ) ) );

		$this->assertCount( 2, $rows, 'header and one license' );
		$this->assertSame( 'license_key', $rows[0][0] );
		$row = array_combine( $rows[0], $rows[1] );
		$this->assertSame( $a['license_key'], $row['license_key'] );
		$this->assertSame( "'=cmd@example.org", $row['email'] );
		$this->assertSame( 'one.example two.example', $row['sites'] );
		$this->assertSame( '2', $row['max_activations'] );
	}

	public function test_csv_export_pages_through_everything(): void {
		for ( $i = 0; $i < 205; $i++ ) {
			$this->license();
		}

		$this->assertCount( 206, array_filter( explode( "\n", Screen::csv( array() ) ) ) );
	}

	public function test_handlers_refuse_users_without_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );

		foreach ( array( 'handle_save', 'handle_deactivate', 'handle_issue', 'handle_export' ) as $handler ) {
			try {
				Screen::$handler();
				$this->fail( $handler . ' ran for a customer' );
			} catch ( \WPDieException $e ) {
				$this->assertStringContainsString( 'not allowed', $e->getMessage() );
			}
		}
	}

	public function test_handlers_check_the_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$license = $this->license();
		$_POST   = array(
			'license'  => $license['id'],
			'status'   => 'revoked',
			'_wpnonce' => 'wrong',
		);
		$_REQUEST = $_POST;

		try {
			Screen::handle_save();
			$this->fail( 'saved without a valid nonce' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'active', Licenses::find( $license['id'] )['status'] );
		}
	}

	public function test_save_handler_redirects_back_with_a_notice(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$license  = $this->license();
		$_POST    = array(
			'license'         => $license['id'],
			'status'          => 'suspended',
			'expires'         => '',
			'max_activations' => 2,
			'_wpnonce'        => wp_create_nonce( 'beltoft_licenses_save_' . $license['id'] ),
		);
		$_REQUEST = $_POST;
		$location = '';
		add_filter(
			'wp_redirect',
			static function ( $to ) use ( &$location ) {
				$location = $to;
				throw new \RuntimeException( 'redirected' );
			}
		);

		try {
			Screen::handle_save();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( 'suspended', Licenses::find( $license['id'] )['status'] );
		$this->assertSame( Screen::url( array( 'license' => $license['id'], 'notice' => 'saved' ) ), $location );
	}

	public function test_list_page_slug_differs_from_the_settings_page(): void {
		$this->assertNotSame( \BeltoftLicenses\Settings::PAGE, Screen::PAGE, 'admin.php?page= would open the settings instead of the list' );
	}

	public function test_per_page_screen_option(): void {
		$this->assertSame( 50, Screen::save_per_page( false, Screen::PER_PAGE_OPTION, '50' ) );
		$this->assertSame( 200, Screen::save_per_page( false, Screen::PER_PAGE_OPTION, '9999' ) );
		$this->assertFalse( Screen::save_per_page( false, 'other_option', '50' ) );
	}

	public function test_order_box_finds_the_order_licenses_and_renders(): void {
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.org' );
		$order->save();
		$mine = $this->license( array( 'order_id' => $order->get_id() ) );
		$this->license( array( 'order_id' => $order->get_id() + 1000 ) );

		$this->assertSame( array( $mine['id'] ), array_column( OrderBox::licenses_for( $order ), 'id' ) );

		ob_start();
		OrderBox::render( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $mine['license_key'], $html );
		$this->assertStringContainsString( 'view=new', $html );
		$this->assertStringContainsString( 'email=buyer%40example.org', $html );
		$this->assertStringContainsString( 'order_id=' . $order->get_id(), $html );
	}

	public function test_resource_label_filter_reaches_the_list_and_the_order_box(): void {
		add_filter( 'beltoft_licenses_resource_label', static fn() => '1 of 1 server' );
		$order = wc_create_order();
		$order->save();
		$license = $this->license( array( 'order_id' => $order->get_id() ) );

		ob_start();
		OrderBox::render( $order );
		$this->assertStringContainsString( '1 of 1 server', (string) ob_get_clean() );

		$table = new ListTable();
		$this->assertSame( '1 of 1 server', ( new \ReflectionMethod( $table, 'column_default' ) )->invoke( $table, $license, 'sites' ) );
	}

	public function test_edit_screen_renders_sites_history_and_extension_point(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$license = $this->license();
		Licenses::activate( $license, 'shop.example' );
		add_action(
			'beltoft_licenses_admin_license',
			static function ( $l ) {
				echo '<p>panel for ' . (int) $l['id'] . '</p>';
			}
		);
		$_GET = array( 'license' => $license['id'] );

		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $license['license_key'], $html );
		$this->assertStringContainsString( 'shop.example', $html );
		$this->assertStringContainsString( 'beltoft_licenses_deactivate', $html );
		$this->assertStringContainsString( 'Activated on shop.example.', $html );
		$this->assertStringContainsString( 'panel for ' . $license['id'], $html );
	}

	public function test_list_screen_renders(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'woocommerce_page_beltoft-licenses-list' );
		$license = $this->license();

		ob_start();
		Screen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( $license['license_key'], $html );
		$this->assertStringContainsString( 'name="licenses[]"', $html );
		$this->assertStringContainsString( 'Export CSV', $html );
	}

	public function test_reactivating_clears_a_past_expiry_and_keeps_a_future_one(): void {
		$past   = $this->license( array( 'status' => 'revoked', 'expires_at' => time() - DAY_IN_SECONDS ) );
		$future = $this->license( array( 'status' => 'suspended', 'expires_at' => time() + 30 * DAY_IN_SECONDS ) );

		$this->assertSame( 2, Screen::bulk( 'reactivate', array( $past['id'], $future['id'] ) ) );
		$this->assertNull( Licenses::find( $past['id'] )['expires_at'] );
		$this->assertSame( 'active', Licenses::effective_status( Licenses::find( $past['id'] ) ) );
		$this->assertNotNull( Licenses::find( $future['id'] )['expires_at'] );
	}

	public function test_save_to_active_clears_a_past_expiry_but_not_a_future_one(): void {
		$past   = $this->license( array( 'status' => 'revoked', 'expires_at' => time() - DAY_IN_SECONDS ) );
		$future = $this->license( array( 'status' => 'revoked', 'expires_at' => time() + 30 * DAY_IN_SECONDS ) );

		$shown = gmdate( 'Y-m-d', strtotime( $past['expires_at'] . ' UTC' ) );
		$this->assertTrue( Screen::save( $past['id'], array( 'status' => 'active', 'expires' => $shown ) ) );
		$this->assertNull( Licenses::find( $past['id'] )['expires_at'] );

		$this->assertTrue( Screen::save( $future['id'], array( 'status' => 'active', 'expires' => gmdate( 'Y-m-d', strtotime( $future['expires_at'] . ' UTC' ) ) ) ) );
		$this->assertNotNull( Licenses::find( $future['id'] )['expires_at'] );
	}

	public function test_save_without_an_expires_field_keeps_the_expiry(): void {
		$license = $this->license( array( 'expires_at' => time() + 30 * DAY_IN_SECONDS ) );

		$this->assertTrue( Screen::save( $license['id'], array( 'max_activations' => 9 ) ) );
		$this->assertSame( $license['expires_at'], Licenses::find( $license['id'] )['expires_at'] );

		$this->assertTrue( Screen::save( $license['id'], array( 'expires' => '' ) ) );
		$this->assertNull( Licenses::find( $license['id'] )['expires_at'], 'a submitted empty field still clears it' );
	}
}
