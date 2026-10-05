<?php
/**
 * When to stop posting to a receiver that has gone.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class EndpointHealth
 *
 * One receiver's run of failures, and whether it has earned being switched
 * off. Posting for ever to a host that has been gone since spring is how a
 * sender ends up on a blocklist; switching it off over a blip is how a
 * customer loses a week of events.
 *
 * **Two conditions, not one.** Failures are counted per delivery, so a count
 * alone is the wrong measure: twenty queued events meeting a one-minute
 * outage are twenty failures in a minute, and a breaker on the count would
 * trip over a blip. An endpoint is switched off only when it has failed
 * often enough *and* for long enough — every attempt for a week.
 *
 * **Consecutive.** One success clears the record, so an endpoint that drops
 * a delivery a month never accumulates its way to being switched off.
 *
 * Holds no storage. Read the two numbers from wherever an endpoint is kept,
 * ask what one more failure or success makes them, and write them back:
 *
 *   $health = ( new EndpointHealth( $row['failures'], $row['failing_since'] ) )->failed( $now );
 *
 *   save( failures: $health->failures, failing_since: $health->failing_since,
 *         status: $health->disabled ? 'disabled' : 'active' );
 *
 * @since 1.2.0
 */
final readonly class EndpointHealth {

	/**
	 * Failures in a row before an endpoint can be switched off.
	 *
	 * @since 1.2.0
	 */
	public const int MAX_FAILURES = 20;

	/**
	 * Seconds an endpoint must have been failing before it is switched off.
	 *
	 * @since 1.2.0
	 */
	public const int DISABLE_AFTER = 7 * 86400;

	/**
	 * @since 1.2.0
	 *
	 * @param int      $failures      Failures in a row.
	 * @param int|null $failing_since When the first of them happened, or
	 *                                null when the endpoint is healthy.
	 * @param bool     $disabled      Whether it has earned switching off.
	 */
	public function __construct(
		public int $failures = 0,
		public ?int $failing_since = null,
		public bool $disabled = false,
	) {}

	/**
	 * The record after one more failure.
	 *
	 * @since 1.2.0
	 *
	 * @param int $now           Current time.
	 * @param int $max_failures  Failures in a row before switching off.
	 * @param int $disable_after Seconds of failing before switching off.
	 *
	 * @return self
	 */
	public function failed( int $now, int $max_failures = self::MAX_FAILURES, int $disable_after = self::DISABLE_AFTER ): self {
		$failures = $this->failures + 1;
		$since    = $this->failing_since ?? $now;

		return new self( $failures, $since, $failures >= $max_failures && $now - $since >= $disable_after );
	}

	/**
	 * The record after a success: clean.
	 *
	 * @since 1.2.0
	 *
	 * @return self
	 */
	public function succeeded(): self {
		return new self();
	}
}
