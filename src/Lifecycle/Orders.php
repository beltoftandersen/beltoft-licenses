<?php
/**
 * Licenses from paid orders, and revoking them on refund.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Lifecycle;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Product;
use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One license per unit of each licensed line item, once the order is paid. Subscription
 * products are left to Subscriptions, which issues their licenses when the subscription
 * starts and keeps them in step with it.
 */
final class Orders {

	public const ITEM_META = '_beltoft_licenses_ids';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'revoke_for_order_id' ) );
	}

	/**
	 * Hook adapter.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function issue_for_order_id( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order && ! $order instanceof \WC_Subscription ) {
			self::issue( $order );
		}
	}

	/**
	 * Issues the missing licenses for an order. Safe to call any number of times: each line
	 * item remembers the licenses it already has.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>> Licenses issued now.
	 */
	public static function issue( \WC_Order $order ): array {
		$issued = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product || ! Product::is_licensed( $product ) || Product::is_subscription( $product ) ) {
				continue;
			}

			$ids     = array_filter( array_map( 'intval', (array) $item->get_meta( self::ITEM_META ) ) );
			$wanted  = Product::is_free_plan( $product ) ? 1 : max( 1, (int) $item->get_quantity() );
			$missing = $wanted - count( $ids );

			for ( $i = 0; $i < $missing; $i++ ) {
				list( $license, $is_new ) = self::license_for( $order, $item, $product );

				if ( $license ) {
					$ids[] = (int) $license['id'];
					if ( $is_new ) {
						$issued[] = $license;
					}
				}
			}

			$item->update_meta_data( self::ITEM_META, array_values( array_unique( $ids ) ) );
			$item->save();
		}

		foreach ( $issued as $license ) {
			/* translators: 1: license key, 2: software slug. */
			$order->add_order_note( sprintf( __( 'License %1$s issued for %2$s.', 'beltoft-licenses' ), $license['license_key'], $license['product_slug'] ) );
		}

		return $issued;
	}

	/**
	 * A new license for one unit, or the customer's existing one for a free plan.
	 *
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Line item.
	 * @param \WC_Product            $product Product or variation.
	 * @return array{0: array<string, mixed>|null, 1: bool} The license, and whether it is new.
	 */
	private static function license_for( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product ): array {
		$slug = Product::slug( $product );

		if ( Product::is_free_plan( $product ) ) {
			$existing = Licenses::find_for_customer( $slug, (int) $order->get_customer_id(), (string) $order->get_billing_email() );

			if ( $existing ) {
				/* translators: %s: license key. */
				$order->add_order_note( sprintf( __( 'Free plan already claimed: the customer keeps license %s.', 'beltoft-licenses' ), $existing['license_key'] ) );
				return array( $existing, false );
			}
		}

		$license = Licenses::create(
			array(
				'product_slug'    => $slug,
				'max_activations' => Product::max_activations( $product ),
				'user_id'         => $order->get_customer_id() ? (int) $order->get_customer_id() : null,
				'email'           => (string) $order->get_billing_email(),
				'product_id'      => (int) $item->get_product_id(),
				'variation_id'    => $item->get_variation_id() ? (int) $item->get_variation_id() : null,
				'order_id'        => (int) $order->get_id(),
				'source'          => Product::is_free_plan( $product ) ? 'free' : 'order',
			)
		);

		return array( $license, null !== $license );
	}

	/**
	 * Hook adapter.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function revoke_for_order_id( $order_id ): void {
		if ( ! Settings::get( 'revoke_on_refund' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		foreach ( Licenses::where( 'order_id', (int) $order_id ) as $license ) {
			if ( Licenses::STATUS_REVOKED === $license['status'] ) {
				continue;
			}

			Licenses::update(
				(int) $license['id'],
				array(
					'status'     => Licenses::STATUS_REVOKED,
					'expires_at' => time(),
				)
			);

			if ( $order instanceof \WC_Order ) {
				/* translators: %s: license key. */
				$order->add_order_note( sprintf( __( 'License %s revoked: the order was fully refunded.', 'beltoft-licenses' ), $license['license_key'] ) );
			}
		}
	}
}
