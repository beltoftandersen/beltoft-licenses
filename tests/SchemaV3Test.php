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

	/**
	 * Puts the schema back. The test suite turns CREATE TABLE into CREATE TEMPORARY TABLE, so
	 * the tables built here are temporary copies that hide the real one, and the ALTER TABLE
	 * statements of an upgrade commit the transaction (with the db_version written before them).
	 */
	public function tear_down(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . Install::tables()['activations'] );

		parent::tear_down();

		update_option( Install::OPTION_DB_VERSION, Install::DB_VERSION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- keeps the restored version past the rollback.
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Builds the activations table as it was in schema 2 (or with another `live` key), with one
	 * live and one closed site, and stores the given schema version.
	 *
	 * @param string $version  Stored schema version.
	 * @param string $live_key Definition of the unique key `live`.
	 * @param string $columns  Extra columns.
	 */
	private function old_table( string $version = '2', string $live_key = 'license_id,live_domain(191)', string $columns = '' ): void {
		global $wpdb;

		$table = Install::tables()['activations'];
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS $table" );
		$wpdb->query(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				domain varchar(255) NOT NULL,
				$columns
				live_domain varchar(255) NULL DEFAULT NULL,
				activated_at datetime NOT NULL,
				deactivated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY live ($live_key),
				KEY license_id (license_id),
				KEY domain (domain(191))
			)"
		);
		// phpcs:enable
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
		update_option( Install::OPTION_DB_VERSION, $version );
	}

	/**
	 * The unique key `live`: column => prefix length.
	 *
	 * @return array<string, string|null>
	 */
	private function live_key(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test schema.
		$index = $wpdb->get_results( 'SHOW INDEX FROM ' . Install::tables()['activations'] . " WHERE Key_name = 'live'", ARRAY_A );

		return array_combine( wp_list_pluck( $index, 'Column_name' ), wp_list_pluck( $index, 'Sub_part' ) );
	}

	public function test_upgrade_from_v2_keeps_rows_and_swaps_the_unique_key(): void {
		global $wpdb;

		$table = Install::tables()['activations'];
		$this->old_table();

		Install::maybe_upgrade();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$kinds = $wpdb->get_col( "SELECT kind FROM $table ORDER BY id" );
		$this->assertSame( array( 'site', 'site' ), $kinds );

		$this->assertSame( array( 'license_id', 'kind', 'live_domain' ), array_keys( $this->live_key() ) );
		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION_DB_VERSION ) );

		$before = $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A );
		Install::maybe_upgrade();
		$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM $table ORDER BY id", ARRAY_A ) );
		// phpcs:enable
	}

	public function test_activating_over_a_v2_table_swaps_the_unique_key(): void {
		$this->old_table();

		ob_start();
		Install::activate();
		Install::maybe_upgrade();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output, 'activation prints nothing' );
		$this->assertSame(
			array(
				'license_id'  => null,
				'kind'        => null,
				'live_domain' => '191',
			),
			$this->live_key()
		);
		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION_DB_VERSION ) );

		// A site and a resource with the same text are both live.
		$license = $this->license();
		Licenses::activate( $license, 'vm-12' );
		$added = Licenses::add_resource( $license, 'vm', 'vm-12' );
		$this->assertSame( 'activated', $added['code'] );
		$this->assertGreaterThan( 0, $added['activation_id'] );
		$this->assertCount( 2, Licenses::live_activations( (int) $license['id'] ) );
	}

	public function test_a_key_with_a_shorter_prefix_is_rebuilt(): void {
		$this->old_table( '2', 'license_id,kind,live_domain(180)', "kind varchar(20) NOT NULL DEFAULT 'site'," );

		Install::activate();

		$this->assertSame( '191', $this->live_key()['live_domain'] );
		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION_DB_VERSION ) );
	}

	public function test_the_version_waits_until_the_new_key_exists(): void {
		$this->old_table();
		$break = static function ( $query ) {
			return false !== stripos( $query, 'ADD UNIQUE KEY' ) ? 'SELECT * FROM zz_no_such_table' : $query;
		};
		add_filter( 'query', $break );

		ob_start();
		Install::activate();
		$output = (string) ob_get_clean();

		remove_filter( 'query', $break );

		$this->assertSame( '', $output, 'a failed key prints nothing' );
		$this->assertSame( array(), $this->live_key() );
		$this->assertSame( '2', get_option( Install::OPTION_DB_VERSION ), 'not marked as upgraded' );

		Install::maybe_upgrade();

		$this->assertSame( array( 'license_id', 'kind', 'live_domain' ), array_keys( $this->live_key() ) );
		$this->assertSame( Install::DB_VERSION, get_option( Install::OPTION_DB_VERSION ) );
	}

	public function test_rest_deactivate_cannot_close_a_resource(): void {
		$license = $this->license();
		$this->insert_resource( (int) $license['id'], 'vm-1' );

		$response = $this->api(
			'POST',
			'deactivate',
			array(
				'license_key' => $license['license_key'],
				'domain'      => 'vm-1',
			)
		);

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertCount( 1, Licenses::resources( (int) $license['id'], 'vm' ) );
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
