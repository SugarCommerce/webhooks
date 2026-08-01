<?php
/**
 * Webhook delivery retry scheduling.
 *
 * @package   SugarMill\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarMill\Webhooks;

/**
 * Class RetryPolicy
 *
 * Decides whether a failed delivery is worth trying again, and when.
 *
 * Two rules do most of the work:
 *
 * **Retry the remote end's problems, not your own.** A 500, a timeout,
 * or a refused connection means the receiver was unavailable and will
 * probably work later. A 400 or a 422 means it read the payload and
 * refused it, and sending the identical bytes twelve more times cannot
 * change that. A 410 Gone is the receiver asking to be removed.
 *
 * **Back off exponentially with jitter.** If a receiver goes down while
 * you hold a thousand queued events, retrying all of them on a fixed
 * schedule sends a thousand simultaneous requests the moment it returns,
 * which knocks it over again. Jitter spreads them out.
 *
 * @since 1.0.0
 */
final readonly class RetryPolicy {

	/**
	 * @since 1.0.0
	 *
	 * @param int $max_attempts Total attempts including the first.
	 * @param int $base_delay   First retry delay in seconds; doubles each
	 *                          attempt.
	 * @param int $max_delay    Ceiling for the delay.
	 * @param int $jitter       Percentage of random spread, 0-100.
	 */
	public function __construct(
		public int $max_attempts = 6,
		public int $base_delay = 60,
		public int $max_delay = 86400,
		public int $jitter = 25,
	) {}

	/**
	 * Whether to try again.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status  HTTP status, or 0 for a transport failure.
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return bool
	 */
	public function should_retry( int $status, int $attempt ): bool {
		if ( $attempt >= $this->max_attempts ) {
			return false;
		}

		// Transport failure: nothing was served, so nothing was refused.
		if ( 0 === $status ) {
			return true;
		}

		if ( $status >= 200 && $status < 300 ) {
			return false;
		}

		// The receiver asking to be removed. Honour it — repeatedly
		// posting to a dead endpoint is how you end up on a blocklist.
		if ( 410 === $status ) {
			return false;
		}

		// 429 and 408 are explicit "later, please".
		if ( 429 === $status || 408 === $status ) {
			return true;
		}

		// Anything else in the 4xx range is a considered refusal, and the
		// same bytes will be refused again.
		if ( $status >= 400 && $status < 500 ) {
			return false;
		}

		return $status >= 500;
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attempt     Attempts made so far, 1-indexed.
	 * @param int|null $retry_after The receiver's `Retry-After`, when it
	 *                              sent one. Honoured, because it knows
	 *                              more about its own recovery than any
	 *                              backoff curve does.
	 *
	 * @return int
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public function delay( int $attempt, ?int $retry_after = null ): int {
		if ( null !== $retry_after && $retry_after > 0 ) {
			return min( $retry_after, $this->max_delay );
		}

		$delay = min( $this->base_delay * ( 2 ** max( 0, $attempt - 1 ) ), $this->max_delay );

		if ( $this->jitter <= 0 ) {
			return (int) $delay;
		}

		// Spread retries so a recovering receiver is not hit by every
		// queued delivery in the same second.
		$spread = (int) ( $delay * ( $this->jitter / 100 ) );

		return (int) max( 1, $delay - $spread + random_int( 0, $spread * 2 ) );
	}

	/**
	 * The schedule this policy produces, for documentation and tests.
	 *
	 * @since 1.0.0
	 *
	 * @return int[] Delay before each retry, jitter excluded.
	 */
	public function schedule(): array {
		$schedule = array();

		for ( $attempt = 1; $attempt < $this->max_attempts; $attempt++ ) {
			$schedule[] = (int) min( $this->base_delay * ( 2 ** ( $attempt - 1 ) ), $this->max_delay );
		}

		return $schedule;
	}
}
