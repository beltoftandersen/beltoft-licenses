<?php
/**
 * The GitHub release webhook.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Releases\GitHub;
use BeltoftLicenses\Software;

class GitHubTest extends TestCase {

	private const SECRET = 'whsec_test';

	public function set_up(): void {
		parent::set_up();
		$this->settings(
			array(
				'github_secret' => self::SECRET,
				'github_repos'  => "Beltoft/Rel-Plugin => rel-plugin\nnot a line\nbad repo => x",
			)
		);
		$product = new \WC_Product_Simple();
		$product->update_meta_data( Software::META_LICENSED, 'yes' );
		$product->update_meta_data( Software::META_SLUG, 'rel-plugin' );
		$product->save();
	}

	private function hook( array $payload, string $event = 'release', ?string $secret = null, string $delivery = '' ): \WP_REST_Response {
		$body    = (string) wp_json_encode( $payload );
		$request = new \WP_REST_Request( 'POST', '/beltoft-licenses/v1/github' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-github-event', $event );
		$request->set_header( 'x-hub-signature-256', 'sha256=' . hash_hmac( 'sha256', $body, $secret ?? self::SECRET ) );
		if ( '' !== $delivery ) {
			$request->set_header( 'x-github-delivery', $delivery );
		}
		$request->set_body( $body );

		return rest_get_server()->dispatch( $request );
	}

	private function published( array $release = array() ): array {
		return array(
			'action'     => 'published',
			'repository' => array( 'full_name' => 'beltoft/rel-plugin' ),
			'release'    => array_merge(
				array(
					'tag_name'    => 'v1.4.0',
					'body'        => 'Notes.',
					'draft'       => false,
					'prerelease'  => false,
					'zipball_url' => 'https://api.github.com/repos/beltoft/rel-plugin/zipball/v1.4.0',
					'assets'      => array(),
				),
				$release
			),
		);
	}

	public function test_repositories_setting(): void {
		$this->assertSame( array( 'beltoft/rel-plugin' => 'rel-plugin' ), GitHub::repositories() );
	}

	public function test_signature_and_configuration(): void {
		$this->assertSame( 401, $this->hook( $this->published(), 'release', 'wrong' )->get_status() );
		$this->assertSame( 200, $this->hook( array( 'zen' => 'hi' ), 'ping' )->get_status() );

		$this->settings( array( 'github_secret' => '' ) );
		$this->assertSame( 403, $this->hook( $this->published() )->get_status() );
	}

	public function test_ignored_events(): void {
		$this->assertSame( 'ignored', $this->body( $this->hook( array_merge( $this->published(), array( 'action' => 'created' ) ) ) )['code'] );
		$this->assertSame( 'ignored', $this->body( $this->hook( array_merge( $this->published(), array( 'repository' => array( 'full_name' => 'someone/else' ) ) ) ) )['code'] );
		$this->assertSame( 'ignored', $this->body( $this->hook( $this->published( array( 'prerelease' => true ) ) ) )['code'] );
		$this->assertSame( 'ignored', $this->body( $this->hook( $this->published( array( 'draft' => true ) ) ) )['code'] );
	}

	public function test_published_release_is_queued_once(): void {
		$first = $this->hook( $this->published(), 'release', null, 'abc-123' );
		$this->assertSame( 202, $first->get_status() );
		$this->assertSame( 'duplicate', $this->body( $this->hook( $this->published(), 'release', null, 'abc-123' ) )['code'] );

		$actions = as_get_scheduled_actions( array( 'hook' => GitHub::JOB, 'status' => \ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$this->assertCount( 1, $actions );
		as_unschedule_all_actions( GitHub::JOB );
	}

	public function test_job_downloads_the_attached_zip_and_publishes(): void {
		$this->settings( array( 'github_token' => 'ghp_secret' ) );
		$sent = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$sent ) {
				$sent = array( $url, $args['headers'] );
				copy( ReleasesTest::zip( array( 'rel-plugin/rel-plugin.php' => '<?php' ) ), $args['filename'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
			},
			10,
			3
		);

		GitHub::process(
			array(
				'repo'      => 'beltoft/rel-plugin',
				'slug'      => 'rel-plugin',
				'version'   => '1.4.0',
				'changelog' => 'Notes.',
				'asset'     => array( 'url' => 'https://api.github.com/repos/beltoft/rel-plugin/releases/assets/1' ),
				'source'    => '',
			)
		);

		$this->assertSame( 'https://api.github.com/repos/beltoft/rel-plugin/releases/assets/1', $sent[0] );
		$this->assertSame( 'application/octet-stream', $sent[1]['Accept'] );
		$this->assertSame( 'Bearer ghp_secret', $sent[1]['Authorization'] );
		$this->assertSame( '1.4.0', Software::current_version( 'rel-plugin' ) );
		$this->assertSame( 'Notes.', Software::release( 'rel-plugin', '1.4.0' )['changelog'] );
		$this->assertStringContainsString( 'published', GitHub::log()[0]['message'] );
	}

	public function test_job_repackages_the_source_zip_and_respects_auto_publish(): void {
		$this->settings( array( 'github_auto_publish' => 0 ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				copy( ReleasesTest::zip( array( 'beltoft-rel-plugin-1a2b3c/rel-plugin.php' => '<?php' ) ), $args['filename'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => $args['filename'] );
			},
			10,
			2
		);

		GitHub::process( array( 'repo' => 'beltoft/rel-plugin', 'slug' => 'rel-plugin', 'version' => '1.5.0', 'changelog' => '', 'asset' => null, 'source' => 'https://api.github.com/x/zipball' ) );

		$this->assertNotNull( Software::release( 'rel-plugin', '1.5.0' ) );
		$this->assertSame( '', (string) get_post_meta( Software::product_id( 'rel-plugin' ), Software::META_VERSION, true ), 'stored but not current' );
	}

	public function test_job_records_a_failed_download(): void {
		add_filter(
			'pre_http_request',
			static fn() => array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null )
		);
		GitHub::process( array( 'repo' => 'beltoft/rel-plugin', 'slug' => 'rel-plugin', 'version' => '9.0.0', 'changelog' => '', 'asset' => null, 'source' => 'https://api.github.com/x' ) );

		$this->assertNull( Software::release( 'rel-plugin', '9.0.0' ) );
		$this->assertStringContainsString( 'HTTP 404', GitHub::log()[0]['message'] );
	}

	public function test_secret_is_kept_when_the_field_is_left_empty(): void {
		$clean = \BeltoftLicenses\Settings::sanitize( array( 'github_secret' => '' ) );
		$this->assertSame( self::SECRET, $clean['github_secret'] );
	}
}
