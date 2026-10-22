<?php
/**
 * License revoked email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/license-revoked.php.
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: 1: product name, 2: masked license key. */
printf( esc_html__( 'Your %1$s license (%2$s) has been revoked. It no longer works: the software will not get updates or support, and sites using it can no longer activate it.', 'beltoft-licenses' ), esc_html( $product ), '<code>' . esc_html( $masked_key ) . '</code>' );
?>
</p>

<?php if ( $account_url ) : ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'See your licenses', 'beltoft-licenses' ); ?></a></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
