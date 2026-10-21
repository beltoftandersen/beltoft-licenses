<?php
/**
 * Publishing, choosing and deleting releases.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Releases\Publisher;
use BeltoftLicenses\Software;

class ReleasesTest extends TestCase {

	/**
	 * A zip with the given files (path => contents), in a temporary file.
	 *
	 * @param array<string, string> $files Files.
	 */
	public static function zip( array $files ): string {
		$path = wp_tempnam( 'test.zip' );
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $files as $name => $contents ) {
			'/' === substr( $name, -1 ) ? $zip->addEmptyDir( rtrim( $name, '/' ) ) : $zip->addFromString( $name, $contents );
		}
		$zip->close();

		return $path;
	}

	private function product( string $slug = 'rel-plugin' ): int {
		$product = new \WC_Product_Simple();
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, $slug );
		$product->save();

		return $product->get_id();
	}

	public function test_publish_stores_and_makes_current(): void {
		$this->product();
		$zip = self::zip( array( 'rel-plugin/rel-plugin.php' => '<?php // v1' ) );

		$this->assertTrue( Publisher::publish( 'rel-plugin', 'v1.0.0', $zip, 'First.' ) );
		$this->assertFileDoesNotExist( $zip, 'moved, not copied' );

		$release = Software::release( 'rel-plugin', '1.0.0' );
		$this->assertSame( 'rel-plugin/rel-plugin-1.0.0.zip', $release['file'] );
		$this->assertFileExists( Software::path( $release['file'] ) );
		$this->assertSame( hash_file( 'sha256', Software::path( $release['file'] ) ), $release['sha256'] );
		$this->assertSame( '1.0.0', Software::current_version( 'rel-plugin' ) );
	}

	public function test_stored_file_and_folder_are_readable_by_the_web_server(): void {
		$this->product();
		$zip = self::zip( array( 'rel-plugin/a.php' => '1' ) );
		chmod( $zip, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- reproduces a temp file.

		Publisher::publish( 'rel-plugin', '1.0.0', $zip );
		$file = Software::path( 'rel-plugin/rel-plugin-1.0.0.zip' );

		$this->assertSame( FS_CHMOD_FILE & 0777, fileperms( $file ) & 0777 );
		$this->assertSame( FS_CHMOD_DIR & 0777, fileperms( dirname( $file ) ) & 0777 );
	}

	public function test_not_current_until_chosen_and_rollback(): void {
		$this->product();
		Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( 'rel-plugin/a.php' => '1' ) ) );
		Publisher::publish( 'rel-plugin', '1.1.0', self::zip( array( 'rel-plugin/a.php' => '2' ) ), '', 'upload', false );
		$this->assertSame( '1.0.0', Software::current_version( 'rel-plugin' ) );

		$this->assertTrue( Software::set_current( 'rel-plugin', '1.1.0' ) );
		$this->assertSame( '1.1.0', Software::current_version( 'rel-plugin' ) );
		$this->assertTrue( Software::set_current( 'rel-plugin', '1.0.0' ), 'roll back' );
		$this->assertFalse( Software::set_current( 'rel-plugin', '9.9.9' ) );
	}

	public function test_wrong_root_is_refused(): void {
		$this->product();
		$cases = array(
			'two roots'   => array( 'rel-plugin/a.php' => '1', 'other/b.php' => '2' ),
			'wrong name'  => array( 'something-else/a.php' => '1' ),
			'flat files'  => array( 'a.php' => '1' ),
		);
		foreach ( $cases as $label => $files ) {
			$result = Publisher::publish( 'rel-plugin', '1.0.0', self::zip( $files ) );
			$this->assertWPError( $result, $label );
			$this->assertSame( 'beltoft_licenses_root', $result->get_error_code(), $label );
		}
	}

	public function test_mac_metadata_folder_is_ignored(): void {
		$this->product();
		$this->assertTrue( Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( 'rel-plugin/a.php' => '1', '__MACOSX/rel-plugin/._a.php' => 'x' ) ) ) );
	}

	public function test_repackage_renames_the_root_folder(): void {
		$this->product();
		$zip = self::zip( array( 'owner-rel-plugin-abc123/' => '', 'owner-rel-plugin-abc123/rel-plugin.php' => '<?php', 'owner-rel-plugin-abc123/inc/x.php' => 'x' ) );
		$this->assertTrue( Publisher::publish( 'rel-plugin', '2.0.0', $zip, '', 'github', true, true ) );

		$stored = new \ZipArchive();
		$stored->open( Software::path( 'rel-plugin/rel-plugin-2.0.0.zip' ) );
		$names = array();
		for ( $i = 0; $i < $stored->numFiles; $i++ ) {
			$names[] = $stored->getNameIndex( $i );
		}
		sort( $names );
		$this->assertSame( array( 'rel-plugin/', 'rel-plugin/inc/x.php', 'rel-plugin/rel-plugin.php' ), $names );
	}

	public function test_refusals(): void {
		$this->product();
		$ok = self::zip( array( 'rel-plugin/a.php' => '1' ) );

		$this->assertSame( 'beltoft_licenses_version', Publisher::publish( 'rel-plugin', '1.0 beta', $ok )->get_error_code() );
		$this->assertSame( 'beltoft_licenses_slug', Publisher::publish( '', '1.0.0', $ok )->get_error_code() );
		$this->assertSame( 'beltoft_licenses_zip', Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( '../evil.php' => 'x' ) ) )->get_error_code() );

		$not_zip = wp_tempnam( 'x.zip' );
		file_put_contents( $not_zip, 'not a zip' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->assertSame( 'beltoft_licenses_zip', Publisher::publish( 'rel-plugin', '1.0.0', $not_zip )->get_error_code() );

		$this->assertTrue( Publisher::publish( 'rel-plugin', '1.0.0', $ok ) );
		$this->assertSame( 'beltoft_licenses_exists', Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( 'rel-plugin/a.php' => '1' ) ) )->get_error_code() );

		$this->settings( array( 'release_max_mb' => 1 ) );
		$big = self::zip( array( 'rel-plugin/big.bin' => random_bytes( 2 * MB_IN_BYTES ) ) );
		$this->assertSame( 'beltoft_licenses_size', Publisher::publish( 'rel-plugin', '3.0.0', $big )->get_error_code() );
	}

	public function test_delete_moves_current_to_newest_remaining(): void {
		$this->product();
		Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( 'rel-plugin/a.php' => '1' ) ) );
		Publisher::publish( 'rel-plugin', '1.1.0', self::zip( array( 'rel-plugin/a.php' => '2' ) ) );
		$file = Software::path( Software::release( 'rel-plugin', '1.1.0' )['file'] );

		$this->assertTrue( Software::delete_release( 'rel-plugin', '1.1.0' ) );
		$this->assertFileDoesNotExist( $file );
		$this->assertSame( '1.0.0', Software::current_version( 'rel-plugin' ) );
		$this->assertFalse( Software::delete_release( 'rel-plugin', '1.1.0' ) );
	}

	public function test_published_release_is_downloadable_through_the_api(): void {
		$this->product();
		Publisher::publish( 'rel-plugin', '1.0.0', self::zip( array( 'rel-plugin/a.php' => '1' ) ) );
		$l = $this->license( array( 'product_slug' => 'rel-plugin' ) );
		$this->api( 'POST', 'activate', array( 'license_key' => $l['license_key'], 'domain' => 'site.example' ) );

		$body = $this->body( $this->api( 'POST', 'download', array( 'license_key' => $l['license_key'], 'domain' => 'site.example' ) ) );
		$this->assertSame( 'rel-plugin-1.0.0.zip', $body['filename'] );
	}

	public function test_software_list_for_the_admin_screen(): void {
		$this->product( 'b-plugin' );
		$this->product( 'a-plugin' );
		$this->assertSame( array( 'a-plugin', 'b-plugin' ), array_keys( Software::all() ) );
	}
}
