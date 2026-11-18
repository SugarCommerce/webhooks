<?php
/**
 * Private-network refusal tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Network\IP;
use SugarCommerce\Webhooks\SafeUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An address somebody typed in must not make the server post inside its own
 * network -- by name, by literal, by any spelling of an address, by one
 * private answer among several, or by a redirect.
 */
final class SafeUrlTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/**
	 * A guard whose DNS answers from a table.
	 *
	 * @param array<string, string[]> $dns Host => addresses.
	 *
	 * @return SafeUrl
	 */
	private function guard( array $dns = array() ): SafeUrl {
		return new SafeUrl(
			false,
			static fn( string $host ): array => $dns[ $host ] ?? SafeUrl::resolve( $host )
		);
	}

	/**
	 * Addresses and whether each is the public internet.
	 *
	 * @param string $ip       The address.
	 * @param bool   $expected Whether it is public.
	 */
	#[DataProvider( 'addresses' )]
	public function test_which_addresses_are_public( string $ip, bool $expected ): void {
		$this->assertSame( $expected, IP::is_public( $ip ) );
	}

	/**
	 * Every kind of address that is not the internet, and some that are.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function addresses(): array {
		return array(
			'public v4'              => array( '93.184.216.34', true ),
			'public v6'              => array( '2606:2800:220:1:248:1893:25c8:1946', true ),
			'loopback'               => array( '127.0.0.1', false ),
			'loopback, elsewhere'    => array( '127.8.9.10', false ),
			'ten'                    => array( '10.0.0.5', false ),
			'one-seven-two'          => array( '172.16.0.1', false ),
			'one-seven-two, top'     => array( '172.31.255.255', false ),
			'just past it'           => array( '172.32.0.1', true ),
			'one-nine-two'           => array( '192.168.1.1', false ),
			'metadata service'       => array( '169.254.169.254', false ),
			'carrier NAT'            => array( '100.64.0.1', false ),
			'this network'           => array( '0.0.0.0', false ),
			'multicast'              => array( '224.0.0.1', false ),
			'documentation'          => array( '203.0.113.9', false ),
			'v6 loopback'            => array( '::1', false ),
			'v6 unique local'        => array( 'fd00::1', false ),
			'v6 link-local'          => array( 'fe80::1', false ),
			'mapped metadata'        => array( '::ffff:169.254.169.254', false ),
			'mapped public'          => array( '::ffff:93.184.216.34', true ),
			'mapped, in hex'         => array( '::ffff:a9fe:a9fe', false ),
			'NAT64 metadata'         => array( '64:ff9b::a9fe:a9fe', false ),
			'NAT64, written long'    => array( '0064:ff9b::a9fe:a9fe', false ),
			'6to4'                   => array( '2002:0a00:0005::1', false ),
			'v6 documentation'       => array( '2001:db8::1', false ),
			'bracketed v6 loopback'  => array( '[::1]', false ),
			'not an address'         => array( 'localhost', false ),
		);
	}

	/**
	 * Addresses refused before anything is looked up.
	 *
	 * @param string $url The address.
	 */
	#[DataProvider( 'malformed' )]
	public function test_malformed_addresses_are_refused( string $url ): void {
		// Every name resolves, and to somewhere public: what refuses these
		// has to be the shape of the address, not the lookup.
		$this->assertNotNull( ( new SafeUrl( false, static fn(): array => array( '93.184.216.34' ) ) )->refuse( $url ) );
	}

	/**
	 * Addresses that parsers disagree about.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed(): array {
		return array(
			'credentials'      => array( 'https://real.example@10.0.0.5/' ),
			'user only'        => array( 'https://admin@hooks.example/' ),
			'not http'         => array( 'gopher://hooks.example/' ),
			'file'             => array( 'file:///etc/passwd' ),
			'a newline'        => array( "https://hooks.example\n/" ),
			'a space'          => array( 'https://hooks .example/' ),
			'encoded dot'      => array( 'https://hooks%2eexample/' ),
			'no host'          => array( 'https:///path' ),
			'unicode host'     => array( 'https://hööks.example/' ),
			'a zone index'     => array( 'https://[fe80::1%25en0]/' ),
		);
	}

	/**
	 * The same address, well-formed, passes: the refusals above are about shape.
	 */
	public function test_a_plain_address_passes(): void {
		$this->assertNull( ( new SafeUrl( false, static fn(): array => array( '93.184.216.34' ) ) )->refuse( 'https://hooks.example/in?x=1' ) );
	}

	/**
	 * A private literal, by any spelling.
	 */
	public function test_private_literals_are_refused(): void {
		$guard = $this->guard();

		$this->assertNotNull( $guard->refuse( 'http://127.0.0.1/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[::1]/' ) );
		$this->assertNotNull( $guard->refuse( 'http://[::ffff:169.254.169.254]/latest/meta-data/' ) );
		$this->assertNull( $guard->refuse( 'https://93.184.216.34/' ) );
	}

	/**
	 * A name is judged by every address it has, not the first.
	 */
	public function test_one_private_answer_among_public_ones_is_refused(): void {
		$guard = $this->guard(
			array(
				'rebind.example' => array( '93.184.216.34', '10.0.0.5' ),
				'six.example'    => array( '93.184.216.34', 'fd00::5' ),
				'fine.example'   => array( '93.184.216.34', '2606:2800:220:1::1' ),
			)
		);

		$this->assertNotNull( $guard->refuse( 'https://rebind.example/' ) );
		$this->assertNotNull( $guard->refuse( 'https://six.example/' ), 'The AAAA record was not checked.' );
		$this->assertNull( $guard->refuse( 'https://fine.example/' ) );
	}

	/**
	 * A name that does not resolve has nowhere to go.
	 */
	public function test_a_name_that_does_not_resolve_is_refused(): void {
		$this->assertNotNull( $this->guard( array( 'nowhere.example' => array() ) )->refuse( 'https://nowhere.example/' ) );
	}

	/**
	 * WordPress's own escape hatch is honoured, for a development setup.
	 */
	public function test_the_core_filter_allows_a_private_host(): void {
		add_filter( 'http_request_host_is_external', static fn( $external, string $host ): bool => 'hooks.test' === $host, 10, 2 );

		$guard = $this->guard(
			array(
				'hooks.test'  => array( '127.0.0.1' ),
				'other.test'  => array( '127.0.0.1' ),
			)
		);

		$this->assertNull( $guard->refuse( 'http://hooks.test/' ) );
		$this->assertNotNull( $guard->refuse( 'http://other.test/' ) );
	}

	/**
	 * Or switched off altogether.
	 */
	public function test_private_addresses_can_be_allowed(): void {
		$this->assertNull( ( new SafeUrl( true ) )->refuse( 'http://127.0.0.1/' ) );
	}

	/**
	 * A refused address is never requested.
	 */
	public function test_a_refused_post_makes_no_request(): void {
		$result = $this->guard( array( 'inside.example' => array( '10.1.2.3' ) ) )->post( 'https://inside.example/', array( 'body' => 'x' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array(), $GLOBALS['wh_requests'] );
	}

	/**
	 * Without following, a redirect is the answer.
	 */
	public function test_a_redirect_is_not_followed_by_default(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 308, array( 'Location' => 'https://elsewhere.example/' ) );

		$result = $this->guard( array( 'hooks.example' => array( '93.184.216.34' ) ) )->post( 'https://hooks.example/' );

		$this->assertSame( 308, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 1, $GLOBALS['wh_requests'] );
		$this->assertSame( 0, $GLOBALS['wh_requests'][0]['args']['redirection'] );
	}

	/**
	 * Followed, each hop is checked: a public address cannot bounce inside.
	 */
	public function test_a_redirect_inside_the_network_is_refused(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 307, array( 'Location' => 'http://169.254.169.254/latest/meta-data/' ) );

		$result = $this->guard( array( 'hooks.example' => array( '93.184.216.34' ) ) )->post( 'https://hooks.example/', array(), 3 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertCount( 1, $GLOBALS['wh_requests'] );
	}

	/**
	 * A redirect to somewhere public is followed with the same request.
	 */
	public function test_a_public_redirect_is_followed(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 308, array( 'Location' => '/v2/hook' ) );

		$guard  = $this->guard( array( 'hooks.example' => array( '93.184.216.34' ) ) );
		$result = $guard->post( 'https://hooks.example/v1/hook', array( 'body' => 'same' ), 1 );

		$this->assertSame( 200, wp_remote_retrieve_response_code( $result ) );
		$this->assertSame( 'https://hooks.example/v2/hook', $GLOBALS['wh_requests'][1]['url'] );
		$this->assertSame( 'same', $GLOBALS['wh_requests'][1]['args']['body'] );
	}

	/**
	 * Only as many as asked for.
	 */
	public function test_redirects_stop_at_the_limit(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 302, array( 'Location' => 'https://hooks.example/a' ) );
		$GLOBALS['wh_answers'][] = wh_answer( 302, array( 'Location' => 'https://hooks.example/b' ) );

		$result = $this->guard( array( 'hooks.example' => array( '93.184.216.34' ) ) )->post( 'https://hooks.example/', array(), 1 );

		$this->assertSame( 302, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 2, $GLOBALS['wh_requests'] );
	}

	/**
	 * A 303 says "go and GET something else": not a delivery.
	 */
	public function test_a_303_is_not_followed(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 303, array( 'Location' => 'https://hooks.example/thanks' ) );

		$result = $this->guard( array( 'hooks.example' => array( '93.184.216.34' ) ) )->post( 'https://hooks.example/', array(), 3 );

		$this->assertSame( 303, wp_remote_retrieve_response_code( $result ) );
		$this->assertCount( 1, $GLOBALS['wh_requests'] );
	}

	/**
	 * The pin names the host and port that were checked, and goes afterwards.
	 */
	public function test_the_pin_is_for_this_request_only(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'No curl.' );
		}

		$guard = $this->guard( array( 'hooks.example' => array( '2606:2800:220:1::1' ) ) );

		$guard->post( 'http://hooks.example:8080/' );

		$this->assertSame( array( 'hooks.example:8080:[2606:2800:220:1::1]' ), $GLOBALS['wh_requests'][0]['pin'] );
		$this->assertSame( array(), array_values( $GLOBALS['wh_hooks']['http_api_curl'] ?? array() ) );
	}
}
