<?php
/**
 * License storage and activation rules.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;

class LicensesTest extends TestCase {

	public function test_create_generates_a_unique_key(): void {
		$a = $this->license();
		$b = $this->license();
		$this->assertNotSame( $a['license_key'], $b['license_key'] );
		$this->assertSame( 'active', $a['status'] );
		$this->assertSame( $a, Licenses::find_by_key( strtolower( ' ' . $a['license_key'] . ' ' ) ) );
	}

	public function test_given_key_is_kept_and_duplicates_refused(): void {
		$a = $this->license( array( 'license_key' => 'bltft-aaaa-bbbb-cccc-dddd' ) );
		$this->assertSame( 'BLTFT-AAAA-BBBB-CCCC-DDDD', $a['license_key'] );
		$this->assertNull( Licenses::create( array( 'license_key' => 'BLTFT-AAAA-BBBB-CCCC-DDDD' ) ) );
	}

	public function test_activation_limit_and_already_active(): void {
		$l = $this->license( array( 'max_activations' => 1 ) );
		$this->assertSame( 'activated', Licenses::activate( $l, 'a.example' )['code'] );
		$this->assertSame( 'already_active', Licenses::activate( $l, 'a.example' )['code'] );
		$this->assertSame( 'Maximum activations reached (1)', Licenses::activate( $l, 'b.example' )['message'] );
	}

	public function test_zero_means_unlimited(): void {
		$l = $this->license( array( 'max_activations' => 0 ) );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Licenses::activate( $l, "s$i.example" )['success'] );
		}
		$this->assertCount( 5, Licenses::live_activations( $l['id'] ) );
	}

	public function test_deactivate_frees_a_slot_and_allows_reactivation(): void {
		$l = $this->license( array( 'max_activations' => 1 ) );
		Licenses::activate( $l, 'a.example' );
		$this->assertSame( 'Deactivation successful', Licenses::deactivate( $l, 'a.example' )['message'] );
		$this->assertSame( 'No active activation found for this domain', Licenses::deactivate( $l, 'a.example' )['message'] );
		$this->assertTrue( Licenses::activate( $l, 'b.example' )['success'] );
		Licenses::deactivate( $l, 'b.example' );
		$this->assertSame( 'activated', Licenses::activate( $l, 'a.example' )['code'] );
	}

	public function test_inactive_and_expired_cannot_activate(): void {
		$revoked = $this->license( array( 'status' => 'revoked' ) );
		$this->assertSame( 'License is revoked', Licenses::activate( $revoked, 'a.example' )['message'] );

		$expired = $this->license( array( 'expires_at' => time() - 60 ) );
		$this->assertSame( 'License has expired', Licenses::activate( $expired, 'a.example' )['message'] );
		$this->assertSame( 'expired', Licenses::effective_status( $expired ) );
	}

	public function test_update_fires_status_and_expiry_actions(): void {
		$l      = $this->license();
		$status = did_action( 'beltoft_licenses_status_changed' );
		$expiry = did_action( 'beltoft_licenses_expiry_changed' );

		Licenses::update( $l['id'], array( 'status' => 'suspended', 'expires_at' => '2030-01-01 00:00:00' ) );

		$this->assertSame( $status + 1, did_action( 'beltoft_licenses_status_changed' ) );
		$this->assertSame( $expiry + 1, did_action( 'beltoft_licenses_expiry_changed' ) );
		$this->assertFalse( Licenses::update( $l['id'], array( 'status' => 'bogus' ) ) );
	}

	public function test_iso_matches_javascript_tojson(): void {
		$this->assertSame( '2030-01-02T03:04:05.000Z', Licenses::iso( '2030-01-02 03:04:05' ) );
		$this->assertNull( Licenses::iso( null ) );
	}
}
