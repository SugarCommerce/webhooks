<?php
/**
 * Delivery: an event queued for each endpoint that wants it, sent, retried or given up on, and a test send.
 *
 * @package     SugarCommerce\Webhooks
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Traits\Dispatcher;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Scheduler;
use SugarCommerce\Security\Random;

/**
 * Delivery: an event queued for each endpoint that wants it, sent, retried or given up on, and a test send.
 *
 * One id for the event on every endpoint and every retry; a failure counts
 * towards switching the endpoint off before another attempt is queued.
 *
 * @since 1.0.0
 */
trait Delivery {

	/**
	 * Queue an event for every endpoint that wants it.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $type The event type, from the catalogue.
	 * @param array<string, mixed> $data What happened.
	 *
	 * @return int How many deliveries were queued.
	 */
	public function dispatch( string $type, array $data ): int {
		if ( ! $this->events->has( $type ) ) {
			return 0;
		}

		$endpoints = $this->endpoints->wanting( $type );

		if ( [] === $endpoints ) {
			return 0;
		}

		/**
		 * Filter what an outgoing webhook says.
		 *
		 * Named `{prefix}_webhook_data`.
		 *
		 * @param array<string, mixed> $data What happened.
		 * @param string               $type The event type.
		 */
		$data = (array) apply_filters( $this->prefix . '_webhook_data', $data, $type );

		// One id for the event, the same on every endpoint and on every
		// retry, so a receiver that gets it twice can tell.
		$body = $this->body( $type, $data );

		foreach ( $endpoints as $endpoint ) {
			$this->queue( $endpoint['id'], $type, $body, 1, 0 );
		}

		return count( $endpoints );
	}

	/**
	 * Send one event to one endpoint. The queued action.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $endpoint_id Which endpoint.
	 * @param mixed $type        The event type.
	 * @param mixed $body        The JSON body.
	 * @param mixed $attempt     Which attempt this is.
	 *
	 * @return bool Whether the endpoint accepted it.
	 */
	public function send( $endpoint_id, $type, $body, $attempt = 1 ): bool {
		$endpoint = $this->endpoints->find( (string) $endpoint_id );

		// Removed or switched off since this was queued. Nothing to do, and
		// nothing to retry.
		if ( null === $endpoint ) {
			return false;
		}

		$body   = (string) $body;
		$result = $this->sender->send( $endpoint['url'], (string) $type, $body, $endpoint['secret'] );

		if ( $result->sent ) {
			$this->breaker->succeeded( $endpoint['id'] );

			return true;
		}

		$attempt = (int) $attempt;

		// Counted towards switching it off first: an endpoint switched off
		// by this failure is not one to queue another attempt for.
		$off = $this->breaker->failed( $endpoint, $result->status, $result->error );

		if ( ! $off && $this->should_retry( $result->status, $attempt ) && Scheduler::available() ) {
			$this->queue( (string) $endpoint_id, (string) $type, $body, $attempt + 1, $this->retry_delay( $attempt, $result->retry_after ) );
		}

		/**
		 * Fires when an outgoing webhook was not accepted.
		 *
		 * Named `{prefix}_webhook_failed`.
		 *
		 * @param array<string, mixed> $endpoint The endpoint.
		 * @param string               $type     The event type.
		 * @param int                  $status   The HTTP status, or 0 when
		 *                                       the request itself failed.
		 * @param int                  $attempt  Which attempt this was.
		 */
		do_action( $this->prefix . '_webhook_failed', $endpoint, (string) $type, $result->status, $attempt );

		return false;
	}

	/**
	 * Send a test event to one endpoint, now, whether it is switched on or
	 * not.
	 *
	 * Not queued and not retried: somebody is waiting to see what it
	 * answers. Answered, an endpoint that was switched off for failing is
	 * switched back on; a test that fails changes nothing and is not
	 * counted as a delivery that failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint_id Which endpoint.
	 *
	 * @return array{found: bool, ok: bool, status: int, error: string, url: string}
	 */
	public function test( string $endpoint_id ): array {
		$endpoint = $this->endpoints->find( $endpoint_id, true );

		if ( null === $endpoint ) {
			return [
				'found'  => false,
				'ok'     => false,
				'status' => 0,
				'error'  => '',
				'url'    => '',
			];
		}

		$message = is_callable( $this->test_message ) ? ( $this->test_message )() : $this->test_message;
		$body    = $this->body( $this->test_event, [ 'message' => (string) $message ] );
		$result  = $this->sender->send( $endpoint['url'], $this->test_event, $body, $endpoint['secret'] );

		if ( $result->sent ) {
			$this->breaker->recovered( $endpoint['id'] );
		}

		return [
			'found'  => true,
			'ok'     => $result->sent,
			'status' => $result->status,
			'error'  => $result->error,
			'url'    => $endpoint['url'],
		];
	}

	/**
	 * Whether a delivery that was not accepted is worth trying again.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status  The HTTP status, or 0 when the request failed.
	 * @param int $attempt Which attempt that was, counting from one.
	 *
	 * @return bool
	 */
	public function should_retry( int $status, int $attempt ): bool {
		return $this->retry->again( $status, $attempt );
	}

	/**
	 * Seconds to wait after a failed attempt before the next.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attempt     Which attempt just failed, from one.
	 * @param int|null $retry_after What the receiver asked for, if it did.
	 *
	 * @return int
	 */
	public function retry_delay( int $attempt, ?int $retry_after = null ): int {
		return $this->retry->delay( $attempt, $retry_after );
	}

	/**
	 * The JSON body of one event.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data What happened.
	 *
	 * @return string
	 */
	private function body( string $type, array $data ): string {
		return (string) wp_json_encode(
			[
				'id'      => $this->id_prefix . Random::hex( 16 ),
				'type'    => $type,
				'created' => Clock::time(),
				'test'    => (bool) ( $this->test )(),
				'data'    => $data,
			]
		);
	}

	/**
	 * Put one delivery in the queue.
	 *
	 * @param string $endpoint_id Which endpoint.
	 * @param string $type        The event type.
	 * @param string $body        The JSON body.
	 * @param int    $attempt     Which attempt this will be.
	 * @param int    $delay       Seconds to wait first.
	 *
	 * @return void
	 */
	private function queue( string $endpoint_id, string $type, string $body, int $attempt, int $delay ): void {
		$args = [ $endpoint_id, $type, $body, $attempt ];

		// No scheduler: once, now, and not again. Better than never, and it
		// must not hold the request that caused it up while it retries.
		if ( ! $this->queue->later( $this->hook(), $args, $delay ) ) {
			$this->send( ...$args );
		}
	}
}
