<?php
/**
 * The "License expired" email.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to the customer when a license expires (the subscription ended, or its term ran out).
 */
final class LicenseExpired extends LicenseEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'beltoft_licenses_expired';
		$this->title          = __( 'License expired', 'beltoft-licenses' );
		$this->description    = __( 'Sent to the customer when one of their licenses expires.', 'beltoft-licenses' );
		$this->template_html  = 'emails/license-expired.php';
		$this->template_plain = 'emails/plain/license-expired.php';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'Your {product} license has expired', 'beltoft-licenses' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'Your license has expired', 'beltoft-licenses' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content(): string {
		return __( 'Thank you for using our software.', 'beltoft-licenses' );
	}
}
