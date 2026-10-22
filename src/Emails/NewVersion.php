<?php
/**
 * The "New version available" email.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent to a customer holding an active license when a newer version of their software is
 * published. One email covers every license the customer holds for that software.
 */
final class NewVersion extends LicenseEmail {

	/**
	 * Version being announced.
	 *
	 * @var string
	 */
	private $version = '';

	/**
	 * Short changelog, plain text.
	 *
	 * @var string
	 */
	private $changelog = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id             = 'beltoft_licenses_new_version';
		$this->title          = __( 'New version available', 'beltoft-licenses' );
		$this->description    = __( 'Sent to customers with an active license when a new version of their software is published, with a link to download it from their account.', 'beltoft-licenses' );
		$this->template_html  = 'emails/new-version.php';
		$this->template_plain = 'emails/plain/new-version.php';

		parent::__construct();

		$this->placeholders['{version}'] = '';
	}

	/**
	 * Sends the email.
	 *
	 * @param array<string, mixed> $license Any one of the customer's licenses for the software (its email is used).
	 * @param array<string, mixed> $release The release: version and changelog.
	 * @return bool Whether the email was sent.
	 */
	public function trigger( $license, array $release = array() ): bool {
		$this->version                   = (string) ( $release['version'] ?? '' );
		$this->changelog                 = self::short_changelog( (string) ( $release['changelog'] ?? '' ) );
		$this->placeholders['{version}'] = $this->version;

		return parent::trigger( $license );
	}

	/**
	 * A changelog as short plain text: no markup, one line per entry, at most 800 characters.
	 *
	 * @param string $changelog Changelog as stored.
	 */
	public static function short_changelog( string $changelog ): string {
		$text  = wp_strip_all_tags( $changelog );
		$lines = array_filter( array_map( 'trim', preg_split( '/\R/', $text ) ?: array() ), static fn( $line ) => '' !== $line );
		$text  = implode( "\n", $lines );

		if ( function_exists( 'mb_strimwidth' ) ) {
			return mb_strimwidth( $text, 0, 800, '…' );
		}

		return strlen( $text ) > 800 ? substr( $text, 0, 799 ) . '…' : $text;
	}

	/**
	 * Template arguments.
	 *
	 * @return array<string, mixed>
	 */
	protected function extra_args(): array {
		return array(
			'version'   => $this->version,
			'changelog' => $this->changelog,
		);
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( '{product} {version} is available', 'beltoft-licenses' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'A new version is available', 'beltoft-licenses' );
	}

	/**
	 * Default additional content.
	 */
	public function get_default_additional_content(): string {
		return __( 'Thank you for using our software.', 'beltoft-licenses' );
	}
}
