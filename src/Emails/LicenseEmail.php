<?php
/**
 * Base of the license emails.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

use BeltoftLicenses\Account\Endpoint;
use BeltoftLicenses\Account\View;

defined( 'ABSPATH' ) || exit;

/**
 * A customer email about one license. The object is the license row.
 */
abstract class LicenseEmail extends \WC_Email {

	/**
	 * Constructor: the subclass sets id, title and templates, then calls this.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->email_group    = 'order-changes';
		$this->template_base  = BELTOFT_LICENSES_PATH . 'templates/';
		$this->placeholders   = array( '{product}' => '' );

		parent::__construct();
	}

	/**
	 * Sends the email for a license.
	 *
	 * @param array<string, mixed> $license License row.
	 * @return bool Whether the email was sent (false when disabled or without a recipient).
	 */
	public function trigger( $license ): bool {
		$this->setup_locale();

		$this->object                  = $license;
		$this->recipient               = (string) $license['email'];
		$this->placeholders['{product}'] = View::product_name( $license );

		$result = $this->send_notification();

		$this->restore_locale();

		// Older WooCommerce returns nothing from send_notification().
		return null === $result || (bool) $result;
	}

	/**
	 * The key with all but its last four characters hidden, keeping the dashes.
	 *
	 * @param string $key License key.
	 */
	public static function masked_key( string $key ): string {
		$length = strlen( $key );
		$out    = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$out .= ( $i >= $length - 4 || '-' === $key[ $i ] ) ? $key[ $i ] : '•';
		}

		return $out;
	}

	/**
	 * Template arguments.
	 *
	 * @param bool $plain_text Plain text version.
	 * @return array<string, mixed>
	 */
	protected function args( bool $plain_text ): array {
		return array_merge(
			$this->extra_args(),
			array(
			'license'            => $this->object,
			'product'            => View::product_name( $this->object ),
			'masked_key'         => self::masked_key( (string) $this->object['license_key'] ),
			'account_url'        => function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( Endpoint::slug() ) : '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
			)
		);
	}

	/**
	 * Template arguments a subclass adds.
	 *
	 * @return array<string, mixed>
	 */
	protected function extra_args(): array {
		return array();
	}

	/**
	 * HTML content.
	 */
	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->args( false ), '', $this->template_base );
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->args( true ), '', $this->template_base );
	}
}
