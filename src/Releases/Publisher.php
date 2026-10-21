<?php
/**
 * Turns a zip on disk into a stored release.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Releases;

use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * The one path every release takes, from the admin upload or from GitHub: check the
 * version, the size and the zip, make sure the zip has a single root folder named after
 * the software (or repackage it so), move it into protected storage and record it.
 *
 * WordPress installs an update into the folder named by the zip's root, so a wrong root
 * would install a second copy of the plugin next to the real one.
 */
final class Publisher {

	/**
	 * Publishes a release.
	 *
	 * @param string $slug         Software slug.
	 * @param string $version      Version, e.g. 1.2.3 (a leading "v" is dropped).
	 * @param string $zip          Path to the zip. It is moved, not copied, on success.
	 * @param string $changelog    Changelog.
	 * @param string $source       upload or github.
	 * @param bool   $make_current Whether the API serves it straight away.
	 * @param bool   $repackage    Rename a different single root folder to the slug instead of refusing.
	 * @return true|\WP_Error
	 */
	public static function publish( string $slug, string $version, string $zip, string $changelog = '', string $source = 'upload', bool $make_current = true, bool $repackage = false ) {
		$slug    = sanitize_title( $slug );
		$version = ltrim( trim( $version ), 'vV' );

		if ( '' === $slug ) {
			return new \WP_Error( 'beltoft_licenses_slug', __( 'Choose the software this release is for.', 'beltoft-licenses' ) );
		}

		if ( ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+\-]{0,31}$/', $version ) ) {
			return new \WP_Error( 'beltoft_licenses_version', __( 'The version may only contain letters, digits, dots, plus and minus signs, for example 1.2.3.', 'beltoft-licenses' ) );
		}

		if ( Software::release( $slug, $version ) ) {
			/* translators: 1: software slug, 2: version. */
			return new \WP_Error( 'beltoft_licenses_exists', sprintf( __( '%1$s %2$s is already published.', 'beltoft-licenses' ), $slug, $version ) );
		}

		if ( ! is_file( $zip ) ) {
			return new \WP_Error( 'beltoft_licenses_file', __( 'The release file is missing.', 'beltoft-licenses' ) );
		}

		$max = max( 1, (int) Settings::get( 'release_max_mb' ) ) * MB_IN_BYTES;
		if ( filesize( $zip ) > $max ) {
			/* translators: %d: size limit in MB. */
			return new \WP_Error( 'beltoft_licenses_size', sprintf( __( 'The file is larger than %d MB.', 'beltoft-licenses' ), (int) Settings::get( 'release_max_mb' ) ) );
		}

		$checked = self::check_root( $zip, $slug, $repackage );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$relative = $slug . '/' . $slug . '-' . $version . '.zip';
		$target   = Software::path( $relative );

		wp_mkdir_p( dirname( $target ) );

		if ( ! self::filesystem() || ! $GLOBALS['wp_filesystem']->move( $checked, $target, true ) ) {
			return new \WP_Error( 'beltoft_licenses_store', __( 'The release could not be saved in the storage directory. Check that the web server can write to it.', 'beltoft-licenses' ) );
		}

		// Temporary files are private to the process that made them (0600), and a folder made
		// from WP-CLI belongs to that user. A release published from the command line or a
		// background job must still be readable by the web server, which serves the downloads.
		$GLOBALS['wp_filesystem']->chmod( dirname( $target ), FS_CHMOD_DIR );
		$GLOBALS['wp_filesystem']->chmod( $target, FS_CHMOD_FILE );

		if ( $checked !== $zip && is_file( $zip ) ) {
			wp_delete_file( $zip );
		}

		if ( ! Software::add_release( $slug, $version, $relative, $changelog, $source, $make_current ) ) {
			wp_delete_file( $target );
			return new \WP_Error( 'beltoft_licenses_record', __( 'The release could not be recorded.', 'beltoft-licenses' ) );
		}

		return true;
	}

	/**
	 * Checks that the zip has exactly one root folder, named after the software. With
	 * $repackage, a single root folder with another name (GitHub's "owner-repo-sha/") is
	 * renamed in a new zip, whose path is returned.
	 *
	 * @param string $zip       Zip path.
	 * @param string $slug      Software slug.
	 * @param bool   $repackage Whether to rename the root folder.
	 * @return string|\WP_Error Path of the zip to store.
	 */
	public static function check_root( string $zip, string $slug, bool $repackage ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'beltoft_licenses_zip', __( 'PHP\'s zip extension is not installed, so the release cannot be checked.', 'beltoft-licenses' ) );
		}

		$archive = new \ZipArchive();

		if ( true !== $archive->open( $zip ) ) {
			return new \WP_Error( 'beltoft_licenses_zip', __( 'The file is not a valid zip archive.', 'beltoft-licenses' ) );
		}

		$roots = array();
		for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			$name = (string) $archive->getNameIndex( $i );

			if ( false !== strpos( $name, '..' ) || 0 === strpos( $name, '/' ) ) {
				$archive->close();
				return new \WP_Error( 'beltoft_licenses_zip', __( 'The zip contains unsafe paths.', 'beltoft-licenses' ) );
			}

			// Mac archivers add these next to the real folder.
			if ( 0 === strpos( $name, '__MACOSX/' ) ) {
				continue;
			}

			$roots[ strtok( $name, '/' ) ] = true;
		}

		$roots = array_keys( $roots );

		if ( array( $slug ) === $roots ) {
			$archive->close();
			return $zip;
		}

		if ( 1 !== count( $roots ) || ! $repackage ) {
			$archive->close();
			return new \WP_Error(
				'beltoft_licenses_root',
				/* translators: %s: software slug. */
				sprintf( __( 'The zip must contain one folder named "%s" with the plugin or theme inside it.', 'beltoft-licenses' ), $slug )
			);
		}

		$repacked = self::repackage( $archive, $roots[0], $slug );
		$archive->close();

		return $repacked;
	}

	/**
	 * Writes a copy of the archive with its root folder renamed.
	 *
	 * @param \ZipArchive $archive Open source archive.
	 * @param string      $from    Current root folder.
	 * @param string      $to      New root folder.
	 * @return string|\WP_Error Path of the new zip.
	 */
	private static function repackage( \ZipArchive $archive, string $from, string $to ) {
		$target = wp_tempnam( $to . '.zip' );
		$out    = new \ZipArchive();

		if ( true !== $out->open( $target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'beltoft_licenses_zip', __( 'The release could not be repackaged.', 'beltoft-licenses' ) );
		}

		for ( $i = 0; $i < $archive->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive API.
			$name = (string) $archive->getNameIndex( $i );

			if ( 0 !== strpos( $name, $from . '/' ) ) {
				continue;
			}

			$new = $to . substr( $name, strlen( $from ) );

			if ( '/' === substr( $name, -1 ) ) {
				$out->addEmptyDir( rtrim( $new, '/' ) );
			} else {
				$out->addFromString( $new, (string) $archive->getFromIndex( $i ) );
			}
		}

		$out->close();

		return $target;
	}

	/**
	 * WP_Filesystem, set up for direct access.
	 */
	private static function filesystem(): bool {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return (bool) $wp_filesystem;
	}
}
