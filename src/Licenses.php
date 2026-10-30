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

	/**
	 * How often a meta update reads and merges again when another write landed in between.
	 */
	private const META_TRIES = 10;


	public const STATUS_ACTIVE    = 'active';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_REVOKED   = 'revoked';
	public const STATUS_SUSPENDED = 'suspended';

	public const STATUSES = array( self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_REVOKED, self::STATUS_SUSPENDED );

	/**
	 * Creates a license. A key is generated unless one is given; a generated key that collides
	 * with an existing one is regenerated.
	 *
	 * An `issue_key` marks what the license was issued for (an order item's unit, a subscription
	 * item's unit). It is unique: a second license with the same marker is refused and null is
	 * returned, so find_by_issue_key() then gives the one that already exists.
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
			'issue_key'       => isset( $data['issue_key'] ) && '' !== (string) $data['issue_key'] ? substr( (string) $data['issue_key'], 0, 64 ) : null,
			'created_at'      => $now,
			'updated_at'      => $now,
		);

		// Only sent when given, so a schema 3 table (upgrade not done yet) still accepts every create.
		if ( null === $row['issue_key'] ) {
			unset( $row['issue_key'] );
		}

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

			if ( ! $generate || ( isset( $row['issue_key'] ) && self::find_by_issue_key( $row['issue_key'] ) ) ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * The markers that start with a prefix, e.g. every unit of one subscription item.
	 *
	 * @param string $prefix Marker prefix.
	 * @return array<int, string>
	 */
	public static function issue_keys( string $prefix ): array {
		global $wpdb;

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT issue_key FROM %i WHERE issue_key LIKE %s', $table, $wpdb->esc_like( $prefix ) . '%' ) ) );
	}

	/**
	 * The license issued for a marker (see create()).
	 *
	 * @param string $issue_key Marker.
	 * @return array<string, mixed>|null
	 */
	public static function find_by_issue_key( string $issue_key ): ?array {
		global $wpdb;

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE issue_key = %s', $table, $issue_key ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
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
	 * Licenses matching one column, oldest first.
	 *
	 * @param string     $column order_id, subscription_id, user_id or email.
	 * @param int|string $value  Value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function where( string $column, $value ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'order_id', 'subscription_id', 'user_id', 'email' ), true ) ) {
			return array();
		}

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s ORDER BY id ASC', $table, $column, (string) $value ), ARRAY_A );

		return array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Licenses for the admin list: filtered, searched and paged, newest first.
	 *
	 * The search matches part of a key, an email address or a site.
	 *
	 * @param array{status?:string, product_slug?:string, search?:string, per_page?:int, page?:int} $args Filters.
	 * @return array{0: array<int, array<string, mixed>>, 1: int} Licenses and the total matching.
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;

		$t      = Install::tables();
		$where  = array( '1=1' );
		$params = array( $t['licenses'] );

		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['product_slug'] ) ) {
			$where[]  = 'product_slug = %s';
			$params[] = (string) $args['product_slug'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[]  = '(license_key LIKE %s OR email LIKE %s OR id IN (SELECT license_id FROM %i WHERE domain LIKE %s))';
			$params[] = $like;
			$params[] = $like;
			$params[] = $t['activations'];
			$params[] = $like;
		}

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$sql_from = 'FROM %i WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table; $sql_from holds only fixed SQL and placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql_from", $params ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * $sql_from ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array( array_map( array( __CLASS__, 'hydrate' ), is_array( $rows ) ? $rows : array() ), $total );
	}

	/**
	 * A customer's existing license for a piece of software: by user ID when there is one,
	 * otherwise by email. Used so a free plan is claimed once.
	 *
	 * @param string $slug    Software slug.
	 * @param int    $user_id User ID, or 0 for a guest.
	 * @param string $email   Email.
	 * @return array<string, mixed>|null
	 */
	public static function find_for_customer( string $slug, int $user_id, string $email ): ?array {
		$candidates = $user_id ? self::where( 'user_id', $user_id ) : array();

		if ( ! $candidates && '' !== $email ) {
			$candidates = self::where( 'email', sanitize_email( $email ) );
		}

		foreach ( $candidates as $license ) {
			if ( $license['product_slug'] === $slug && self::STATUS_REVOKED !== $license['status'] ) {
				return $license;
			}
		}

		return null;
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

			$changed = array_values( array_filter( $allowed, static fn( $column ) => array_key_exists( $column, $row ) && $before[ $column ] !== $after[ $column ] ) );

			if ( $changed ) {
				/**
				 * Fires once after a license update that changed at least one column.
				 *
				 * @param array<string, mixed> $after   The license after the update.
				 * @param array<string, mixed> $before  The license before the update.
				 * @param string[]             $changed Names of the changed columns, never updated_at.
				 */
				do_action( 'beltoft_licenses_license_updated', $after, $before, $changed );
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
	 * @param int         $license_id License ID.
	 * @param string|null $kind       Only this kind ('site' for licensed sites); null for every kind.
	 * @return array<int, array<string, mixed>>
	 */
	public static function live_activations( int $license_id, ?string $kind = null ): array {
		global $wpdb;

		$table = Install::tables()['activations'];
		if ( null === $kind ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND deactivated_at IS NULL ORDER BY activated_at ASC, id ASC', $table, $license_id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND kind = %s AND deactivated_at IS NULL ORDER BY activated_at ASC, id ASC', $table, $license_id, $kind ), ARRAY_A );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Is the license live on this site? The domain must already be normalised. Only sites count,
	 * not other kinds of activation.
	 *
	 * @param int    $license_id License ID.
	 * @param string $domain     Normalised domain.
	 */
	public static function is_live_on( int $license_id, string $domain ): bool {
		global $wpdb;

		$table = Install::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE license_id = %d AND kind = %s AND live_domain = %s', $table, $license_id, 'site', $domain ) );
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

		$refused = self::gate( $license, self::is_live_on( (int) $license['id'], $domain ), 'Domain already activated' );

		if ( $refused ) {
			return $refused;
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$inserted = $wpdb->insert(
			Install::tables()['activations'],
			array(
				'license_id'   => (int) $license['id'],
				'kind'         => 'site',
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

		$id = (int) $wpdb->insert_id;

		do_action(
			'beltoft_licenses_activated',
			$license,
			$domain,
			self::activation_or(
				$id,
				array(
					'id'           => $id,
					'license_id'   => (int) $license['id'],
					'kind'         => 'site',
					'domain'       => $domain,
					'live_domain'  => $domain,
					'activated_at' => $now,
				)
			)
		);

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

		$row = self::live_row( (int) $license['id'], 'site', $domain );
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$changed = $row ? $wpdb->update(
			Install::tables()['activations'],
			array(
				'deactivated_at' => $now,
				'live_domain'    => null,
			),
			array(
				'id'          => (int) $row['id'],
				'live_domain' => $domain,
			)
		) : 0;

		if ( ! $changed ) {
			return array(
				'success' => false,
				'code'    => 'not_activated',
				'message' => 'No active activation found for this domain',
			);
		}

		do_action( 'beltoft_licenses_deactivated', $license, $domain, self::activation_or( (int) $row['id'], self::closed( $row, $now ) ) );

		return array(
			'success' => true,
			'code'    => 'deactivated',
			'message' => 'Deactivation successful',
		);
	}

	/**
	 * Adds a live resource (a VM, a service account) to a license as an activation of another kind.
	 *
	 * The status, expiry and limit rules, and their messages, are those of activate(). The
	 * identifier is stored as the row's domain, so it is live while that is set.
	 *
	 * @param array<string, mixed> $license    License.
	 * @param string               $kind       Kind, such as 'vm'. Not 'site'.
	 * @param string               $identifier Resource identifier, unique within the license and kind.
	 * @param array<string, mixed> $meta       Stored with the row as JSON.
	 * @return array{success:bool, message:string, code:string, activation_id?:int}
	 */
	public static function add_resource( array $license, string $kind, string $identifier, array $meta = array() ): array {
		global $wpdb;

		if ( ! self::valid_resource( $kind, $identifier ) ) {
			return array(
				'success' => false,
				'code'    => 'invalid',
				'message' => 'Invalid kind or identifier',
			);
		}

		$live    = self::live_row( (int) $license['id'], $kind, $identifier );
		$refused = self::gate( $license, null !== $live, 'Resource already active' );

		if ( $refused ) {
			if ( $live ) {
				$refused['activation_id'] = (int) $live['id'];
			}

			return $refused;
		}

		$row = array(
			'license_id'   => (int) $license['id'],
			'kind'         => $kind,
			'domain'       => $identifier,
			'live_domain'  => $identifier,
			'meta'         => $meta ? wp_json_encode( $meta ) : null,
			'activated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$inserted = $wpdb->insert( Install::tables()['activations'], $row );
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			$live = self::live_row( (int) $license['id'], $kind, $identifier );

			// Any other failure: nothing was stored.
			if ( ! $live ) {
				return array(
					'success' => false,
					'code'    => 'error',
					'message' => 'Resource could not be stored',
				);
			}

			// Two requests for the same resource at once: the unique key stopped the second.
			return array(
				'success'       => true,
				'code'          => 'already_active',
				'message'       => 'Resource already active',
				'activation_id' => (int) $live['id'],
			);
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'beltoft_licenses_activated', $license, $identifier, self::activation_or( $id, array( 'id' => $id ) + $row ) );

		return array(
			'success'       => true,
			'code'          => 'activated',
			'message'       => 'Activation successful',
			'activation_id' => $id,
		);
	}

	/**
	 * Closes a resource, keeping the row as history.
	 *
	 * @param array<string, mixed> $license    License.
	 * @param string               $kind       Kind.
	 * @param string               $identifier Resource identifier.
	 * @return bool False when no such live resource exists.
	 */
	public static function close_resource( array $license, string $kind, string $identifier ): bool {
		global $wpdb;

		if ( ! self::valid_resource( $kind, $identifier ) ) {
			return false;
		}

		$row = self::live_row( (int) $license['id'], $kind, $identifier );

		if ( ! $row ) {
			return false;
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$changed = $wpdb->update(
			Install::tables()['activations'],
			array(
				'deactivated_at' => $now,
				'live_domain'    => null,
			),
			array(
				'id'          => (int) $row['id'],
				'live_domain' => $identifier,
			)
		);

		if ( ! $changed ) {
			return false;
		}

		do_action( 'beltoft_licenses_deactivated', $license, $identifier, self::activation_or( (int) $row['id'], self::closed( $row, $now ) ) );

		return true;
	}

	/**
	 * Merges values into a row's meta and stamps updated_at.
	 *
	 * The write is a compare and swap: it only lands on the meta it was merged with. When another request wrote the
	 * row in between, the meta is read again and merged again, so two writers of different keys never undo each other.
	 *
	 * @param int                  $activation_id Activation ID.
	 * @param array<string, mixed> $meta          Values to merge over the stored ones.
	 */
	public static function update_resource_meta( int $activation_id, array $meta ): bool {
		global $wpdb;

		$table = Install::tables()['activations'];

		for ( $try = 0; $try < self::META_TRIES; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
			$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT meta FROM %i WHERE id = %d', $table, $activation_id ), ARRAY_A );

			if ( ! $stored ) {
				return false;
			}

			$merged = array_merge( self::decode_meta( $stored )['meta'], $meta );
			$json   = $merged ? wp_json_encode( $merged ) : null;

			if ( false === $json ) {
				return false;
			}

			$now  = gmdate( 'Y-m-d H:i:s' );
			$old  = $stored['meta'];
			$set  = null === $json ? 'meta = NULL' : $wpdb->prepare( 'meta = %s', $json );
			$same = null === $old ? 'meta IS NULL' : $wpdb->prepare( 'BINARY meta = BINARY %s', $old );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; $set and $same are prepared above.
			$done = $wpdb->query( $wpdb->prepare( "UPDATE %i SET {$set}, updated_at = %s WHERE id = %d AND {$same}", $table, $now, $activation_id ) );

			if ( false === $done ) {
				return false;
			}

			// 0 rows: either someone wrote in between (merge again) or the row already held exactly this (done).
			if ( $done > 0 || $old === $json ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Live resources of a license: every live activation that is not a site, oldest first.
	 *
	 * @param int    $license_id License ID.
	 * @param string $kind       Only this kind; empty for all.
	 * @return array<int, array<string, mixed>> Rows with meta decoded.
	 */
	public static function resources( int $license_id, string $kind = '' ): array {
		$rows = array_filter(
			self::live_activations( $license_id ),
			static fn( $row ) => 'site' !== $row['kind'] && ( '' === $kind || $kind === $row['kind'] )
		);

		return array_values( array_map( array( __CLASS__, 'decode_meta' ), $rows ) );
	}

	/**
	 * One activation row of any kind, live or closed.
	 *
	 * @param int $activation_id Activation ID.
	 * @return array<string, mixed>|null Row with meta decoded.
	 */
	public static function activation( int $activation_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Install::tables()['activations'], $activation_id ), ARRAY_A );

		return $row ? self::decode_meta( $row ) : null;
	}

	/**
	 * The activation row for an action: read back, or, if it is gone already (another request
	 * removed it), the values written. Listeners always get an array.
	 *
	 * @param int                  $activation_id Activation ID.
	 * @param array<string, mixed> $fallback      The row as written.
	 * @return array<string, mixed> Row with meta decoded.
	 */
	private static function activation_or( int $activation_id, array $fallback ): array {
		$row = self::activation( $activation_id );

		if ( $row ) {
			return $row;
		}

		$fallback += array(
			'deactivated_at' => null,
			'meta'           => null,
			'updated_at'     => null,
		);

		return self::decode_meta( $fallback );
	}

	/**
	 * A live row as it is once closed.
	 *
	 * @param array<string, mixed> $row Row.
	 * @param string               $now Closing time.
	 * @return array<string, mixed>
	 */
	private static function closed( array $row, string $now ): array {
		$row['live_domain']    = null;
		$row['deactivated_at'] = $now;

		return $row;
	}

	/**
	 * The live activation of one kind and identifier, or null.
	 *
	 * @param int    $license_id License ID.
	 * @param string $kind       Kind.
	 * @param string $identifier Domain or resource identifier.
	 * @return array<string, mixed>|null
	 */
	private static function live_row( int $license_id, string $kind, string $identifier ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND kind = %s AND live_domain = %s', Install::tables()['activations'], $license_id, $kind, $identifier ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * May this kind and identifier be stored as a resource?
	 *
	 * @param string $kind       Kind.
	 * @param string $identifier Identifier.
	 */
	private static function valid_resource( string $kind, string $identifier ): bool {
		return 'site' !== $kind && 1 === preg_match( '/^[a-z0-9_-]{1,20}$/', $kind ) && 1 === preg_match( '/^[A-Za-z0-9._:-]{1,180}$/', $identifier );
	}

	/**
	 * The checks every new activation passes, in the order the Medusa contract fixes: status,
	 * expiry, already live, limit (counting every kind).
	 *
	 * @param array<string, mixed> $license         License.
	 * @param bool                 $already_live    Is this domain or resource already live?
	 * @param string               $already_message Message for the already-live outcome.
	 * @return array{success:bool, message:string, code:string}|null A result to return, or null to go ahead.
	 */
	private static function gate( array $license, bool $already_live, string $already_message ): ?array {
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

		if ( $already_live ) {
			return array(
				'success' => true,
				'code'    => 'already_active',
				'message' => $already_message,
			);
		}

		if ( $license['max_activations'] > 0 && count( self::live_activations( (int) $license['id'] ) ) >= $license['max_activations'] ) {
			return array(
				'success' => false,
				'code'    => 'activation_limit',
				'message' => sprintf( 'Maximum activations reached (%d)', $license['max_activations'] ),
			);
		}

		return null;
	}

	/**
	 * Decodes an activation row's meta JSON to an array (empty when none).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function decode_meta( array $row ): array {
		$decoded     = isset( $row['meta'] ) ? json_decode( (string) $row['meta'], true ) : null;
		$row['meta'] = is_array( $decoded ) ? $decoded : array();

		return $row;
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
	public static function hydrate( array $row ): array {
		// An internal marker, not part of a license's public shape.
		unset( $row['issue_key'] );

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
