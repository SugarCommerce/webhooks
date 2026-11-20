<?php
/**
 * Sending one webhook delivery.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Webhooks\Models\Attempt;

/**
 * Class Sender
 *
 * One POST of a signed body to a receiver, and what came of it. The queue,
 * the retries and the endpoint's health stay with {@see Dispatcher}; this is
 * the part every sender writes the same way and gets wrong in the same
 * places:
 *
 * - **Signed at the moment of sending**, not when queued: a delivery that
 *   waited in the queue, or was retried two hours later, otherwise arrives
 *   older than the receiver's tolerance and is refused for something the
 *   receiver cannot see.
 * - **A timeout**, so one slow receiver cannot hold up everyone else's.
 * - **Never throws.** An address {@see SafeUrl} refuses, because it is
 *   somewhere inside the server's own network, is a failed attempt like any
 *   other -- to the person whose endpoint it is, it is one.
 *
 *   $attempt = $sender->send( $endpoint['url'], 'order.completed', $body, $endpoint['secret'] );
 *
 *   $attempt->sent;          // 2xx
 *   $attempt->status;        // 0 when nothing came back
 *   $attempt->error;         // why nothing came back
 *   $attempt->retry_after;   // seconds the receiver asked for, on a 429 or 503
 *
 * @since 1.0.0
 */
final class Sender {

	/**
	 * @since 1.0.0
	 *
	 * @param SafeUrl $safe       The request, behind the private-network
	 *                            check.
	 * @param string  $header     Before `-Signature` and `-Event`.
	 * @param string  $user_agent Product/version. The site's address is
	 *                            added.
	 * @param int     $timeout    Seconds a receiver has to answer.
	 * @param int     $redirects  Redirects followed, each one checked.
	 */
	public function __construct(
		private SafeUrl $safe = new SafeUrl(),
		private string $header = 'Webhook',
		private string $user_agent = '',
		private int $timeout = 10,
		private int $redirects = 0,
	) {}

	/**
	 * Post one body, signed now.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $url    The receiver.
	 * @param string          $type   The event type, for `{header}-Event`.
	 * @param string          $body   The JSON body, sent as it is.
	 * @param string|string[] $secret The endpoint's signing secret, or
	 *                                every one valid while it is rotated;
	 *                                unsigned when empty.
	 *
	 * @return Attempt
	 */
	public function send( string $url, string $type, string $body, #[\SensitiveParameter] string|array $secret ): Attempt {
		return Attempt::from_response(
			$this->safe->post(
				$url,
				[
					'headers' => $this->headers( $type, $body, $secret ),
					'body'    => $body,
					'timeout' => max( 1, $this->timeout ),
				],
				max( 0, $this->redirects )
			)
		);
	}

	/**
	 * The headers one delivery is sent with.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $type   The event type.
	 * @param string          $body   The exact bytes being sent.
	 * @param string|string[] $secret The endpoint's signing secret; unsigned
	 *                                when empty.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $type, string $body, #[\SensitiveParameter] string|array $secret ): array {
		$agent = '' !== $this->user_agent ? $this->user_agent : 'WordPress/' . get_bloginfo( 'version' );

		$headers = [
			'Content-Type'           => 'application/json',
			'User-Agent'             => $agent . '; ' . home_url( '/' ),
			$this->header . '-Event' => $type,
		];

		try {
			$headers[ $this->header . '-Signature' ] = Signature::sign( $body, $secret, Clock::time() );
		} catch ( \InvalidArgumentException $unsigned ) {
			// No secret: sent unsigned, as the endpoint was saved.
			unset( $unsigned );
		}

		return $headers;
	}
}
