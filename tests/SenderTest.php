<?php
/**
 * Sender tests.
 *
 * @package SugarCommerce\Webhooks\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\{Fake, Response};
use SugarCommerce\Webhooks\{Sender, Signature};

/**
 * Class SenderTest
 */
final class SenderTest extends TestCase {

	private const string URL = 'https://hooks.example/receive';

	private const string SECRET = 'whsec-test-secret';

	public function test_a_delivery_is_signed_now_and_carries_its_headers(): void {
		$fake    = ( new Fake( new Response( true, 204 ) ) );
		$attempt = ( new Sender( $fake ) )->send( self::URL, '{"id":1}', self::SECRET, array( 'Shop-Event' => 'order.paid', 'Shop-Delivery' => '42' ), 1_800_000_000 );

		$this->assertTrue( $attempt->sent );
		$this->assertSame( 204, $attempt->status );
		$this->assertSame( '', $attempt->error );

		$sent = $fake->sent( 'POST', self::URL );
		$this->assertCount( 1, $sent );
		$headers = array_change_key_case( $sent[0]['headers'], CASE_LOWER );
		$this->assertSame( 'application/json', $headers['content-type'] );
		$this->assertSame( 'order.paid', $headers['shop-event'] );
		$this->assertSame( '{"id":1}', $sent[0]['body'] );
		$this->assertTrue( Signature::verify( '{"id":1}', $headers[ strtolower( Signature::HEADER ) ], self::SECRET, now: 1_800_000_010 )->valid );
	}

	public function test_extra_headers_cannot_replace_the_signature_or_content_type(): void {
		$fake = new Fake( new Response( true, 200 ) );
		( new Sender( $fake ) )->send( self::URL, '{}', self::SECRET, array( Signature::HEADER => 'forged', 'Content-Type' => 'text/plain' ) );

		$headers = array_change_key_case( $fake->sent()[0]['headers'], CASE_LOWER );
		$this->assertNotSame( 'forged', $headers[ strtolower( Signature::HEADER ) ] );
		$this->assertSame( 'application/json', $headers['content-type'] );
	}

	public function test_a_refusal_reports_the_status_and_when_to_come_back(): void {
		$fake    = new Fake( new Response( false, 503, headers: array( 'retry-after' => '120' ) ) );
		$attempt = ( new Sender( $fake ) )->send( self::URL, '{}', self::SECRET );

		$this->assertFalse( $attempt->sent );
		$this->assertSame( 503, $attempt->status );
		$this->assertSame( 'Endpoint answered 503', $attempt->error );
		$this->assertSame( 120, $attempt->retry_after );
	}

	public function test_no_answer_is_a_failed_attempt_not_an_exception(): void {
		$attempt = ( new Sender( new Fake( Response::failure( 'Operation timed out' ) ) ) )->send( self::URL, '{}', self::SECRET );

		$this->assertFalse( $attempt->sent );
		$this->assertSame( 0, $attempt->status );
		$this->assertSame( 'Operation timed out', $attempt->error );
		$this->assertNull( $attempt->retry_after );
	}

	public function test_a_client_that_throws_is_a_failed_attempt_too(): void {
		$fake = ( new Fake() )->on( 'POST', '*', static function (): Response {
			throw new \InvalidArgumentException( 'Refusing to connect to a private address.' );
		} );

		$attempt = ( new Sender( $fake ) )->send( self::URL, '{}', self::SECRET );

		$this->assertFalse( $attempt->sent );
		$this->assertSame( 'Refusing to connect to a private address.', $attempt->error );
	}

	public function test_another_signature_header_name(): void {
		$fake = new Fake( new Response( true, 200 ) );
		( new Sender( $fake, header: 'Shop-Signature' ) )->send( self::URL, '{}', self::SECRET );

		$this->assertArrayHasKey( 'shop-signature', array_change_key_case( $fake->sent()[0]['headers'], CASE_LOWER ) );
	}
}
