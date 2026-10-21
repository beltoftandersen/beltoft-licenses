<?php
/**
 * The resource API: activations of kinds other than 'site'.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;

/**
 * Add, close, update and list resources.
 */
final class ResourcesTest extends TestCase {

	public function test_add_resource_creates_a_live_row_with_kind_and_meta() {
		$license = $this->license();
		$result  = Licenses::add_resource( $license, 'vm', 'vm-12', array( 'state' => 'requested' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'activated', $result['code'] );

		$row = Licenses::activation( $result['activation_id'] );
		$this->assertSame( 'vm', $row['kind'] );
		$this->assertSame( 'vm-12', $row['domain'] );
		$this->assertSame( 'vm-12', $row['live_domain'] );
		$this->assertSame( array( 'state' => 'requested' ), $row['meta'] );
	}

	public function test_add_resource_obeys_status_expiry_and_limit() {
		$suspended = $this->license( array( 'status' => 'suspended' ) );
		$result    = Licenses::add_resource( $suspended, 'vm', 'a' );
		$this->assertSame( 'license_not_active', $result['code'] );
		$this->assertSame( Licenses::activate( $suspended, 'a.test' )['message'], $result['message'] );

		$expired = $this->license( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		$result  = Licenses::add_resource( $expired, 'vm', 'a' );
		$this->assertSame( 'license_expired', $result['code'] );
		$this->assertFalse( $result['success'] );

		$limited = $this->license( array( 'max_activations' => 1 ) );
		$this->assertTrue( Licenses::add_resource( $limited, 'vm', 'a' )['success'] );
		$result = Licenses::add_resource( $limited, 'vm', 'b' );
		$this->assertSame( 'activation_limit', $result['code'] );
		$this->assertSame( Licenses::activate( $limited, 'c.test' )['message'], $result['message'] );
	}

	public function test_add_resource_is_idempotent() {
		$license = $this->license();
		$first   = Licenses::add_resource( $license, 'vm', 'vm-1' );
		$second  = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'x' => 1 ) );

		$this->assertSame( 'already_active', $second['code'] );
		$this->assertTrue( $second['success'] );
		$this->assertCount( 1, Licenses::resources( (int) $license['id'], 'vm' ) );
		$this->assertSame( $first['activation_id'], $second['activation_id'] );
	}

	public function test_site_and_resource_with_the_same_text_coexist() {
		$license = $this->license();

		$this->assertSame( 'activated', Licenses::activate( $license, 'vm-12' )['code'] );
		$this->assertSame( 'activated', Licenses::add_resource( $license, 'vm', 'vm-12' )['code'] );
		$this->assertCount( 2, Licenses::live_activations( (int) $license['id'] ) );
	}

	public function test_invalid_kind_or_identifier_is_refused() {
		$license = $this->license();

		foreach ( array( array( 'site', 'x' ), array( '', 'x' ), array( 'vm', '' ), array( 'vm', 'a b' ), array( 'SITE', 'x' ), array( 'Site', 'x' ), array( 'v m', 'x' ), array( 'VM', 'x' ), array( str_repeat( 'k', 21 ), 'x' ), array( 'vm', str_repeat( 'a', 181 ) ) ) as $case ) {
			$result = Licenses::add_resource( $license, $case[0], $case[1] );
			$this->assertFalse( $result['success'] );
			$this->assertSame( 'invalid', $result['code'] );
		}

		$this->assertSame( 'activated', Licenses::add_resource( $license, 'vm', str_repeat( 'a', 180 ) )['code'] );
	}

	public function test_close_resource_keeps_history_and_fires_the_action() {
		$license = $this->license();
		$added   = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'k' => 'v' ) );
		$seen    = array();
		add_action(
			'beltoft_licenses_deactivated',
			function ( $lic, $identifier, $activation ) use ( &$seen ) {
				$seen = array( $identifier, $activation );
			},
			10,
			3
		);

		$this->assertTrue( Licenses::close_resource( $license, 'vm', 'vm-1' ) );
		$this->assertFalse( Licenses::close_resource( $license, 'vm', 'vm-1' ) );

		$row = Licenses::activation( $added['activation_id'] );
		$this->assertNotNull( $row['deactivated_at'] );
		$this->assertNull( $row['live_domain'] );
		$this->assertSame( 'vm-1', $seen[0] );
		$this->assertSame( $added['activation_id'], (int) $seen[1]['id'] );
		$this->assertSame( array( 'k' => 'v' ), $seen[1]['meta'] );
		$this->assertSame( array(), Licenses::resources( (int) $license['id'] ) );
	}

	public function test_activated_action_gets_the_row_for_sites_and_resources() {
		$license = $this->license();
		$seen    = array();
		add_action(
			'beltoft_licenses_activated',
			function ( $lic, $identifier, $activation ) use ( &$seen ) {
				$seen[] = array( $identifier, $activation['kind'] );
			},
			10,
			3
		);

		Licenses::activate( $license, 'a.test' );
		Licenses::add_resource( $license, 'vm', 'vm-1' );
		Licenses::deactivate( $license, 'a.test' );

		$this->assertSame( array( array( 'a.test', 'site' ), array( 'vm-1', 'vm' ) ), $seen );
	}

	public function test_resource_actions_do_not_write_site_history() {
		$license = $this->license();
		Licenses::add_resource( $license, 'vm', 'vm-1' );
		Licenses::close_resource( $license, 'vm', 'vm-1' );
		Licenses::activate( $license, 'a.test' );

		$messages = implode( ' ', wp_list_pluck( \BeltoftLicenses\Events::for_license( (int) $license['id'] ), 'message' ) );
		$this->assertStringNotContainsString( 'vm-1', $messages );
		$this->assertStringContainsString( 'a.test', $messages );
	}

	public function test_update_resource_meta_merges() {
		$license = $this->license();
		$added   = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'a' => 1 ) );

		$this->assertNull( Licenses::activation( $added['activation_id'] )['updated_at'] );
		$this->assertTrue( Licenses::update_resource_meta( $added['activation_id'], array( 'b' => 2 ) ) );

		$row = Licenses::activation( $added['activation_id'] );
		$this->assertSame( array( 'a' => 1, 'b' => 2 ), $row['meta'] );
		$this->assertNotNull( $row['updated_at'] );
		$this->assertFalse( Licenses::update_resource_meta( 999999, array( 'b' => 2 ) ) );
	}

	public function test_resources_lists_live_non_site_rows_only() {
		$license = $this->license( array( 'max_activations' => 0 ) );
		$id      = (int) $license['id'];

		Licenses::activate( $license, 'a.test' );
		Licenses::add_resource( $license, 'vm', 'vm-1' );
		Licenses::add_resource( $license, 'account', 'acc-1' );
		Licenses::add_resource( $license, 'vm', 'vm-2' );
		Licenses::close_resource( $license, 'vm', 'vm-2' );

		$all = Licenses::resources( $id );
		$this->assertSame( array( 'vm-1', 'acc-1' ), wp_list_pluck( $all, 'domain' ) );
		$this->assertSame( array(), $all[0]['meta'] );
		$this->assertSame( array( 'vm-1' ), wp_list_pluck( Licenses::resources( $id, 'vm' ), 'domain' ) );
	}

	public function test_sites_and_resources_with_the_same_text_never_close_each_other() {
		$license = $this->license();
		$site    = Licenses::activate( $license, 'vm-12' );
		$added   = Licenses::add_resource( $license, 'vm', 'vm-12' );

		$this->assertTrue( Licenses::deactivate( $license, 'vm-12' )['success'] );
		$this->assertSame( 'vm-12', Licenses::activation( $added['activation_id'] )['live_domain'] );
		$this->assertCount( 1, Licenses::resources( (int) $license['id'] ) );

		Licenses::activate( $license, 'vm-12' );
		$this->assertTrue( Licenses::close_resource( $license, 'vm', 'vm-12' ) );
		$this->assertTrue( Licenses::is_live_on( (int) $license['id'], 'vm-12' ) );
		$this->assertSame( 'activated', $site['code'] );
	}

	public function test_add_resource_reports_an_insert_that_failed() {
		$license = $this->license();
		$table   = \BeltoftLicenses\Install::tables()['activations'];
		$break   = static function ( $query ) use ( $table ) {
			return 0 === strpos( $query, "INSERT INTO `$table`" ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$result = Licenses::add_resource( $license, 'vm', 'vm-1' );
		remove_filter( 'query', $break );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'error', $result['code'] );
		$this->assertSame( 'Resource could not be stored', $result['message'] );
		$this->assertArrayNotHasKey( 'activation_id', $result );
		$this->assertSame( array(), Licenses::resources( (int) $license['id'] ) );
	}

	public function test_actions_always_get_an_activation_row() {
		$license = $this->license();
		$seen    = array();
		$listen  = function ( array $lic, string $identifier, array $activation ) use ( &$seen ) {
			$seen[] = array( $identifier, $activation['kind'], (int) $activation['id'] > 0 );
		};
		add_action( 'beltoft_licenses_activated', $listen, 10, 3 );
		add_action( 'beltoft_licenses_deactivated', $listen, 10, 3 );

		// The row is gone by the time it is read back, as when another request removes it.
		$hide = static function ( $query ) {
			return false !== strpos( $query, 'SELECT * FROM' ) && preg_match( '/WHERE id = \d+$/', $query ) ? $query . ' AND 0 = 1' : $query;
		};
		add_filter( 'query', $hide );

		Licenses::activate( $license, 'a.test' );
		Licenses::deactivate( $license, 'a.test' );
		Licenses::add_resource( $license, 'vm', 'vm-1' );
		Licenses::close_resource( $license, 'vm', 'vm-1' );

		remove_filter( 'query', $hide );

		$this->assertSame(
			array(
				array( 'a.test', 'site', true ),
				array( 'a.test', 'site', true ),
				array( 'vm-1', 'vm', true ),
				array( 'vm-1', 'vm', true ),
			),
			$seen
		);
	}

	public function test_update_resource_meta_writes_nothing_it_cannot_encode() {
		$license = $this->license();
		$added   = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'a' => 1 ) );

		$this->assertFalse( Licenses::update_resource_meta( $added['activation_id'], array( 'b' => NAN ) ) );

		$row = Licenses::activation( $added['activation_id'] );
		$this->assertSame( array( 'a' => 1 ), $row['meta'] );
		$this->assertNull( $row['updated_at'] );
	}
}
