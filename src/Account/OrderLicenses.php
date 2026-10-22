<?php
/**
 * License keys on the order received page, the customer's order view and order emails.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Account;

use BeltoftLicenses\Lifecycle\Orders;
use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Lists an order's license keys under the order table, wherever WooCommerce shows it.
 */
final class OrderLicenses {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'html' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email' ), 10, 3 );
	}

	/**
	 * The order's licenses: issued for it, or linked to its line items (a free plan reusing an
	 * earlier key).
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_order( \WC_Order $order ): array {
		$licenses = array();

		foreach ( Licenses::where( 'order_id', (int) $order->get_id() ) as $license ) {
			$licenses[ $license['id'] ] = $license;
		}

		foreach ( $order->get_items() as $item ) {
			foreach ( array_filter( array_map( 'intval', (array) $item->get_meta( Orders::ITEM_META ) ) ) as $id ) {
				$license = Licenses::find( $id );
				if ( $license ) {
					$licenses[ $id ] = $license;
				}
			}
		}

		ksort( $licenses );

		return array_values( $licenses );
	}

	/**
	 * The status to show beside a key: nothing while it is active.
	 *
	 * @param array<string, mixed> $license License.
	 */
	private static function status_suffix( array $license ): string {
		$status = Licenses::effective_status( $license );

		return Licenses::STATUS_ACTIVE === $status ? '' : ' (' . View::status_label( $status ) . ')';
	}

	/**
	 * Under the order table on the thank-you and view-order pages.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function html( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$licenses = self::for_order( $order );

		if ( ! $licenses ) {
			return;
		}

		echo '<section class="beltoft-licenses__order"><h2>' . esc_html__( 'License keys', 'beltoft-licenses' ) . '</h2><ul>';
		foreach ( $licenses as $license ) {
			printf( '<li>%1$s: <code>%2$s</code>%3$s</li>', esc_html( View::product_name( $license ) ), esc_html( (string) $license['license_key'] ), esc_html( self::status_suffix( $license ) ) );
		}
		echo '</ul></section>';
	}

	/**
	 * In customer order emails (never admin copies, never before payment).
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Whether the email goes to the shop.
	 * @param bool      $plain_text    Plain-text email.
	 */
	public static function email( $order, $sent_to_admin = false, $plain_text = false ): void {
		if ( ! $order instanceof \WC_Order || $sent_to_admin || ! $order->is_paid() ) {
			return;
		}

		$licenses = self::for_order( $order );

		if ( ! $licenses ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'License keys', 'beltoft-licenses' ) ) ) . "\n\n";
			foreach ( $licenses as $license ) {
				echo esc_html( View::product_name( $license ) . ': ' . $license['license_key'] . self::status_suffix( $license ) ) . "\n";
			}
			echo "\n";
			return;
		}

		echo '<h2>' . esc_html__( 'License keys', 'beltoft-licenses' ) . '</h2><ul>';
		foreach ( $licenses as $license ) {
			printf( '<li>%1$s: <code>%2$s</code>%3$s</li>', esc_html( View::product_name( $license ) ), esc_html( (string) $license['license_key'] ), esc_html( self::status_suffix( $license ) ) );
		}
		echo '</ul>';
	}
}
