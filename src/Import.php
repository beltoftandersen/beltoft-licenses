<?php
/**
 * Import of licenses, activations and releases from another system.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

use BeltoftLicenses\Releases\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the import format (README.md, "Importing"), checks all of it, and only then writes.
 *
 * A license is matched by external_id, then by key, so running the same export again updates
 * what changed: a delta import. Keys, creation dates and activation dates are kept.
 *
 * While an import runs, is_running() is true. The license actions still fire, so the history
 * is complete, but plugins that act on them (provisioning, suspending) must do nothing then.
 */
final class Import {

	/**
	 * Is an import writing right now?
	 *
	 * @var bool
	 */
	private static $running = false;

	/**
	 * For listeners to the beltoft_licenses_* actions: skip side effects while this is true.
	 */
	public static function is_running(): bool {
		return self::$running;
	}

	/**
	 * Checks and, unless it is a dry run, applies an import.
	 *
	 * @param array<string, mixed> $data     Decoded JSON: licenses and releases.
	 * @param bool                 $dry_run  Report only.
	 * @param string               $base_dir Directory release files are relative to.
	 * @return array{errors: string[], lines: string[], counts: array<string, int>}
	 */
	public static function run( array $data, bool $dry_run, string $base_dir = '' ): array {
		$report = array(
			'errors' => array(),
			'lines'  => array(),
			'counts' => array(
				'created'            => 0,
				'updated'            => 0,
				'unchanged'          => 0,
				'activations_added'  => 0,
				'activations_closed' => 0,
				'releases'           => 0,
			),
		);

		$licenses = self::check_licenses( isset( $data['licenses'] ) ? $data['licenses'] : array(), $report['errors'] );
		$releases = self::check_releases( isset( $data['releases'] ) ? $data['releases'] : array(), $base_dir, $report['errors'] );

		if ( $report['errors'] ) {
			return $report;
		}

		self::$running = ! $dry_run;

		try {
			foreach ( $licenses as $entry ) {
				self::apply_license( $entry, $dry_run, $report );
			}

			foreach ( $releases as $entry ) {
				self::apply_release( $entry, $dry_run, $report );
			}
		} finally {
			self::$running = false;
		}

		return $report;
	}

	/**
	 * Validates and normalises the license entries.
	 *
	 * @param mixed    $entries Raw entries.
	 * @param string[] $errors  Collected errors.
	 * @return array<int, array<string, mixed>>
	 */
	private static function check_licenses( $entries, array &$errors ): array {
		if ( ! is_array( $entries ) ) {
			$errors[] = 'licenses: must be a list.';
			return array();
		}

		$software = Software::all();
		$seen     = array(
			'key'      => array(),
			'external' => array(),
		);
		$out      = array();

		foreach ( array_values( $entries ) as $i => $raw ) {
			$at = "licenses[$i]";

			if ( ! is_array( $raw ) ) {
				$errors[] = "$at: must be an object.";
				continue;
			}

			$key      = Key::normalize( $raw['license_key'] ?? '' );
			$external = isset( $raw['external_id'] ) && '' !== (string) $raw['external_id'] ? (string) $raw['external_id'] : null;
			$slug     = sanitize_title( (string) ( $raw['product_slug'] ?? '' ) );
			$email    = sanitize_email( (string) ( $raw['email'] ?? '' ) );
			$status   = (string) ( $raw['status'] ?? Licenses::STATUS_ACTIVE );
			$before   = count( $errors );

			if ( '' === $key ) {
				$errors[] = "$at: license_key is required.";
			} elseif ( isset( $seen['key'][ $key ] ) ) {
				$errors[] = "$at: license_key $key appears twice in the file.";
			}

			if ( null !== $external && ( strlen( $external ) > 64 || isset( $seen['external'][ $external ] ) ) ) {
				$errors[] = "$at: external_id must be unique and at most 64 characters.";
			}

			if ( ! isset( $software[ $slug ] ) ) {
				$errors[] = "$at: product_slug \"$slug\" is not the software slug of any product.";
			}

			if ( ! is_email( $email ) ) {
				$errors[] = "$at: email is not a valid email address.";
			}

			if ( ! in_array( $status, Licenses::STATUSES, true ) ) {
				$errors[] = "$at: status must be one of " . implode( ', ', Licenses::STATUSES ) . '.';
			}

			$max = $raw['max_activations'] ?? 1;
			if ( ! is_numeric( $max ) || (int) $max < 0 ) {
				$errors[] = "$at: max_activations must be 0 (unlimited) or more.";
			}

			$expires = self::date( $raw['expires_at'] ?? null, "$at.expires_at", $errors );
			$created = self::date( $raw['created_at'] ?? null, "$at.created_at", $errors );

			$subscription_id = absint( $raw['subscription_id'] ?? 0 );
			if ( $subscription_id && ! ( function_exists( 'wcs_get_subscription' ) && wcs_get_subscription( $subscription_id ) ) ) {
				$errors[] = "$at: subscription $subscription_id does not exist (or WooCommerce Subscriptions is not active).";
			}

			$order_id = absint( $raw['order_id'] ?? 0 );
			if ( $order_id && ! wc_get_order( $order_id ) ) {
				$errors[] = "$at: order $order_id does not exist.";
			}

			$activations = self::check_activations( $raw['activations'] ?? array(), $at, $errors );

			if ( count( $errors ) > $before ) {
				continue;
			}

			$existing = self::existing( $key, $external, $at, $errors );
			if ( count( $errors ) > $before ) {
				continue;
			}

			$seen['key'][ $key ] = true;
			if ( null !== $external ) {
				$seen['external'][ $external ] = true;
			}

			$user  = get_user_by( 'email', $email );
			$out[] = array(
				'existing'    => $existing,
				'external_id' => $external,
				'created_at'  => $created,
				'activations' => $activations,
				'columns'     => array(
					'license_key'     => $key,
					'product_slug'    => $slug,
					'product_id'      => Software::product_id( $slug ),
					'email'           => $email,
					'user_id'         => $user ? (int) $user->ID : null,
					'status'          => $status,
					'max_activations' => (int) $max,
					'expires_at'      => $expires,
					'subscription_id' => $subscription_id ? $subscription_id : null,
					'order_id'        => $order_id ? $order_id : null,
				),
			);
		}

		return $out;
	}

	/**
	 * Validates a license's activations.
	 *
	 * @param mixed    $entries Raw activations.
	 * @param string   $at      Where in the file.
	 * @param string[] $errors  Collected errors.
	 * @return array<int, array{domain: string, activated_at: string|null, deactivated_at: string|null}>
	 */
	private static function check_activations( $entries, string $at, array &$errors ): array {
		if ( ! is_array( $entries ) ) {
			$errors[] = "$at.activations: must be a list.";
			return array();
		}

		$out  = array();
		$live = array();

		foreach ( array_values( $entries ) as $j => $raw ) {
			$where  = "$at.activations[$j]";
			$domain = is_array( $raw ) ? Key::domain( $raw['domain'] ?? '' ) : '';

			if ( '' === $domain ) {
				$errors[] = "$where: domain is required.";
				continue;
			}

			$activated   = self::date( $raw['activated_at'] ?? null, "$where.activated_at", $errors );
			$deactivated = self::date( $raw['deactivated_at'] ?? null, "$where.deactivated_at", $errors );

			// A site can only be live once per license; a repeat in the export is dropped.
			if ( null === $deactivated ) {
				if ( isset( $live[ $domain ] ) ) {
					continue;
				}
				$live[ $domain ] = true;
			}

			$out[] = array(
				'domain'         => $domain,
				'activated_at'   => $activated,
				'deactivated_at' => $deactivated,
			);
		}

		return $out;
	}

	/**
	 * The stored license an entry matches: by external_id, then by key.
	 *
	 * @param string      $key      Normalised key.
	 * @param string|null $external External ID.
	 * @param string      $at       Where in the file.
	 * @param string[]    $errors   Collected errors.
	 * @return array<string, mixed>|null
	 */
	private static function existing( string $key, ?string $external, string $at, array &$errors ): ?array {
		global $wpdb;

		$by_key = Licenses::find_by_key( $key );

		if ( null === $external ) {
			return $by_key;
		}

		$table = Install::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$id          = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE external_id = %s', $table, $external ) );
		$by_external = $id ? Licenses::find( $id ) : null;

		if ( $by_external && $by_external['license_key'] !== $key ) {
			$errors[] = "$at: external_id $external is stored with another key ({$by_external['license_key']}).";
			return null;
		}

		if ( ! $by_external && $by_key && null !== $by_key['external_id'] ) {
			$errors[] = "$at: license_key $key is stored with another external_id ({$by_key['external_id']}).";
			return null;
		}

		return $by_external ? $by_external : $by_key;
	}

	/**
	 * Validates the release entries.
	 *
	 * @param mixed    $entries  Raw entries.
	 * @param string   $base_dir Directory files are relative to.
	 * @param string[] $errors   Collected errors.
	 * @return array<int, array<string, mixed>>
	 */
	private static function check_releases( $entries, string $base_dir, array &$errors ): array {
		if ( ! is_array( $entries ) ) {
			$errors[] = 'releases: must be a list.';
			return array();
		}

		$software = Software::all();
		$out      = array();

		foreach ( array_values( $entries ) as $i => $raw ) {
			$at      = "releases[$i]";
			$slug    = is_array( $raw ) ? sanitize_title( (string) ( $raw['product_slug'] ?? '' ) ) : '';
			$version = is_array( $raw ) ? ltrim( trim( (string) ( $raw['version'] ?? '' ) ), 'vV' ) : '';
			$file    = is_array( $raw ) ? (string) ( $raw['file'] ?? '' ) : '';

			if ( '' !== $file && ! path_is_absolute( $file ) && '' !== $base_dir ) {
				$file = trailingslashit( $base_dir ) . $file;
			}

			if ( ! isset( $software[ $slug ] ) ) {
				$errors[] = "$at: product_slug \"$slug\" is not the software slug of any product.";
				continue;
			}

			if ( '' === $version ) {
				$errors[] = "$at: version is required.";
				continue;
			}

			$published = Software::release( $slug, $version );

			if ( ! $published && ! is_file( $file ) ) {
				$errors[] = "$at: file not found: $file";
				continue;
			}

			$out[] = array(
				'slug'      => $slug,
				'version'   => $version,
				'file'      => $file,
				'changelog' => (string) ( $raw['changelog'] ?? '' ),
				'current'   => ! empty( $raw['current'] ),
				'published' => (bool) $published,
			);
		}

		return $out;
	}

	/**
	 * Creates or updates one license, then its activations.
	 *
	 * @param array<string, mixed> $entry   Checked entry.
	 * @param bool                 $dry_run Report only.
	 * @param array<string, mixed> $report  Report.
	 */
	private static function apply_license( array $entry, bool $dry_run, array &$report ): void {
		$columns  = $entry['columns'];
		$existing = $entry['existing'];
		$key      = $columns['license_key'];

		if ( ! $existing ) {
			++$report['counts']['created'];
			$report['lines'][] = "create $key ({$columns['product_slug']}, {$columns['email']})";

			if ( $dry_run ) {
				$report['counts']['activations_added'] += count( array_filter( $entry['activations'], static fn( $a ) => null === $a['deactivated_at'] ) );
				return;
			}

			$license = Licenses::create(
				array_merge(
					$columns,
					array(
						'source'      => 'import',
						'external_id' => $entry['external_id'],
					)
				)
			);

			if ( ! $license ) {
				$report['errors'][] = "$key: could not be created.";
				return;
			}

			if ( null !== $entry['created_at'] ) {
				self::write( 'licenses', array( 'created_at' => $entry['created_at'] ), (int) $license['id'] );
			}
		} else {
			$license = $existing;
			$changes = array();

			foreach ( $columns as $column => $value ) {
				if ( 'license_key' !== $column && $license[ $column ] !== $value ) {
					$changes[ $column ] = $value;
				}
			}

			if ( null !== $entry['external_id'] && null === $license['external_id'] ) {
				$changes['external_id'] = $entry['external_id'];
			}

			if ( $changes ) {
				++$report['counts']['updated'];
				$report['lines'][] = "update $key: " . implode( ', ', array_keys( $changes ) );

				if ( ! $dry_run ) {
					if ( isset( $changes['external_id'] ) ) {
						self::write( 'licenses', array( 'external_id' => $changes['external_id'] ), (int) $license['id'] );
						unset( $changes['external_id'] );
					}
					if ( $changes ) {
						Licenses::update( (int) $license['id'], $changes );
					}
				}
			} else {
				++$report['counts']['unchanged'];
			}
		}

		self::apply_activations( $license, $entry['activations'], $dry_run, $report );
	}

	/**
	 * Brings a license's activations in line with the file: adds sites that are live there, and
	 * closes sites that were deactivated there. Sites the file does not mention are left alone.
	 *
	 * @param array<string, mixed>             $license     License.
	 * @param array<int, array<string, mixed>> $activations Checked activations.
	 * @param bool                             $dry_run     Report only.
	 * @param array<string, mixed>             $report      Report.
	 */
	private static function apply_activations( array $license, array $activations, bool $dry_run, array &$report ): void {
		global $wpdb;

		$id    = (int) $license['id'];
		$table = Install::tables()['activations'];
		// Only sites: other kinds of activation belong to plugins built on this one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d AND kind = %s', $table, $id, 'site' ), ARRAY_A );
		$known = array();
		$live  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$known[ $row['domain'] ] = true;
			if ( null !== $row['live_domain'] ) {
				$live[ $row['domain'] ] = (int) $row['id'];
			}
		}

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $activations as $activation ) {
			$domain = $activation['domain'];

			if ( null === $activation['deactivated_at'] ) {
				if ( isset( $live[ $domain ] ) ) {
					continue;
				}

				++$report['counts']['activations_added'];
				$report['lines'][] = "  activate {$license['license_key']} on $domain";

				if ( ! $dry_run ) {
					self::insert_activation( $id, $domain, $activation['activated_at'] ?? $now, null );
					/* translators: %s: site address. */
					Events::add( $id, 'import', sprintf( __( 'Imported: active on %s.', 'beltoft-licenses' ), $domain ) );
				}
				$live[ $domain ] = 0;
				continue;
			}

			if ( isset( $live[ $domain ] ) ) {
				++$report['counts']['activations_closed'];
				$report['lines'][] = "  deactivate {$license['license_key']} on $domain";

				if ( ! $dry_run && $live[ $domain ] ) {
					self::write(
						'activations',
						array(
							'deactivated_at' => $activation['deactivated_at'],
							'live_domain'    => null,
						),
						$live[ $domain ]
					);
					/* translators: %s: site address. */
					Events::add( $id, 'import', sprintf( __( 'Imported: deactivated on %s.', 'beltoft-licenses' ), $domain ) );
				}
				unset( $live[ $domain ] );
				continue;
			}

			// Past activations are kept as history the first time.
			if ( ! isset( $known[ $domain ] ) && ! $dry_run ) {
				self::insert_activation( $id, $domain, $activation['activated_at'] ?? $activation['deactivated_at'], $activation['deactivated_at'] );
			}
			$known[ $domain ] = true;
		}
	}

	/**
	 * Publishes one release from a copy of its file, as Publisher moves what it is given.
	 *
	 * @param array<string, mixed> $entry   Checked entry.
	 * @param bool                 $dry_run Report only.
	 * @param array<string, mixed> $report  Report.
	 */
	private static function apply_release( array $entry, bool $dry_run, array &$report ): void {
		$label = "{$entry['slug']} {$entry['version']}";

		if ( ! $entry['published'] ) {
			++$report['counts']['releases'];
			$report['lines'][] = "publish $label" . ( $entry['current'] ? ' (current)' : '' );

			if ( $dry_run ) {
				return;
			}

			$incoming = Software::storage_dir() . '/incoming';
			wp_mkdir_p( $incoming );
			$copy = $incoming . '/import-' . wp_generate_password( 12, false ) . '.zip';

			if ( ! copy( $entry['file'], $copy ) ) {
				$report['errors'][] = "$label: the file could not be copied.";
				return;
			}

			$result = Publisher::publish( $entry['slug'], $entry['version'], $copy, $entry['changelog'], 'import', $entry['current'] );

			if ( is_wp_error( $result ) ) {
				wp_delete_file( $copy );
				$report['errors'][] = "$label: " . $result->get_error_message();
			}
			return;
		}

		if ( $entry['current'] && Software::current_version( $entry['slug'] ) !== $entry['version'] ) {
			$report['lines'][] = "make $label current";
			if ( ! $dry_run ) {
				Software::set_current( $entry['slug'], $entry['version'] );
			}
		}
	}

	/**
	 * Inserts an activation row with its own dates.
	 *
	 * @param int         $license_id     License ID.
	 * @param string      $domain         Normalised site.
	 * @param string      $activated_at   UTC datetime.
	 * @param string|null $deactivated_at UTC datetime, or null when live.
	 */
	private static function insert_activation( int $license_id, string $domain, string $activated_at, ?string $deactivated_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$wpdb->insert(
			Install::tables()['activations'],
			array(
				'license_id'     => $license_id,
				'kind'           => 'site',
				'domain'         => $domain,
				'live_domain'    => null === $deactivated_at ? $domain : null,
				'activated_at'   => $activated_at,
				'deactivated_at' => $deactivated_at,
			)
		);
	}

	/**
	 * Updates columns no public method writes (creation date, external ID, closed activations).
	 *
	 * @param string               $table licenses or activations.
	 * @param array<string, mixed> $row   Columns.
	 * @param int                  $id    Row ID.
	 */
	private static function write( string $table, array $row, int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$wpdb->update( Install::tables()[ $table ], $row, array( 'id' => $id ) );
	}

	/**
	 * A date from the file as a UTC datetime, or null when empty.
	 *
	 * @param mixed    $value  ISO 8601, or anything strtotime() reads.
	 * @param string   $at     Where in the file.
	 * @param string[] $errors Collected errors.
	 */
	private static function date( $value, string $at, array &$errors ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$time = is_string( $value ) ? strtotime( $value ) : false;

		if ( false === $time ) {
			$errors[] = "$at: not a date.";
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $time );
	}
}
