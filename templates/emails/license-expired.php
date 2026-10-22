<?php
/**
 * License expired email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/license-expired.php.
 *
 * @package BeltoftLicenses\Templates\Emails
 * @version 0.7.1
 *
 * @var string $product            Product name.
 * @var string $masked_key         Key with all but the last four characters hidden.
 * @var string $account_url        My Account, Licenses.
 * @var string $email_heading      Heading.
 * @var string $additional_content Additional content from the email's settings.
 * @var object $email              The WC_Email.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
?>

<p>
<?php
/* translators: 1: product name, 2: masked license key. */
printf( esc_html__( 'Your %1$s license (%2$s) has expired. The software stops getting updates and support until the license is renewed. Renewing keeps your key and your sites.', 'beltoft-licenses' ), esc_html( $product ), '<code>' . esc_html( $masked_key ) . '</code>' );
?>
</p>

<?php if ( $account_url ) : ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'See your licenses', 'beltoft-licenses' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
