<?php
/**
 * Licenses that follow a WooCommerce Subscriptions subscription.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Lifecycle;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Product;
use BeltoftLicenses\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors subscription state onto its licenses, and nothing else: WooCommerce Subscriptions
 * keeps sole charge of billing, renewals, retries, switching, emails and the customer's
 * subscription pages. This class only listens to its status actions.
 *
 * Methods take the subscription untyped so tests can pass a stand-in with the same methods.
 */
final class Subscriptions {

	/**
	 * Hooks, only when WooCommerce Subscriptions is active.
	 */
	public static function register(): void {
		if ( ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}

		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'activated' ) );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( __CLASS__, 'renewed' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( __CLASS__, 'on_hold' ) );
		add_action( 'woocommerce_subscription_status_pending-cancel', array( __CLASS__, 'pending_cancel' ) );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'ended' ) );
		add_action( 'woocommerce_subscription_status_expired', array( __CLASS__, 'ended' ) );
		add_action( 'woocommerce_subscriptions_switch_completed', array( __CLASS__, 'switched' ) );
	}

	/**
	 * Active (first time, or again after on-hold): issues missing licenses, clears the expiry,
	 * and brings expired licenses back. Revoked and suspended licenses stay as an admin left them.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function activated( $subscription ): void {
		self::issue( $subscription );
		self::sync_limits( $subscription );

		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$changes = array( 'expires_at' => null );

			if ( Licenses::STATUS_EXPIRED === $license['status'] ) {
				$changes['status'] = Licenses::STATUS_ACTIVE;
			}

			Licenses::update( (int) $license['id'], $changes );
		}
	}

	/**
	 * A renewal was paid.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function renewed( $subscription ): void {
		self::activated( $subscription );
	}

	/**
	 * On hold (usually a failed renewal payment): the license keeps working for the grace
	 * period while Subscriptions retries, then expires unless the subscription is active again.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function on_hold( $subscription ): void {
		self::set_expiry( $subscription, time() + DAY_IN_SECONDS * max( 0, (int) Settings::get( 'grace_days' ) ) );
	}

	/**
	 * Cancelled by the customer, still paid up: the license runs to the end of the paid period.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function pending_cancel( $subscription ): void {
		$end = (int) $subscription->get_time( 'end' );

		self::set_expiry( $subscription, $end > 0 ? $end : time() );
	}

	/**
	 * Cancelled or expired: the license expires now.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function ended( $subscription ): void {
		self::set_expiry( $subscription, time() );
	}

	/**
	 * A tier switch completed: the subscription's licenses take the new site limit. The key
	 * is kept. Extra live sites stay; new activations are refused until under the limit.
	 *
	 * @param \WC_Order $order The switch order.
	 */
	public static function switched( $order ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'switch' ) ) ) as $subscription ) {
			self::sync_limits( $subscription );
		}
	}

	/**
	 * One license per unit of each licensed line item, for items that don't have theirs yet.
	 *
	 * @param object $subscription WC_Subscription.
	 * @return array<int, array<string, mixed>> Licenses issued now.
	 */
	public static function issue( $subscription ): array {
		$existing = Licenses::where( 'subscription_id', (int) $subscription->get_id() );
		$issued   = array();

		foreach ( self::licensed_items( $subscription ) as $item ) {
			$product = $item->get_product();
			$slug    = Product::slug( $product );
			$have    = count( array_filter( $existing, static fn( $l ) => $l['product_slug'] === $slug ) );
			$wanted  = max( 1, (int) $item->get_quantity() );

			for ( $i = $have; $i < $wanted; $i++ ) {
				$license = Licenses::create(
					array(
						'product_slug'    => $slug,
						'max_activations' => Product::max_activations( $product ),
						'user_id'         => $subscription->get_customer_id() ? (int) $subscription->get_customer_id() : null,
						'email'           => (string) $subscription->get_billing_email(),
						'product_id'      => (int) $item->get_product_id(),
						'variation_id'    => $item->get_variation_id() ? (int) $item->get_variation_id() : null,
						'order_id'        => $subscription->get_parent_id() ? (int) $subscription->get_parent_id() : null,
						'subscription_id' => (int) $subscription->get_id(),
						'source'          => 'order',
					)
				);

				if ( $license ) {
					$issued[] = $license;
					/* translators: 1: license key, 2: software slug. */
					$subscription->add_order_note( sprintf( __( 'License %1$s issued for %2$s.', 'beltoft-licenses' ), $license['license_key'], $license['product_slug'] ) );
				}
			}
		}

		return $issued;
	}

	/**
	 * Gives the subscription's licenses the site limit and product of its current line items,
	 * matched by software.
	 *
	 * @param object $subscription WC_Subscription.
	 */
	public static function sync_limits( $subscription ): void {
		$by_slug = array();

		foreach ( self::licensed_items( $subscription ) as $item ) {
			$by_slug[ Product::slug( $item->get_product() ) ] = $item;
		}

		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			$item = $by_slug[ $license['product_slug'] ] ?? null;

			if ( ! $item ) {
				continue;
			}

			$max = Product::max_activations( $item->get_product() );

			if ( $max !== $license['max_activations'] || (int) $item->get_variation_id() !== (int) $license['variation_id'] ) {
				Licenses::update(
					(int) $license['id'],
					array(
						'max_activations' => $max,
						'product_id'      => (int) $item->get_product_id(),
						'variation_id'    => $item->get_variation_id() ? (int) $item->get_variation_id() : null,
					)
				);
			}
		}
	}

	/**
	 * Sets the expiry of every license of a subscription that is still in use.
	 *
	 * @param object $subscription WC_Subscription.
	 * @param int    $timestamp    Expiry.
	 */
	private static function set_expiry( $subscription, int $timestamp ): void {
		foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
			if ( Licenses::STATUS_ACTIVE === $license['status'] ) {
				Licenses::update( (int) $license['id'], array( 'expires_at' => $timestamp ) );
			}
		}
	}

	/**
	 * The subscription's line items for licensed products.
	 *
	 * @param object $subscription WC_Subscription.
	 * @return \WC_Order_Item_Product[]
	 */
	private static function licensed_items( $subscription ): array {
		$items = array();

		foreach ( $subscription->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product() instanceof \WC_Product && Product::is_licensed( $item->get_product() ) ) {
				$items[] = $item;
			}
		}

		return $items;
	}
}
