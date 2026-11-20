<?php
/**
 * Sender and health-store tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Webhooks\Breaker;
use SugarCommerce\Webhooks\Dispatcher;
use SugarCommerce\Webhooks\EndpointHealth;
use SugarCommerce\Webhooks\Endpoints;
use SugarCommerce\Network\Remote\SafeUrl;
use SugarCommerce\Webhooks\Sender;
use SugarCommerce\Webhooks\Signature;

/**
 * One POST, signed as it leaves; and the record of failures kept wherever
 * the plugin keeps it.
 */
final class SenderTest extends TestCase {

	private const SECRET = 'test-signing-key-not-a-real-secret';

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/**
	 * The clock runs again.
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/**
	 * A sender whose DNS says every name is public.
	 *
	 * @return Sender
	 */
	private function sender(): Sender {
		return new Sender( new SafeUrl( false, static fn(): array => [ '93.184.216.34' ] ), 'Shop', 'Shop/2.0', 7 );
	}

	/**
	 * Signed at the moment of sending, so a delivery that waited in the
	 * queue is not older than the receiver's window when it arrives.
	 */
	public function test_it_is_signed_when_it_is_sent(): void {
		Clock::freeze( 1_800_000_000 );

		$attempt = $this->sender()->send( 'https://hooks.example/in', 'order.completed', '{"id":"evt_1"}', self::SECRET );
		$request = $GLOBALS['wh_requests'][0];
		$headers = $request['args']['headers'];

		$this->assertTrue( $attempt->sent );
		$this->assertSame( 200, $attempt->status );
		$this->assertSame( '{"id":"evt_1"}', $request['args']['body'] );
		$this->assertSame( 7, $request['args']['timeout'] );
		$this->assertSame( 'order.completed', $headers['Shop-Event'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'Shop/2.0; https://shop.example/', $headers['User-Agent'] );
		$this->assertSame( Signature::sign( '{"id":"evt_1"}', self::SECRET, 1_800_000_000 ), $headers['Shop-Signature'] );
	}

	public function test_without_a_secret_it_is_unsigned(): void {
		$this->sender()->send( 'https://hooks.example/in', 'ping', '{}', '' );

		$this->assertArrayNotHasKey( 'Shop-Signature', $GLOBALS['wh_requests'][0]['args']['headers'] );
	}

	/**
	 * While a secret is rotated, both sign the one delivery.
	 */
	public function test_two_secrets_both_sign(): void {
		$this->sender()->send( 'https://hooks.example/in', 'ping', '{}', [ self::SECRET, 'test-old-key' ] );

		$header = $GLOBALS['wh_requests'][0]['args']['headers']['Shop-Signature'];

		$this->assertTrue( Signature::verify( '{}', $header, 'test-old-key' )->valid );
		$this->assertTrue( Signature::verify( '{}', $header, self::SECRET )->valid );
	}

	/**
	 * A refused address is a failed attempt like any other, never a throw.
	 */
	public function test_a_private_address_is_a_failed_attempt(): void {
		$sender  = new Sender( new SafeUrl( false, static fn(): array => [ '10.0.0.5' ] ) );
		$attempt = $sender->send( 'https://inside.example/', 'ping', '{}', self::SECRET );

		$this->assertFalse( $attempt->sent );
		$this->assertSame( 0, $attempt->status );
		$this->assertStringContainsString( 'private', $attempt->error );
		$this->assertSame( [], $GLOBALS['wh_requests'] );
	}

	public function test_the_user_agent_defaults_to_wordpress(): void {
		( new Sender( new SafeUrl( false, static fn(): array => [ '93.184.216.34' ] ) ) )->send( 'https://hooks.example/', 'ping', '{}', '' );

		$headers = $GLOBALS['wh_requests'][0]['args']['headers'];

		$this->assertSame( 'WordPress/7.1; https://shop.example/', $headers['User-Agent'] );
		$this->assertSame( 'ping', $headers['Webhook-Event'] );
	}

	/* ─── The record of failures, kept anywhere ─────────────────────── */

	public function test_health_can_be_kept_through_callables(): void {
		$store  = [];
		$health = new EndpointHealth(
			static function () use ( &$store ): array {
				return $store;
			},
			static function ( array $records ) use ( &$store ): void {
				$store = $records;
			},
			2,
			100
		);

		$this->assertNull( $health->failed( 'we_1', 503, '', 1000 ) );
		$this->assertSame( 1, $store['we_1']['failures'] );
		$this->assertNull( $health->failed( 'we_1', 503, '', 1050 ), 'Enough failures, not for long enough.' );
		$this->assertSame( 1100, $health->failed( 'we_1', 503, '', 1100 )['off_at'] ?? null );
		$this->assertSame( [ 'we_1' ], array_keys( $health->switched_off() ) );

		$health->forget( 'we_1' );

		$this->assertSame( [], $store );
		$this->assertNull( $health->get( 'we_1' ) );
	}

	/**
	 * The dispatcher passes a `health` store to the breaker in place of the
	 * option.
	 */
	public function test_the_dispatcher_keeps_health_where_it_is_told(): void {
		$store    = [ 'seen' => [] ];
		$webhooks = new Dispatcher(
			[
				'prefix'    => 'shop',
				'events'    => [ 'order.completed' => 'Order completed' ],
				'endpoints' => [
					'read'  => static fn(): array => [
						[
							'id'     => 'we_1',
							'url'    => 'https://hooks.example/',
							'secret' => self::SECRET,
							'active' => 1,
						],
					],
					'write' => static fn( array $rows ) => null,
				],
				'health'    => [
					'read'  => static function () use ( &$store ): array {
						return $store['seen'];
					},
					'write' => static function ( array $records ) use ( &$store ): void {
						$store['seen'] = $records;
					},
				],
				'resolver'  => static fn(): array => [ '93.184.216.34' ],
			]
		);

		$GLOBALS['wh_answers'][] = wh_answer( 500 );

		$webhooks->send( 'we_1', 'order.completed', '{}', 1 );

		$this->assertSame( 1, $store['seen']['we_1']['failures'] );
		$this->assertArrayNotHasKey( 'shop_webhook_health', $GLOBALS['wh_options'] );
	}

	public function test_a_health_store_needs_both_halves(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Breaker( new Endpoints( static fn(): array => [] ), 'shop', [ 'read' => static fn(): array => [] ] );
	}

	/**
	 * The prefixes a minted endpoint id and secret start with are the
	 * plugin's to choose.
	 */
	public function test_minted_names_take_the_configured_prefixes(): void {
		$webhooks = new Dispatcher(
			[
				'prefix'          => 'shop',
				'endpoint_prefix' => 'ep_',
				'secret_prefix'   => 'shopsec_',
			]
		);

		$row = $webhooks->endpoints()->sanitize( [ [ 'url' => 'https://hooks.example/' ] ] )[0];

		$this->assertMatchesRegularExpression( '/^ep_[a-f0-9]{16}$/', $row['id'] );
		$this->assertMatchesRegularExpression( '/^shopsec_[A-Za-z0-9]{32}$/', $row['secret'] );
		$this->assertSame( 'shop_webhook_endpoints', $webhooks->endpoints()->option_name() );
	}
}
