<?php
/**
 * Licensed software: products, versions and release files.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the software behind a license (by its slug, the plugin or theme folder name), its
 * current version and the release file for that version.
 */
final class Software {

	public const META_LICENSED = '_beltoft_licenses_licensed';
	public const META_SLUG     = '_beltoft_licenses_software_slug';
	public const META_VERSION  = '_beltoft_licenses_current_version';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_product' ), 10, 4 );
		}
	}

	/**
	 * Drops the cached slug → product lookup when a product's software slug changes.
	 *
	 * @param mixed  $meta_id    Meta ID(s).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function forget_product( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( self::META_SLUG !== $meta_key ) {
			return;
		}

		foreach ( array_filter( array( (string) $meta_value, (string) get_post_meta( (int) $object_id, self::META_SLUG, true ) ) ) as $slug ) {
			wp_cache_delete( 'product_' . $slug, 'beltoft_licenses' );
		}
	}

	/**
	 * The product selling this software, if any.
	 *
	 * @param string $slug Software slug.
	 */
	public static function product_id( string $slug ): ?int {
		if ( '' === $slug ) {
			return null;
		}

		$cached = wp_cache_get( 'product_' . $slug, 'beltoft_licenses' );

		if ( false !== $cached ) {
			return $cached ? (int) $cached : null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed lookup, cached with wp_cache_set below.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = 'product' AND p.post_status IN ('publish','private','draft')
				AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 1",
				self::META_SLUG,
				$slug
			)
		);

		wp_cache_set( 'product_' . $slug, $id, 'beltoft_licenses', MINUTE_IN_SECONDS );

		return $id ? $id : null;
	}

	/**
	 * The current version of a piece of software: the product's setting, else the newest
	 * published release. Null when the software is unknown.
	 *
	 * @param string $slug Software slug.
	 */
	public static function current_version( string $slug ): ?string {
		$product_id = self::product_id( $slug );

		if ( $product_id ) {
			$version = (string) get_post_meta( $product_id, self::META_VERSION, true );
			if ( '' !== $version ) {
				return $version;
			}
		}

		$latest = self::latest_release( $slug );

		if ( $latest ) {
			return (string) $latest['version'];
		}

		return $product_id ? '' : null;
	}

	/**
	 * Is there a product or a release for this slug?
	 *
	 * @param string $slug Software slug.
	 */
	public static function exists( string $slug ): bool {
		return null !== self::current_version( $slug );
	}

	/**
	 * One release.
	 *
	 * @param string $slug    Software slug.
	 * @param string $version Version.
	 * @return array<string, mixed>|null
	 */
	public static function release( string $slug, string $version ): ?array {
		global $wpdb;

		$table = Install::tables()['releases'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_slug = %s AND version = %s', $table, $slug, $version ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * The most recently published release.
	 *
	 * @param string $slug Software slug.
	 * @return array<string, mixed>|null
	 */
	public static function latest_release( string $slug ): ?array {
		global $wpdb;

		$table = Install::tables()['releases'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_slug = %s ORDER BY published_at DESC, id DESC LIMIT 1', $table, $slug ), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Records a release whose file is already in the storage directory.
	 *
	 * @param string $slug      Software slug.
	 * @param string $version   Version.
	 * @param string $file      Path relative to the storage directory.
	 * @param string $changelog Changelog.
	 * @param string $source    upload or github.
	 */
	public static function add_release( string $slug, string $version, string $file, string $changelog = '', string $source = 'upload' ): bool {
		global $wpdb;

		$path = self::path( $file );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write to a custom table; nothing to cache.
		$ok = false !== $wpdb->insert(
			Install::tables()['releases'],
			array(
				'product_slug' => sanitize_title( $slug ),
				'version'      => sanitize_text_field( $version ),
				'file'         => $file,
				'filesize'     => is_file( $path ) ? (int) filesize( $path ) : 0,
				'sha256'       => is_file( $path ) ? (string) hash_file( 'sha256', $path ) : '',
				'changelog'    => $changelog,
				'source'       => sanitize_key( $source ),
				'published_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		if ( $ok ) {
			$product_id = self::product_id( $slug );
			if ( $product_id ) {
				update_post_meta( $product_id, self::META_VERSION, $version );
			}
			do_action( 'beltoft_licenses_release_published', $slug, $version );
		}

		return $ok;
	}

	/**
	 * The release storage directory, created and protected on first use.
	 */
	public static function storage_dir(): string {
		$dir = (string) Settings::get( 'storage_dir' );

		if ( '' === $dir ) {
			$uploads = wp_upload_dir( null, false );
			$suffix  = get_option( 'beltoft_licenses_storage_suffix' );

			if ( ! $suffix ) {
				$suffix = wp_generate_password( 16, false );
				add_option( 'beltoft_licenses_storage_suffix', $suffix, '', false );
			}

			$dir = trailingslashit( $uploads['basedir'] ) . 'beltoft-licenses-private/' . $suffix;
		}

		$dir = untrailingslashit( $dir );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		self::protect( dirname( $dir ) );
		self::protect( $dir );

		return $dir;
	}

	/**
	 * Absolute path of a stored release file.
	 *
	 * @param string $file Path relative to the storage directory.
	 */
	public static function path( string $file ): string {
		return self::storage_dir() . '/' . ltrim( str_replace( '..', '', $file ), '/' );
	}

	/**
	 * Deny-all files for Apache, and an empty index for directory listings. nginx ignores
	 * both; the settings page explains the deny rule for it.
	 *
	 * @param string $dir Directory.
	 */
	private static function protect( string $dir ): void {
		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return;
		}

		if ( ! $wp_filesystem->exists( $dir . '/.htaccess' ) ) {
			$wp_filesystem->put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n", FS_CHMOD_FILE );
		}

		if ( ! $wp_filesystem->exists( $dir . '/index.php' ) ) {
			$wp_filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
	}
}
