<?php
/**
 * The "New version available" customer email.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Emails\UpdateNotifier;
use BeltoftLicenses\Events;
use BeltoftLicenses\Import;
use BeltoftLicenses\Licenses;
use BeltoftLicenses\Releases\Publisher;
use BeltoftLicenses\Software;

class NewVersionEmailTest extends TestCase {

	private const SLUG = 'nv-plugin';

	/**
	 * @var int
	 */
	private $product_id = 0;

	public function set_up(): void {
		parent::set_up();

		$product = new \WC_Product_Simple();
		$product->set_name( 'NV Plugin' );
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, self::SLUG );
		$product->save();
		$this->product_id = $product->get_id();

		$this->publish( '1.0.0' );
		$this->clear_queue();
		$this->fresh_mailer();
	}

	private function lic( array $data = array() ): array {
		return $this->license( array_merge( array( 'product_slug' => self::SLUG, 'product_id' => $this->product_id ), $data ) );
	}

	private function publish( string $version, string $changelog = 'Changes.', bool $current = true ) {
		return Publisher::publish( self::SLUG, $version, ReleasesTest::zip( array( self::SLUG . '/a.php' => $version ) ), $changelog, 'upload', $current );
	}

	private function fresh_mailer(): void {
		$instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null, null );
		WC()->mailer();
	}

	/**
	 * @return int[] Pending action IDs.
	 */
	private function pending(): array {
		return as_get_scheduled_actions( array( 'hook' => UpdateNotifier::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
	}

	private function clear_queue(): void {
		as_unschedule_all_actions( UpdateNotifier::HOOK );
		delete_option( UpdateNotifier::OPTION );
	}

	/**
	 * Runs the queued actions the way Action Scheduler does, one at a time, and returns the mail sent.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function run_queue(): array {
		$sent   = array();
		$filter = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = array( 'to' => is_array( $atts['to'] ) ? $atts['to'][0] : $atts['to'], 'subject' => (string) $atts['subject'], 'message' => (string) $atts['message'] );
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );

		for ( $guard = 0; $guard < 10 && $this->pending(); $guard++ ) {
			foreach ( $this->pending() as $id ) {
				$action = \ActionScheduler::store()->fetch_action( $id );
				\ActionScheduler::store()->mark_complete( $id );
				do_action_ref_array( $action->get_hook(), $action->get_args() );
			}
		}

		remove_filter( 'pre_wp_mail', $filter, 10 );

		return $sent;
	}

	public function test_publishing_a_newer_version_queues_one_background_action_and_sends_nothing_inline(): void {
		$this->lic();

		$sent   = array();
		$filter = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		$this->publish( '1.1.0' );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertSame( array(), $sent );
		$this->assertCount( 1, $this->pending() );
	}

	public function test_only_active_holders_are_emailed(): void {
		$this->lic( array( 'email' => 'active@example.org' ) );
		$this->lic( array( 'email' => 'revoked@example.org', 'status' => 'revoked' ) );
		$this->lic( array( 'email' => 'suspended@example.org', 'status' => 'suspended' ) );
		$this->lic( array( 'email' => 'expired@example.org', 'status' => 'expired' ) );
		$this->lic( array( 'email' => 'past@example.org', 'expires_at' => time() - DAY_IN_SECONDS ) );
		$this->lic( array( 'email' => 'other@example.org', 'product_slug' => 'another-plugin' ) );

		$this->publish( '1.1.0' );
		$sent = $this->run_queue();

		$this->assertSame( array( 'active@example.org' ), array_column( $sent, 'to' ) );
		$this->assertStringContainsString( 'NV Plugin 1.1.0', $sent[0]['subject'] );
	}

	public function test_a_customer_with_several_licenses_gets_one_email(): void {
		$a = $this->lic( array( 'email' => 'Multi@Example.org' ) );
		$b = $this->lic( array( 'email' => 'multi@example.org' ) );

		$this->publish( '1.1.0' );
		$sent = $this->run_queue();

		$this->assertCount( 1, $sent );
		$this->assertCount( 1, array_filter( Events::for_license( (int) $a['id'] ), static fn( $e ) => 'email_new_version' === $e['type'] ) );
		$this->assertCount( 1, array_filter( Events::for_license( (int) $b['id'] ), static fn( $e ) => 'email_new_version' === $e['type'] ) );
	}

	public function test_batches_of_fifty_through_action_scheduler(): void {
		for ( $i = 0; $i < UpdateNotifier::BATCH + 5; $i++ ) {
			$this->lic( array( 'email' => sprintf( 'c%03d@example.org', $i ) ) );
		}

		$this->publish( '1.1.0' );
		$this->assertCount( 1, $this->pending() );

		// One batch only: it sends 50 and queues the next for the remaining 5.
		$id     = $this->pending()[0];
		$action = \ActionScheduler::store()->fetch_action( $id );
		\ActionScheduler::store()->mark_complete( $id );
		$sent   = array();
		$filter = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts['to'];
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		do_action_ref_array( $action->get_hook(), $action->get_args() );
		$this->assertCount( UpdateNotifier::BATCH, $sent );
		$this->assertCount( 1, $this->pending() );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$rest = $this->run_queue();
		$this->assertCount( 5, $rest );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_rollback_does_not_email(): void {
		$this->lic();
		$this->publish( '1.1.0' );
		$this->run_queue();
		$this->clear_queue();

		$this->assertTrue( Software::set_current( self::SLUG, '1.0.0' ) );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_promoting_a_release_published_as_not_current_emails(): void {
		$this->lic();
		$this->publish( '1.1.0', 'x', false );
		$this->assertSame( array(), $this->pending() );

		Software::set_current( self::SLUG, '1.1.0' );

		$this->assertCount( 1, $this->pending() );
	}

	public function test_a_rollback_before_the_batch_runs_cancels_it(): void {
		$this->lic();
		$this->publish( '1.1.0' );
		Software::set_current( self::SLUG, '1.0.0' );

		$this->assertSame( array(), $this->run_queue() );
	}

	public function test_a_release_is_announced_once(): void {
		$this->lic();
		$this->publish( '1.1.0' );
		$this->assertCount( 1, $this->run_queue() );

		// Published again after a delete: still the same release.
		Software::delete_release( self::SLUG, '1.1.0' );
		$this->publish( '1.1.0' );
		$this->assertSame( array(), $this->pending() );

		// And Software::set_current() to the same version again.
		Software::set_current( self::SLUG, '1.0.0' );
		Software::set_current( self::SLUG, '1.1.0' );
		$this->assertSame( array(), $this->pending() );
	}

	public function test_a_batch_that_runs_twice_does_not_email_twice(): void {
		$this->lic();
		$this->publish( '1.1.0' );
		$args = \ActionScheduler::store()->fetch_action( $this->pending()[0] )->get_args();

		$sent   = array();
		$filter = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts['to'];
			return true;
		};
		add_filter( 'pre_wp_mail', $filter, 10, 2 );
		UpdateNotifier::send_batch( ...$args );
		UpdateNotifier::send_batch( ...$args );
		remove_filter( 'pre_wp_mail', $filter, 10 );

		$this->assertCount( 1, $sent );
	}

	public function test_nothing_is_queued_or_sent_during_an_import(): void {
		$this->lic();
		$running = new \ReflectionProperty( Import::class, 'running' );
		$running->setAccessible( true );

		$running->setValue( null, true );
		$this->publish( '1.1.0' );
		$running->setValue( null, false );

		$this->assertSame( array(), $this->pending() );

		// An import starting after the action was queued stops it.
		$this->publish( '1.2.0' );
		$running->setValue( null, true );
		$sent = $this->run_queue();
		$running->setValue( null, false );
		$this->assertSame( array(), $sent );
	}

	public function test_the_email_can_be_switched_off(): void {
		$this->lic();
		update_option( 'woocommerce_beltoft_licenses_new_version_settings', array( 'enabled' => 'no' ) );
		$this->fresh_mailer();

		$this->publish( '1.1.0' );

		$this->assertSame( array(), $this->pending() );
	}

	public function test_filter_opts_a_license_out(): void {
		$this->lic( array( 'email' => 'in@example.org' ) );
		$out = $this->lic( array( 'email' => 'out@example.org' ) );
		add_filter( 'beltoft_licenses_send_update_email', static fn( $send, $license ) => (int) $license['id'] !== (int) $out['id'] && $send, 10, 2 );

		$this->publish( '1.1.0' );

		$this->assertSame( array( 'in@example.org' ), array_column( $this->run_queue(), 'to' ) );
	}

	public function test_content_links_to_the_account_and_escapes_the_changelog(): void {
		$this->lic( array( 'email' => 'c@example.org' ) );

		$this->publish( '2.0.0', "= 2.0.0 =\n* Fixed <script>alert(1)</script> & more\n\n* Added <b>thing</b>" );
		$sent = $this->run_queue();

		$html = $sent[0]['message'];
		$this->assertStringContainsString( 'Version <strong>2.0.0</strong> of NV Plugin is now available', $html );
		$this->assertStringContainsString( wc_get_account_endpoint_url( 'licenses' ), $html );
		$this->assertStringContainsString( 'WordPress dashboard', $html );
		$this->assertStringContainsString( 'Fixed  &amp; more', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>thing', $html );
		$this->assertStringNotContainsString( 'license_key', $html );
	}

	public function test_changelog_is_trimmed_and_plain_text_version_renders(): void {
		$this->assertSame( 800, mb_strlen( \BeltoftLicenses\Emails\NewVersion::short_changelog( str_repeat( 'a', 2000 ) ) ) );

		$this->lic();
		$this->publish( '1.3.0', 'Plain <i>log</i>' );
		$email = WC()->mailer()->get_emails()['Beltoft_Licenses_Email_New_Version'];
		$email->trigger( $this->lic( array( 'email' => 'p@example.org' ) ), Software::release( self::SLUG, '1.3.0' ) );
		$plain = $email->get_content_plain();

		$this->assertStringContainsString( 'Version 1.3.0 of NV Plugin is now available.', $plain );
		$this->assertStringContainsString( 'Plain log', $plain );
		$this->assertStringContainsString( wc_get_account_endpoint_url( 'licenses' ), $plain );
		$this->assertStringNotContainsString( '<i>', $plain );
	}
}
