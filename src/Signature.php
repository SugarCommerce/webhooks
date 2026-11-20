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

use InvalidArgumentException;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Security\Hash;
use SugarCommerce\Webhooks\Models\Verification;

/**
 * Class Signature
 *
 * The Stripe-style `t=<timestamp>,v1=<hmac>` scheme, for both ends: sign
 * what you send, verify what you receive. Stripe's, because most people
 * who receive webhooks have verified one of Stripe's and already have the
 * code.
 *
 * The receiving half is the one people get wrong, and it is the half
 * that matters -- a webhook endpoint is a public URL that performs
 * privileged work. Four mistakes, all common:
 *
 * **Comparing with `===`.** String comparison short-circuits on the
 * first differing byte, so response time leaks how much of a guess was
 * correct and an attacker can recover a valid signature byte by byte.
 * {@see hash_equals()} is not optional.
 *
 * **Not checking the timestamp.** A signature stays valid forever, so
 * anyone who captures one request can replay it indefinitely -- refunding
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
 * A signature fresh enough to pass can still be sent twice inside the
 * window. {@see Receiver} records each event's id and refuses one already
 * handled: the timestamp bounds how long that record has to be kept, not
 * whether it is needed.
 *
 * @since 1.0.0
 */
final readonly class Signature {

	/**
	 * Header carrying the signature, when nobody names it.
	 *
	 * @since 1.0.0
	 */
	public const string HEADER = 'X-Webhook-Signature';

	/**
	 * How much clock skew to tolerate, in seconds.
	 *
	 * Five minutes matches Stripe. Longer widens the replay window;
	 * shorter starts rejecting legitimate deliveries when the sender's
	 * clock drifts or a queue backs up.
	 *
	 * @since 1.0.0
	 */
	public const int DEFAULT_TOLERANCE = 300;

	/**
	 * Sign a payload.
	 *
	 * Given more than one secret -- the new one and the one being retired
	 * -- each is signed at the same timestamp, as one `v1=` apiece, so a
	 * receiver on either verifies it and can move to the new one at its own
	 * pace.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $payload   Exact bytes to be sent.
	 * @param string|string[] $secret    Shared secret for this endpoint, or
	 *                                   every one currently valid for it.
	 * @param int|null        $timestamp Signing time. Null uses the clock.
	 *
	 * @return string Header value, e.g. `t=1700000000,v1=abc…`.
	 *
	 * @throws InvalidArgumentException When there is no secret.
	 */
	public static function sign( string $payload, #[\SensitiveParameter] string|array $secret, ?int $timestamp = null ): string {
		$secrets = self::secrets( $secret );

		if ( [] === $secrets ) {
			throw new InvalidArgumentException( 'A webhook secret is required to sign a payload.' );
		}

		$timestamp ??= Clock::time();

		// The timestamp is inside the signed string, so it cannot be
		// altered to extend the window without invalidating the whole
		// signature.
		$header = 't=' . $timestamp;

		foreach ( $secrets as $one ) {
			$header .= ',v1=' . self::hmac( $timestamp, $payload, $one );
		}

		return $header;
	}

	/**
	 * Verify a received payload.
	 *
	 * Pass the **raw request body**, exactly as received. Anything that
	 * has been decoded and re-encoded will not verify, and rightly so.
	 *
	 * While a secret is being rotated, pass both: a delivery signed with
	 * either verifies, and the old one is dropped once the sender has
	 * switched.
	 *
	 * @since 1.0.0
	 *
	 * @param string          $payload   Raw request body.
	 * @param string          $header    The signature header value.
	 * @param string|string[] $secret    Shared secret for this endpoint, or
	 *                                   every secret currently valid for it.
	 *                                   Empty ones are ignored.
	 * @param int             $tolerance Permitted clock skew in seconds. 0
	 *                                   disables the check, which also
	 *                                   disables replay protection. Below 0
	 *                                   is a misconfiguration, and fails.
	 * @param int|null        $now       Current time. Null uses the clock.
	 *
	 * @return Verification
	 */
	public static function verify(
		string $payload,
		string $header,
		#[\SensitiveParameter] string|array $secret,
		int $tolerance = self::DEFAULT_TOLERANCE,
		?int $now = null
	): Verification {
		$secrets = self::secrets( $secret );

		if ( [] === $secrets ) {
			return Verification::failed( 'No signing secret is configured for this endpoint.' );
		}

		// A negative window used to read as "no window", which turned a
		// typo into accepting every replay for ever.
		if ( $tolerance < 0 ) {
			return Verification::failed( 'The permitted time window cannot be negative.' );
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

		$matched = false;

		// Every candidate is compared against every secret, without an
		// early exit, so the time taken does not reveal which one matched
		// or how many were sent.
		foreach ( $secrets as $one ) {
			$expected = self::hmac( $timestamp, $payload, $one );

			foreach ( $signatures as $candidate ) {
				if ( hash_equals( $expected, $candidate ) ) {
					$matched = true;
				}
			}
		}

		if ( ! $matched ) {
			return Verification::failed( 'Signature does not match.' );
		}

		// Age is checked only after the signature passes, so a forged
		// request is never reported as merely stale.
		if ( $tolerance > 0 ) {
			$age = abs( ( $now ?? Clock::time() ) - $timestamp );

			if ( $age > $tolerance ) {
				return Verification::failed( 'Signature is outside the permitted time window (' . $age . 's old).' );
			}
		}

		return Verification::passed( $timestamp );
	}

	/**
	 * The HMAC of one signed string.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $timestamp Signing time.
	 * @param string $payload   Exact bytes.
	 * @param string $secret    One secret, not empty.
	 *
	 * @return string Lower-case hex SHA-256.
	 */
	private static function hmac( int $timestamp, string $payload, #[\SensitiveParameter] string $secret ): string {
		return ( new Hash( $secret ) )->opaque( $timestamp . '.' . $payload );
	}

	/**
	 * The secrets given, without the empty ones.
	 *
	 * @since 1.0.0
	 *
	 * @param string|array<int|string, mixed> $secret One or several.
	 *
	 * @return string[]
	 */
	private static function secrets( #[\SensitiveParameter] string|array $secret ): array {
		$out = [];

		foreach ( (array) $secret as $one ) {
			if ( is_string( $one ) && '' !== $one ) {
				$out[] = $one;
			}
		}

		return $out;
	}

	/**
	 * Parse a signature header.
	 *
	 * More than one `v1` may be present -- that is how a secret is rotated
	 * without dropping deliveries, so all of them are collected. More than
	 * one `t` is malformed: there is one signing time, and a header that
	 * offers a choice is not one this end should make for the sender.
	 *
	 * @since 1.0.0
	 *
	 * @param string $header Header value.
	 *
	 * @return array{0: int, 1: string[]}|null Null when malformed.
	 */
	private static function parse( string $header ): ?array {
		$timestamp  = null;
		$signatures = [];

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );

			if ( 2 !== count( $pair ) ) {
				continue;
			}

			[ $key, $value ] = $pair;

			if ( 't' === $key ) {
				// Fifteen digits is thirty million years; twenty overflowed
				// an integer, and the time checked was then not the time
				// signed.
				if ( null !== $timestamp || 1 !== preg_match( '/^\d{1,15}$/D', $value ) ) {
					return null;
				}

				$timestamp = (int) $value;
				continue;
			}

			if ( 'v1' === $key && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
				$signatures[] = $value;
			}
		}

		if ( null === $timestamp || [] === $signatures ) {
			return null;
		}

		return [ $timestamp, $signatures ];
	}
}
