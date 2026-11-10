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

	public function test_a_visitor_request_asks_action_scheduler_once_per_period(): void {
		global $wpdb;

		Expiry::unschedule();
		delete_option( Expiry::CHECKED );
		set_current_screen( 'front' );

		Expiry::schedule();
		$this->assertTrue( as_has_scheduled_action( Expiry::HOOK ), 'The first request schedules the run.' );

		$before = $wpdb->num_queries;
		Expiry::schedule();
		$this->assertSame( 0, $wpdb->num_queries - $before, 'A second visitor request inside the period asks nothing.' );

		// The run is lost and the period has passed: the next request brings it back.
		as_unschedule_all_actions( Expiry::HOOK, array(), 'beltoft-licenses' );
		as_unschedule_all_actions( Expiry::HOOK );
		Expiry::schedule();
		$this->assertFalse( as_has_scheduled_action( Expiry::HOOK ), 'Inside the period a lost run is not noticed by a visitor request.' );
		update_option( Expiry::CHECKED, time() - Expiry::CHECK_PERIOD - 1 );
		Expiry::schedule();
		$this->assertTrue( as_has_scheduled_action( Expiry::HOOK ) );

		// The admin always checks.
		as_unschedule_all_actions( Expiry::HOOK );
		set_current_screen( 'dashboard' );
		Expiry::schedule();
		$this->assertTrue( as_has_scheduled_action( Expiry::HOOK ), 'An admin request checks every time.' );
		set_current_screen( 'front' );

		Expiry::unschedule();
		$this->assertFalse( get_option( Expiry::CHECKED ), 'Unscheduling forgets the stamp, so the next request checks.' );
	}

	public function test_an_admin_check_rewrites_the_stamp_only_every_five_minutes(): void {
		Expiry::unschedule();
		set_current_screen( 'dashboard' );

		$writes = 0;
		$count  = static function ( $name ) use ( &$writes ) {
			if ( Expiry::CHECKED === $name ) {
				++$writes;
			}
		};
		add_action( 'added_option', $count );
		add_action( 'updated_option', $count );

		try {
			Expiry::schedule();
			$this->assertSame( 1, $writes, 'The first check stores the stamp.' );

			// A minute ago: the time would differ, so every admin, cron and CLI request would UPDATE the option.
			update_option( Expiry::CHECKED, time() - 60 );
			$writes = 0;
			as_unschedule_all_actions( Expiry::HOOK );
			Expiry::schedule();
			Expiry::schedule();
			$this->assertSame( 0, $writes, 'No rewrite inside five minutes.' );
			$this->assertEqualsWithDelta( time() - 60, (int) get_option( Expiry::CHECKED ), 2, 'The stored time is left alone.' );
			$this->assertTrue( as_has_scheduled_action( Expiry::HOOK ), 'The check itself still ran.' );

			update_option( Expiry::CHECKED, time() - 301 );
			$writes = 0;
			Expiry::schedule();
			$this->assertSame( 1, $writes, 'Older than five minutes: stamped again.' );
			$this->assertEqualsWithDelta( time(), (int) get_option( Expiry::CHECKED ), 2 );
		} finally {
			remove_action( 'added_option', $count );
			remove_action( 'updated_option', $count );
			set_current_screen( 'front' );
		}
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
