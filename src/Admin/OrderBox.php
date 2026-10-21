<?php
/**
 * Licenses box on the order and subscription screens.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Admin;

use BeltoftLicenses\Account\View;
use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the licenses of an order or subscription, with a link to issue one by hand. Works with
 * both order storage modes (posts and HPOS).
 */
final class OrderBox {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
	}

	/**
	 * Adds the box to every order and subscription screen.
	 */
	public static function add(): void {
		if ( ! current_user_can( Screen::CAPABILITY ) ) {
			return;
		}

		$screens = array( 'shop_order', 'shop_subscription' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			// Under HPOS these are WooCommerce's own pages; an unknown order type gives ''.
			$screens[] = wc_get_page_screen_id( 'shop-order' );
			$screens[] = wc_get_page_screen_id( 'shop_subscription' );
		}

		add_meta_box( 'beltoft-licenses', __( 'Licenses', 'beltoft-licenses' ), array( __CLASS__, 'render' ), array_values( array_unique( array_filter( $screens ) ) ), 'normal', 'default' );
	}

	/**
	 * The licenses of an order, or of a subscription.
	 *
	 * @param \WC_Order $order Order or subscription.
	 * @return array<int, array<string, mixed>>
	 */
	public static function licenses_for( \WC_Order $order ): array {
		$is_subscription = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );

		return Licenses::where( $is_subscription ? 'subscription_id' : 'order_id', $order->get_id() );
	}

	/**
	 * The box.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order The post (posts storage) or the order (HPOS).
	 */
	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			return;
		}

		$licenses = self::licenses_for( $order );

		if ( ! $licenses ) {
			echo '<p>' . esc_html__( 'No licenses.', 'beltoft-licenses' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'License key', 'beltoft-licenses' ), __( 'Product', 'beltoft-licenses' ), __( 'Status', 'beltoft-licenses' ), __( 'Sites', 'beltoft-licenses' ), __( 'Valid', 'beltoft-licenses' ) ) as $heading ) {
				echo '<th>' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';

			foreach ( $licenses as $license ) {
				$status = Licenses::effective_status( $license );

				printf(
					'<tr><td><a href="%1$s"><code>%2$s</code></a></td><td>%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td></tr>',
					esc_url( Screen::url( array( 'license' => (int) $license['id'] ) ) ),
					esc_html( (string) $license['license_key'] ),
					esc_html( View::product_name( $license ) ),
					esc_html( View::status_label( $status ) ),
					esc_html( View::usage_label( $license ) ),
					esc_html( View::expiry_label( $license, $status ) )
				);
			}

			echo '</tbody></table>';
		}

		$issue = array(
			'view'  => 'new',
			'email' => $order->get_billing_email(),
		);
		if ( ! ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order ) ) ) {
			$issue['order_id'] = $order->get_id();
		}

		printf( '<p><a class="button" href="%1$s">%2$s</a></p>', esc_url( Screen::url( $issue ) ), esc_html__( 'Issue a license', 'beltoft-licenses' ) );
	}
}
