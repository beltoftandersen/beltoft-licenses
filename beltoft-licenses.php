<?php
/**
 * Plugin Name:          Beltoft Licenses for WooCommerce
 * Plugin URI:           https://beltoft.net/
 * Description:          Sell software licenses with WooCommerce and WooCommerce Subscriptions: license keys, site activations, a license API for your plugins, and update downloads.
 * Version:              0.8.2
 * Requires at least:    6.5
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * Author:               beltoft.net
 * Author URI:           https://beltoft.net/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          beltoft-licenses
 * Domain Path:          /languages
 * WC requires at least: 9.0
 *
 * @package BeltoftLicenses
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_LICENSES_FILE', __FILE__ );
define( 'BELTOFT_LICENSES_PATH', plugin_dir_path( __FILE__ ) );
define( 'BELTOFT_LICENSES_VERSION', '0.8.2' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BeltoftLicenses\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = BELTOFT_LICENSES_PATH . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BELTOFT_LICENSES_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BELTOFT_LICENSES_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \BeltoftLicenses\Install::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \BeltoftLicenses\Install::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \BeltoftLicenses\Plugin::class, 'boot' ) );
