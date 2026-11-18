<?php
/**
 * What one webhook delivery attempt came to.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Dates\Clock;

/**
 * Class Attempt
 *
 * Whether it arrived, and enough to report and reschedule when it did not:
 * the receiver's status (0 when none came back), an error in words a site
 * owner can read, and how long the receiver asked to be left -- its
 * `Retry-After`, believed in either of its forms.
 *
 * @since 1.0.0
 */
final readonly class Attempt {

	/**
	 * @since 1.0.0
	 *
	 * @param bool     $sent        Whether the receiver accepted it (2xx).
	 * @param int      $status      The receiver's status, 0 for none.
	 * @param string   $error       Why it did not arrive, '' when it did.
	 * @param int|null $retry_after Seconds the receiver asked to wait, if
	 *                              it said.
	 */
	public function __construct(
		public bool $sent,
		public int $status,
		public string $error = '',
		public ?int $retry_after = null,
	) {}

	/**
	 * Read what WordPress's HTTP API answered.
	 *
	 * The `Retry-After` is read only from a 429 or a 503 -- the two answers
	 * that mean "later" and may say when.
	 *
	 * @since 1.0.0
	 *
	 * @param array|\WP_Error $response As wp_remote_post() returns it.
	 *
	 * @return self
	 */
	public static function from_response( $response ): self {
		if ( is_wp_error( $response ) ) {
			$error = $response->get_error_message();

			return new self( false, 0, '' !== $error ? $error : (string) $response->get_error_code() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 200 && $status < 300 ) {
			return new self( true, $status );
		}

		return new self(
			false,
			$status,
			'',
			in_array( $status, [ 429, 503 ], true ) ? self::retry_after( (string) wp_remote_retrieve_header( $response, 'retry-after' ) ) : null
		);
	}

	/**
	 * A `Retry-After` header, as seconds from now.
	 *
	 * RFC 9110 allows two forms and receivers send both: a number of
	 * seconds, and an HTTP-date. Reading only the number -- the usual
	 * is_numeric() check -- throws the date away and falls back on the
	 * schedule, at exactly the moment the receiver said something better. A
	 * date already past is nought, which a retry schedule reads as "no
	 * answer", and anything unreadable is null.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $header The header's value, as received.
	 * @param int|null    $now    Current time. Null uses the clock.
	 *
	 * @return int|null Seconds to wait, or null when it does not say.
	 */
	public static function retry_after( ?string $header, ?int $now = null ): ?int {
		$header = trim( (string) $header );

		if ( '' === $header ) {
			return null;
		}

		if ( ctype_digit( $header ) ) {
			// Twenty digits is not a wait anybody meant, and not an int.
			return strlen( $header ) > 9 ? PHP_INT_MAX : (int) $header;
		}

		$value = (string) preg_replace( '/\s+/', ' ', $header );
		$utc   = new \DateTimeZone( 'UTC' );

		// The three forms a recipient must accept: the one in use, and two
		// that are obsolete and still sent.
		foreach ( [ '!D, d M Y H:i:s \G\M\T', '!l, d-M-y H:i:s \G\M\T', '!D M j H:i:s Y' ] as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $utc );

			// A date that rolled over -- 31 February -- parses with a
			// warning, and is not one the sender meant.
			if ( false !== $date && false === \DateTimeImmutable::getLastErrors() ) {
				return max( 0, $date->getTimestamp() - ( $now ?? Clock::time() ) );
			}
		}

		return null;
	}
}
