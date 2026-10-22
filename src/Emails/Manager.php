<?php
/**
 * Customer emails when a license is revoked or expires.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

use BeltoftLicenses\Events;
use BeltoftLicenses\Import;
use BeltoftLicenses\Licenses;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the two WooCommerce emails and sends them from the license's own actions. The
 * emails are real WC_Email classes, so shop owners edit and switch them off under
 * WooCommerce → Settings → Emails and themes override their templates.
 */
final class Manager {

	/**
	 * Status => the key of the email in WooCommerce's mailer.
	 */
	private const EMAILS = array(
		Licenses::STATUS_REVOKED => 'Beltoft_Licenses_Email_Revoked',
		Licenses::STATUS_EXPIRED => 'Beltoft_Licenses_Email_Expired',
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'classes' ) );
		add_action( 'beltoft_licenses_status_changed', array( __CLASS__, 'on_change' ) );
		add_action( 'beltoft_licenses_expiry_changed', array( __CLASS__, 'on_change' ) );
	}

	/**
	 * Adds the emails to WooCommerce's list.
	 *
	 * @param array<string, object> $emails Emails.
	 * @return array<string, object>
	 */
	public static function classes( $emails ) {
		if ( ! class_exists( '\WC_Email' ) ) {
			return $emails;
		}

		$emails[ self::EMAILS[ Licenses::STATUS_REVOKED ] ] = new LicenseRevoked();
		$emails[ self::EMAILS[ Licenses::STATUS_EXPIRED ] ] = new LicenseExpired();

		return $emails;
	}

	/**
	 * A license changed status or expiry. Revoked, or expired (by status, or by an expiry that has
	 * passed), emails the customer once. Never during an import, and not for suspended licenses.
	 *
	 * @param array<string, mixed> $license License after the change.
	 */
	public static function on_change( $license ): void {
		if ( ! is_array( $license ) || Import::is_running() || ! function_exists( 'WC' ) ) {
			return;
		}

		$status = Licenses::effective_status( $license );

		if ( ! isset( self::EMAILS[ $status ] ) || ! is_email( (string) $license['email'] ) ) {
			return;
		}

		/**
		 * Whether to email the customer about this license ending.
		 *
		 * @param bool                 $send    Default true.
		 * @param array<string, mixed> $license License.
		 * @param string               $status  revoked or expired.
		 */
		if ( ! apply_filters( 'beltoft_licenses_send_status_email', true, $license, $status ) ) {
			return;
		}

		$mailer = WC()->mailer()->get_emails();
		$email  = $mailer[ self::EMAILS[ $status ] ] ?? null;

		if ( ! $email instanceof LicenseEmail || ! $email->is_enabled() ) {
			return;
		}

		// Once per license and ending. An expired license that comes back (a resubscribe) and
		// expires again has a new expiry, so the marker holds it and the customer is told again.
		// A revoked license is told once, whatever its expiry does afterwards.
		$type   = 'email_' . $status;
		$marker = Licenses::STATUS_EXPIRED === $status ? '[' . (string) $license['expires_at'] . ']' : '[revoked]';

		foreach ( Events::for_license( (int) $license['id'], 500 ) as $event ) {
			if ( $type === $event['type'] && false !== strpos( (string) $event['message'], $marker ) ) {
				return;
			}
		}

		// Recorded only once the email went out, so a failed send is tried again on the next change.
		if ( $email->trigger( $license ) ) {
			/* translators: 1: status (revoked or expired), 2: marker with the expiry. */
			Events::add( (int) $license['id'], $type, sprintf( __( 'Customer emailed: license %1$s. %2$s', 'beltoft-licenses' ), $status, $marker ) );
		}
	}
}
