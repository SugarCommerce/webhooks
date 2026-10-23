<?php
/**
 * Sending one webhook delivery.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\HttpClient\Client;

/**
 * Class Sender
 *
 * One POST of a signed payload to a receiver, and what came of it. The
 * queue, the retries and the endpoint's health stay with the caller; this
 * is the part every sender writes the same way and gets wrong in the same
 * places:
 *
 * - Signed at the moment of sending, not when queued or when a run began:
 *   a delivery late in a slow run otherwise arrives older than the
 *   receiver's tolerance, and is refused for something the receiver
 *   cannot see.
 * - A timeout, so one slow receiver cannot hold up everyone else's.
 * - Never throws. A URL the client refuses, or its SSRF policy rejecting a
 *   host that resolves somewhere private, is a failed attempt like any
 *   other — to the person whose endpoint it is, it is one.
 *
 * ```php
 * $attempt = ( new Sender( $http ) )->send( $endpoint['url'], $payload, $endpoint['secret'], array(
 *     'Shop-Event'    => $event,
 *     'Shop-Delivery' => (string) $id,   // stable across retries: receivers dedupe on it
 * ) );
 *
 * $attempt->sent ? $health->succeeded() : $policy->next_attempt_at( $n, time(), $attempt->retry_after );
 * ```
 *
 * @since 1.4.0
 */
final readonly class Sender {

	/**
	 * @since 1.4.0
	 *
	 * @param Client $http    Client to post through — keep its SSRF policy:
	 *                        webhook URLs are typed by other people.
	 * @param int    $timeout Seconds a receiver gets to answer.
	 * @param string $header  The signature header's name.
	 */
	public function __construct(
		private Client $http,
		private int $timeout = 10,
		private string $header = Signature::HEADER,
	) {}

	/**
	 * Post one payload, signed now.
	 *
	 * @since 1.4.0
	 *
	 * @param string                $url     Receiver.
	 * @param string                $payload JSON body, sent as it is.
	 * @param string                $secret  The endpoint's signing secret.
	 * @param array<string, string> $headers More headers — an event name, a
	 *                                       delivery id. They cannot replace
	 *                                       the signature or content type.
	 * @param int|null              $now     Signing time; null for now.
	 *
	 * @return Attempt
	 */
	public function send( string $url, string $payload, #[\SensitiveParameter] string $secret, array $headers = array(), ?int $now = null ): Attempt {
		try {
			$response = $this->http->with_timeout( max( 1, $this->timeout ) )->post(
				$url,
				$payload,
				array(
					'Content-Type' => 'application/json',
					$this->header  => Signature::sign( $payload, $secret, $now ?? time() ),
				) + $headers
			);
		} catch ( \Throwable $e ) {
			return new Attempt( false, 0, '' !== $e->getMessage() ? $e->getMessage() : $e::class );
		}

		if ( $response->ok ) {
			return new Attempt( true, $response->status );
		}

		return new Attempt(
			false,
			$response->status,
			'' !== $response->error ? $response->error : 'Endpoint answered ' . $response->status,
			RetryPolicy::retry_after( $response->header( 'retry-after' ), $now ),
		);
	}
}
