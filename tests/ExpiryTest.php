<?php
/**
 * The expiry run: daily, or hourly when the setting is on.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;
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

	public function test_cron_follows_the_setting(): void {
		update_option( Settings::OPTION, array( 'expiry_hourly' => 0 ) );
		$this->assertSame( '0 2 * * *', Expiry::cron() );
		update_option( Settings::OPTION, array( 'expiry_hourly' => 1 ) );
		$this->assertSame( '0 * * * *', Expiry::cron() );
	}

	public function test_toggling_leaves_exactly_one_schedule(): void {
		Expiry::unschedule();
		update_option( Settings::OPTION, array( 'expiry_hourly' => 0 ) );
		Expiry::schedule();
		foreach ( array( 1, 0, 1 ) as $on ) {
			update_option( Settings::OPTION, array( 'expiry_hourly' => $on ) );
			$ids = as_get_scheduled_actions( array( 'hook' => Expiry::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
			$this->assertCount( 1, $ids );
		}
		$action = \ActionScheduler::store()->fetch_action( $ids[0] );
		$this->assertSame( '0 * * * *', $action->get_schedule()->get_recurrence() );

		// An unrelated change leaves the schedule alone.
		update_option( Settings::OPTION, array( 'expiry_hourly' => 1, 'grace_days' => 5 ) );
		$this->assertSame( $ids, as_get_scheduled_actions( array( 'hook' => Expiry::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
		Expiry::unschedule();
	}

	public function test_first_save_with_hourly_on_reschedules(): void {
		Expiry::unschedule();
		delete_option( Settings::OPTION );
		Expiry::schedule();
		add_option( Settings::OPTION, array( 'expiry_hourly' => 1 ) );
		$ids = as_get_scheduled_actions( array( 'hook' => Expiry::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$this->assertCount( 1, $ids );
		$this->assertSame( '0 * * * *', \ActionScheduler::store()->fetch_action( $ids[0] )->get_schedule()->get_recurrence() );
		Expiry::unschedule();
	}
}
