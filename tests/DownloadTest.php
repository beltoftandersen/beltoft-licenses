<?php
/**
 * How a release file is handed to the client.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Api\Download;
use BeltoftLicenses\Software;

class DownloadTest extends TestCase {

	private function file(): string {
		$path = Software::path( 'dl-plugin/dl-plugin-1.0.0.zip' );
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, 'abcde' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		return $path;
	}

	public function test_php_delivery_streams_with_a_length_and_a_safe_file_name(): void {
		$this->settings( array( 'delivery' => 'php' ) );
		$plan = Download::plan( $this->file(), 'my plugin/../1.0.0.zip' );

		$this->assertTrue( $plan['stream'] );
		$this->assertContains( 'Content-Length: 5', $plan['headers'] );
		$this->assertContains( 'Content-Type: application/zip', $plan['headers'] );
		$this->assertContains( 'Content-Disposition: attachment; filename="my-plugin.1.0.0.zip"', $plan['headers'] );
		$this->assertContains( 'Cache-Control: no-store', $plan['headers'] );
	}

	public function test_x_accel_sends_the_path_below_the_storage_directory_under_the_prefix(): void {
		$this->settings( array( 'delivery' => 'x-accel', 'x_accel_prefix' => '/protected-releases' ) );
		$plan = Download::plan( $this->file(), 'dl-plugin.zip' );

		$this->assertFalse( $plan['stream'] );
		$this->assertContains( 'X-Accel-Redirect: /protected-releases/dl-plugin/dl-plugin-1.0.0.zip', $plan['headers'] );
		$this->assertEmpty( preg_grep( '/^Content-Length/', $plan['headers'] ) );
	}

	public function test_x_accel_without_a_prefix_falls_back_to_streaming(): void {
		$this->settings( array( 'delivery' => 'x-accel', 'x_accel_prefix' => '' ) );

		$this->assertTrue( Download::plan( $this->file(), 'dl-plugin.zip' )['stream'] );
	}

	public function test_x_sendfile_names_the_file(): void {
		$this->settings( array( 'delivery' => 'x-sendfile' ) );
		$path = $this->file();
		$plan = Download::plan( $path, 'dl-plugin.zip' );

		$this->assertFalse( $plan['stream'] );
		$this->assertContains( 'X-Sendfile: ' . $path, $plan['headers'] );
	}
}
