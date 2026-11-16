<?php
/**
 * What uninstall removes.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Software;
use BeltoftLicenses\Uninstall;

class UninstallTest extends TestCase {

	public function test_purge_removes_every_option_and_transient_of_the_plugin_and_the_release_folder(): void {
		$dir = Software::storage_dir();
		file_put_contents( $dir . '/a.zip', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->assertDirectoryExists( $dir );

		add_option( 'beltoft_licenses_alerted_order_42', time(), '', false );
		add_option( 'beltoft_licenses_duplicates', array( 1 ), '', false );
		add_option( 'beltoft_licenses_duplicates_scanned', 3, '', false );
		set_transient( 'beltoft_licenses_gh_abc', 1, DAY_IN_SECONDS );
		set_transient( 'beltoft_licenses_rl_1.2.3.4', 4, 60 );
		add_option( 'beltoft_services_keep', 'yes', '', false );
		$this->assertNotEmpty( get_option( 'beltoft_licenses_storage_suffix' ) );

		Uninstall::purge();

		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%beltoft\\_licenses\\_%'" ) );
		$this->assertSame( 'yes', get_option( 'beltoft_services_keep' ) );
		$this->assertDirectoryDoesNotExist( $dir );
		delete_option( 'beltoft_services_keep' );
	}

	public function test_a_storage_directory_chosen_in_the_settings_is_left_alone(): void {
		$custom = trailingslashit( sys_get_temp_dir() ) . 'bl-custom-' . wp_generate_password( 6, false );
		wp_mkdir_p( $custom );
		$this->settings( array( 'storage_dir' => $custom ) );
		add_option( 'beltoft_licenses_storage_suffix', 'abc123', '', false );

		Uninstall::purge();

		$this->assertDirectoryExists( $custom );
		rmdir( $custom ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
	}
}
