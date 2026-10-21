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

	public const DB_VERSION        = '1';
	public const OPTION_DB_VERSION = 'beltoft_licenses_db_version';
	public const OPTION_SECRET     = 'beltoft_licenses_token_secret';

	/**
	 * Table names, prefixed for this install.
	 *
	 * @return array{licenses:string, activations:string, releases:string}
	 */
	public static function tables(): array {
		global $wpdb;

		return array(
			'licenses'    => $wpdb->prefix . 'beltoft_licenses',
			'activations' => $wpdb->prefix . 'beltoft_licenses_activations',
			'releases'    => $wpdb->prefix . 'beltoft_licenses_releases',
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::create_tables();
		self::ensure_secret();
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
		if ( self::DB_VERSION !== get_option( self::OPTION_DB_VERSION ) ) {
			self::create_tables();
			self::ensure_secret();
		}
	}

	/**
	 * Creates or updates the tables with dbDelta.
	 *
	 * `live_domain` holds the domain while an activation is live and NULL once it is
	 * deactivated. The unique key on (license_id, live_domain) therefore allows at most one
	 * live row per domain while keeping the history, without needing generated columns.
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
				live_domain varchar(255) NULL DEFAULT NULL,
				activated_at datetime NOT NULL,
				deactivated_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY live (license_id,live_domain(191)),
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
