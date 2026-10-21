<?php
/**
 * PHPUnit bootstrap.
 *
 * Runs against the WordPress test suite's own throwaway install (WP_TESTS_DIR), so
 * WooCommerce is loaded by absolute path from SITE_PLUGINS. The plugin has no runtime
 * dependencies and no vendor/ of its own; the runner provides phpunit and the test library:
 *
 *   /root/plugin-tests/run.sh <path-to-this-plugin>
 *
 * @package BeltoftLicenses
 */

// Never runnable over HTTP: it requires files by path and boots a test install.
if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$beltoft_licenses_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';
$beltoft_licenses_site      = getenv( 'SITE_PLUGINS' );

require_once $beltoft_licenses_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $beltoft_licenses_site ) {
		if ( $beltoft_licenses_site && file_exists( $beltoft_licenses_site . '/woocommerce/woocommerce.php' ) ) {
			require $beltoft_licenses_site . '/woocommerce/woocommerce.php';
		}

		require dirname( __DIR__ ) . '/beltoft-licenses.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}

		\BeltoftLicenses\Install::create_tables();
		\BeltoftLicenses\Install::ensure_secret();
	}
);

require $beltoft_licenses_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/TestCase.php';
