<?php
/**
 * Schema v3: activation kinds, and the site paths that act on sites only.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;

/**
 * Upgrade from schema 2, and the isolation of kind 'site' from other kinds.
 */
final class SchemaV3Test extends TestCase {

	/**
	 * Inserts a live activation of another kind directly.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Resource name.
	 * @param string $kind       Kind.
	 */
	private function insert_resource( int $license_id, string $domain, string $kind = 'vm' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			Install::tables()['activations'],
			array(
				'license_id'   => $license_id,
				'kind'         => $kind,
				'domain'       => $domain,
				'live_domain'  => $domain,
				'activated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public function test_upgrade_from_v2_keeps_rows_and_swaps_the_unique_key(): void {
		global $wpdb;

		$table = Install::tables()['activations'];
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS $table" );
		$wpdb->query(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				domain varchar(255) NOT NULL,
				live_domain varchar(255) NULL DEFAULT NULL,
				activated_at datetime NOT NULL,
				deactivated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY live (license_id,live_domain(191)),
				KEY license_id (license_id),
				KEY domain (domain(191))
			)"
		);
		$wpdb->insert(
			$table,
			array(
				'license_id'   => 7,
				'domain'       => 'a.example',
				'live_domain'  => 'a.example',
				'activated_at' => $now,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'license_id'     => 7,
				'domain'         => 'b.example',
				'live_domain'    => null,
				'activated_at'   => $now,
				'deactivated_at' => $now,
			)
		);
		update_option( Install::OPTION_DB_VERSION, '2' );

		Install::maybe_upgrade();

		$kinds = $wpdb->get_col( "SELECT kind FROM $table ORDER BY id" );
		$this->assertSame( array( 'site', 'site' ), $kinds );

		$index = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'live'", ARRAY_A );
		$this->assertSame( array( 'license_id', 'kind', 'live_domain' ), wp_list_pluck( $index, 'Column_name' ) );
		$this->assertSame( '3', get_option( Install::OPTION_DB_VERSION ) );

		$before = $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A );
		Install::maybe_upgrade();
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A ) );
		// phpcs:enable
	}

	public function test_free_this_site_cannot_close_a_resource(): void {
		$license = $this->license();
		$this->insert_resource( (int) $license['id'], 'vm-1' );

		$result = Licenses::deactivate( $license, 'vm-1' );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'not_activated', $result['code'] );
		$this->assertCount( 1, Licenses::live_activations( (int) $license['id'] ) );
		$this->assertFalse( Licenses::is_live_on( (int) $license['id'], 'vm-1' ) );
	}

	public function test_limit_counts_every_kind(): void {
		$license = $this->license( array( 'max_activations' => 1 ) );
		$this->insert_resource( (int) $license['id'], 'vm-1' );

		$result = Licenses::activate( $license, 'a.example' );

		$this->assertSame( 'activation_limit', $result['code'] );
	}

	public function test_live_activations_can_be_filtered_by_kind(): void {
		$license = $this->license( array( 'max_activations' => 3 ) );
		Licenses::activate( $license, 'a.example' );
		$this->insert_resource( (int) $license['id'], 'vm-1' );

		$this->assertCount( 2, Licenses::live_activations( (int) $license['id'] ) );
		$this->assertSame( array( 'a.example' ), wp_list_pluck( Licenses::live_activations( (int) $license['id'], 'site' ), 'domain' ) );
		$this->assertSame( array( 'vm-1' ), wp_list_pluck( Licenses::live_activations( (int) $license['id'], 'vm' ), 'domain' ) );
	}
}
