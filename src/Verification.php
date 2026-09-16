<?php
/**
 * The result of verifying a webhook signature.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class Verification
 *
 * A boolean would be enough to act on, but not enough to debug — "the
 * signature did not match" and "the signature was valid four hours ago"
 * point at completely different problems, and the second one is usually
 * a clock.
 *
 * Log {@see self::$reason}. Do not return it to the caller: it
 * distinguishes failure modes, which helps an attacker iterate.
 *
 * @since 1.0.0
 */
final readonly class Verification {

	/**
	 * @since 1.0.0
	 *
	 * @param bool   $valid     Whether the payload is authentic and fresh.
	 * @param string $reason    Why not, for your logs.
	 * @param int    $timestamp The signing time, when known.
	 */
	private function __construct(
		public bool $valid,
		public string $reason = '',
		public int $timestamp = 0,
	) {}

	/**
	 * A successful verification.
	 *
	 * @since 1.0.0
	 *
	 * @param int $timestamp Signing time.
	 *
	 * @return self
	 */
	public static function passed( int $timestamp ): self {
		return new self( true, '', $timestamp );
	}

	/**
	 * A failed verification.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason Diagnostic detail. Log it; do not echo it.
	 *
	 * @return self
	 */
	public static function failed( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Whether verification failed.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function failed_check(): bool {
		return ! $this->valid;
	}
}
