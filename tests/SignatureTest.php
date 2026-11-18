<?php
/**
 * Signature tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Webhooks\Signature;

/**
 * Sign what you send, verify what you receive: in constant time, over the
 * raw bytes, inside a window, and never by skipping a missing header.
 */
final class SignatureTest extends TestCase {

	/*
	 * Not a Stripe secret, and shaped so it cannot be mistaken for one: the
	 * key is only an HMAC input here, and `whsec_` and 32 hex characters is
	 * the shape a secret scanner raises an alert on.
	 */
	private const SECRET  = 'test-signing-key-not-a-real-secret';
	private const PAYLOAD = '{"event":"order.completed","id":"evt_123","amount":4999}';
	private const NOW     = 1700000000;

	/**
	 * The clock runs again.
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/* ─── Round trip ────────────────────────────────────────────────── */

	public function test_a_signed_payload_verifies(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_the_header_has_the_expected_shape(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[a-f0-9]{64}$/', $header );
		$this->assertStringContainsString( 't=' . self::NOW, $header );
	}

	public function test_the_signing_time_is_reported_back(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertSame( self::NOW, Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->timestamp );
	}

	public function test_signing_is_deterministic(): void {
		$this->assertSame(
			Signature::sign( self::PAYLOAD, self::SECRET, self::NOW ),
			Signature::sign( self::PAYLOAD, self::SECRET, self::NOW )
		);
	}

	/**
	 * Pinned: a receiver verifies against this string, and the library this
	 * one replaced signed the same way byte for byte.
	 */
	public function test_the_signature_is_these_exact_bytes(): void {
		$this->assertSame(
			't=1767312000,v1=4a2d4fe401a5cb681818f4282e072aa8c3ea0f5539f1df21304b78eab5880b9d',
			Signature::sign( '{"type":"order.completed"}', 'endpoint-key', 1767312000 )
		);
	}

	/**
	 * Without a time given, the shared clock: a frozen one in a test.
	 */
	public function test_both_ends_read_the_shared_clock(): void {
		Clock::freeze( self::NOW );

		$header = Signature::sign( self::PAYLOAD, self::SECRET );

		$this->assertStringStartsWith( 't=' . self::NOW . ',', $header );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET )->valid );

		Clock::advance( 301 );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, self::SECRET )->valid );
	}

	/* ─── Tampering ─────────────────────────────────────────────────── */

	public function test_a_modified_payload_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$forged = str_replace( '4999', '1', self::PAYLOAD );

		$this->assertFalse( Signature::verify( $forged, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_a_different_secret_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, 'test-wrong-key', 300, self::NOW )->valid );
	}

	/**
	 * The timestamp is inside the signed string, so moving it forward to
	 * escape the freshness window breaks the signature.
	 */
	public function test_the_timestamp_cannot_be_moved_forward(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 10000 );
		$forged = preg_replace( '/^t=\d+/', 't=' . self::NOW, $header );

		$result = Signature::verify( self::PAYLOAD, (string) $forged, self::SECRET, 300, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'does not match', $result->reason );
	}

	public function test_a_flipped_signature_character_is_rejected(): void {
		$header  = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$flipped = substr( $header, 0, -1 ) . ( 'a' === substr( $header, -1 ) ? 'b' : 'a' );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $flipped, self::SECRET, 300, self::NOW )->valid );
	}

	/* ─── Replay ────────────────────────────────────────────────────── */

	/**
	 * Without a freshness check, a captured request stays valid forever.
	 */
	public function test_a_stale_but_genuine_signature_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 3600 );
		$result = Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertTrue( $result->failed_check() );
		$this->assertStringContainsString( 'time window', $result->reason );
	}

	public function test_the_window_edge_is_inclusive(): void {
		$this->assertTrue( Signature::verify( self::PAYLOAD, Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 300 ), self::SECRET, 300, self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 301 ), self::SECRET, 300, self::NOW )->valid );
	}

	public function test_clock_skew_is_tolerated_in_both_directions(): void {
		$this->assertTrue( Signature::verify( self::PAYLOAD, Signature::sign( self::PAYLOAD, self::SECRET, self::NOW + 120 ), self::SECRET, 300, self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, Signature::sign( self::PAYLOAD, self::SECRET, self::NOW + 301 ), self::SECRET, 300, self::NOW )->valid );
	}

	public function test_tolerance_can_be_disabled(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 999999 );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 0, self::NOW )->valid );
	}

	/**
	 * A negative window used to read as "no window", which turned a typo
	 * into accepting every replay for ever.
	 */
	public function test_a_negative_tolerance_fails_rather_than_switching_the_check_off(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$result = Signature::verify( self::PAYLOAD, $header, self::SECRET, -1, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'negative', $result->reason );
	}

	/**
	 * A forgery must never come back as merely stale.
	 */
	public function test_a_forged_stale_request_reports_a_bad_signature(): void {
		$forged = 't=' . ( self::NOW - 999999 ) . ',v1=' . str_repeat( 'a', 64 );
		$result = Signature::verify( self::PAYLOAD, $forged, self::SECRET, 300, self::NOW );

		$this->assertStringContainsString( 'does not match', $result->reason );
		$this->assertStringNotContainsString( 'time window', $result->reason );
	}

	/* ─── Malformed input ───────────────────────────────────────────── */

	#[DataProvider( 'bad_headers' )]
	public function test_malformed_headers_are_rejected( string $header ): void {
		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->valid );
	}

	/** @return array<string, array{0: string}> */
	public static function bad_headers(): array {
		$sig  = str_repeat( 'a', 64 );
		$real = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		return [
			'empty'            => [ '' ],
			'whitespace'       => [ '   ' ],
			'no timestamp'     => [ 'v1=' . $sig ],
			'no signature'     => [ 't=1700000000' ],
			'garbage'          => [ 'nonsense' ],
			'short signature'  => [ 't=1700000000,v1=' . str_repeat( 'a', 63 ) ],
			'uppercase sig'    => [ 't=1700000000,v1=' . strtoupper( $sig ) ],
			'non-hex sig'      => [ 't=1700000000,v1=' . str_repeat( 'z', 64 ) ],
			'negative time'    => [ 't=-1,v1=' . $sig ],
			'non-numeric time' => [ 't=abc,v1=' . $sig ],
			'two timestamps'   => [ 't=1,' . $real ],
			'two, same value'  => [ $real . ',t=' . self::NOW ],
			'sixteen digits'   => [ 't=1000000000000000,v1=' . $sig ],
		];
	}

	/**
	 * Correctly signed, and still refused: a timestamp that long is past
	 * any time anybody meant, and twenty digits overflow the integer it is
	 * read into, so the time checked would not be the time signed.
	 */
	public function test_a_timestamp_too_long_to_mean_anything_is_malformed(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, 1000000000000000 );
		$result = Signature::verify( self::PAYLOAD, $header, self::SECRET, 0, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'malformed', $result->reason );
		$this->assertTrue( Signature::verify( self::PAYLOAD, Signature::sign( self::PAYLOAD, self::SECRET, 100000000000000 ), self::SECRET, 0, self::NOW )->valid );
	}

	public function test_an_unsigned_request_is_a_rejection(): void {
		$result = Signature::verify( self::PAYLOAD, '', self::SECRET, 300, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'missing', $result->reason );
	}

	public function test_a_missing_secret_fails_closed(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, '', 300, self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, [], 300, self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, [ '', '' ], 300, self::NOW )->valid );
	}

	public function test_signing_without_a_secret_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		Signature::sign( self::PAYLOAD, '' );
	}

	public function test_signing_with_only_empty_secrets_throws(): void {
		$this->expectException( \InvalidArgumentException::class );

		Signature::sign( self::PAYLOAD, [ '', '' ] );
	}

	/* ─── Secret rotation ───────────────────────────────────────────── */

	/**
	 * Sending: both secrets at the same timestamp, one v1 apiece.
	 */
	public function test_signing_with_two_secrets_satisfies_either_receiver(): void {
		$header = Signature::sign( self::PAYLOAD, [ self::SECRET, 'test-rotated-key' ], self::NOW );

		$this->assertMatchesRegularExpression( '/^t=1700000000,v1=[a-f0-9]{64},v1=[a-f0-9]{64}$/', $header );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->valid );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, 'test-rotated-key', 300, self::NOW )->valid );
		$this->assertStringStartsWith( Signature::sign( self::PAYLOAD, self::SECRET, self::NOW ) . ',v1=', $header );
	}

	/**
	 * A header assembled by hand the same way verifies too.
	 */
	public function test_multiple_signatures_allow_rotation(): void {
		$old    = Signature::sign( self::PAYLOAD, 'test-rotated-key', self::NOW );
		$new    = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$merged = $old . ',' . explode( ',', $new )[1];

		$this->assertTrue( Signature::verify( self::PAYLOAD, $merged, self::SECRET, 300, self::NOW )->valid );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $merged, 'test-rotated-key', 300, self::NOW )->valid );
	}

	/**
	 * Receiving: accept either while the sender switches.
	 */
	public function test_a_receiver_can_accept_two_secrets(): void {
		$old = Signature::sign( self::PAYLOAD, 'test-rotated-key', self::NOW );
		$new = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $old, [ self::SECRET, 'test-rotated-key' ], 300, self::NOW )->valid );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $new, [ self::SECRET, 'test-rotated-key' ], 300, self::NOW )->valid );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $new, [ '', self::SECRET ], 300, self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, $new, [ 'test-wrong-key', 'test-other-key' ], 300, self::NOW )->valid );
	}

	/* ─── Raw body ──────────────────────────────────────────────────── */

	public function test_a_re_encoded_body_does_not_verify(): void {
		$header        = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$round_tripped = json_encode( json_decode( self::PAYLOAD, true ), JSON_PRETTY_PRINT );

		$this->assertFalse( Signature::verify( (string) $round_tripped, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_whitespace_in_the_body_is_significant(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD . "\n", $header, self::SECRET, 300, self::NOW )->valid );
	}
}
