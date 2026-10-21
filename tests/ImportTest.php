<?php
/**
 * Import of licenses, activations and releases.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Events;
use BeltoftLicenses\Import;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Software;

class ImportTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->software( 'my-plugin', '1.0.0' );
	}

	/**
	 * One license entry as an export writes it.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private function entry( array $over = array() ): array {
		return array_merge(
			array(
				'external_id'     => 'lic_01',
				'license_key'     => 'BLTFT-AAAA-0000-1111-2222',
				'product_slug'    => 'my-plugin',
				'email'           => 'old@example.org',
				'status'          => 'active',
				'max_activations' => 3,
				'expires_at'      => '2030-01-01T00:00:00.000Z',
				'created_at'      => '2024-05-06T07:08:09.000Z',
				'activations'     => array(
					array(
						'domain'       => 'https://Shop.example/',
						'activated_at' => '2024-06-01T10:00:00.000Z',
					),
					array(
						'domain'         => 'old.example',
						'activated_at'   => '2024-05-07T10:00:00.000Z',
						'deactivated_at' => '2024-05-20T10:00:00.000Z',
					),
				),
			),
			$over
		);
	}

	public function test_dry_run_reports_and_writes_nothing(): void {
		$report = Import::run( array( 'licenses' => array( $this->entry() ) ), true );

		$this->assertSame( array(), $report['errors'] );
		$this->assertSame( 1, $report['counts']['created'] );
		$this->assertSame( 1, $report['counts']['activations_added'] );
		$this->assertNull( Licenses::find_by_key( 'BLTFT-AAAA-0000-1111-2222' ) );
	}

	public function test_import_keeps_key_dates_and_activations(): void {
		$user   = self::factory()->user->create( array( 'user_email' => 'old@example.org' ) );
		$report = Import::run( array( 'licenses' => array( $this->entry() ) ), false );

		$this->assertSame( array(), $report['errors'] );
		$license = Licenses::find_by_key( 'BLTFT-AAAA-0000-1111-2222' );
		$this->assertSame( 'import', $license['source'] );
		$this->assertSame( 'lic_01', $license['external_id'] );
		$this->assertSame( $user, $license['user_id'], 'matched by email' );
		$this->assertSame( '2024-05-06 07:08:09', $license['created_at'] );
		$this->assertSame( '2030-01-01 00:00:00', $license['expires_at'] );
		$this->assertSame( 3, $license['max_activations'] );

		$live = Licenses::live_activations( $license['id'] );
		$this->assertSame( array( 'shop.example' ), array_column( $live, 'domain' ) );
		$this->assertSame( '2024-06-01 10:00:00', $live[0]['activated_at'] );
		$this->assertFalse( Licenses::is_live_on( $license['id'], 'old.example' ), 'past activation kept as history only' );
		$this->assertContains( 'import', array_column( Events::for_license( $license['id'] ), 'type' ) );
	}

	public function test_import_does_not_trip_the_activation_limit(): void {
		$entry = $this->entry(
			array(
				'max_activations' => 1,
				'activations'     => array(
					array( 'domain' => 'a.example' ),
					array( 'domain' => 'b.example' ),
				),
			)
		);

		Import::run( array( 'licenses' => array( $entry ) ), false );

		$this->assertCount( 2, Licenses::live_activations( Licenses::find_by_key( $entry['license_key'] )['id'] ), 'the file is the truth' );
	}

	public function test_second_run_is_a_delta(): void {
		Import::run( array( 'licenses' => array( $this->entry() ) ), false );

		$again = Import::run( array( 'licenses' => array( $this->entry() ) ), false );
		$this->assertSame( 1, $again['counts']['unchanged'] );
		$this->assertSame( 0, $again['counts']['activations_added'] );

		$changed = $this->entry(
			array(
				'status'      => 'revoked',
				'email'       => 'new@example.org',
				'activations' => array(
					array(
						'domain'         => 'shop.example',
						'deactivated_at' => '2025-01-01T00:00:00Z',
					),
					array( 'domain' => 'new.example' ),
				),
			)
		);
		$report  = Import::run( array( 'licenses' => array( $changed ) ), false );

		$this->assertSame( 1, $report['counts']['updated'] );
		$this->assertSame( 1, $report['counts']['activations_added'] );
		$this->assertSame( 1, $report['counts']['activations_closed'] );

		$license = Licenses::find_by_key( 'BLTFT-AAAA-0000-1111-2222' );
		$this->assertSame( 'revoked', $license['status'] );
		$this->assertSame( 'new@example.org', $license['email'] );
		$this->assertSame( array( 'new.example' ), array_column( Licenses::live_activations( $license['id'] ), 'domain' ) );
		$this->assertSame( 1, Licenses::query()[1], 'still one license' );
	}

	public function test_import_never_reads_or_closes_other_kinds_of_activation(): void {
		global $wpdb;

		Import::run( array( 'licenses' => array( $this->entry( array( 'activations' => array() ) ) ) ), false );
		$license = Licenses::find_by_key( 'BLTFT-AAAA-0000-1111-2222' );
		$wpdb->insert(
			\BeltoftLicenses\Install::tables()['activations'],
			array(
				'license_id'   => $license['id'],
				'kind'         => 'vm',
				'domain'       => 'x',
				'live_domain'  => 'x',
				'activated_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Import::run( array( 'licenses' => array( $this->entry( array( 'activations' => array( array( 'domain' => 'x' ) ) ) ) ) ), false );
		$this->assertSame( array( 'x' ), array_column( Licenses::live_activations( $license['id'], 'site' ), 'domain' ), 'a live site x is added next to the vm' );
		$this->assertCount( 1, Licenses::live_activations( $license['id'], 'vm' ) );

		Import::run( array( 'licenses' => array( $this->entry( array( 'activations' => array( array( 'domain' => 'x', 'deactivated_at' => '2025-01-01T00:00:00Z' ) ) ) ) ) ), false );
		$this->assertSame( array(), Licenses::live_activations( $license['id'], 'site' ), 'the site is closed' );
		$this->assertCount( 1, Licenses::live_activations( $license['id'], 'vm' ), 'the vm stays live' );
	}

	public function test_existing_license_without_external_id_is_matched_by_key_and_tagged(): void {
		$local = $this->license( array( 'license_key' => 'BLTFT-AAAA-0000-1111-2222' ) );

		$report = Import::run( array( 'licenses' => array( $this->entry() ) ), false );

		$this->assertSame( 1, $report['counts']['updated'] );
		$this->assertSame( 'lic_01', Licenses::find( $local['id'] )['external_id'] );
	}

	public function test_any_error_means_nothing_is_written(): void {
		$report = Import::run(
			array(
				'licenses' => array(
					$this->entry(),
					$this->entry( array( 'external_id' => 'lic_02', 'license_key' => 'BLTFT-BBBB-0000-1111-2222', 'product_slug' => 'nope' ) ),
					$this->entry( array( 'external_id' => 'lic_03', 'license_key' => 'BLTFT-AAAA-0000-1111-2222' ) ),
					$this->entry( array( 'external_id' => 'lic_04', 'license_key' => 'BLTFT-CCCC-0000-1111-2222', 'email' => 'nope', 'status' => 'gone', 'expires_at' => 'soonish', 'max_activations' => -1 ) ),
					$this->entry( array( 'external_id' => 'lic_05', 'license_key' => 'BLTFT-DDDD-0000-1111-2222', 'order_id' => 999999, 'activations' => array( array( 'domain' => '' ) ) ) ),
				),
			),
			false
		);

		$text = implode( "\n", $report['errors'] );
		$this->assertStringContainsString( 'licenses[1]: product_slug "nope"', $text );
		$this->assertStringContainsString( 'licenses[2]: license_key BLTFT-AAAA-0000-1111-2222 appears twice', $text );
		$this->assertStringContainsString( 'licenses[3]: email', $text );
		$this->assertStringContainsString( 'licenses[3]: status', $text );
		$this->assertStringContainsString( 'licenses[3].expires_at: not a date', $text );
		$this->assertStringContainsString( 'licenses[3]: max_activations', $text );
		$this->assertStringContainsString( 'licenses[4]: order 999999 does not exist', $text );
		$this->assertStringContainsString( 'licenses[4].activations[0]: domain is required', $text );
		$this->assertSame( 0, Licenses::query()[1] );
	}

	public function test_key_and_external_id_conflicts_are_refused(): void {
		Import::run( array( 'licenses' => array( $this->entry() ) ), false );

		$other_key = Import::run( array( 'licenses' => array( $this->entry( array( 'license_key' => 'BLTFT-EEEE-0000-1111-2222' ) ) ) ), true );
		$this->assertStringContainsString( 'is stored with another key', implode( "\n", $other_key['errors'] ) );

		$other_id = Import::run( array( 'licenses' => array( $this->entry( array( 'external_id' => 'lic_99' ) ) ) ), true );
		$this->assertStringContainsString( 'is stored with another external_id', implode( "\n", $other_id['errors'] ) );
	}

	public function test_is_running_only_while_writing(): void {
		$seen = array();
		add_action(
			'beltoft_licenses_license_created',
			static function () use ( &$seen ) {
				$seen[] = Import::is_running();
			}
		);

		Import::run( array( 'licenses' => array( $this->entry() ) ), false );
		$this->license();

		$this->assertSame( array( true, false ), $seen );
		$this->assertFalse( Import::is_running() );
	}

	public function test_releases_are_published_from_a_copy(): void {
		$dir = get_temp_dir() . 'bl-import-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir );
		copy( ReleasesTest::zip( array( 'my-plugin/my-plugin.php' => '<?php' ) ), $dir . '/my-plugin-2.0.0.zip' );

		$data = array(
			'releases' => array(
				array(
					'product_slug' => 'my-plugin',
					'version'      => 'v2.0.0',
					'file'         => 'my-plugin-2.0.0.zip',
					'changelog'    => 'Two.',
					'current'      => true,
				),
			),
		);

		$this->assertSame( 1, Import::run( $data, true, $dir )['counts']['releases'] );
		$this->assertNull( Software::release( 'my-plugin', '2.0.0' ) );

		$report = Import::run( $data, false, $dir );
		$this->assertSame( array(), $report['errors'] );
		$this->assertSame( 'import', Software::release( 'my-plugin', '2.0.0' )['source'] );
		$this->assertSame( '2.0.0', Software::current_version( 'my-plugin' ) );
		$this->assertFileExists( $dir . '/my-plugin-2.0.0.zip', 'the source file is left in place' );

		$this->assertSame( 0, Import::run( $data, false, $dir )['counts']['releases'], 'already published' );

		$missing = Import::run( array( 'releases' => array( array( 'product_slug' => 'my-plugin', 'version' => '3.0.0', 'file' => 'nope.zip' ) ) ), true, $dir );
		$this->assertStringContainsString( 'file not found', implode( "\n", $missing['errors'] ) );
	}
}
