<?php
/**
 * Webhook payload signing and verification.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

/**
 * Class Signature
 *
 * The Stripe-style `t=<timestamp>,v1=<hmac>` scheme, for both ends: sign
 * what you send, verify what you receive.
 *
 * The receiving half is the one people get wrong, and it is the half
 * that matters — a webhook endpoint is a public URL that performs
 * privileged work. Four mistakes, all common:
 *
 * **Comparing with `===`.** String comparison short-circuits on the
 * first differing byte, so response time leaks how much of a guess was
 * correct and an attacker can recover a valid signature byte by byte.
 * {@see hash_equals()} is not optional.
 *
 * **Not checking the timestamp.** A signature stays valid forever, so
 * anyone who captures one request can replay it indefinitely — refunding
 * the same order, re-granting the same licence.
 *
 * **Verifying the parsed body.** The signature covers the exact bytes
 * that were sent. Decode and re-encode and the JSON key order or number
 * formatting shifts, so the signature no longer matches its own payload.
 * Always verify the raw body, then parse.
 *
 * **Accepting an unsigned request.** An absent header must be a
 * rejection, not a skipped check.
 *
 * @since 1.0.0
 */
final readonly class Signature {

	/**
	 * Header carrying the signature.
	 */
	public const HEADER = 'X-Webhook-Signature';

	/**
	 * How much clock skew to tolerate, in seconds.
	 *
	 * Five minutes matches Stripe. Longer widens the replay window;
	 * shorter starts rejecting legitimate deliveries when the sender's
	 * clock drifts or a queue backs up.
	 */
	public const DEFAULT_TOLERANCE = 300;

	/**
	 * Sign a payload.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $payload   Exact bytes to be sent.
	 * @param string   $secret    Shared secret for this endpoint.
	 * @param int|null $timestamp Signing time. Null uses the clock.
	 *
	 * @return string Header value, e.g. `t=1700000000,v1=abc…`.
	 *
	 * @throws \InvalidArgumentException When the secret is empty.
	 */
	public static function sign( string $payload, #[\SensitiveParameter] string $secret, ?int $timestamp = null ): string {
		if ( '' === $secret ) {
			throw new \InvalidArgumentException( 'A webhook secret is required to sign a payload.' );
		}

		$timestamp ??= time();

		// The timestamp is inside the signed string, so it cannot be
		// altered to extend the window without invalidating the whole
		// signature.
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	}

	/**
	 * Verify a received payload.
	 *
	 * Pass the **raw request body**, exactly as received. Anything that
	 * has been decoded and re-encoded will not verify, and rightly so.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $payload   Raw request body.
	 * @param string   $header    The signature header value.
	 * @param string   $secret    Shared secret for this endpoint.
	 * @param int      $tolerance Permitted clock skew in seconds. 0 disables
	 *                            the check, which also disables replay
	 *                            protection.
	 * @param int|null $now       Current time. For tests.
	 *
	 * @return Verification
	 */
	public static function verify(
		string $payload,
		string $header,
		#[\SensitiveParameter] string $secret,
		int $tolerance = self::DEFAULT_TOLERANCE,
		?int $now = null
	): Verification {
		if ( '' === $secret ) {
			return Verification::failed( 'No signing secret is configured for this endpoint.' );
		}

		if ( '' === trim( $header ) ) {
			// An unsigned request is a rejection, never a skipped check.
			return Verification::failed( 'Signature header is missing.' );
		}

		$parts = self::parse( $header );

		if ( null === $parts ) {
			return Verification::failed( 'Signature header is malformed.' );
		}

		[ $timestamp, $signatures ] = $parts;

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		$matched  = false;

		// Every candidate is compared, without an early exit, so the time
		// taken does not reveal which one matched or how many were sent.
		foreach ( $signatures as $candidate ) {
			if ( hash_equals( $expected, $candidate ) ) {
				$matched = true;
			}
		}

		if ( ! $matched ) {
			return Verification::failed( 'Signature does not match.' );
		}

		// Age is checked only after the signature passes, so a forged
		// request is never reported as merely stale.
		if ( $tolerance > 0 ) {
			$age = abs( ( $now ?? time() ) - $timestamp );

			if ( $age > $tolerance ) {
				return Verification::failed( 'Signature is outside the permitted time window (' . $age . 's old).' );
			}
		}

		return Verification::passed( $timestamp );
	}

	/**
	 * Parse a signature header.
	 *
	 * More than one `v1` may be present — that is how a secret is rotated
	 * without dropping deliveries, so all of them are collected.
	 *
	 * @since 1.0.0
	 *
	 * @param string $header Header value.
	 *
	 * @return array{0: int, 1: string[]}|null Null when malformed.
	 */
	private static function parse( string $header ): ?array {
		$timestamp  = null;
		$signatures = array();

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			[ $key, $value ] = $pair;

			if ( 't' === $key && 1 === preg_match( '/^\d{1,20}$/', $value ) ) {
				$timestamp = (int) $value;
				continue;
			}

			if ( 'v1' === $key && 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ) {
				$signatures[] = $value;
			}
		}

		if ( null === $timestamp || array() === $signatures ) {
			return null;
		}

		return array( $timestamp, $signatures );
	}
}
