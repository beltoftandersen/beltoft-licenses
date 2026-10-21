<?php
/**
 * Download tokens.
 *
 * @package BeltoftLicenses
 */

namespace BeltoftLicenses\Tests;

use BeltoftLicenses\Api\Token;

class TokenTest extends TestCase {

	public function test_round_trip(): void {
		$payload = Token::verify( Token::create( 42, 'example.com', 60 ) );
		$this->assertSame( 42, $payload['license_id'] );
		$this->assertSame( 'example.com', $payload['domain'] );
	}

	public function test_tampered_payload_is_refused(): void {
		$token                  = Token::create( 42, 'example.com', 60 );
		list( $payload, $sig )  = explode( '.', $token );
		$forged                 = rtrim( strtr( base64_encode( (string) wp_json_encode( array( 'license_id' => 43, 'domain' => 'example.com', 'exp' => time() + 60 ) ) ), '+/', '-_' ), '=' );
		$this->assertNull( Token::verify( $forged . '.' . $sig ) );
		$this->assertNull( Token::verify( $payload . '.x' . $sig ) );
	}

	public function test_expired_token_is_refused(): void {
		$this->assertNull( Token::verify( Token::create( 42, 'example.com', -1 ) ) );
	}

	public function test_garbage_is_refused(): void {
		foreach ( array( '', 'abc', 'a.b.c', 'abc.def', null, 42 ) as $bad ) {
			$this->assertNull( Token::verify( $bad ) );
		}
	}

	public function test_secret_constant_is_not_required(): void {
		$this->assertNotEmpty( \BeltoftLicenses\Install::secret() );
	}
}
