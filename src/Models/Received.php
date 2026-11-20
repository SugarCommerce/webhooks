<?php
/**
 * What receiving one webhook came to.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Models;

use Throwable;

/**
 * Class Received
 *
 * The status to answer the sender with, and enough to log. Made by
 * {@see \SugarCommerce\Webhooks\Receiver::receive()}.
 *
 *   200  handled now, or already handled by an earlier delivery (`duplicate`)
 *   400  not signed, signed wrongly, too old, or not an event: do not retry
 *   409  another delivery of the same event is being handled right now
 *   500  the handler failed: the sender's retry will take the event over
 *
 * Log `reason`; answer with `status` only. The reason tells a forger which
 * of their guesses was closest.
 *
 * @since 1.0.0
 */
final readonly class Received {

	/**
	 * @since 1.0.0
	 *
	 * @param int                       $status    The HTTP status to answer.
	 * @param string                    $reason    Why, for a log.
	 * @param array<string, mixed>|null $event     The decoded event, once it
	 *                                             verified.
	 * @param bool                      $duplicate Whether an earlier delivery
	 *                                             had already handled it.
	 * @param Throwable|null            $error     What the handler threw.
	 */
	public function __construct(
		public int $status,
		public string $reason = '',
		public ?array $event = null,
		public bool $duplicate = false,
		public ?Throwable $error = null,
	) {}

	/**
	 * Whether the sender should be told it arrived.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether the handler ran for this delivery and finished.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function handled(): bool {
		return $this->ok() && ! $this->duplicate;
	}
}
