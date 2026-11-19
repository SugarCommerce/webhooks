<?php
/**
 * How long to wait between attempts: a doubling curve with jitter.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Jobs\Retry;

/**
 * Class Backoff
 *
 * The waits between delivery attempts, when a plain list of them is not
 * what you want. Whether a failure is worth another attempt at all is
 * {@see \SugarCommerce\Jobs\Retry}'s rule -- no answer, a 408, a 429 and
 * the five hundreds, never a refusal -- and this plugs into it:
 *
 *   'retry' => new Retry( new Backoff() ),                // in Dispatcher's config
 *
 * **Back off exponentially with jitter.** If a receiver goes down while
 * you hold a thousand queued events, retrying all of them on a fixed
 * schedule sends a thousand simultaneous requests the moment it returns,
 * which knocks it over again. Jitter spreads them out.
 *
 * A caller that already has a schedule it has promised somebody -- "a
 * minute, five minutes, half an hour, two hours" -- states it with
 * {@see self::fixed()}, spread a tenth either way unless it asks
 * otherwise, as a plain list of waits in `new Retry( [ 60, 300, 1800,
 * 7200 ] )` is.
 *
 * Usable on its own, too, for a row that keeps its own count of failures
 * rather than a status code -- a sweep that retries until something works:
 *
 *   $sweep = new Backoff( max_attempts: 12, base_delay: 60, max_delay: 6 * 3600, jitter: 0 );
 *
 *   $sweep->exhausted( $tries ) ? give_up( $row ) : retry_at( $row, $sweep->next_attempt_at( $tries, time() ) );
 *
 * @since 1.0.0
 */
final readonly class Backoff {

	/**
	 * @since 1.0.0
	 *
	 * @param int   $max_attempts Total attempts including the first.
	 * @param int   $base_delay   First retry delay in seconds; doubles each
	 *                            attempt.
	 * @param int   $max_delay    Ceiling for the delay.
	 * @param int   $jitter       Percentage of random spread, 0-100.
	 * @param int[] $delays       A stated schedule: seconds to wait after
	 *                            the first failure, the second, and so on.
	 *                            Empty for the doubling curve. Use
	 *                            {@see self::fixed()}, which cleans it.
	 */
	public function __construct(
		public int $max_attempts = 6,
		public int $base_delay = 60,
		public int $max_delay = 86400,
		public int $jitter = 25,
		public array $delays = [],
	) {}

	/**
	 * A schedule that waits as long as it is told to, give or take jitter.
	 *
	 *   $backoff = Backoff::fixed( [ 60, 300, 1800, 7200 ] );
	 *
	 *   $backoff->delay( 1 );   // 54 to 66: the wait after the first failure
	 *   $backoff->delay( 9 );   // around 7200: past the end, the last again
	 *
	 *   Backoff::fixed( [ 60, 300 ], jitter: 0 );   // exactly as told
	 *
	 * One more attempt than there are waits, unless told otherwise: four
	 * waits are five tries.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $delays       Seconds to wait after each failed
	 *                               attempt, in order. Anything that is not
	 *                               a whole number of seconds or more than
	 *                               nought is dropped.
	 * @param int|null $max_attempts Total attempts including the first.
	 *                               Null for one more than there are waits.
	 * @param int      $jitter       Percentage of random spread, 0-100. A
	 *                               tenth by default, as a Retry's list of
	 *                               waits is ({@see Retry::JITTER}).
	 * @param int|null $max_delay    Ceiling for the delay. Null for a day,
	 *                               or the longest wait if that is longer.
	 *
	 * @return self
	 */
	public static function fixed( array $delays, ?int $max_attempts = null, int $jitter = Retry::JITTER, ?int $max_delay = null ): self {
		$clean = [];

		foreach ( $delays as $delay ) {
			if ( ( is_int( $delay ) || ( is_string( $delay ) && ctype_digit( $delay ) ) ) && (int) $delay > 0 ) {
				$clean[] = (int) $delay;
			}
		}

		$longest = [] === $clean ? 0 : max( $clean );

		return new self(
			max_attempts: max( 1, $max_attempts ?? count( $clean ) + 1 ),
			base_delay: $clean[0] ?? 0,
			max_delay: $max_delay ?? max( 86400, $longest ),
			jitter: $jitter,
			delays: $clean,
		);
	}

	/**
	 * Whether every attempt has been used.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return bool
	 */
	public function exhausted( int $attempt ): bool {
		return $attempt >= $this->max_attempts;
	}

	/**
	 * Seconds to wait after a failed attempt before the next.
	 *
	 * A `Retry-After` the receiver sent is {@see \SugarCommerce\Jobs\Retry}'s
	 * to honour, before this is asked.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return int
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public function delay( int $attempt ): int {
		$delay = $this->backoff( $attempt );

		if ( $this->jitter <= 0 ) {
			return $delay;
		}

		// Spread retries so a recovering receiver is not hit by every
		// queued delivery in the same second.
		$spread = intdiv( $delay * min( 100, $this->jitter ), 100 );

		return max( 1, $delay - $spread + random_int( 0, $spread * 2 ) );
	}

	/**
	 * When the next attempt is due.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attempt     Attempts made so far, 1-indexed.
	 * @param int      $now         Current time.
	 * @param int|null $retry_after Seconds the receiver asked for, if it
	 *                              did. Honoured up to the ceiling.
	 *
	 * @return int A Unix time.
	 */
	public function next_attempt_at( int $attempt, int $now, ?int $retry_after = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return $now + min( $retry_after, $this->max_delay );
		}

		return $now + $this->delay( $attempt );
	}

	/**
	 * The schedule this produces, for documentation and a settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return int[] Delay before each retry, jitter excluded.
	 */
	public function schedule(): array {
		$schedule = [];

		for ( $attempt = 1; $attempt < $this->max_attempts; $attempt++ ) {
			$schedule[] = $this->backoff( $attempt );
		}

		return $schedule;
	}

	/**
	 * The wait after an attempt, before any jitter.
	 *
	 * From the stated schedule when there is one, its last wait standing
	 * for every attempt past the end. Otherwise the base delay doubled per
	 * attempt up to the ceiling -- a step at a time, in integers, because
	 * `2 ** $attempt` is a float from the sixty-third attempt and infinite
	 * not long after.
	 *
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return int Seconds.
	 */
	private function backoff( int $attempt ): int {
		if ( [] !== $this->delays ) {
			$waits = array_values( $this->delays );

			return (int) ( $waits[ max( 1, $attempt ) - 1 ] ?? end( $waits ) );
		}

		$ceiling = max( 0, $this->max_delay );
		$delay   = min( max( 0, $this->base_delay ), $ceiling );

		for ( $step = 1; $step < $attempt && $delay > 0 && $delay < $ceiling; $step++ ) {
			$delay = $delay > intdiv( $ceiling, 2 ) ? $ceiling : $delay * 2;
		}

		return $delay;
	}
}
