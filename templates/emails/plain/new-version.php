<?php
/**
 * New version available email (plain text).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/new-version.php.
 *
 * @package BeltoftLicenses\Templates\Emails\Plain
 * @version 0.7.2
 *
 * @var string $product            Product name.
 * @var string $version            The new version.
 * @var string $changelog          Short changelog, plain text.
 * @var string $account_url        My Account, Licenses.
 * @var string $email_heading      Heading.
 * @var string $additional_content Additional content from the email's settings.
 */

defined( 'ABSPATH' ) || exit;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html( wp_strip_all_tags( $email_heading ) );
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: 1: product name, 2: version. */
echo esc_html( sprintf( __( 'Version %2$s of %1$s is now available.', 'beltoft-licenses' ), $product, $version ) ) . "\n\n";

if ( $changelog ) {
	echo esc_html__( 'What is new', 'beltoft-licenses' ) . "\n" . esc_html( $changelog ) . "\n\n";
}

if ( $account_url ) {
	echo esc_html__( 'Download it from your account:', 'beltoft-licenses' ) . ' ' . esc_url( $account_url ) . "\n\n";
}

echo esc_html__( 'Sites where your license is activated can also update from the WordPress dashboard (Dashboard → Updates).', 'beltoft-licenses' ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
