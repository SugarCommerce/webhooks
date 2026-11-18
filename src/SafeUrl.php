<?php
/**
 * Posting to an address somebody typed in.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Network\IP;

/**
 * Class SafeUrl
 *
 * A webhook endpoint is an address a site owner typed into a settings
 * screen, and the server posts to it. The server has network reach nobody
 * else has: `169.254.169.254` (the cloud's instance credentials), `10.x`
 * (the database), `127.0.0.1` (whatever is bound to loopback). Without a
 * check, the endpoint field is a way to make the server send requests there.
 *
 * WordPress's own wp_safe_remote_post() is the base, and it is not enough on
 * its own. It resolves the host with gethostbyname(), which answers one IPv4
 * address, so a name with a private AAAA record -- or several A records, one
 * of them private -- passes. It knows nothing of IPv6: `[::1]`, `[fd00::1]`
 * and the NAT64 and 6to4 forms that carry an IPv4 address inside are all
 * "external". This adds:
 *
 * - **Every address the name resolves to, A and AAAA, must be public.**
 *   One private answer among public ones is a rebinding attempt, not a
 *   misconfiguration. "Public" is {@see IP::is_public()}'s answer, the one
 *   the rest of the family uses: IANA's special-purpose blocks for both
 *   families, in canonical binary form, and an IPv6 address that carries an
 *   IPv4 one -- mapped, SIIT, NAT64, 6to4 -- judged by the address it
 *   carries, so `::ffff:a9fe:a9fe` meets the same rule as `169.254.169.254`.
 * - **No credentials in the URL, and a plain host**: `http://real@internal/`
 *   and `%2e` in a host are read differently by different parsers.
 * - **The connection pinned** to the address that was checked, when curl is
 *   the transport, so a second DNS answer cannot send it somewhere else.
 * - **Redirects re-checked**, each hop, when any are followed at all.
 *
 * `http_request_host_is_external` -- the filter WordPress already offers for
 * "this private host is fine" -- is honoured, so a local development setup
 * that uses it keeps working. Ports are WordPress's business, through
 * `http_allowed_safe_ports`.
 *
 * @since 1.0.0
 */
final class SafeUrl {

	/**
	 * Statuses that are followed, when redirects are followed at all.
	 *
	 * The ones that keep the method and the body. A 303 means "go and GET
	 * something else", which is not delivering a webhook.
	 */
	private const FOLLOWED = [ 301, 302, 307, 308 ];

	/**
	 * How a name is resolved: fn( string $host ): string[].
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * @since 1.0.0
	 *
	 * @param bool          $allow_private Permit private and loopback
	 *                                     addresses. For development only:
	 *                                     it switches off the point of the
	 *                                     class.
	 * @param callable|null $resolver      fn( string $host ): string[], every
	 *                                     address a name resolves to. For
	 *                                     tests; DNS when left out.
	 */
	public function __construct(
		private bool $allow_private = false,
		?callable $resolver = null,
	) {
		$this->resolver = $resolver ?? [ self::class, 'resolve' ];
	}

	/**
	 * Why an address may not be posted to, or null when it may.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url The address.
	 *
	 * @return string|null A reason, for a log -- not for the person who
	 *                     typed the address, whom it would help to probe.
	 */
	public function refuse( string $url ): ?string {
		return $this->check( $url )[0];
	}

	/**
	 * Post to an address, refusing ones that reach inside the network.
	 *
	 * wp_safe_remote_post(), after the check, with the connection pinned to
	 * the address that passed. Redirects are followed by hand, and only as
	 * many as `$redirects`: each hop goes through the check again, because
	 * a public address answering "308, go to http://10.0.0.5/" is the whole
	 * attack.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url       The address.
	 * @param array  $args      As for wp_remote_post(). `redirection` is
	 *                          replaced.
	 * @param int    $redirects Redirects to follow. Nought treats a 3xx as
	 *                          the answer.
	 *
	 * @return array|\WP_Error The response, or why there is none.
	 */
	public function post( string $url, array $args = [], int $redirects = 0 ) {
		$args['redirection'] = 0;

		for ( $hop = 0; ; $hop++ ) {
			[ $reason, $pin ] = $this->check( $url );

			if ( null !== $reason ) {
				return new \WP_Error( 'http_request_not_executed', $reason, [ 'url' => $url ] );
			}

			$response = $this->pinned( $url, $args, $pin );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( $hop >= $redirects || '' === $location || ! in_array( $status, self::FOLLOWED, true ) ) {
				return $response;
			}

			$url = self::absolute( $location, $url );
		}
	}

	/**
	 * Every address a name resolves to, A and AAAA.
	 *
	 * An IP literal is returned as it is.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host A name or an IP literal.
	 *
	 * @return string[] Empty when it does not resolve.
	 */
	public static function resolve( string $host ): array {
		$host = trim( $host, '[]' );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}

		$ips = [];
		$a   = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not resolve is an answer, not a warning.

		if ( is_array( $a ) ) {
			$ips = $a;
		}

		$aaaa = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_AAAA ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.

		if ( is_array( $aaaa ) ) {
			foreach ( $aaaa as $record ) {
				if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Check an address: why not, and the address to connect to.
	 *
	 * @param string $url The address.
	 *
	 * @return array{0: string|null, 1: string} A reason or null, and the IP
	 *                                          that passed.
	 */
	private function check( string $url ): array {
		// parse_url() turns a control character in a host into an
		// underscore, so `http://example.com\n/` would be checked as a
		// name nobody asked for. No valid URL has whitespace in it.
		if ( 1 === preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return [ 'The address has whitespace or a control character in it.', '' ];
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return [ 'The address could not be read.', '' ];
		}

		if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return [ 'Only http and https addresses are posted to.', '' ];
		}

		// Read differently by different parsers, which is what makes them
		// useful for smuggling a host past a check.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return [ 'The address has a username or password in it.', '' ];
		}

		$host = (string) $parts['host'];

		if ( ! self::is_plain_host( $host ) ) {
			return [ 'The host is not a plain name or IP address.', '' ];
		}

		$ips = array_values( array_filter( array_map( 'strval', (array) ( $this->resolver )( $host ) ) ) );

		if ( [] === $ips ) {
			return [ 'The host does not resolve.', '' ];
		}

		if ( ! $this->allow_private && ! apply_filters( 'http_request_host_is_external', false, trim( $host, '[]' ), $url ) ) {
			foreach ( $ips as $ip ) {
				// Every answer must pass. One private address among public
				// ones is a rebinding attempt.
				if ( ! IP::is_public( $ip ) ) {
					return [ 'The host resolves to a private or reserved address.', '' ];
				}
			}
		}

		return [ null, $ips[0] ];
	}

	/**
	 * Post, with curl held to the address that was checked.
	 *
	 * CURLOPT_RESOLVE for this host and port, for this one request. The
	 * name stays in the URL, so TLS still checks the certificate against
	 * it. With another transport the check stands alone.
	 *
	 * @param string $url  The address.
	 * @param array  $args As for wp_safe_remote_post().
	 * @param string $ip   The address that passed.
	 *
	 * @return array|\WP_Error
	 */
	private function pinned( string $url, array $args, string $ip ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port = (int) wp_parse_url( $url, PHP_URL_PORT );
		$port = $port > 0 ? $port : ( 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80 );

		$pin = static function ( $handle ) use ( $host, $port, $ip ): void {
			if ( defined( 'CURLOPT_RESOLVE' ) && ( is_resource( $handle ) || $handle instanceof \CurlHandle ) ) {
				$address = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;

				curl_setopt( $handle, CURLOPT_RESOLVE, [ trim( $host, '[]' ) . ':' . $port . ':' . $address ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the only way to pin the connection; this is WordPress's own curl handle.
			}
		};

		add_action( 'http_api_curl', $pin, 10, 1 );

		try {
			return wp_safe_remote_post( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
		}
	}

	/**
	 * A `Location` as a whole address, against the one it came from.
	 *
	 * @param string $location The header.
	 * @param string $base     The address that answered.
	 *
	 * @return string
	 */
	private static function absolute( string $location, string $base ): string {
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
			return $location;
		}

		$parts  = (array) wp_parse_url( $base );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( str_starts_with( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}

		$path = (string) ( $parts['path'] ?? '/' );

		return $origin . substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) . $location;
	}

	/**
	 * Whether a host is spelled the way a resolver and curl agree on.
	 *
	 * Letters, digits, hyphens, dots and underscores, or a bracketed IPv6
	 * literal. Anything else is where parsers part company: curl decodes
	 * `%2e` in a host into a dot and parse_url() does not, so the name that
	 * was checked and the one connected to would be two names. A raw
	 * Unicode name is refused for the same reason; give its `xn--` form.
	 *
	 * @param string $host Host as wp_parse_url() returned it.
	 *
	 * @return bool
	 */
	private static function is_plain_host( string $host ): bool {
		if ( str_starts_with( $host, '[' ) ) {
			return 1 === preg_match( '/^\[[0-9a-f:.]+\]$/iD', $host )
				&& false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		return 1 === preg_match( '/^[a-z0-9_.-]{1,253}$/iD', $host );
	}
}
