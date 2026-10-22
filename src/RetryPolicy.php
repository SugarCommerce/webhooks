<?php
/**
 * Webhook delivery retry scheduling.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

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
	 * Whether a row has used all its attempts.
	 *
	 * For a caller that keeps its own failure count — a sweep that retries
	 * a row until it works, with no status code to judge by — and needs to
	 * know when to stop and mark it given up rather than try for ever.
	 *
	 * @since 1.2.0
	 *
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return bool
	 */
	public function exhausted( int $attempt ): bool {
		return $attempt >= $this->max_attempts;
	}

	/**
	 * When to try again, as a Unix time.
	 *
	 * The same wait as {@see self::delay()}, added to the clock, for a row
	 * that stores when it is next due rather than how long to wait.
	 *
	 * @since 1.2.0
	 *
	 * @param int      $attempt     Attempts made so far, 1-indexed.
	 * @param int      $now         Current time.
	 * @param int|null $retry_after The receiver's `Retry-After`, in seconds.
	 *
	 * @return int
	 *
	 * @throws \Random\RandomException When the CSPRNG is unavailable.
	 */
	public function next_attempt_at( int $attempt, int $now, ?int $retry_after = null ): int {
		return $now + $this->delay( $attempt, $retry_after );
	}

	/**
	 * Read a `Retry-After` header as seconds from now.
	 *
	 * RFC 9110 allows two forms, and receivers use both: a number of
	 * seconds, and an HTTP-date. Code that only handles the number — the
	 * usual `is_numeric()` check — throws the date away and falls back to
	 * its own backoff, which is exactly when the receiver said something
	 * more useful. A date in the past is "now", and anything unreadable is
	 * null, so the policy's own curve decides.
	 *
	 * @since 1.2.0
	 *
	 * @param string|null $header The header's value, as received.
	 * @param int|null    $now    Current time. For tests.
	 *
	 * @return int|null Seconds to wait, or null when there is no usable
	 *                  answer.
	 */
	public static function retry_after( ?string $header, ?int $now = null ): ?int {
		$header = trim( (string) $header );

		if ( '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			return (int) $header;
		}

		$when = self::http_date( $header );

		return null === $when ? null : max( 0, $when - ( $now ?? time() ) );
	}

	/**
	 * Parse an HTTP-date in any of the three forms RFC 9110 says a
	 * recipient must accept.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value e.g. `Sun, 06 Nov 1994 08:49:37 GMT`.
	 *
	 * @return int|null Unix time, or null when it is not one.
	 */
	private static function http_date( string $value ): ?int {
		$value = (string) preg_replace( '/\s+/', ' ', $value );
		$utc   = new \DateTimeZone( 'UTC' );

		foreach ( array( '!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y' ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $utc );

			// Rolled over (31 February) is not a date the sender meant.
			if ( false !== $date && false === \DateTimeImmutable::getLastErrors() ) {
				return $date->getTimestamp();
			}
		}

		return null;
	}

	/**
	 * The schedule this policy produces, for documentation and tests.
	 *
	 * @since 1.0.0
	 *
	 * @return list<int> Delay before each retry, jitter excluded.
	 */
	public function schedule(): array {
		$schedule = array();

		for ( $attempt = 1; $attempt < $this->max_attempts; $attempt++ ) {
			$schedule[] = $this->backoff( $attempt );
		}

		return $schedule;
	}

	/**
	 * The un-jittered wait after an attempt: the base delay doubled per
	 * attempt, up to the ceiling.
	 *
	 * Doubled a step at a time and stopped at the ceiling, in integers.
	 * `2 ** $attempt` is a float from the 63rd attempt and infinite not long
	 * after, and a zero base delay times infinity is not a number.
	 *
	 * @since 1.3.0
	 *
	 * @param int $attempt Attempts made so far, 1-indexed.
	 *
	 * @return int Seconds, 0 to max_delay.
	 */
	private function backoff( int $attempt ): int {
		$ceiling = max( 0, $this->max_delay );
		$delay   = min( max( 0, $this->base_delay ), $ceiling );

		for ( $step = 1; $step < $attempt && $delay > 0 && $delay < $ceiling; $step++ ) {
			$delay = $delay > intdiv( $ceiling, 2 ) ? $ceiling : $delay * 2;
		}

		return $delay;
	}
}
