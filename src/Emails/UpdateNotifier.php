<?php
/**
 * Tells customers when a new version of their software is published.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

use BeltoftLicenses\Events;
use BeltoftLicenses\Import;
use BeltoftLicenses\Install;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Software;

defined( 'ABSPATH' ) || exit;

/**
 * When Software::set_current() makes a newer release current (a publish from the admin or
 * GitHub; never a rollback), one Action Scheduler action is queued. It emails the holders of
 * active licenses in batches of {@see self::BATCH}, grouped by address so a customer with
 * several licenses of the software gets one email. A release is announced once, even if it
 * is deleted and published again.
 */
final class UpdateNotifier {

	public const HOOK    = 'beltoft_licenses_send_update_emails';
	public const GROUP   = 'beltoft-licenses';
	public const OPTION  = 'beltoft_licenses_update_notified';
	public const BATCH   = 50;
	public const EVENT   = 'email_new_version';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'classes' ) );
		add_action( 'beltoft_licenses_version_current', array( __CLASS__, 'on_current' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'send_batch' ), 10, 3 );
	}

	/**
	 * Adds the email to WooCommerce's list.
	 *
	 * @param array<string, object> $emails Emails.
	 * @return array<string, object>
	 */
	public static function classes( $emails ) {
		if ( class_exists( '\WC_Email' ) ) {
			$emails['Beltoft_Licenses_Email_New_Version'] = new NewVersion();
		}

		return $emails;
	}

	/**
	 * A newer version became current: queue the first batch, once per release.
	 *
	 * @param string $slug    Software slug.
	 * @param string $version Version.
	 */
	public static function on_current( $slug, $version ): void {
		$slug    = (string) $slug;
		$version = (string) $version;

		if ( Import::is_running() || ! function_exists( 'as_enqueue_async_action' ) || ! self::email() ) {
			return;
		}

		$done = get_option( self::OPTION, array() );
		$done = is_array( $done ) ? $done : array();
		$key  = $slug . '|' . $version;

		if ( isset( $done[ $key ] ) ) {
			return;
		}

		$done[ $key ] = time();
		update_option( self::OPTION, array_slice( $done, -200, null, true ), false );

		as_enqueue_async_action( self::HOOK, array( $slug, $version, '' ), self::GROUP );
	}

	/**
	 * Sends one batch and queues the next when this one was full.
	 *
	 * @param string $slug    Software slug.
	 * @param string $version Version being announced.
	 * @param string $after   Last address of the previous batch.
	 */
	public static function send_batch( $slug, $version, $after = '' ): void {
		$slug    = (string) $slug;
		$version = (string) $version;
		$after   = (string) $after;
		$email   = self::email();
		$release = Software::release( $slug, $version );

		// Stop when an import runs, the email was switched off, or the release was rolled back
		// or deleted since the batch was queued.
		if ( Import::is_running() || ! $email || ! $release || Software::current_version( $slug ) !== $version ) {
			return;
		}

		$addresses = self::addresses( $slug, $after );

		foreach ( $addresses as $address ) {
			self::notify( $email, $address, $slug, $release );
		}

		if ( count( $addresses ) >= self::BATCH && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $slug, $version, (string) end( $addresses ) ), self::GROUP );
		}
	}

	/**
	 * The email object when it is enabled.
	 */
	private static function email(): ?NewVersion {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return null;
		}

		$email = WC()->mailer()->get_emails()['Beltoft_Licenses_Email_New_Version'] ?? null;

		return $email instanceof NewVersion && $email->is_enabled() ? $email : null;
	}

	/**
	 * The next addresses (lowercase, sorted) with an active license for the software.
	 *
	 * @param string $slug  Software slug.
	 * @param string $after Return only addresses after this one.
	 * @return string[]
	 */
	private static function addresses( string $slug, string $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, always fresh.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT LOWER(email) AS addr FROM %i WHERE product_slug = %s AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) AND email <> '' AND LOWER(email) > %s ORDER BY addr ASC LIMIT %d",
				Install::tables()['licenses'],
				$slug,
				gmdate( 'Y-m-d H:i:s' ),
				$after,
				self::BATCH
			)
		);

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Emails one customer, once per release.
	 *
	 * @param NewVersion           $email   The email.
	 * @param string               $address Lowercase address.
	 * @param string               $slug    Software slug.
	 * @param array<string, mixed> $release Release row.
	 */
	private static function notify( NewVersion $email, string $address, string $slug, array $release ): void {
		if ( ! is_email( $address ) ) {
			return;
		}

		$marker   = '[' . $slug . ' ' . $release['version'] . ']';
		$licenses = array();

		foreach ( Licenses::where( 'email', $address ) as $license ) {
			if ( $license['product_slug'] !== $slug || Licenses::STATUS_ACTIVE !== Licenses::effective_status( $license ) ) {
				continue;
			}

			/**
			 * Whether to tell the holder of this license about the new version.
			 *
			 * @param bool                 $send    Default true.
			 * @param array<string, mixed> $license License.
			 * @param array<string, mixed> $release Release row (version, changelog, product_slug).
			 */
			if ( apply_filters( 'beltoft_licenses_send_update_email', true, $license, $release ) ) {
				$licenses[] = $license;
			}
		}

		if ( ! $licenses ) {
			return;
		}

		// A batch that ran twice (a crash, a retry) must not email twice.
		foreach ( Events::for_license( (int) $licenses[0]['id'], 500 ) as $event ) {
			if ( self::EVENT === $event['type'] && false !== strpos( (string) $event['message'], $marker ) ) {
				return;
			}
		}

		if ( $email->trigger( $licenses[0], $release ) ) {
			foreach ( $licenses as $license ) {
				/* translators: %s: software slug and version. */
				Events::add( (int) $license['id'], self::EVENT, sprintf( __( 'Customer emailed: new version %s.', 'beltoft-licenses' ), $marker ) );
			}
		}
	}
}
