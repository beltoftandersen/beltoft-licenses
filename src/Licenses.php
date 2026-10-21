<?php
/**
 * License and activation storage.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes licenses and their site activations.
 *
 * Licenses are returned as arrays with typed values. Dates are stored in UTC as MySQL datetimes.
 */
final class Licenses {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_REVOKED   = 'revoked';
	public const STATUS_SUSPENDED = 'suspended';

	public const STATUSES = array( self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_REVOKED, self::STATUS_SUSPENDED );

	/**
	 * Creates a license. A key is generated unless one is given; a generated key that collides
	 * with an existing one is regenerated.
	 *
	 * @param array<string, mixed> $data Columns.
	 * @return array<string, mixed>|null The new license, or null on failure.
	 */
	public static function create( array $data ): ?array {
		global $wpdb;

		$now  = gmdate( 'Y-m-d H:i:s' );
		$row  = array(
			'license_key'     => isset( $data['license_key'] ) ? Key::normalize( $data['license_key'] ) : '',
			'status'          => in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : self::STATUS_ACTIVE,
			'max_activations' => isset( $data['max_activations'] ) ? max( 0, (int) $data['max_activations'] ) : 1,
			'expires_at'      => self::to_db_date( $data['expires_at'] ?? null ),
			'product_slug'    => sanitize_title( (string) ( $data['product_slug'] ?? '' ) ),
			'user_id'         => isset( $data['user_id'] ) ? (int) $data['user_id'] : null,
			'email'           => sanitize_email( (string) ( $data['email'] ?? '' ) ),
			'product_id'      => isset( $data['product_id'] ) ? (int) $data['product_id'] : null,
			'variation_id'    => isset( $data['variation_id'] ) ? (int) $data['variation_id'] : null,
			'order_id'        => isset( $data['order_id'] ) ? (int) $data['order_id'] : null,
			'subscription_id' => isset( $data['subscription_id'] ) ? (int) $data['subscription_id'] : null,
			'source'          => sanitize_key( (string) ( $data['source'] ?? 'manual' ) ),
			'external_id'     => isset( $data['external_id'] ) ? (string) $data['external_id'] : null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		$generate = '' === $row['license_key'];

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			if ( $generate ) {
				$row['license_key'] = Key::generate();
			}

			// A duplicate key is an expected outcome here, not an error to print.
			$suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
			$inserted = $wpdb->insert( Install::tables()['licenses'], $row );
			$wpdb->suppress_errors( $suppress );

			if ( false !== $inserted ) {
				$license = self::find( (int) $wpdb->insert_id );

				if ( $license ) {
					do_action( 'beltoft_licenses_license_created', $license );
				}

				return $license;
			}

			if ( ! $generate ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * A license by ID.
	 *
	 * @param int $id License ID.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read once per request.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * A license by key. The key is normalised first.
	 *
	 * @param mixed $key Raw key.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_key( $key ): ?array {
		global $wpdb;

		$key = Key::normalize( $key );

		if ( '' === $key ) {
			return null;
		}

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read once per request.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_key = %s', $table, $key ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Updates columns of one license.
	 *
	 * @param int                  $id   License ID.
	 * @param array<string, mixed> $data Columns to change.
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$before  = self::find( $id );
		$allowed = array( 'status', 'max_activations', 'expires_at', 'product_slug', 'user_id', 'email', 'product_id', 'variation_id', 'order_id', 'subscription_id' );
		$row     = array_intersect_key( $data, array_flip( $allowed ) );

		if ( ! $before || ! $row ) {
			return false;
		}

		if ( array_key_exists( 'expires_at', $row ) ) {
			$row['expires_at'] = self::to_db_date( $row['expires_at'] );
		}

		if ( isset( $row['status'] ) && ! in_array( $row['status'], self::STATUSES, true ) ) {
			return false;
		}

		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$ok    = false !== $wpdb->update( Install::tables()['licenses'], $row, array( 'id' => $id ) );
		$after = self::find( $id );

		if ( $ok && $after ) {
			if ( $before['status'] !== $after['status'] ) {
				do_action( 'beltoft_licenses_status_changed', $after, $before['status'] );
			}
			if ( $before['expires_at'] !== $after['expires_at'] ) {
				do_action( 'beltoft_licenses_expiry_changed', $after, $before['expires_at'] );
			}
		}

		return $ok;
	}

	/**
	 * The status that applies right now: an active license past its expiry is expired,
	 * whether or not the daily job has run yet.
	 *
	 * @param array<string, mixed> $license License.
	 */
	public static function effective_status( array $license ): string {
		if ( self::STATUS_ACTIVE === $license['status'] && self::is_past( $license['expires_at'] ) ) {
			return self::STATUS_EXPIRED;
		}

		return $license['status'];
	}

	/**
	 * Is the license's expiry date in the past?
	 *
	 * @param string|null $expires_at MySQL datetime in UTC, or null.
	 */
	public static function is_past( ?string $expires_at ): bool {
		return null !== $expires_at && strtotime( $expires_at . ' UTC' ) < time();
	}

	/**
	 * Live activations of a license.
	 *
	 * @param int $license_id License ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function live_activations( int $license_id ): array {
		global $wpdb;

		$table = Install::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND deactivated_at IS NULL ORDER BY activated_at ASC', $table, $license_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Is the license live on this domain? The domain must already be normalised.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function is_live_on( int $license_id, string $domain ): bool {
		global $wpdb;

		$table = Install::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE license_id = %d AND live_domain = %s', $table, $license_id, $domain ) );
	}

	/**
	 * Activates a license on a domain.
	 *
	 * The order of checks, and the messages, are those of the license backend this plugin can
	 * stand in for, because plugins in the field show them to their users.
	 *
	 * @param array<string, mixed> $license License.
	 * @param string               $domain  Normalised domain.
	 * @return array{success:bool, message:string, code:string}
	 */
	public static function activate( array $license, string $domain ): array {
		global $wpdb;

		if ( self::STATUS_ACTIVE !== $license['status'] ) {
			return array(
				'success' => false,
				'code'    => 'license_not_active',
				/* translators: %s: license status. Kept in English by default because API clients may compare it. */
				'message' => sprintf( 'License is %s', $license['status'] ),
			);
		}

		if ( self::is_past( $license['expires_at'] ) ) {
			return array(
				'success' => false,
				'code'    => 'license_expired',
				'message' => 'License has expired',
			);
		}

		if ( self::is_live_on( (int) $license['id'], $domain ) ) {
			return array(
				'success' => true,
				'code'    => 'already_active',
				'message' => 'Domain already activated',
			);
		}

		if ( $license['max_activations'] > 0 && count( self::live_activations( (int) $license['id'] ) ) >= $license['max_activations'] ) {
			return array(
				'success' => false,
				'code'    => 'activation_limit',
				'message' => sprintf( 'Maximum activations reached (%d)', $license['max_activations'] ),
			);
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$inserted = $wpdb->insert(
			Install::tables()['activations'],
			array(
				'license_id'   => (int) $license['id'],
				'domain'       => $domain,
				'live_domain'  => $domain,
				'activated_at' => $now,
			)
		);
		$wpdb->suppress_errors( $suppress );

		// Two requests for the same site at once: the unique key stops the second, which then
		// counts as already active.
		if ( false === $inserted ) {
			return array(
				'success' => true,
				'code'    => 'already_active',
				'message' => 'Domain already activated',
			);
		}

		do_action( 'beltoft_licenses_activated', $license, $domain );

		return array(
			'success' => true,
			'code'    => 'activated',
			'message' => 'Activation successful',
		);
	}

	/**
	 * Deactivates a license on a domain, keeping the row as history.
	 *
	 * @param array<string, mixed> $license License.
	 * @param string               $domain  Normalised domain.
	 * @return array{success:bool, message:string, code:string}
	 */
	public static function deactivate( array $license, string $domain ): array {
		global $wpdb;

		$table = Install::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$changed = $wpdb->update(
			$table,
			array(
				'deactivated_at' => gmdate( 'Y-m-d H:i:s' ),
				'live_domain'    => null,
			),
			array(
				'license_id'  => (int) $license['id'],
				'live_domain' => $domain,
			)
		);

		if ( ! $changed ) {
			return array(
				'success' => false,
				'code'    => 'not_activated',
				'message' => 'No active activation found for this domain',
			);
		}

		do_action( 'beltoft_licenses_deactivated', $license, $domain );

		return array(
			'success' => true,
			'code'    => 'deactivated',
			'message' => 'Deactivation successful',
		);
	}

	/**
	 * ISO 8601 in UTC with milliseconds, as JavaScript's Date#toJSON writes it, or null.
	 *
	 * @param string|null $db_date MySQL datetime in UTC.
	 */
	public static function iso( ?string $db_date ): ?string {
		return null === $db_date ? null : gmdate( 'Y-m-d\TH:i:s.000\Z', (int) strtotime( $db_date . ' UTC' ) );
	}

	/**
	 * Typed values from a database row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		foreach ( array( 'id', 'max_activations' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}

		foreach ( array( 'user_id', 'product_id', 'variation_id', 'order_id', 'subscription_id' ) as $nullable ) {
			$row[ $nullable ] = null === $row[ $nullable ] ? null : (int) $row[ $nullable ];
		}

		return $row;
	}

	/**
	 * A date for the database: accepts null, a timestamp, or any string strtotime() reads.
	 *
	 * @param mixed $value Date.
	 */
	private static function to_db_date( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );

		return false === $time ? null : gmdate( 'Y-m-d H:i:s', $time );
	}
}
