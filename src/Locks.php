<?php
/**
 * MySQL named locks around license issuance.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Issuing a license is "check whether it exists, then create": two requests doing it at the
 * same moment (a payment webhook and the thank-you page, say) would both see nothing and both
 * create. A named lock per order item or subscription makes the pair atomic.
 *
 * MySQL lock names are global to the database server, so every name carries a short hash of
 * this site's database name and table prefix.
 */
final class Locks {

	/**
	 * The lock name for one thing of this site: bl_<site hash>_<scope>_<id> (at most 64 chars).
	 *
	 * @param string $scope What is locked, e.g. 'item' or 'sub'.
	 * @param int    $id    Its id.
	 */
	public static function name( string $scope, int $id ): string {
		global $wpdb;

		return 'bl_' . substr( md5( DB_NAME . '|' . $wpdb->prefix ), 0, 10 ) . '_' . $scope . '_' . $id;
	}

	/**
	 * Seconds to wait for a lock before giving up and retrying later (default 3), so a
	 * checkout request never waits long.
	 */
	public static function wait(): int {
		/**
		 * Filters how long license issuance waits for its lock.
		 *
		 * @param int $seconds Seconds (default 3).
		 */
		return max( 0, (int) apply_filters( 'beltoft_licenses_lock_wait', 3 ) );
	}

	/**
	 * Runs a callback while holding a named lock. The lock is released even when the callback throws.
	 *
	 * @param string   $name     Lock name from name().
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @param callable $callback Work to do.
	 * @return array{0: bool, 1: mixed} Whether the lock was taken, and the callback's result.
	 */
	public static function with( string $name, int $timeout, callable $callback ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock has no cache.
		$got = 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', $name, $timeout ) );

		if ( ! $got ) {
			return array( false, null );
		}

		try {
			return array( true, $callback() );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a named lock has no cache.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $name ) );
		}
	}
}
