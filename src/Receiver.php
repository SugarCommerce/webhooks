<?php
/**
 * Receiving webhooks: verified, and handled once.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use Closure;
use SugarCommerce\Jobs\Inbox;
use SugarCommerce\Jobs\Models\Claim;
use SugarCommerce\Webhooks\Models\Received;
use Throwable;

/**
 * Class Receiver
 *
 * The other end: a webhook arriving at the site, from another site running
 * this library or from anything else that signs Stripe's way. Three checks
 * stand between the request and the handler, in the order that matters:
 *
 * 1. **The signature**, over the raw body, in constant time, with the
 *    timestamp inside the window ({@see Signature::verify()}). Nothing is
 *    decoded, let alone acted on, before it passes.
 * 2. **The event's id.** A signature fresh enough to pass can be sent twice
 *    inside the window -- by an attacker who captured it, and far more
 *    often by a sender retrying after a timeout. With an {@see Inbox}, the
 *    id is claimed by its unique index before the handler runs: an event
 *    already handled is acknowledged and not handled again, and one being
 *    handled right now is answered 409 so the sender tries later.
 * 3. **The handler's outcome.** One that throws, or returns false, marks
 *    the event failed and answers 500, so the sender's retry takes it over.
 *    Answering "already handled" to that retry would drop the event.
 *
 *   $receiver = new Receiver( fn() => get_option( 'myplugin_inbound_secret' ), new Inbox( $wpdb->prefix . 'myplugin_events' ), header: 'MyShop-Signature' );
 *
 *   register_rest_route( 'myplugin/v1', '/webhook', [
 *       'methods'             => 'POST',
 *       'permission_callback' => '__return_true',      // the signature is the permission
 *       'callback'            => fn( WP_REST_Request $request ) => $receiver->rest( $request, function ( array $event ) {
 *           // ... act on $event['type'] and $event['data'] ...
 *       } ),
 *   ] );
 *
 * @since 1.0.0
 */
final class Receiver {

	/**
	 * The secret, or every one valid while it is rotated, or what reads it.
	 *
	 * @var string|array|Closure
	 */
	private string|array|Closure $secret;

	/**
	 * @since 1.0.0
	 *
	 * @param string|string[]|Closure $secret    The shared secret; every one
	 *                                           valid while it is rotated; or
	 *                                           a closure answering either,
	 *                                           read on each delivery.
	 * @param Inbox|null              $inbox     Where event ids are claimed.
	 *                                           Without one, a delivery that
	 *                                           verifies is always handled.
	 * @param int                     $tolerance Seconds of clock skew
	 *                                           allowed. Five minutes.
	 * @param string                  $header    The signature header, read
	 *                                           by self::rest().
	 * @param string                  $id_key    The event's id in the body.
	 */
	public function __construct(
		#[\SensitiveParameter] string|array|Closure $secret,
		private ?Inbox $inbox = null,
		private int $tolerance = Signature::DEFAULT_TOLERANCE,
		private string $header = Signature::HEADER,
		private string $id_key = 'id',
	) {
		$this->secret = $secret;
	}

	/**
	 * Verify a delivery and, the first time it is seen, handle it.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $body      The raw request body, never re-encoded.
	 * @param string   $signature The signature header's value.
	 * @param callable $handle    fn( array $event ): mixed. Throw, or
	 *                            return false, when it failed.
	 *
	 * @return Received
	 */
	public function receive( string $body, string $signature, callable $handle ): Received {
		$secret = $this->secret instanceof Closure ? ( $this->secret )() : $this->secret;
		$check  = Signature::verify( $body, $signature, is_array( $secret ) ? $secret : (string) $secret, $this->tolerance );

		if ( ! $check->valid ) {
			return new Received( 400, $check->reason );
		}

		// Checked before it is decoded: a signed body is still somebody
		// else's text, and json_validate() reads it without building it.
		$event = json_validate( $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $event ) ) {
			return new Received( 400, 'The body is not a JSON object.' );
		}

		$id = $event[ $this->id_key ] ?? null;

		if ( null !== $this->inbox ) {
			if ( ! is_scalar( $id ) || '' === (string) $id ) {
				return new Received( 400, 'The event has no id to handle it once by.', $event );
			}

			$claim = $this->inbox->claim( (string) $id );

			if ( Claim::Done === $claim ) {
				return new Received( 200, 'Already handled.', $event, true );
			}

			if ( Claim::Busy === $claim ) {
				return new Received( 409, 'Being handled by another delivery.', $event );
			}
		}

		try {
			$failed = false === $handle( $event );
			$error  = null;
		} catch ( Throwable $thrown ) {
			$failed = true;
			$error  = $thrown;
		}

		if ( $failed ) {
			// The class, not the message: a message can carry a customer's
			// address or a URL with a token in it, and the inbox keeps it.
			// The exception itself is on the result, to log as you see fit.
			$this->inbox?->fail( (string) $id, null === $error ? 'The handler returned false.' : $error::class );

			return new Received( 500, null === $error ? 'The handler returned false.' : 'The handler threw ' . $error::class . '.', $event, false, $error );
		}

		$this->inbox?->complete( (string) $id );

		return new Received( 200, '', $event );
	}

	/**
	 * Receive a delivery made to a REST route, and answer it.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param callable         $handle  fn( array $event ): mixed.
	 *
	 * @return \WP_REST_Response Only a status: what went wrong is for the
	 *                           site's log, not the sender.
	 */
	public function rest( \WP_REST_Request $request, callable $handle ): \WP_REST_Response {
		$received = $this->receive( (string) $request->get_body(), (string) $request->get_header( $this->header ), $handle );

		return new \WP_REST_Response( [ 'received' => $received->ok() ], $received->status );
	}
}
