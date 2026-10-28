<?php
/**
 * Licenses from paid orders, and revoking them on refund.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Lifecycle;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Locks;
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
	 * Action Scheduler hook (group beltoft-licenses) that issues again when the lock was busy.
	 */
	public const RETRY_HOOK = 'beltoft_licenses_retry_issue';

	/**
	 * Tries a busy lock this many times (30 seconds apart) before leaving a note for the admin.
	 */
	public const MAX_RETRIES = 10;

	/**
	 * The retry attempt being run (0 outside a retry).
	 *
	 * @var int
	 */
	private static $attempt = 0;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'issue_for_order_id' ) );
		add_action( self::RETRY_HOOK, array( __CLASS__, 'retry' ), 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'revoke_for_order_id' ) );
		add_action( 'woocommerce_order_fully_refunded', array( __CLASS__, 'cancel_subscriptions_for_order_id' ), 20 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'revoke_for_refunded_lines' ), 10, 2 );
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
			self::issue_for_subscriptions( $order );
		}
	}

	/**
	 * Issues the licenses of the subscriptions this order started, as soon as it is paid.
	 *
	 * WooCommerce runs `woocommerce_order_status_{to}` (class-wc-order.php, status_transition,
	 * ~line 457) before `woocommerce_order_status_{from}_to_{to}` (~line 477), which is the
	 * action that sends the customer's "processing" email. WooCommerce Subscriptions activates
	 * the subscription later still, so without this the first email would have no key.
	 * Subscriptions::issue() is idempotent, so activation finds these licenses in place.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function issue_for_subscriptions( \WC_Order $order ): void {
		foreach ( self::subscriptions_for( $order, array( 'parent' ) ) as $subscription ) {
			$issued = Subscriptions::issue( $subscription );

			// This runs before WooCommerce Subscriptions records the payment and activates the
			// subscription (it does that on woocommerce_order_status_changed, class-wc-subscriptions-order.php:40,
			// after this hook). If it does not activate (an order moved by hand from a status WCS does not treat
			// as unpaid, or a gateway filter), the key must not be perpetual: it gets the grace period, as
			// an on-hold subscription does, and Subscriptions::activated() clears it when the subscription starts.
			if ( $issued && 'active' !== $subscription->get_status() ) {
				self::grace( $issued );
			}
		}
	}

	/**
	 * Gives freshly issued licenses the grace period as their expiry.
	 *
	 * @param array<int, array<string, mixed>> $licenses Licenses.
	 */
	public static function grace( array $licenses ): void {
		$until = time() + DAY_IN_SECONDS * max( 1, (int) Settings::get( 'grace_days' ) );

		foreach ( $licenses as $license ) {
			Licenses::update( (int) $license['id'], array( 'expires_at' => $until ) );
		}
	}

	/**
	 * The subscriptions linked to an order through the given relations (parent, renewal,
	 * resubscribe, switch). Empty without WooCommerce Subscriptions.
	 *
	 * @param \WC_Order     $order Order.
	 * @param array<string> $types Order types, as `wcs_get_subscriptions_for_order()` takes them.
	 * @return array<int, object> WC_Subscription objects.
	 */
	public static function subscriptions_for( \WC_Order $order, array $types ): array {
		$subscriptions = array();

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = array_values( wcs_get_subscriptions_for_order( $order, array( 'order_type' => $types ) ) );
		}

		/**
		 * The subscriptions linked to an order through the given relations. A site using a
		 * subscriptions plugin other than WooCommerce Subscriptions can return its own subscription
		 * objects (with the WC_Subscription methods this plugin calls), or narrow the list.
		 *
		 * @param array<int, object> $subscriptions Subscriptions.
		 * @param \WC_Order          $order         Order.
		 * @param array<string>      $types         Order types.
		 */
		return (array) apply_filters( 'beltoft_licenses_order_subscriptions', $subscriptions, $order, $types );
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

			list( $locked, $new ) = Locks::with(
				Locks::name( 'item', (int) $item->get_id() ),
				Locks::wait(),
				fn() => self::issue_item( $order, $item, $product )
			);

			if ( ! $locked ) {
				// Another request is issuing for this item right now. Never create without the
				// lock: try again shortly.
				self::defer( (int) $order->get_id() );
				continue;
			}

			$issued = array_merge( $issued, $new );
		}

		foreach ( $issued as $license ) {
			/* translators: 1: license key, 2: software slug. */
			$order->add_order_note( sprintf( __( 'License %1$s issued for %2$s.', 'beltoft-licenses' ), $license['license_key'], $license['product_slug'] ) );
		}

		return $issued;
	}

	/**
	 * Issues the missing licenses of one line item. Runs under the item's lock, so what it reads
	 * is what the other request left: the item is read afresh, and each unit carries a unique
	 * marker, so even a missed lock could not create a second license for the same unit.
	 *
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Line item (possibly stale).
	 * @param \WC_Product            $product Product or variation.
	 * @return array<int, array<string, mixed>> Licenses issued now.
	 */
	private static function issue_item( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product ): array {
		$fresh = $item->get_id() ? \WC_Order_Factory::get_order_item( $item->get_id() ) : null;
		$work  = $fresh instanceof \WC_Order_Item_Product ? $fresh : $item;

		$ids     = array_filter( array_map( 'intval', (array) $work->get_meta( self::ITEM_META ) ) );
		$wanted  = Product::is_free_plan( $product ) ? 1 : max( 1, (int) $work->get_quantity() );
		$missing = $wanted - count( $ids );
		$issued  = array();

		for ( $i = 0; $i < $missing; $i++ ) {
			list( $license, $is_new ) = self::license_for( $order, $work, $product, count( $ids ) + 1 );

			if ( $license ) {
				$ids[] = (int) $license['id'];
				if ( $is_new ) {
					$issued[] = $license;
				}
			}
		}

		$work->update_meta_data( self::ITEM_META, array_values( array_unique( $ids ) ) );
		$work->save();

		if ( $work !== $item ) {
			$item->read_meta_data( true );
		}

		return $issued;
	}

	/**
	 * Tries issuing again later, through Action Scheduler (WP-Cron without it). Gives up after
	 * MAX_RETRIES with a note on the order.
	 *
	 * @param int $order_id Order ID.
	 */
	private static function defer( int $order_id ): void {
		$attempt = self::$attempt + 1;

		if ( $attempt > self::MAX_RETRIES ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof \WC_Order ) {
				$order->add_order_note( __( 'Licenses could not be issued: another request kept the lock. Issue them from the license box of this order.', 'beltoft-licenses' ) );
			}

			return;
		}

		$args = array( $order_id, $attempt );
		$when = time() + 30;

		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( self::RETRY_HOOK, array( $order_id, $attempt ), 'beltoft-licenses' ) ) {
				as_schedule_single_action( $when, self::RETRY_HOOK, $args, 'beltoft-licenses' );
			}
		} elseif ( ! wp_next_scheduled( self::RETRY_HOOK, $args ) ) {
			wp_schedule_single_event( $when, self::RETRY_HOOK, $args );
		}
	}

	/**
	 * The scheduled retry: issues for the order again. A lock still busy defers again.
	 *
	 * @param int $order_id Order ID.
	 * @param int $attempt  Which attempt this is.
	 */
	public static function retry( $order_id, $attempt = 1 ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Subscription || ! $order->is_paid() ) {
			return;
		}

		self::$attempt = (int) $attempt;

		try {
			self::issue( $order );
		} finally {
			self::$attempt = 0;
		}
	}

	/**
	 * A new license for one unit, or the customer's existing one for a free plan.
	 *
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Line item.
	 * @param \WC_Product            $product Product or variation.
	 * @param int                    $unit    Which unit of the line this is, from 1.
	 * @return array{0: array<string, mixed>|null, 1: bool} The license, and whether it is new.
	 */
	private static function license_for( \WC_Order $order, \WC_Order_Item_Product $item, \WC_Product $product, int $unit ): array {
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
				'issue_key'       => $item->get_id() ? 'oi' . $item->get_id() . '-' . $unit : null,
			)
		);

		if ( ! $license && $item->get_id() ) {
			// The marker is taken: this unit already has its license.
			return array( Licenses::find_by_issue_key( 'oi' . $item->get_id() . '-' . $unit ), false );
		}

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
			self::revoke( $license, $order, __( 'the order was fully refunded', 'beltoft-licenses' ) );
		}
	}

	/**
	 * Revokes a license (once) and notes it on the order.
	 *
	 * @param array<string, mixed> $license License.
	 * @param \WC_Order|false|null $order   Order to note on.
	 * @param string               $reason  Why, for the note.
	 */
	private static function revoke( array $license, $order, string $reason ): void {
		// A free-plan license is the customer's one key, shared by every order that claimed it.
		if ( Licenses::STATUS_REVOKED === $license['status'] || 'free' === $license['source'] ) {
			return;
		}

		Licenses::update(
			(int) $license['id'],
			array(
				'status'     => Licenses::STATUS_REVOKED,
				'expires_at' => time(),
			)
		);

		if ( $order instanceof \WC_Order ) {
			/* translators: 1: license key, 2: reason. */
			$order->add_order_note( sprintf( __( 'License %1$s revoked: %2$s.', 'beltoft-licenses' ), $license['license_key'], $reason ) );
		}
	}

	/**
	 * A refund with quantities revokes as many licenses of each refunded line as units refunded
	 * (`woocommerce_order_refunded`, wc-order-functions.php:752, after the refund and its line
	 * items are saved). A refund by amount only has no quantities and revokes nothing here; a
	 * full refund is handled by revoke_for_order_id().
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public static function revoke_for_refunded_lines( $order_id, $refund_id ): void {
		if ( ! Settings::get( 'revoke_on_refund' ) ) {
			return;
		}

		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );

		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund ) {
			return;
		}

		$subscriptions = null;

		foreach ( $refund->get_items() as $refund_item ) {
			$units = abs( (int) $refund_item->get_quantity() );
			$item  = $order->get_item( (int) $refund_item->get_meta( '_refunded_item_id' ) );

			if ( $units < 1 || ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof \WC_Product || ! Product::is_licensed( $product ) || Product::is_free_plan( $product ) ) {
				continue;
			}

			if ( Product::is_subscription( $product ) ) {
				// Only the order that sold the key (parent, resubscribe and switch orders): a renewal
				// order's line refund leaves the subscription billing, so its key stays.
				$subscriptions = $subscriptions ?? self::subscriptions_for( $order, array( 'parent', 'switch' ) );

				if ( ! $subscriptions ) {
					$order->add_order_note( __( 'A subscription line was refunded: no license was revoked, because this is not the order that started the subscription.', 'beltoft-licenses' ) );
					continue;
				}

				$slug          = Product::slug( $product );
				$candidates    = array();

				foreach ( $subscriptions as $subscription ) {
					foreach ( Licenses::where( 'subscription_id', (int) $subscription->get_id() ) as $license ) {
						if ( $license['product_slug'] === $slug ) {
							$candidates[ (int) $license['id'] ] = $license;
						}
					}
				}
			} else {
				$candidates = array();

				foreach ( array_filter( array_map( 'intval', (array) $item->get_meta( self::ITEM_META ) ) ) as $id ) {
					$license = Licenses::find( $id );
					if ( $license ) {
						$candidates[ $id ] = $license;
					}
				}
			}

			// Newest first, and never count one that is already revoked.
			krsort( $candidates );
			$candidates = array_filter( $candidates, static fn( $l ) => Licenses::STATUS_REVOKED !== $l['status'] );

			foreach ( array_slice( $candidates, 0, $units ) as $license ) {
				self::revoke( $license, $order, __( 'its line was refunded', 'beltoft-licenses' ) );
			}
		}
	}

	/**
	 * A fully refunded order ends the subscriptions linked to it (parent, renewal, resubscribe
	 * and switch orders), as an admin would by cancelling them. WooCommerce Subscriptions only
	 * cancels one that is pending cancellation
	 * (class-wc-subscriptions-order.php, maybe_cancel_subscription_on_full_refund), so an active
	 * subscription would keep billing. Cancelling fires `woocommerce_subscription_status_cancelled`,
	 * which expires the licenses; nothing here refunds or fires a refund action, so no loop.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function cancel_subscriptions_for_order_id( $order_id ): void {
		if ( ! Settings::get( 'cancel_subscription_on_refund' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Subscription ) {
			return;
		}

		foreach ( self::subscriptions_for( $order, array( 'parent', 'renewal', 'resubscribe', 'switch' ) ) as $subscription ) {
			if ( in_array( $subscription->get_status(), array( 'cancelled', 'expired', 'trash' ), true ) ) {
				continue;
			}

			try {
				$subscription->update_status( 'cancelled', __( 'Cancelled: the order was fully refunded.', 'beltoft-licenses' ) );
			} catch ( \Exception $e ) {
				$subscription->add_order_note(
					/* translators: %s: error message. */
					sprintf( __( 'Could not cancel after the refund: %s', 'beltoft-licenses' ), $e->getMessage() )
				);
			}
		}
	}
}
