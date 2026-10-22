<?php
/**
 * New version available email (HTML).
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/new-version.php.
 *
 * @package BeltoftLicenses\Templates\Emails
 * @version 0.7.2
 *
 * @var string $product            Product name.
 * @var string $version            The new version.
 * @var string $changelog          Short changelog, plain text.
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
/* translators: 1: product name, 2: version. */
printf( esc_html__( 'Version %2$s of %1$s is now available.', 'beltoft-licenses' ), esc_html( $product ), '<strong>' . esc_html( $version ) . '</strong>' );
?>
</p>

<?php if ( $changelog ) : ?>
<h2><?php esc_html_e( 'What is new', 'beltoft-licenses' ); ?></h2>
<p><?php echo wp_kses( nl2br( esc_html( $changelog ) ), array( 'br' => array() ) ); ?></p>
<?php endif; ?>

<?php if ( $account_url ) : ?>
<p><a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Download it from your account', 'beltoft-licenses' ); ?></a></p>
<?php endif; ?>

<p><?php esc_html_e( 'Sites where your license is activated can also update from the WordPress dashboard (Dashboard → Updates).', 'beltoft-licenses' ); ?></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook.
