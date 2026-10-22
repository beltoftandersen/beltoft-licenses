<?php
/**
 * The "License revoked" email.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when a license is revoked (a refund, or an admin).
 */
final class LicenseRevoked extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'beltoft_licenses_revoked';
		$this->title          = __( 'License revoked', 'beltoft-licenses' );
		$this->description    = __( 'Sent to the customer when one of their licenses is revoked, for example after a refund.', 'beltoft-licenses' );
		$this->template_html  = 'emails/license-revoked.php';
		$this->template_plain = 'emails/plain/license-revoked.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'Your {product} license has been revoked', 'beltoft-licenses' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'Your license has been revoked', 'beltoft-licenses' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content(): string {
		return __( 'If you think this is a mistake, please reply to this email.', 'beltoft-licenses' );
	}
}
