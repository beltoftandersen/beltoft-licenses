<?php
/**
 * The daily expiry run.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Lifecycle\Expiry;

class ExpiryTest extends TestCase {

	public function test_run_expires_only_what_is_due(): void {
		$due     = $this->license( array( 'expires_at' => time() - 60 ) );
		$future  = $this->license( array( 'expires_at' => time() + 3600 ) );
		$never   = $this->license();
		$revoked = $this->license( array( 'status' => 'revoked', 'expires_at' => time() - 60 ) );
		$before  = did_action( 'beltoft_licenses_status_changed' );

		$this->assertSame( 1, Expiry::run() );
		$this->assertSame( 'expired', Licenses::find( $due['id'] )['status'] );
		$this->assertSame( 'active', Licenses::find( $future['id'] )['status'] );
		$this->assertSame( 'active', Licenses::find( $never['id'] )['status'] );
		$this->assertSame( 'revoked', Licenses::find( $revoked['id'] )['status'] );
		$this->assertSame( $before + 1, did_action( 'beltoft_licenses_status_changed' ) );
		$this->assertSame( 0, Expiry::run() );
	}

	public function test_daily_schedule_is_registered_once(): void {
		Expiry::schedule();
		Expiry::schedule();
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => Expiry::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		Expiry::unschedule();
		$this->assertFalse( as_has_scheduled_action( Expiry::HOOK ) );
	}
}
