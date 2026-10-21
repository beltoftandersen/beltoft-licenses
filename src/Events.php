<?php
/**
 * License history.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * One row per thing that happened to a license, written from the license's own actions so
 * nothing has to remember to log. Plugins built on top of this one call Events::add() for
 * their own events (a service provisioned, a VM suspended) against the same license.
 */
final class Events {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'beltoft_licenses_license_created', array( __CLASS__, 'on_created' ) );
		add_action( 'beltoft_licenses_status_changed', array( __CLASS__, 'on_status' ), 10, 2 );
		add_action( 'beltoft_licenses_expiry_changed', array( __CLASS__, 'on_expiry' ), 10, 2 );
		add_action( 'beltoft_licenses_activated', array( __CLASS__, 'on_activated' ), 10, 3 );
		add_action( 'beltoft_licenses_deactivated', array( __CLASS__, 'on_deactivated' ), 10, 3 );
	}

	/**
	 * Records an event.
	 *
	 * @param int    $license_id License ID.
	 * @param string $type       Short machine name, e.g. status, activated, vm_suspended.
	 * @param string $message    What happened, for people.
	 */
	public static function add( int $license_id, string $type, string $message ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$wpdb->insert(
			Install::tables()['events'],
			array(
				'license_id' => $license_id,
				'type'       => sanitize_key( $type ),
				'message'    => wp_strip_all_tags( $message ),
				'user_id'    => get_current_user_id() ? get_current_user_id() : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * A license's history, newest first.
	 *
	 * @param int $license_id License ID.
	 * @param int $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_license( int $license_id, int $limit = 100 ): array {
		global $wpdb;

		$table = Install::tables()['events'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, admin screen.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d ORDER BY id DESC LIMIT %d', $table, $license_id, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Action handler.
	 *
	 * @param array<string, mixed> $license License.
	 */
	public static function on_created( array $license ): void {
		/* translators: 1: source (order, free, manual, import), 2: number of sites. */
		self::add( (int) $license['id'], 'created', sprintf( __( 'Created (%1$s), %2$s sites.', 'beltoft-licenses' ), $license['source'], self::sites( (int) $license['max_activations'] ) ) );
	}

	/**
	 * Action handler.
	 *
	 * @param array<string, mixed> $license License after the change.
	 * @param string               $old     Previous status.
	 */
	public static function on_status( array $license, string $old ): void {
		/* translators: 1: previous status, 2: new status. */
		self::add( (int) $license['id'], 'status', sprintf( __( 'Status %1$s → %2$s.', 'beltoft-licenses' ), $old, $license['status'] ) );
	}

	/**
	 * Action handler.
	 *
	 * @param array<string, mixed> $license License after the change.
	 */
	public static function on_expiry( array $license ): void {
		self::add(
			(int) $license['id'],
			'expiry',
			null === $license['expires_at']
				? __( 'Expiry cleared.', 'beltoft-licenses' )
				/* translators: %s: date and time in UTC. */
				: sprintf( __( 'Expires %s UTC.', 'beltoft-licenses' ), $license['expires_at'] )
		);
	}

	/**
	 * Action handler.
	 *
	 * @param array<string, mixed> $license    License.
	 * @param string               $domain     Site.
	 * @param array<string, mixed> $activation The activation row. Resources write their own history.
	 */
	public static function on_activated( array $license, string $domain, array $activation = array() ): void {
		if ( isset( $activation['kind'] ) && 'site' !== $activation['kind'] ) {
			return;
		}

		/* translators: %s: site address. */
		self::add( (int) $license['id'], 'activated', sprintf( __( 'Activated on %s.', 'beltoft-licenses' ), $domain ) );
	}

	/**
	 * Action handler.
	 *
	 * @param array<string, mixed> $license    License.
	 * @param string               $domain     Site.
	 * @param array<string, mixed> $activation The activation row. Resources write their own history.
	 */
	public static function on_deactivated( array $license, string $domain, array $activation = array() ): void {
		if ( isset( $activation['kind'] ) && 'site' !== $activation['kind'] ) {
			return;
		}

		/* translators: %s: site address. */
		self::add( (int) $license['id'], 'deactivated', sprintf( __( 'Deactivated on %s.', 'beltoft-licenses' ), $domain ) );
	}

	/**
	 * "3" or "unlimited".
	 *
	 * @param int $max Site limit.
	 */
	private static function sites( int $max ): string {
		return 0 === $max ? __( 'unlimited', 'beltoft-licenses' ) : (string) $max;
	}
}
