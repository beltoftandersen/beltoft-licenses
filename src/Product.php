<?php
/**
 * License settings on WooCommerce products.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses;

defined( 'ABSPATH' ) || exit;

/**
 * Which products are licensed, for which software, with how many sites, and the "License"
 * tab where that is set. Variations can override the site limit (1-, 2-, 3-site tiers).
 */
final class Product {

	public const META_MAX  = '_beltoft_licenses_max_activations';
	public const META_FREE = '_beltoft_licenses_free_plan';

	/**
	 * Hooks for the product editor.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * The parent product of a variation, or the product itself.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function parent( \WC_Product $product ): \WC_Product {
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent instanceof \WC_Product ) {
				return $parent;
			}
		}

		return $product;
	}

	/**
	 * Is this product (or its parent) a licensed product with a software slug?
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function is_licensed( \WC_Product $product ): bool {
		$parent = self::parent( $product );

		return 'yes' === $parent->get_meta( Software::META_LICENSED ) && '' !== self::slug( $product );
	}

	/**
	 * The software slug.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function slug( \WC_Product $product ): string {
		return sanitize_title( (string) self::parent( $product )->get_meta( Software::META_SLUG ) );
	}

	/**
	 * The site limit: the variation's, else the product's, else 1. 0 means unlimited.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function max_activations( \WC_Product $product ): int {
		foreach ( array( $product, self::parent( $product ) ) as $candidate ) {
			$value = $candidate->get_meta( self::META_MAX );
			if ( '' !== $value && null !== $value ) {
				return max( 0, (int) $value );
			}
		}

		return 1;
	}

	/**
	 * Is this a free plan: claimed once per customer, never expiring?
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function is_free_plan( \WC_Product $product ): bool {
		return 'yes' === self::parent( $product )->get_meta( self::META_FREE );
	}

	/**
	 * Is this a WooCommerce Subscriptions product? Its licenses follow the subscription.
	 *
	 * @param \WC_Product $product Product or variation.
	 */
	public static function is_subscription( \WC_Product $product ): bool {
		return class_exists( 'WC_Subscriptions_Product' ) && \WC_Subscriptions_Product::is_subscription( $product );
	}

	/**
	 * The "License" tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public static function tab( array $tabs ): array {
		$tabs['beltoft_licenses'] = array(
			'label'    => __( 'License', 'beltoft-licenses' ),
			'target'   => 'beltoft_licenses_product_data',
			'class'    => array(),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * The tab's fields.
	 */
	public static function panel(): void {
		global $product_object;

		$product = $product_object instanceof \WC_Product ? $product_object : null;

		echo '<div id="beltoft_licenses_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';

		woocommerce_wp_checkbox(
			array(
				'id'          => Software::META_LICENSED,
				'label'       => __( 'Licensed product', 'beltoft-licenses' ),
				'description' => __( 'Issue a license key for each one sold.', 'beltoft-licenses' ),
				'value'       => $product ? $product->get_meta( Software::META_LICENSED ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Software::META_SLUG,
				'label'       => __( 'Software slug', 'beltoft-licenses' ),
				'description' => __( 'The plugin or theme folder name. The license API and downloads use it.', 'beltoft-licenses' ),
				'desc_tip'    => true,
				'value'       => $product ? $product->get_meta( Software::META_SLUG ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_MAX,
				'label'             => __( 'Sites per license', 'beltoft-licenses' ),
				'description'       => __( '0 for unlimited. Variations can set their own.', 'beltoft-licenses' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				'placeholder'       => '1',
				'value'             => $product ? $product->get_meta( self::META_MAX ) : '',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => Software::META_VERSION,
				'label'       => __( 'Current version', 'beltoft-licenses' ),
				'description' => __( 'Set automatically when a release is published. Change it to roll back.', 'beltoft-licenses' ),
				'desc_tip'    => true,
				'value'       => $product ? $product->get_meta( Software::META_VERSION ) : '',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_FREE,
				'label'       => __( 'Free plan', 'beltoft-licenses' ),
				'description' => __( 'One license per customer, with no expiry. A second order reuses the first key.', 'beltoft-licenses' ),
				'value'       => $product ? $product->get_meta( self::META_FREE ) : '',
			)
		);

		echo '</div></div>';
	}

	/**
	 * Saves the tab. WooCommerce has verified the product form's nonce and the user's
	 * capability before this action runs.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save( \WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before woocommerce_admin_process_product_object.
		$product->update_meta_data( Software::META_LICENSED, isset( $_POST[ Software::META_LICENSED ] ) ? 'yes' : 'no' );
		$product->update_meta_data( self::META_FREE, isset( $_POST[ self::META_FREE ] ) ? 'yes' : 'no' );
		$product->update_meta_data( Software::META_SLUG, isset( $_POST[ Software::META_SLUG ] ) ? sanitize_title( wp_unslash( $_POST[ Software::META_SLUG ] ) ) : '' );
		$product->update_meta_data( Software::META_VERSION, isset( $_POST[ Software::META_VERSION ] ) ? sanitize_text_field( wp_unslash( $_POST[ Software::META_VERSION ] ) ) : '' );

		$max = isset( $_POST[ self::META_MAX ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::META_MAX ] ) ) ) : '';
		// phpcs:enable
		$product->update_meta_data( self::META_MAX, '' === $max ? '' : (string) absint( $max ) );
	}

	/**
	 * Site limit field on each variation.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Unused.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function variation_field( $loop, $variation_data, $variation ): void {
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_MAX . '_' . $loop,
				'name'              => self::META_MAX . '[' . $loop . ']',
				'label'             => __( 'Sites per license', 'beltoft-licenses' ),
				'description'       => __( 'Empty uses the product\'s setting. 0 for unlimited.', 'beltoft-licenses' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
				'wrapper_class'     => 'form-row form-row-first',
				'value'             => get_post_meta( $variation->ID, self::META_MAX, true ),
			)
		);
	}

	/**
	 * Saves a variation's site limit. WooCommerce has verified the nonce
	 * (save-variations) before this action runs.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param int                   $i         Index.
	 */
	public static function save_variation( $variation, $i ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations).
		$raw = isset( $_POST[ self::META_MAX ][ $i ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::META_MAX ][ $i ] ) ) ) : '';
		$variation->update_meta_data( self::META_MAX, '' === $raw ? '' : (string) absint( $raw ) );
	}
}
