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

	public function test_update_resource_meta_keeps_a_write_that_lands_between_its_read_and_its_write() {
		global $wpdb;

		$license = $this->license();
		$added   = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'task' => 'old', 'a' => 1 ) );
		$id      = (int) $added['activation_id'];
		$table   = \BeltoftLicenses\Install::tables()['activations'];
		$raced   = false;

		// Just before this update writes, another request stores a new task (a provision step, say).
		$race = static function ( $query ) use ( &$raced, $wpdb, $table, $id ) {
			if ( ! $raced && 0 === strpos( $query, 'UPDATE `' . $table . '` SET meta' ) ) {
				$raced = true;
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET meta = %s WHERE id = %d', $table, wp_json_encode( array( 'task' => 'new', 'a' => 1 ) ), $id ) );
			}
			return $query;
		};
		add_filter( 'query', $race );
		$ok = Licenses::update_resource_meta( $id, array( 'agent' => true ) );
		remove_filter( 'query', $race );

		$this->assertTrue( $raced, 'The other write ran in between.' );
		$this->assertTrue( $ok );
		$this->assertSame( array( 'task' => 'new', 'a' => 1, 'agent' => true ), Licenses::activation( $id )['meta'], 'Both writes are kept.' );
	}

	public function test_update_resource_meta_succeeds_when_nothing_changes_and_compares_case_exactly() {
		$license = $this->license();
		$added   = Licenses::add_resource( $license, 'vm', 'vm-1', array( 'name' => 'Web' ) );
		$id      = (int) $added['activation_id'];

		$this->assertTrue( Licenses::update_resource_meta( $id, array( 'name' => 'Web' ) ) );
		$this->assertTrue( Licenses::update_resource_meta( $id, array( 'name' => 'Web' ) ), 'The same values twice in one second is still a success.' );
		$this->assertTrue( Licenses::update_resource_meta( $id, array( 'name' => 'web' ) ) );
		$this->assertSame( 'web', Licenses::activation( $id )['meta']['name'], 'A change in case only is written.' );
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

	public function test_live_resource_ids_filters_pages_and_orders() {
		$license = $this->license( array( 'max_activations' => 0 ) );
		Licenses::activate( $license, 'a.test' );
		$vm1 = Licenses::add_resource( $license, 'vm', 'vm-1' )['activation_id'];
		$db1 = Licenses::add_resource( $license, 'db', 'db-1' )['activation_id'];
		$vm2 = Licenses::add_resource( $license, 'vm', 'vm-2' )['activation_id'];
		$vm3 = Licenses::add_resource( $license, 'vm', 'vm-3' )['activation_id'];
		Licenses::close_resource( $license, 'vm', 'vm-3' );

		$this->assertSame( array( $vm1, $db1, $vm2 ), Licenses::live_resource_ids() );
		$this->assertSame( array( $vm1, $vm2 ), Licenses::live_resource_ids( 'vm' ) );
		$this->assertSame( array( $vm2, $vm1 ), Licenses::live_resource_ids( 'vm', 0, 0, 'DESC' ) );
		$this->assertSame( array( $db1, $vm2 ), Licenses::live_resource_ids( '', $vm1 ) );
		$this->assertSame( array( $db1 ), Licenses::live_resource_ids( '', $vm1, 1 ) );
		$this->assertSame( array( $vm1, $db1, $vm2 ), Licenses::live_resource_ids( '', 0, 0, 'bogus; DROP' ) );
		$this->assertNotContains( $vm3, Licenses::live_resource_ids() );
	}

	public function test_resource_ids_by_meta_matches_exactly_not_12_for_112_and_handles_escaped_quotes_and_unicode() {
		$license = $this->license( array( 'max_activations' => 0 ) );
		$a       = Licenses::add_resource( $license, 'vm', 'a', array( 'vmid' => 12 ) )['activation_id'];
		$b       = Licenses::add_resource( $license, 'vm', 'b', array( 'vmid' => 112 ) )['activation_id'];
		$c       = Licenses::add_resource( $license, 'vm', 'c', array( 'vmid' => '12' ) )['activation_id'];
		$d       = Licenses::add_resource( $license, 'vm', 'd', array( 'name' => 'say "hi" \\ back' ) )['activation_id'];
		$e       = Licenses::add_resource( $license, 'vm', 'e', array( 'name' => 'blåbær 日本' ) )['activation_id'];
		$f       = Licenses::add_resource( $license, 'db', 'f', array( 'vmid' => 12 ) )['activation_id'];
		$g       = Licenses::add_resource( $license, 'vm', 'g', array( 'note' => 'has "vmid":12 in text' ) )['activation_id'];

		$this->assertSame( array( $a ), Licenses::resource_ids_by_meta( 'vm', 'vmid', 12 ) );
		$this->assertSame( array( $c ), Licenses::resource_ids_by_meta( 'vm', 'vmid', '12' ) );
		$this->assertSame( array( $b ), Licenses::resource_ids_by_meta( 'vm', 'vmid', 112 ) );
		$this->assertSame( array( $d ), Licenses::resource_ids_by_meta( 'vm', 'name', 'say "hi" \\ back' ) );
		$this->assertSame( array( $e ), Licenses::resource_ids_by_meta( 'vm', 'name', 'blåbær 日本' ) );
		$this->assertSame( array( $f ), Licenses::resource_ids_by_meta( 'db', 'vmid', 12 ) );
		$this->assertSame( array(), Licenses::resource_ids_by_meta( 'vm', 'note', 12 ) );
		$a2 = Licenses::add_resource( $license, 'vm', 'a2', array( 'vmid' => 12 ) )['activation_id'];
		$this->assertSame( array( $a, $a2 ), Licenses::resource_ids_by_meta( 'vm', 'vmid', 12 ) );
		$this->assertSame( array( $a2, $a ), Licenses::resource_ids_by_meta( 'vm', 'vmid', 12, 'DESC' ) );
		$this->assertSame( array( $g ), Licenses::resource_ids_by_meta( 'vm', 'note', 'has "vmid":12 in text' ) );
	}

	public function test_resource_ids_by_meta_any_finds_each_value_once_in_one_query_and_stays_exact() {
		global $wpdb;

		$license = $this->license( array( 'max_activations' => 0 ) );
		$a       = Licenses::add_resource( $license, 'vm', 'a', array( 'vmid' => 12 ) )['activation_id'];
		$b       = Licenses::add_resource( $license, 'vm', 'b', array( 'vmid' => '12' ) )['activation_id'];
		$c       = Licenses::add_resource( $license, 'vm', 'c', array( 'vmid' => 123 ) )['activation_id'];
		$d       = Licenses::add_resource( $license, 'vm', 'd', array( 'vmid' => 7, 'inner' => array( 'vmid' => 12 ) ) )['activation_id'];
		$e       = Licenses::add_resource( $license, 'vm', 'e', array( 'state' => 'waiting', 'on' => true, 'gone' => null, 'rate' => 1.5 ) )['activation_id'];
		$f       = Licenses::add_resource( $license, 'vm', 'f', array( 'state' => 'applying' ) )['activation_id'];
		$g       = Licenses::add_resource( $license, 'db', 'g', array( 'vmid' => 12 ) )['activation_id'];
		$h       = Licenses::add_resource( $license, 'vm', 'h', array( 'vmid' => 12 ) )['activation_id'];
		Licenses::close_resource( $license, 'vm', 'h' );

		$before = $wpdb->num_queries;
		$this->assertSame( array( $a, $b ), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 12, '12' ) ) );
		$this->assertSame( 1, $wpdb->num_queries - $before, 'One query for the whole list.' );

		$this->assertSame( array( $b, $a ), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 12, '12' ), 'DESC' ) );
		$this->assertSame( array( $a ), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 12, 12 ) ), 'A value given twice still finds the row once.' );
		$this->assertSame( array( $a, $c ), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 12, 123 ) ), '12 as a pattern also reads 123; the decoded meta decides.' );
		$this->assertSame( array( $d ), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 7 ) ), 'The top level counts, not a nested key.' );
		$this->assertSame( array( $e, $f ), Licenses::resource_ids_by_meta_any( 'vm', 'state', array( 'waiting', 'applying' ) ) );
		$this->assertSame( array( $e ), Licenses::resource_ids_by_meta_any( 'vm', 'on', array( true ) ) );
		$this->assertSame( array( $e ), Licenses::resource_ids_by_meta_any( 'vm', 'gone', array( null ) ) );
		$this->assertSame( array( $e ), Licenses::resource_ids_by_meta_any( 'vm', 'rate', array( 1.5 ) ), 'A fraction is found through the key alone.' );
		$this->assertSame( array( $e ), Licenses::resource_ids_by_meta_any( 'vm', 'rate', array( 1.5, 'x' ) ) );
		$this->assertSame( array( $g ), Licenses::resource_ids_by_meta_any( 'db', 'vmid', array( 12 ) ) );
		$this->assertSame( array(), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array() ) );
		$this->assertSame( array(), Licenses::resource_ids_by_meta_any( 'vm', 'vmid', array( 99 ) ) );

		$url = Licenses::add_resource( $license, 'vm', 'url', array( 'url' => 'http://x.y/a_b?c=100%' ) )['activation_id'];
		$this->assertSame( array( $url ), Licenses::resource_ids_by_meta_any( 'vm', 'url', array( 'http://x.y/a_b?c=100%' ) ), 'A slash, an underscore and a percent sign are plain characters.' );
		$this->assertSame( array(), Licenses::resource_ids_by_meta_any( 'vm', 'url', array( 'http://x.y/aXb?c=100%' ) ) );
		$this->assertSame( array(), Licenses::resource_ids_by_meta_any( 'vm', 'url', array( 'http://x.y/a_b?c=100' ) ) );
	}

	public function test_resource_ids_having_meta_skips_null() {
		$license = $this->license( array( 'max_activations' => 0 ) );
		$a       = Licenses::add_resource( $license, 'vm', 'a', array( 'ip' => '10.0.0.1' ) )['activation_id'];
		Licenses::add_resource( $license, 'vm', 'b', array( 'ip' => null ) );
		Licenses::add_resource( $license, 'vm', 'c', array( 'other' => 1 ) );
		$d = Licenses::add_resource( $license, 'db', 'd', array( 'ip' => 0 ) )['activation_id'];

		$this->assertSame( array( $a, $d ), Licenses::resource_ids_having_meta( 'ip' ) );
	}

	public function test_resources_of_source_includes_recently_deactivated_rows_only_since_the_given_time() {
		global $wpdb;

		$woo    = $this->license( array( 'source' => 'woocommerce', 'max_activations' => 0 ) );
		$manual = $this->license( array( 'source' => 'manual', 'max_activations' => 0 ) );
		Licenses::activate( $woo, 'site.test' );
		$live   = Licenses::add_resource( $woo, 'vm', 'live' )['activation_id'];
		$old    = Licenses::add_resource( $woo, 'vm', 'old' )['activation_id'];
		$recent = Licenses::add_resource( $woo, 'vm', 'recent' )['activation_id'];
		Licenses::add_resource( $manual, 'vm', 'other' );
		Licenses::close_resource( $woo, 'vm', 'old' );
		Licenses::close_resource( $woo, 'vm', 'recent' );

		$table = \BeltoftLicenses\Install::tables()['activations'];
		$wpdb->update( $table, array( 'deactivated_at' => '2020-01-01 00:00:00' ), array( 'id' => $old ) );
		$wpdb->update( $table, array( 'deactivated_at' => '2026-11-08 12:00:00' ), array( 'id' => $recent ) );

		$live_only = Licenses::resources_of_source( 'woocommerce' );
		$this->assertSame( array( $live ), array_column( $live_only, 'id' ) );
		$this->assertNull( $live_only[0]['deactivated_at'] );
		$this->assertNotEmpty( $live_only[0]['activated_at'] );

		$since = Licenses::resources_of_source( 'woocommerce', '2026-11-01 00:00:00' );
		$this->assertSame( array( $live, $recent ), array_column( $since, 'id' ) );
		$this->assertSame( '2026-11-08 12:00:00', $since[1]['deactivated_at'] );
	}

	public function test_product_holders_lists_user_and_email_of_every_license_of_the_product_in_one_query() {
		global $wpdb;

		$this->license( array( 'product_slug' => 'alpha', 'user_id' => 7, 'email' => 'christian.beltoft@gmail.com' ) );
		$revoked = $this->license( array( 'product_slug' => 'alpha', 'user_id' => 0, 'email' => 'christian.beltoft+two@gmail.com' ) );
		$this->license( array( 'product_slug' => 'beta', 'user_id' => 9, 'email' => 'christian.beltoft+three@gmail.com' ) );
		Licenses::update( (int) $revoked['id'], array( 'status' => 'revoked' ) );

		$before  = $wpdb->num_queries;
		$holders = Licenses::product_holders( 'alpha' );
		$this->assertSame( 1, $wpdb->num_queries - $before );

		$this->assertSame(
			array(
				array( 'user_id' => 0, 'email' => 'christian.beltoft+two@gmail.com' ),
				array( 'user_id' => 7, 'email' => 'christian.beltoft@gmail.com' ),
			),
			$holders,
			'Every status counts, newest first, and another product is left out.'
		);
		$this->assertSame( array(), Licenses::product_holders( 'none' ) );
		$this->assertSame( array(), Licenses::product_holders( '' ) );
	}
}
