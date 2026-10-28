<?php
/**
 * Report of licenses that may have been issued twice for one order item or subscription item.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Before issuance was made atomic, two requests could both create a license for one item. This
 * finds such groups after the fact. It only reports: it never changes or deletes a license
 * (which of two keys the customer already uses is for a person to decide), and it never fails.
 */
final class Duplicates {

	public const OPTION  = 'beltoft_licenses_duplicates';
	public const OPTION_SCANNED = 'beltoft_licenses_duplicates_scanned';
	public const DISMISS = 'beltoft_licenses_dismiss_duplicates';

	/**
	 * Hooks: the admin notice and its dismiss link.
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_' . self::DISMISS, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * Groups of order- or subscription-sourced licenses for the same order or subscription, product
	 * and variation, with more licenses than the quantity ordered. A group is left out when the
	 * order or subscription can be loaded and its quantity covers the licenses. Free-plan licenses
	 * are shared by design and not counted.
	 *
	 * @return array<int, array{owner: string, owner_id: int, product_id: int|null, variation_id: int|null, license_ids: array<int, int>, expected: int|null}>
	 */
	public static function find(): array {
		global $wpdb;

		$table    = Install::tables()['licenses'];
		$suppress = $wpdb->suppress_errors( true );
		// The same grouping as the SQL in the report; a licenses table without rows or without the table gives nothing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only check on our own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_id, subscription_id, product_id, variation_id, COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids
				FROM %i WHERE source = 'order' AND ( order_id IS NOT NULL OR subscription_id IS NOT NULL )
				GROUP BY order_id, subscription_id, product_id, variation_id HAVING COUNT(*) > 1",
				$table
			),
			ARRAY_A
		);
		$wpdb->suppress_errors( $suppress );

		$groups = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$is_sub   = null !== $row['subscription_id'];
			$owner_id = (int) ( $is_sub ? $row['subscription_id'] : $row['order_id'] );
			$product  = null === $row['product_id'] ? null : (int) $row['product_id'];
			$variant  = null === $row['variation_id'] ? null : (int) $row['variation_id'];
			$expected = self::quantity( $owner_id, $product, $variant );

			if ( null !== $expected && (int) $row['n'] <= $expected ) {
				continue;
			}

			$groups[] = array(
				'owner'        => $is_sub ? 'subscription' : 'order',
				'owner_id'     => $owner_id,
				'product_id'   => $product,
				'variation_id' => $variant,
				'license_ids'  => array_map( 'intval', explode( ',', (string) $row['ids'] ) ),
				'expected'     => $expected,
			);
		}

		return $groups;
	}

	/**
	 * Units of one product on an order or subscription, or null when it cannot be loaded.
	 *
	 * @param int      $owner_id     Order or subscription ID.
	 * @param int|null $product_id   Product.
	 * @param int|null $variation_id Variation.
	 */
	private static function quantity( int $owner_id, ?int $product_id, ?int $variation_id ): ?int {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $owner_id );

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		$units = 0;

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && (int) $item->get_product_id() === (int) $product_id && (int) $item->get_variation_id() === (int) $variation_id ) {
				$units += max( 1, (int) $item->get_quantity() );
			}
		}

		return $units;
	}

	/**
	 * Scans as soon as WooCommerce can load orders (the upgrade runs on plugins_loaded, before its
	 * post types exist). Without WooCommerce it scans at once and reports every group.
	 */
	public static function schedule_scan(): void {
		// Once per upgrade attempt, not on every request while the version is behind.
		if ( Install::DB_VERSION === get_option( self::OPTION_SCANNED ) ) {
			return;
		}

		update_option( self::OPTION_SCANNED, Install::DB_VERSION, false );

		if ( ! class_exists( 'WooCommerce' ) || did_action( 'woocommerce_after_register_post_type' ) ) {
			self::scan_and_store();
		} else {
			add_action( 'woocommerce_after_register_post_type', array( __CLASS__, 'scan_and_store' ), 99 );
		}
	}

	/**
	 * Finds the groups and stores them for the admin notice. Never throws.
	 *
	 * @return array<int, array<string, mixed>> The groups.
	 */
	public static function scan_and_store(): array {
		try {
			$groups = self::find();
		} catch ( \Throwable $e ) {
			$groups = array();
		}

		if ( $groups ) {
			update_option( self::OPTION, $groups, false );
		} else {
			delete_option( self::OPTION );
		}

		return $groups;
	}

	/**
	 * The admin notice, for people who manage licenses.
	 */
	public static function notice(): void {
		$groups = get_option( self::OPTION );

		if ( ! is_array( $groups ) || ! $groups || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$lines = array();
		foreach ( array_slice( $groups, 0, 10 ) as $group ) {
			$lines[] = sprintf(
				/* translators: 1: order or subscription, 2: its number, 3: license IDs. */
				esc_html__( '%1$s #%2$d: licenses %3$s', 'beltoft-licenses' ),
				esc_html( 'subscription' === $group['owner'] ? __( 'Subscription', 'beltoft-licenses' ) : __( 'Order', 'beltoft-licenses' ) ),
				(int) $group['owner_id'],
				esc_html( implode( ', ', array_map( 'intval', (array) $group['license_ids'] ) ) )
			);
		}

		$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS ), self::DISMISS );

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p><a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Beltoft Licenses:', 'beltoft-licenses' ),
			esc_html(
				sprintf(
					/* translators: %d: number of groups. */
					_n( '%d order or subscription item may have more licenses than it should. Nothing was changed; revoke the extra key yourself if it is a duplicate.', '%d order or subscription items may have more licenses than they should. Nothing was changed; revoke the extra keys yourself if they are duplicates.', count( $groups ), 'beltoft-licenses' ),
					count( $groups )
				)
			),
			wp_kses( implode( '<br>', $lines ), array( 'br' => array() ) ),
			esc_url( $dismiss ),
			esc_html__( 'Dismiss', 'beltoft-licenses' )
		);
	}

	/**
	 * admin-post: dismiss the notice.
	 */
	public static function handle_dismiss(): void {
		check_admin_referer( self::DISMISS );

		if ( current_user_can( 'manage_woocommerce' ) ) {
			delete_option( self::OPTION );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
