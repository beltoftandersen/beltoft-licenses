<?php
/**
 * Database schema, activation and upgrades.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the three tables, and the per-install token secret.
 */
final class Install {

	public const DB_VERSION        = '3';
	public const OPTION_DB_VERSION = 'beltoft_licenses_db_version';
	public const OPTION_SECRET     = 'beltoft_licenses_token_secret';

	/**
	 * Table names, prefixed for this install.
	 *
	 * @return array{licenses:string, activations:string, releases:string, events:string}
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'licenses'    => $wpdb->prefix . 'beltoft_licenses',
			'activations' => $wpdb->prefix . 'beltoft_licenses_activations',
			'releases'    => $wpdb->prefix . 'beltoft_licenses_releases',
			'events'      => $wpdb->prefix . 'beltoft_licenses_events',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::create_tables();
		self::ensure_secret();
		// The My Account endpoint's rewrite rule exists from the next request on.
		update_option( Account\Endpoint::OPTION_FLUSH, 1 );
	}

	/**
	 * Deactivation hook: stops the daily job. Licenses and settings stay; see uninstall.php.
	 */
	public static function deactivate(): void {
		Lifecycle\Expiry::unschedule();
	}

	/**
	 * Upgrade in place when the stored schema version is behind.
	 */
	public static function maybe_upgrade(): void {
		$stored = get_option( self::OPTION_DB_VERSION );

		if ( self::DB_VERSION !== $stored ) {
			if ( $stored && version_compare( (string) $stored, '3', '<' ) ) {
				self::upgrade_to_3();
			}
			self::create_tables();
			self::ensure_secret();
			// An update never runs the activation hook: rules for new endpoints come from here.
			update_option( Account\Endpoint::OPTION_FLUSH, 1 );
		}
	}

	/**
	 * Schema 3: the unique key `live` gains the activation kind. dbDelta cannot change an
	 * existing key, so the old one is dropped here; create_tables() then adds the new columns
	 * (existing rows get kind 'site' from the default) and the new key.
	 */
	private static function upgrade_to_3(): void {
		global $wpdb;

		$table = self::tables()['activations'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- schema change on our own table.
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'live' ), 4 );

		if ( $columns && ! in_array( 'kind', $columns, true ) ) {
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX live', $table ) );
		}
		// phpcs:enable
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 *
	 * `live_domain` holds the domain while an activation is live and NULL once it is
	 * deactivated. The unique key on (license_id, kind, live_domain) therefore allows at most one
	 * live row per name and kind while keeping the history, without needing generated columns.
	 * `kind` is 'site' for a licensed site; plugins built on this one store other kinds.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = self::tables();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['licenses']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_key varchar(64) NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				max_activations int(11) unsigned NOT NULL DEFAULT 1,
				expires_at datetime NULL DEFAULT NULL,
				product_slug varchar(100) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NULL DEFAULT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				product_id bigint(20) unsigned NULL DEFAULT NULL,
				variation_id bigint(20) unsigned NULL DEFAULT NULL,
				order_id bigint(20) unsigned NULL DEFAULT NULL,
				subscription_id bigint(20) unsigned NULL DEFAULT NULL,
				source varchar(20) NOT NULL DEFAULT 'manual',
				external_id varchar(64) NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_key (license_key),
				UNIQUE KEY external_id (external_id),
				KEY status (status),
				KEY product_slug (product_slug),
				KEY user_id (user_id),
				KEY email (email),
				KEY subscription_id (subscription_id),
				KEY order_id (order_id)
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['activations']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				domain varchar(255) NOT NULL,
				kind varchar(20) NOT NULL DEFAULT 'site',
				live_domain varchar(255) NULL DEFAULT NULL,
				activated_at datetime NOT NULL,
				deactivated_at datetime NULL DEFAULT NULL,
				meta longtext NULL,
				updated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY live (license_id,kind,live_domain(180)),
				KEY license_id (license_id),
				KEY domain (domain(191))
			) $collate;"
		);

		dbDelta(
			"CREATE TABLE {$t['releases']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_slug varchar(100) NOT NULL,
				version varchar(32) NOT NULL,
				file varchar(255) NOT NULL DEFAULT '',
				filesize bigint(20) unsigned NOT NULL DEFAULT 0,
				sha256 char(64) NOT NULL DEFAULT '',
				changelog longtext NULL,
				source varchar(20) NOT NULL DEFAULT 'upload',
				published_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug_version (product_slug,version)
			) $collate;"
		);

		// The history of each license: what changed, when, and by whom. Shared with plugins
		// built on top of this one, which log their own events against the same license.
		dbDelta(
			"CREATE TABLE {$t['events']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				type varchar(40) NOT NULL,
				message text NOT NULL,
				user_id bigint(20) unsigned NULL DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY license_id (license_id)
			) $collate;"
		);

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	/**
	 * The download-token secret: generated once per install, never autoloaded.
	 */
	public static function ensure_secret(): void {
		if ( ! get_option( self::OPTION_SECRET ) ) {
			add_option( self::OPTION_SECRET, wp_generate_password( 64, true, true ), '', false );
		}
	}

	/**
	 * The secret in use. A constant in wp-config.php wins, so it can live outside the database.
	 */
	public static function secret(): string {
		if ( defined( 'BELTOFT_LICENSES_TOKEN_SECRET' ) && '' !== (string) BELTOFT_LICENSES_TOKEN_SECRET ) {
			return (string) BELTOFT_LICENSES_TOKEN_SECRET;
		}

		self::ensure_secret();

		return (string) get_option( self::OPTION_SECRET );
	}
}
