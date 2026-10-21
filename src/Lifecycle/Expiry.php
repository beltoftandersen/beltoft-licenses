<?php
/**
 * The daily expiry run.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Lifecycle;

use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Marks active licenses past their expiry date as expired, once a day, so lists and
 * reports show the real state. The API does not wait for this: it checks the date on
 * every request.
 */
final class Expiry {

	public const HOOK  = 'beltoft_licenses_expire';
	public const GROUP = 'beltoft-licenses';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules the daily run at 02:00 site time, once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_cron_action( time(), '0 2 * * *', self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Removes the schedule (plugin deactivation).
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Expires what is due. Goes through Licenses::update so status-change actions fire.
	 *
	 * @return int Licenses expired.
	 */
	public static function run(): int {
		global $wpdb;

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, batch job.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE status = %s AND expires_at IS NOT NULL AND expires_at < %s', $table, Licenses::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ) ) );

		foreach ( $ids as $id ) {
			Licenses::update( (int) $id, array( 'status' => Licenses::STATUS_EXPIRED ) );
		}

		return count( $ids );
	}
}
