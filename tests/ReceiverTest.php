<?php
/**
 * Receiver tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Inbox;
use SugarCommerce\Webhooks\Receiver;
use SugarCommerce\Webhooks\Signature;

/**
 * A delivery is handled only when it is signed, fresh and not seen before;
 * a failed handler leaves the event for the sender's retry.
 */
final class ReceiverTest extends TestCase {

	private const SECRET = 'test-signing-key-not-a-real-secret';
	private const NOW    = 1_800_000_000;

	/**
	 * A fresh inbox table and a stopped clock.
	 */
	protected function setUp(): void {
		wh_reset();

		$GLOBALS['wpdb']->pdo->exec(
			'CREATE TABLE wp_events (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				event_id TEXT NOT NULL UNIQUE,
				status TEXT NOT NULL DEFAULT "pending",
				attempts INTEGER NOT NULL DEFAULT 1,
				last_error TEXT,
				date_created INTEGER NOT NULL,
				date_claimed INTEGER,
				date_processed INTEGER
			)'
		);

		Clock::freeze( self::NOW );
	}

	/**
	 * The clock runs again.
	 */
	protected function tearDown(): void {
		Clock::reset();
	}

	/**
	 * A receiver on the test table.
	 *
	 * @param mixed $secret What it verifies with.
	 *
	 * @return Receiver
	 */
	private function receiver( $secret = self::SECRET ): Receiver {
		return new Receiver( $secret, new Inbox( 'wp_events' ), header: 'Shop-Signature' );
	}

	/**
	 * An event's body.
	 *
	 * @param string $id Its id.
	 *
	 * @return string
	 */
	private function body( string $id = 'evt_1' ): string {
		return (string) json_encode(
			[
				'id'   => $id,
				'type' => 'order.completed',
				'data' => [ 'order' => 7 ],
			]
		);
	}

	/**
	 * The inbox row for an event.
	 *
	 * @param string $id The event.
	 *
	 * @return array<string, mixed>
	 */
	private function row( string $id ): array {
		return (array) $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_events WHERE event_id = '{$id}'", ARRAY_A );
	}

	public function test_a_signed_event_is_handled_once(): void {
		$body    = $this->body();
		$handled = [];
		$handle  = static function ( array $event ) use ( &$handled ): void {
			$handled[] = $event['id'];
		};

		$first  = $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), $handle );
		$second = $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), $handle );

		$this->assertSame( 200, $first->status );
		$this->assertTrue( $first->handled() );
		$this->assertSame( 'order.completed', $first->event['type'] );

		$this->assertSame( 200, $second->status, 'Already handled is still "received".' );
		$this->assertTrue( $second->duplicate );
		$this->assertFalse( $second->handled() );

		$this->assertSame( [ 'evt_1' ], $handled );
		$this->assertSame( 'done', $this->row( 'evt_1' )['status'] );
	}

	/**
	 * Nothing is decoded, let alone handled, before the signature passes.
	 */
	public function test_a_bad_signature_is_refused_before_anything_runs(): void {
		$body = $this->body();
		$ran  = false;

		foreach ( [ '', 't=1,v1=' . str_repeat( 'a', 64 ), Signature::sign( $body, 'test-wrong-key' ), Signature::sign( $body, self::SECRET, self::NOW - 301 ) ] as $header ) {
			$result = $this->receiver()->receive(
				$body,
				$header,
				static function () use ( &$ran ): void {
					$ran = true;
				}
			);

			$this->assertSame( 400, $result->status );
			$this->assertNull( $result->event );
		}

		$this->assertFalse( $ran );
		$this->assertSame( [], $this->row( 'evt_1' ) );
	}

	/**
	 * A replay inside the window verifies, and is still not handled again.
	 */
	public function test_a_replay_inside_the_window_is_not_handled_again(): void {
		$body   = $this->body();
		$header = Signature::sign( $body, self::SECRET );
		$count  = 0;
		$handle = static function () use ( &$count ): void {
			++$count;
		};

		$this->receiver()->receive( $body, $header, $handle );
		Clock::advance( 200 );
		$this->receiver()->receive( $body, $header, $handle );

		$this->assertSame( 1, $count );
	}

	public function test_a_body_that_is_not_an_event_is_refused(): void {
		foreach ( [ 'not json', '"a string"', '{"type":"order.completed"}', '{"id":""}', '{"id":["x"]}' ] as $body ) {
			$result = $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null );

			$this->assertSame( 400, $result->status, $body );
		}
	}

	/**
	 * A failure is not "done": the sender's retry takes the event over.
	 */
	public function test_a_handler_that_throws_leaves_it_for_the_retry(): void {
		$body   = $this->body();
		$thrown = $this->receiver()->receive(
			$body,
			Signature::sign( $body, self::SECRET ),
			static function (): void {
				throw new \RuntimeException( 'pat@example.com could not be found' );
			}
		);

		$this->assertSame( 500, $thrown->status );
		$this->assertInstanceOf( \RuntimeException::class, $thrown->error );
		$this->assertSame( 'failed', $this->row( 'evt_1' )['status'] );
		$this->assertSame( 'RuntimeException', $this->row( 'evt_1' )['last_error'], 'The message, with its address, is not kept.' );

		$retried = $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null );

		$this->assertTrue( $retried->handled() );
		$this->assertSame( 'done', $this->row( 'evt_1' )['status'] );
	}

	public function test_a_handler_returning_false_has_failed(): void {
		$body   = $this->body();
		$result = $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), static fn(): bool => false );

		$this->assertSame( 500, $result->status );
		$this->assertNull( $result->error );
		$this->assertSame( 'failed', $this->row( 'evt_1' )['status'] );
	}

	/**
	 * Another delivery handling it right now: try later, not "done".
	 */
	public function test_an_event_being_handled_is_busy(): void {
		$body = $this->body();

		( new Inbox( 'wp_events' ) )->claim( 'evt_1' );

		$this->assertSame( 409, $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null )->status );

		// Abandoned by a request that died: taken over after five minutes.
		Clock::advance( Inbox::STALE_AFTER + 1 );

		$this->assertSame( 200, $this->receiver()->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null )->status );
	}

	public function test_without_an_inbox_every_verified_delivery_is_handled(): void {
		$body     = '{"type":"no.id"}';
		$count    = 0;
		$receiver = new Receiver( self::SECRET );

		for ( $i = 0; $i < 2; $i++ ) {
			$result = $receiver->receive(
				$body,
				Signature::sign( $body, self::SECRET ),
				static function () use ( &$count ): void {
					++$count;
				}
			);

			$this->assertSame( 200, $result->status );
		}

		$this->assertSame( 2, $count );
		$this->assertSame( 500, $receiver->receive( $body, Signature::sign( $body, self::SECRET ), static fn(): bool => false )->status );
	}

	/**
	 * The secret can be read per delivery, and several accepted at once.
	 */
	public function test_the_secret_can_be_read_late_and_rotated(): void {
		$body     = $this->body( 'evt_2' );
		$receiver = $this->receiver( static fn(): array => [ 'test-new-key', self::SECRET ] );

		$this->assertSame( 200, $receiver->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null )->status );
		$this->assertSame( 400, $this->receiver( static fn(): string => '' )->receive( $body, Signature::sign( $body, self::SECRET ), static fn() => null )->status );
	}

	/**
	 * A secret that happens to be a function's name is a secret, not a call.
	 */
	public function test_a_string_secret_is_never_called(): void {
		$body = $this->body( 'evt_3' );

		$this->assertSame( 200, $this->receiver( 'strtoupper' )->receive( $body, Signature::sign( $body, 'strtoupper' ), static fn() => null )->status );
	}

	public function test_a_rest_request_is_answered_with_a_status_only(): void {
		$body    = $this->body();
		$request = new \WP_REST_Request( $body, [ 'shop-signature' => Signature::sign( $body, self::SECRET ) ] );

		$ok = $this->receiver()->rest( $request, static fn() => null );

		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( [ 'received' => true ], $ok->get_data() );

		$refused = $this->receiver()->rest( new \WP_REST_Request( $body, [ 'Shop-Signature' => 't=1,v1=' . str_repeat( 'a', 64 ) ] ), static fn() => null );

		$this->assertSame( 400, $refused->get_status() );
		$this->assertSame( [ 'received' => false ], $refused->get_data() );
	}
}
