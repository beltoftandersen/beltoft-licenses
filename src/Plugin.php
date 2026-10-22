<?php
/**
 * Wiring.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks every part of the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Runs on plugins_loaded.
	 */
	public static function boot(): void {
		// The license API answers plugins in the field even when WooCommerce is briefly
		// deactivated, but nothing that sells or renders account pages may run without it.
		Install::maybe_upgrade();
		Settings::register();
		Software::register();
		Events::register();
		Api\Router::register();

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_woocommerce_missing' ) );
			return;
		}

		Product::register();
		Emails\Manager::register();
		Emails\UpdateNotifier::register();
		Lifecycle\Orders::register();
		Lifecycle\Expiry::register();
		// Hooks itself only when WooCommerce Subscriptions is active.
		Lifecycle\Subscriptions::register();
		Releases\AdminScreen::register();
		Releases\GitHub::register();
		Account\Endpoint::register();
		Account\OrderLicenses::register();
		Account\SubscriptionEmails::register();
		Admin\Screen::register();
		Admin\OrderBox::register();
		Cli\Commands::register();
	}

	/**
	 * Shown to admins when WooCommerce is not active.
	 */
	public static function notice_woocommerce_missing(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Beltoft Licenses needs WooCommerce to sell licenses. The license API keeps answering, but nothing new can be sold until WooCommerce is active.', 'beltoft-licenses' )
		);
	}
}
