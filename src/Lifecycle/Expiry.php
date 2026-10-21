<?php
/**
 * The daily expiry run.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Lifecycle;

use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;

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
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'settings_updated' ), 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, array( __CLASS__, 'settings_added' ), 10, 2 );
	}

	/**
	 * The cron expression: hourly when the setting is on, else daily at 02:00.
	 */
	public static function cron(): string {
		return Settings::get( 'expiry_hourly' ) ? '0 * * * *' : '0 2 * * *';
	}

	/**
	 * Reschedules when the hourly setting changed.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public static function settings_updated( $old_value, $value ): void {
		$was = is_array( $old_value ) && ! empty( $old_value['expiry_hourly'] );
		$is  = is_array( $value ) && ! empty( $value['expiry_hourly'] );
		if ( $was !== $is ) {
			self::unschedule();
			self::schedule();
		}
	}

	/**
	 * Reschedules when the settings are first saved with the hourly flag on.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New settings.
	 */
	public static function settings_added( $option, $value ): void {
		self::settings_updated( array(), $value );
	}

	/**
	 * Schedules the run (daily at 02:00 site time, or hourly by setting), once.
	 */
	public static function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
			as_schedule_cron_action( time(), self::cron(), self::HOOK, array(), self::GROUP );
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
