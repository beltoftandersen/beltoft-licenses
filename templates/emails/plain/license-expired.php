<?php
/**
 * License expired email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/license-expired.php.
 *
 * @package BeltoftLicenses\Templates\Emails\Plain
 * @version 0.7.1
 *
 * @var string $product            Product name.
 * @var string $masked_key         Key with all but the last four characters hidden.
 * @var string $account_url        My Account, Licenses.
 * @var string $email_heading      Heading.
 * @var string $additional_content Additional content from the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: 1: product name, 2: masked license key. */
echo esc_html( sprintf( __( 'Your %1$s license (%2$s) has expired. The software stops getting updates and support until the license is renewed. Renewing keeps your key and your sites.', 'beltoft-licenses' ), $product, $masked_key ) ) . "\n\n";

if ( $account_url ) {
	echo esc_html__( 'See your licenses:', 'beltoft-licenses' ) . ' ' . esc_url( $account_url ) . "\n\n";
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
