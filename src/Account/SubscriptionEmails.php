<?php
/**
 * License block in WooCommerce Subscriptions' customer notifications.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Account;

use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the subscription's license keys and sites to WCS's own renewal and expiry notices, so the
 * customer sees what the payment keeps running. WCS sends and times those emails; this only adds
 * a few lines to them.
 */
final class SubscriptionEmails {

	/**
	 * WCS's customer notification emails.
	 */
	private const EMAIL_IDS = array(
		'customer_notification_auto_renewal',
		'customer_notification_manual_renewal',
		'customer_notification_subscription_expiry',
		'customer_notification_auto_trial_expiry',
		'customer_notification_manual_trial_expiry',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After WCS's own subscription table, which it prints at priority 10.
		add_action( 'woocommerce_subscriptions_email_order_details', array( __CLASS__, 'render' ), 20, 4 );
	}

	/**
	 * Does this email get the license block?
	 *
	 * @param mixed $email The WC_Email.
	 */
	public static function applies_to( $email ): bool {
		/**
		 * The IDs of the subscription emails that show the license block.
		 *
		 * @param string[] $ids Email IDs. Defaults to WCS's five customer notifications.
		 */
		$ids = (array) apply_filters( 'beltoft_licenses_subscription_email_ids', self::EMAIL_IDS );

		return is_object( $email ) && isset( $email->id ) && in_array( $email->id, $ids, true );
	}

	/**
	 * The licenses to show: the subscription's, except revoked ones.
	 *
	 * @param \WC_Order $subscription Subscription.
	 * @return array<int, array<string, mixed>>
	 */
	public static function licenses_for( \WC_Order $subscription ): array {
		return array_values(
			array_filter(
				Licenses::where( 'subscription_id', $subscription->get_id() ),
				static fn( array $license ) => Licenses::STATUS_REVOKED !== $license['status']
			)
		);
	}

	/**
	 * The block.
	 *
	 * @param mixed $subscription  The subscription.
	 * @param bool  $sent_to_admin Whether the email goes to the shop.
	 * @param bool  $plain_text    Plain-text email.
	 * @param mixed $email         The WC_Email.
	 */
	public static function render( $subscription, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( ! $subscription instanceof \WC_Order || $sent_to_admin || ! self::applies_to( $email ) ) {
			return;
		}

		$licenses = self::licenses_for( $subscription );

		if ( ! $licenses ) {
			return;
		}

		$manage = wc_get_account_endpoint_url( Endpoint::slug() );

		if ( $plain_text ) {
			echo "\n" . esc_html( strtoupper( __( 'Licenses', 'beltoft-licenses' ) ) ) . "\n\n";
			foreach ( $licenses as $license ) {
				$view = View::license( $license );
				echo esc_html( $view['product'] . ': ' . $view['key'] ) . "\n";
				echo esc_html( self::sites_line( $view ) ) . "\n\n";
			}
			/* translators: %s: URL of the My Account licenses page. */
			echo esc_html( sprintf( __( 'Manage your licenses: %s', 'beltoft-licenses' ), $manage ) ) . "\n\n";
			return;
		}

		echo '<h2>' . esc_html__( 'Licenses', 'beltoft-licenses' ) . '</h2><ul>';
		foreach ( $licenses as $license ) {
			$view = View::license( $license );
			printf(
				'<li>%1$s: <code>%2$s</code><br>%3$s</li>',
				esc_html( $view['product'] ),
				esc_html( $view['key'] ),
				esc_html( self::sites_line( $view ) )
			);
		}
		echo '</ul>';
		printf( '<p><a href="%1$s">%2$s</a></p>', esc_url( $manage ), esc_html__( 'Manage your licenses', 'beltoft-licenses' ) );
	}

	/**
	 * "1 of 3 sites: shop.example".
	 *
	 * @param array<string, mixed> $view Display values from View::license().
	 */
	private static function sites_line( array $view ): string {
		return $view['domains'] ? $view['sites'] . ': ' . implode( ', ', $view['domains'] ) : $view['sites'];
	}
}
