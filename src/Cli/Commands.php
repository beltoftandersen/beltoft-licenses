<?php
/**
 * WP-CLI commands.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Cli;

use BeltoftLicenses\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Beltoft Licenses from the command line.
 */
final class Commands {

	/**
	 * Registers the commands when WP-CLI is running.
	 */
	public static function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-licenses', self::class );
		}
	}

	/**
	 * Imports licenses, activations and releases from a JSON file.
	 *
	 * The whole file is checked first; if anything is wrong, nothing is written. Licenses are
	 * matched by external_id, then by key, so running a newer export again only applies what
	 * changed. The format is described in the plugin's README.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The JSON file. Release files are relative to its folder.
	 *
	 * [--dry-run]
	 * : Check the file and report what would change, without writing anything.
	 *
	 * [--verbose]
	 * : List every change.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beltoft-licenses import export.json --dry-run
	 *     wp beltoft-licenses import export.json
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		$file = $args[0];

		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Cannot read $file." );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line.
		$data = json_decode( (string) file_get_contents( $file ), true );

		if ( ! is_array( $data ) ) {
			\WP_CLI::error( "$file is not valid JSON: " . json_last_error_msg() );
		}

		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$report  = Import::run( $data, $dry_run, dirname( (string) realpath( $file ) ) );

		if ( $dry_run || \WP_CLI\Utils\get_flag_value( $assoc_args, 'verbose', false ) ) {
			foreach ( $report['lines'] as $line ) {
				\WP_CLI::log( $line );
			}
		}

		foreach ( $report['counts'] as $what => $count ) {
			\WP_CLI::log( sprintf( '%-20s %d', str_replace( '_', ' ', $what ), $count ) );
		}

		if ( $report['errors'] ) {
			foreach ( $report['errors'] as $error ) {
				\WP_CLI::warning( $error );
			}
			\WP_CLI::error( $dry_run || ! array_filter( $report['counts'] ) ? 'Nothing was imported.' : 'The import stopped with errors.' );
		}

		\WP_CLI::success( $dry_run ? 'Dry run: nothing was written.' : 'Imported.' );
	}
}
