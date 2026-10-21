<?php
/**
 * How a license is shown to its customer.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Account;

use BeltoftLicenses\Licenses;
use BeltoftLicenses\Settings;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a license into the values templates print, in one place for My Account, order pages
 * and emails, so they never disagree.
 */
final class View {

	/**
	 * Display values for one license.
	 *
	 * @param array<string, mixed> $license License.
	 * @return array<string, mixed>
	 */
	public static function license( array $license ): array {
		$status  = Licenses::effective_status( $license );
		$domains = array_map( static fn( $a ) => (string) $a['domain'], Licenses::live_activations( (int) $license['id'], 'site' ) );
		$version = '' !== $license['product_slug'] ? Software::current_version( $license['product_slug'] ) : null;
		$active  = Licenses::STATUS_ACTIVE === $status;

		$can_download = $active
			&& null !== $version && '' !== $version
			&& null !== Software::release( (string) $license['product_slug'], (string) $version )
			&& ( ! Settings::get( 'download_requires_domain' ) || $domains );

		return array(
			'license'      => $license,
			'id'           => (int) $license['id'],
			'key'          => (string) $license['license_key'],
			'product'      => self::product_name( $license ),
			'status'       => $status,
			'status_label' => self::status_label( $status ),
			'active'       => $active,
			'max'          => (int) $license['max_activations'],
			'used'         => count( $domains ),
			'sites'        => self::sites_label( count( $domains ), (int) $license['max_activations'] ),
			'domains'      => $domains,
			'expiry'       => self::expiry_label( $license, $status ),
			'version'      => (string) $version,
			'can_download' => $can_download,
			'download_url' => $can_download ? Endpoint::download_url( (int) $license['id'] ) : '',
			'subscription' => self::subscription_url( $license ),
		);
	}

	/**
	 * The product's name, or the software slug when the product is gone.
	 *
	 * @param array<string, mixed> $license License.
	 */
	public static function product_name( array $license ): string {
		$product = $license['product_id'] && function_exists( 'wc_get_product' ) ? wc_get_product( (int) $license['product_id'] ) : null;

		return $product ? $product->get_name() : (string) $license['product_slug'];
	}

	/**
	 * Translated status.
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			Licenses::STATUS_ACTIVE    => __( 'Active', 'beltoft-licenses' ),
			Licenses::STATUS_EXPIRED   => __( 'Expired', 'beltoft-licenses' ),
			Licenses::STATUS_REVOKED   => __( 'Revoked', 'beltoft-licenses' ),
			Licenses::STATUS_SUSPENDED => __( 'Suspended', 'beltoft-licenses' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * "1 of 3 sites" or "2 sites (unlimited)".
	 *
	 * @param int $used Live sites.
	 * @param int $max  Limit, 0 for unlimited.
	 */
	public static function sites_label( int $used, int $max ): string {
		if ( 0 === $max ) {
			/* translators: %d: number of sites in use. */
			return sprintf( _n( '%d site (unlimited)', '%d sites (unlimited)', $used, 'beltoft-licenses' ), $used );
		}

		/* translators: 1: sites in use, 2: site limit. */
		return sprintf( _n( '%1$d of %2$d site', '%1$d of %2$d sites', $max, 'beltoft-licenses' ), $used, $max );
	}

	/**
	 * When the license ends, in words.
	 *
	 * @param array<string, mixed> $license License.
	 * @param string               $status  Effective status.
	 */
	public static function expiry_label( array $license, string $status ): string {
		if ( null !== $license['expires_at'] ) {
			$date = wp_date( get_option( 'date_format' ), (int) strtotime( $license['expires_at'] . ' UTC' ) );

			return Licenses::STATUS_EXPIRED === $status
				/* translators: %s: date. */
				? sprintf( __( 'Expired %s', 'beltoft-licenses' ), $date )
				/* translators: %s: date. */
				: sprintf( __( 'Until %s', 'beltoft-licenses' ), $date );
		}

		if ( $license['subscription_id'] ) {
			return __( 'Renews with your subscription', 'beltoft-licenses' );
		}

		return __( 'Lifetime', 'beltoft-licenses' );
	}

	/**
	 * The customer's view of the license's subscription, when WooCommerce Subscriptions is active.
	 *
	 * @param array<string, mixed> $license License.
	 */
	private static function subscription_url( array $license ): string {
		if ( ! $license['subscription_id'] || ! function_exists( 'wcs_get_subscription' ) ) {
			return '';
		}

		$subscription = wcs_get_subscription( (int) $license['subscription_id'] );

		return $subscription ? (string) $subscription->get_view_order_url() : '';
	}
}
