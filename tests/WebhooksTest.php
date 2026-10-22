<?php
/**
 * Webhooks test suite.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Webhooks\RetryPolicy;
use SugarCommerce\Webhooks\Signature;

final class WebhooksTest extends TestCase {

	private const SECRET  = 'whsec_a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5';
	private const PAYLOAD = '{"event":"order.completed","id":"evt_123","amount":4999}';
	private const NOW     = 1700000000;

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

	/* ─── Tampering ─────────────────────────────────────────────────── */

	public function test_a_modified_payload_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$forged = str_replace( '4999', '1', self::PAYLOAD );

		$this->assertFalse( Signature::verify( $forged, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_a_different_secret_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, 'whsec_wrong', 300, self::NOW )->valid );
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
	 * Without a freshness check, a captured request stays valid forever —
	 * so anyone who observes one can replay it to refund the same order
	 * or re-grant the same licence indefinitely.
	 */
	public function test_a_stale_but_genuine_signature_is_rejected(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 3600 );
		$result = Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'time window', $result->reason );
	}

	public function test_a_signature_within_tolerance_is_accepted(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 120 );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_clock_skew_is_tolerated_in_both_directions(): void {
		// The sender's clock can be ahead as easily as behind.
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW + 120 );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_tolerance_can_be_disabled(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 999999 );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $header, self::SECRET, 0, self::NOW )->valid );
	}

	/**
	 * A forgery must never come back as merely stale — that would tell an
	 * attacker their signature was otherwise well-formed.
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
		$sig = str_repeat( 'a', 64 );

		return array(
			'empty'            => array( '' ),
			'whitespace'       => array( '   ' ),
			'no timestamp'     => array( 'v1=' . $sig ),
			'no signature'     => array( 't=1700000000' ),
			'garbage'          => array( 'nonsense' ),
			'short signature'  => array( 't=1700000000,v1=' . str_repeat( 'a', 63 ) ),
			'uppercase sig'    => array( 't=1700000000,v1=' . strtoupper( $sig ) ),
			'non-hex sig'      => array( 't=1700000000,v1=' . str_repeat( 'z', 64 ) ),
			'negative time'    => array( 't=-1,v1=' . $sig ),
			'non-numeric time' => array( 't=abc,v1=' . $sig ),
		);
	}

	public function test_an_unsigned_request_is_a_rejection(): void {
		$result = Signature::verify( self::PAYLOAD, '', self::SECRET, 300, self::NOW );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'missing', $result->reason );
	}

	public function test_a_missing_secret_fails_closed(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, '', 300, self::NOW )->valid );
	}

	public function test_signing_without_a_secret_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		Signature::sign( self::PAYLOAD, '' );
	}

	/* ─── Secret rotation ───────────────────────────────────────────── */

	/**
	 * Several v1 values in one header is how a secret is rotated without
	 * dropping deliveries — the sender signs with both for a while.
	 */
	public function test_multiple_signatures_allow_rotation(): void {
		$old    = Signature::sign( self::PAYLOAD, 'whsec_old_secret_aaaaaaaaaaaaaaaaaa', self::NOW );
		$new    = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$merged = $old . ',' . explode( ',', $new )[1];

		$this->assertTrue( Signature::verify( self::PAYLOAD, $merged, self::SECRET, 300, self::NOW )->valid );
		$this->assertTrue(
			Signature::verify( self::PAYLOAD, $merged, 'whsec_old_secret_aaaaaaaaaaaaaaaaaa', 300, self::NOW )->valid
		);
	}

	/* ─── Raw body ──────────────────────────────────────────────────── */

	/**
	 * The signature covers exact bytes. Decoding and re-encoding shifts
	 * key order and number formatting, so verifying a round-tripped body
	 * fails — which is why the raw body must be kept.
	 */
	public function test_a_re_encoded_body_does_not_verify(): void {
		$header      = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );
		$round_tripped = json_encode( json_decode( self::PAYLOAD, true ), JSON_PRETTY_PRINT );

		$this->assertFalse( Signature::verify( (string) $round_tripped, $header, self::SECRET, 300, self::NOW )->valid );
	}

	public function test_whitespace_in_the_body_is_significant(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW );

		$this->assertFalse( Signature::verify( self::PAYLOAD . "\n", $header, self::SECRET, 300, self::NOW )->valid );
	}

	/* ─── Retry policy ──────────────────────────────────────────────── */

	#[DataProvider( 'retry_decisions' )]
	public function test_retry_decisions( int $status, bool $expected, string $why ): void {
		$this->assertSame( $expected, ( new RetryPolicy() )->should_retry( $status, 1 ), $why );
	}

	/** @return array<string, array{0: int, 1: bool, 2: string}> */
	public static function retry_decisions(): array {
		return array(
			'200 succeeded'      => array( 200, false, 'delivered' ),
			'204 succeeded'      => array( 204, false, 'delivered' ),
			'400 refused'        => array( 400, false, 'the same bytes will be refused again' ),
			'401 refused'        => array( 401, false, 'credentials will not fix themselves' ),
			'404 refused'        => array( 404, false, 'the endpoint does not exist' ),
			'410 gone'           => array( 410, false, 'the receiver asked to be removed' ),
			'422 refused'        => array( 422, false, 'the payload was read and rejected' ),
			'408 timeout'        => array( 408, true, 'an explicit try-later' ),
			'429 rate limited'   => array( 429, true, 'an explicit try-later' ),
			'500 server error'   => array( 500, true, 'the receiver broke' ),
			'502 bad gateway'    => array( 502, true, 'the receiver broke' ),
			'503 unavailable'    => array( 503, true, 'the receiver is down' ),
			'transport failure'  => array( 0, true, 'nothing was served, so nothing was refused' ),
		);
	}

	public function test_retries_stop_at_the_attempt_limit(): void {
		$policy = new RetryPolicy( max_attempts: 3 );

		$this->assertTrue( $policy->should_retry( 500, 1 ) );
		$this->assertTrue( $policy->should_retry( 500, 2 ) );
		$this->assertFalse( $policy->should_retry( 500, 3 ) );
	}

	public function test_delays_grow_exponentially(): void {
		$this->assertSame( array( 60, 120, 240, 480, 960 ), ( new RetryPolicy() )->schedule() );
	}

	public function test_delays_are_capped(): void {
		$policy = new RetryPolicy( max_attempts: 20, base_delay: 60, max_delay: 3600, jitter: 0 );

		$this->assertSame( 3600, max( $policy->schedule() ) );
		$this->assertSame( 3600, $policy->delay( 19 ) );
	}

	/**
	 * A thousand queued deliveries retrying on the same schedule would
	 * hit a recovering receiver simultaneously and knock it over again.
	 */
	public function test_jitter_spreads_retries_out(): void {
		$policy = new RetryPolicy();
		$delays = array();

		for ( $i = 0; $i < 60; $i++ ) {
			$delays[] = $policy->delay( 3 );
		}

		$this->assertGreaterThan( 5, count( array_unique( $delays ) ), 'delays vary' );
		$this->assertGreaterThan( 100, min( $delays ) );
		$this->assertLessThan( 380, max( $delays ) );
	}

	public function test_jitter_can_be_disabled(): void {
		$policy = new RetryPolicy( jitter: 0 );

		$this->assertSame( 240, $policy->delay( 3 ) );
		$this->assertSame( 240, $policy->delay( 3 ) );
	}

	/**
	 * The receiver knows more about its own recovery than any backoff
	 * curve, so its Retry-After wins.
	 */
	public function test_a_receivers_retry_after_is_honoured(): void {
		$this->assertSame( 42, ( new RetryPolicy() )->delay( 1, 42 ) );
	}

	public function test_a_retry_after_is_still_capped(): void {
		$this->assertSame( 86400, ( new RetryPolicy() )->delay( 1, 999999 ) );
	}

	public function test_a_nonsensical_retry_after_is_ignored(): void {
		$this->assertSame( 60, ( new RetryPolicy( jitter: 0 ) )->delay( 1, 0 ) );
		$this->assertSame( 60, ( new RetryPolicy( jitter: 0 ) )->delay( 1, -5 ) );
	}

	/* ─── Retry-After ───────────────────────────────────────────────── */

	#[DataProvider( 'retry_after_headers' )]
	public function test_retry_after_is_read_in_both_forms( ?string $header, ?int $expected ): void {
		$this->assertSame( $expected, RetryPolicy::retry_after( $header, self::NOW ) );
	}

	/** @return array<string, array{0: ?string, 1: ?int}> */
	public static function retry_after_headers(): array {
		return array(
			'seconds'                => array( '120', 120 ),
			'seconds, padded'        => array( ' 30 ', 30 ),
			'zero'                   => array( '0', 0 ),
			'IMF-fixdate'            => array( gmdate( 'D, d M Y H:i:s', self::NOW + 120 ) . ' GMT', 120 ),
			'RFC 850 date'           => array( gmdate( 'l, d-M-y H:i:s', self::NOW + 60 ) . ' GMT', 60 ),
			'asctime date'           => array( gmdate( 'D M j H:i:s Y', self::NOW + 90 ), 90 ),
			'a date already past'    => array( gmdate( 'D, d M Y H:i:s', self::NOW - 600 ) . ' GMT', 0 ),
			'missing'                => array( null, null ),
			'empty'                  => array( '', null ),
			'negative'               => array( '-5', null ),
			'fractional'             => array( '1.5', null ),
			'nonsense'               => array( 'soon', null ),
			'a date that rolls over' => array( 'Tue, 31 Feb 2026 00:00:00 GMT', null ),
		);
	}

	/**
	 * A header straight into delay(), as the README shows it.
	 */
	public function test_a_dated_retry_after_reaches_the_delay(): void {
		$header = gmdate( 'D, d M Y H:i:s', self::NOW + 300 ) . ' GMT';

		$this->assertSame( 300, ( new RetryPolicy() )->delay( 1, RetryPolicy::retry_after( $header, self::NOW ) ) );
	}

	/* ─── Counting attempts ─────────────────────────────────────────── */

	public function test_attempts_run_out_at_the_limit(): void {
		$policy = new RetryPolicy( max_attempts: 3 );

		$this->assertFalse( $policy->exhausted( 2 ) );
		$this->assertTrue( $policy->exhausted( 3 ) );
		$this->assertTrue( $policy->exhausted( 4 ) );
	}

	public function test_the_next_attempt_is_the_delay_from_now(): void {
		$policy = new RetryPolicy( base_delay: 60, max_delay: 3600, jitter: 0 );

		$this->assertSame( self::NOW + 60, $policy->next_attempt_at( 1, self::NOW ) );
		$this->assertSame( self::NOW + 240, $policy->next_attempt_at( 3, self::NOW ) );
		$this->assertSame( self::NOW + 15, $policy->next_attempt_at( 3, self::NOW, 15 ) );
	}

	/**
	 * A row that has failed a thousand times waits the cap, not a negative
	 * or overflowed number.
	 */
	public function test_a_huge_attempt_count_waits_the_cap(): void {
		$policy = new RetryPolicy( max_attempts: 12, base_delay: 60, max_delay: 21600, jitter: 0 );

		$this->assertSame( 21600, $policy->delay( 1000 ) );
		$this->assertSame( 21600, $policy->delay( PHP_INT_MAX ) );
	}

	/* ─── Hardening (1.3.0) ─────────────────────────────────────────── */

	/**
	 * A negative window read as "no window", so a typo accepted every
	 * replay of every captured request for ever.
	 */
	public function test_a_negative_tolerance_fails_closed(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW - 86400 * 365 );

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, self::SECRET, -1, self::NOW )->valid );
	}

	/**
	 * There is one signing time. A second `t` is a header offering this end
	 * a choice of which time to believe.
	 */
	public function test_a_second_timestamp_is_malformed(): void {
		$header = Signature::sign( self::PAYLOAD, self::SECRET, self::NOW ) . ',t=' . self::NOW;

		$this->assertFalse( Signature::verify( self::PAYLOAD, $header, self::SECRET, now: self::NOW )->valid );
	}

	public function test_a_timestamp_too_long_for_an_integer_is_malformed(): void {
		$result = Signature::verify( self::PAYLOAD, 't=99999999999999999999,v1=' . str_repeat( 'a', 64 ), self::SECRET, 0 );

		$this->assertFalse( $result->valid );
		$this->assertStringContainsString( 'malformed', $result->reason );
	}

	/**
	 * Rotation on the receiving side: both secrets are valid while the
	 * sender switches, and either signature passes.
	 */
	public function test_either_of_two_secrets_verifies_during_rotation(): void {
		$old = Signature::sign( self::PAYLOAD, 'old-secret', self::NOW );
		$new = Signature::sign( self::PAYLOAD, 'new-secret', self::NOW );

		$this->assertTrue( Signature::verify( self::PAYLOAD, $old, array( 'new-secret', 'old-secret' ), now: self::NOW )->valid );
		$this->assertTrue( Signature::verify( self::PAYLOAD, $new, array( 'new-secret', 'old-secret' ), now: self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, $old, array( 'new-secret' ), now: self::NOW )->valid );
		$this->assertFalse( Signature::verify( self::PAYLOAD, $old, array( '', '' ), now: self::NOW )->valid );
	}

	/**
	 * `0 * INF` is not a number, and casting it to an int is a warning in
	 * PHP 8.5 and 0 before.
	 */
	public function test_a_zero_base_delay_stays_zero_at_any_attempt(): void {
		$policy = new RetryPolicy( max_attempts: 12, base_delay: 0, max_delay: 21600, jitter: 0 );

		$this->assertSame( 0, $policy->delay( 2000 ) );
		$this->assertSame( array( 0, 0 ), ( new RetryPolicy( max_attempts: 3, base_delay: 0 ) )->schedule() );
	}
}
