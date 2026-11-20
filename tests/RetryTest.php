<?php
/**
 * Retry, Backoff and Retry-After tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Retry;
use SugarCommerce\Webhooks\Backoff;
use SugarCommerce\Webhooks\Dispatcher;
use SugarCommerce\Webhooks\Models\Attempt;

/**
 * Whether a failed delivery is tried again is sugarcommerce/jobs' Retry;
 * how long the doubling curve waits is Backoff; what the receiver asked for
 * is Attempt::retry_after().
 */
final class RetryTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/* ─── Which failures are worth another go ───────────────────────── */

	#[DataProvider( 'retry_decisions' )]
	public function test_retry_decisions( int $status, bool $expected, string $why ): void {
		$this->assertSame( $expected, ( new Retry( new Backoff() ) )->again( $status, 1 ), $why );
		$this->assertSame( $expected, ( new Retry( Dispatcher::WAITS ) )->again( $status, 1 ), $why );
	}

	/** @return array<string, array{0: int, 1: bool, 2: string}> */
	public static function retry_decisions(): array {
		return [
			'301 not followed'  => [ 301, false, 'a redirect not followed is the answer' ],
			'400 refused'       => [ 400, false, 'the same bytes will be refused again' ],
			'401 refused'       => [ 401, false, 'credentials will not fix themselves' ],
			'404 refused'       => [ 404, false, 'the endpoint does not exist' ],
			'410 gone'          => [ 410, false, 'the receiver asked to be removed' ],
			'422 refused'       => [ 422, false, 'the payload was read and rejected' ],
			'408 timeout'       => [ 408, true, 'an explicit try-later' ],
			'429 rate limited'  => [ 429, true, 'an explicit try-later' ],
			'500 server error'  => [ 500, true, 'the receiver broke' ],
			'502 bad gateway'   => [ 502, true, 'the receiver broke' ],
			'503 unavailable'   => [ 503, true, 'the receiver is down' ],
			'transport failure' => [ 0, true, 'nothing was served, so nothing was refused' ],
		];
	}

	public function test_retries_stop_at_the_attempt_limit(): void {
		$retry = new Retry( new Backoff( max_attempts: 3 ) );

		$this->assertTrue( $retry->again( 500, 1 ) );
		$this->assertTrue( $retry->again( 500, 2 ) );
		$this->assertFalse( $retry->again( 500, 3 ) );
	}

	/* ─── The doubling curve ────────────────────────────────────────── */

	public function test_delays_grow_exponentially(): void {
		$this->assertSame( [ 60, 120, 240, 480, 960 ], ( new Backoff() )->schedule() );
	}

	public function test_delays_are_capped(): void {
		$backoff = new Backoff( max_attempts: 20, base_delay: 60, max_delay: 3600, jitter: 0 );

		$this->assertSame( 3600, max( $backoff->schedule() ) );
		$this->assertSame( 3600, $backoff->delay( 19 ) );
	}

	/**
	 * A thousand queued deliveries retrying on the same schedule would
	 * hit a recovering receiver simultaneously and knock it over again.
	 */
	public function test_jitter_spreads_retries_out(): void {
		$backoff = new Backoff();
		$delays  = [];

		for ( $i = 0; $i < 60; $i++ ) {
			$delays[] = $backoff->delay( 3 );
		}

		$this->assertGreaterThan( 5, count( array_unique( $delays ) ), 'delays vary' );
		$this->assertGreaterThanOrEqual( 180, min( $delays ) );
		$this->assertLessThanOrEqual( 300, max( $delays ) );
	}

	public function test_jitter_can_be_disabled(): void {
		$backoff = new Backoff( jitter: 0 );

		$this->assertSame( 240, $backoff->delay( 3 ) );
		$this->assertSame( 240, $backoff->delay( 3 ) );
	}

	public function test_exhausted_is_the_last_attempt_and_after(): void {
		$backoff = new Backoff( max_attempts: 3 );

		$this->assertFalse( $backoff->exhausted( 2 ) );
		$this->assertTrue( $backoff->exhausted( 3 ) );
		$this->assertTrue( $backoff->exhausted( 4 ) );
	}

	public function test_a_very_long_run_of_attempts_is_still_a_whole_number(): void {
		$this->assertSame( 86400, ( new Backoff( max_attempts: 200, jitter: 0 ) )->delay( 150 ) );
		$this->assertSame( 0, ( new Backoff( base_delay: 0, jitter: 0 ) )->delay( 150 ) );
	}

	public function test_the_next_attempt_is_now_plus_the_wait(): void {
		$backoff = new Backoff( jitter: 0 );

		$this->assertSame( 1000 + 120, $backoff->next_attempt_at( 2, 1000 ) );
		$this->assertSame( 1000 + 42, $backoff->next_attempt_at( 2, 1000, 42 ) );
		$this->assertSame( 1000 + 86400, $backoff->next_attempt_at( 2, 1000, 999999 ) );
		$this->assertSame( 1000 + 120, $backoff->next_attempt_at( 2, 1000, 0 ) );
	}

	/* ─── A stated schedule, with jitter ────────────────────────────── */

	public function test_a_fixed_schedule_is_spread_a_tenth_by_default(): void {
		$backoff = Backoff::fixed( [ 60, 300, 1800, 7200 ] );

		$this->assertSame( [ 60, 300, 1800, 7200 ], $backoff->schedule() );
		$this->assertEqualsWithDelta( 300, $backoff->delay( 2 ), 30 );
		$this->assertEqualsWithDelta( 7200, $backoff->delay( 9 ), 720 );
		$this->assertSame( 5, $backoff->max_attempts );
		$this->assertSame( Retry::JITTER, $backoff->jitter );
	}

	public function test_a_fixed_schedule_without_jitter_waits_exactly_as_told(): void {
		$backoff = Backoff::fixed( [ 60, 300, 1800, 7200 ], jitter: 0 );

		$this->assertSame( 300, $backoff->delay( 2 ) );
		$this->assertSame( 7200, $backoff->delay( 9 ) );
	}

	public function test_a_fixed_schedule_keeps_only_real_waits(): void {
		$backoff = Backoff::fixed( [ 'a' => 60, 'b' => '300', 'c' => 0, 'd' => -5, 'e' => 'soon', 'f' => 1.5, 'g' => null ] );

		$this->assertSame( [ 60, 300 ], $backoff->delays );
		$this->assertSame( 3, $backoff->max_attempts );
	}

	public function test_an_empty_fixed_schedule_never_retries(): void {
		$retry = new Retry( Backoff::fixed( [] ) );

		$this->assertFalse( $retry->again( 500, 1 ) );
		$this->assertSame( [], Backoff::fixed( [] )->schedule() );
	}

	public function test_a_fixed_schedule_can_be_spread(): void {
		$backoff = Backoff::fixed( [ 1000 ], null, 25 );
		$delays  = [];

		for ( $i = 0; $i < 60; $i++ ) {
			$delay    = $backoff->delay( 1 );
			$delays[] = $delay;

			$this->assertGreaterThanOrEqual( 750, $delay );
			$this->assertLessThanOrEqual( 1250, $delay );
		}

		$this->assertGreaterThan( 1, count( array_unique( $delays ) ) );
		$this->assertSame( 172800, Backoff::fixed( [ 60, 172800 ] )->max_delay );
	}

	/* ─── Retry-After ───────────────────────────────────────────────── */

	/**
	 * The receiver knows more about its own recovery than any curve.
	 */
	public function test_a_receivers_retry_after_wins_up_to_a_day(): void {
		$retry = new Retry( new Backoff( jitter: 0 ) );

		$this->assertSame( 42, $retry->delay( 1, 42 ) );
		$this->assertSame( 86400, $retry->delay( 1, 999999 ) );
		$this->assertSame( 60, $retry->delay( 1, 0 ) );
		$this->assertSame( 60, $retry->delay( 1, -5 ) );
	}

	public function test_retry_after_in_seconds(): void {
		$this->assertSame( 120, Attempt::retry_after( '120' ) );
		$this->assertSame( 120, Attempt::retry_after( ' 120 ' ) );
		$this->assertSame( 0, Attempt::retry_after( '0' ) );
	}

	public function test_retry_after_as_a_date(): void {
		$now = gmmktime( 8, 49, 37, 11, 6, 1994 );

		$this->assertSame( 60, Attempt::retry_after( 'Sun, 06 Nov 1994 08:50:37 GMT', $now ) );
		$this->assertSame( 60, Attempt::retry_after( 'Sunday, 06-Nov-94 08:50:37 GMT', $now ) );
		$this->assertSame( 60, Attempt::retry_after( 'Sun Nov  6 08:50:37 1994', $now ) );
	}

	public function test_a_retry_after_date_reads_the_shared_clock(): void {
		Clock::freeze( gmmktime( 8, 49, 37, 11, 6, 1994 ) );

		$this->assertSame( 60, Attempt::retry_after( 'Sun, 06 Nov 1994 08:50:37 GMT' ) );
		$this->assertSame( 0, Attempt::retry_after( 'Sun, 06 Nov 1994 08:00:00 GMT' ) );
	}

	public function test_an_unreadable_retry_after_says_nothing(): void {
		$this->assertNull( Attempt::retry_after( null ) );
		$this->assertNull( Attempt::retry_after( '' ) );
		$this->assertNull( Attempt::retry_after( 'soon' ) );
		$this->assertNull( Attempt::retry_after( '-5' ) );
		$this->assertNull( Attempt::retry_after( '1.5' ) );
		$this->assertNull( Attempt::retry_after( 'Sun, 31 Feb 1994 08:50:37 GMT' ) );
	}

	public function test_an_absurd_retry_after_is_capped(): void {
		$wait = Attempt::retry_after( '99999999999999999999999' );

		$this->assertIsInt( $wait );
		$this->assertSame( 86400, ( new Retry( Dispatcher::WAITS ) )->delay( 1, $wait ) );
	}

	/* ─── What one attempt came to ──────────────────────────────────── */

	public function test_an_answer_is_read(): void {
		$sent = Attempt::from_response( wh_answer( 204 ) );

		$this->assertTrue( $sent->sent );
		$this->assertSame( 204, $sent->status );
		$this->assertSame( '', $sent->error );

		$refused = Attempt::from_response( wh_answer( 422 ) );

		$this->assertFalse( $refused->sent );
		$this->assertSame( 422, $refused->status );
		$this->assertNull( $refused->retry_after );
	}

	public function test_no_answer_is_status_nought_and_why(): void {
		$attempt = Attempt::from_response( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->assertFalse( $attempt->sent );
		$this->assertSame( 0, $attempt->status );
		$this->assertSame( 'cURL error 28: timed out', $attempt->error );
		$this->assertSame( 'quiet', Attempt::from_response( new \WP_Error( 'quiet' ) )->error );
	}

	/**
	 * Only the two answers that mean "later" are believed about when.
	 */
	public function test_retry_after_is_read_from_a_429_or_503_only(): void {
		$this->assertSame( 120, Attempt::from_response( wh_answer( 429, [ 'Retry-After' => '120' ] ) )->retry_after );
		$this->assertSame( 120, Attempt::from_response( wh_answer( 503, [ 'retry-after' => '120' ] ) )->retry_after );
		$this->assertNull( Attempt::from_response( wh_answer( 500, [ 'Retry-After' => '120' ] ) )->retry_after );
	}

	/* ─── The dispatcher's schedule, configured ─────────────────────── */

	public function test_the_dispatcher_takes_a_list_of_waits(): void {
		$webhooks = new Dispatcher(
			[
				'prefix'   => 'shop',
				'retry'    => [ 10, 20 ],
				'attempts' => 6,
				'jitter'   => 0,
			]
		);

		$this->assertSame( [ 10, 20, 20 ], array_map( [ $webhooks, 'retry_delay' ], [ 1, 2, 3 ] ) );
		$this->assertTrue( $webhooks->should_retry( 500, 5 ) );
		$this->assertFalse( $webhooks->should_retry( 500, 6 ) );
	}

	public function test_the_dispatcher_takes_a_backoff_or_a_retry(): void {
		$curve = new Dispatcher(
			[
				'prefix' => 'shop',
				'retry'  => new Backoff( jitter: 0 ),
			]
		);

		$this->assertSame( [ 60, 120, 240 ], array_map( [ $curve, 'retry_delay' ], [ 1, 2, 3 ] ) );
		$this->assertFalse( $curve->should_retry( 500, 6 ) );

		$retry = new Retry( [ 5 ], 2 );

		$this->assertSame(
			$retry,
			( new Dispatcher(
				[
					'prefix' => 'shop',
					'retry'  => $retry,
				]
			) )->retry()
		);
	}
}
