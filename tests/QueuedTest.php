<?php
/**
 * Queued delivery rewriting tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Webhooks\Queued;
use PHPUnit\Framework\TestCase;

/**
 * Erasing somebody from the bodies Action Scheduler is holding.
 */
final class QueuedTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Needs Action Scheduler.' );
		}
	}

	/**
	 * Put a delivery in the queue.
	 *
	 * @param string $email  Who it is about.
	 * @param string $status Its status.
	 * @param string $group  Its group.
	 *
	 * @return void
	 */
	private function queue( string $email, string $status = 'pending', string $group = 'shop' ): void {
		as_schedule_single_action(
			time() + 600,
			'shop_send_webhook',
			array(
				'we_a',
				'order.completed',
				json_encode(
					array(
						'id'   => 'evt_1',
						'type' => 'order.completed',
						'data' => array( 'email' => $email ),
					)
				),
				2,
			),
			$group
		);

		$GLOBALS['as_actions'][ count( $GLOBALS['as_actions'] ) - 1 ]['status'] = $status;
	}

	/**
	 * Blank an email, when the event is about it.
	 *
	 * @param string $email The address.
	 *
	 * @return callable
	 */
	private function blank( string $email ): callable {
		return static function ( array $event ) use ( $email ): ?array {
			if ( ( $event['data']['email'] ?? '' ) !== $email ) {
				return null;
			}

			$event['data']['email'] = '';

			return $event;
		};
	}

	/**
	 * A delivery still to go is queued again, for the same moment, without them.
	 */
	public function test_a_pending_delivery_is_rewritten(): void {
		$this->queue( 'ann@example.com' );

		$when = $GLOBALS['as_actions'][0]['timestamp'];

		$this->assertSame( 1, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', $this->blank( 'ann@example.com' ) ) );

		$queued = wh_queued();

		$this->assertCount( 1, $queued );
		$this->assertSame( '', json_decode( $queued[0]['args'][2], true )['data']['email'] );
		$this->assertSame( array( 'we_a', 'order.completed' ), array_slice( $queued[0]['args'], 0, 2 ) );
		$this->assertSame( 2, $queued[0]['args'][3], 'The attempt it was on.' );
		$this->assertSame( $when, $queued[0]['timestamp'] );
		$this->assertSame( 'shop', $queued[0]['group'] );
	}

	/**
	 * One already sent is deleted, not rewritten.
	 */
	public function test_a_finished_delivery_is_deleted(): void {
		$this->queue( 'ann@example.com', 'complete' );

		( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', $this->blank( 'ann@example.com' ) );

		$this->assertSame( array(), wh_queued() );
	}

	/**
	 * The callback is the judge: a mention is not enough.
	 */
	public function test_an_event_about_somebody_else_is_left(): void {
		$this->queue( 'ann@example.com.au' );

		$this->assertSame( 0, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', $this->blank( 'ann@example.com' ) ) );
		$this->assertStringContainsString( 'ann@example.com.au', wh_queued()[0]['args'][2] );
	}

	/**
	 * Another plugin's deliveries are its own.
	 */
	public function test_another_group_is_left_alone(): void {
		$this->queue( 'ann@example.com', 'pending', 'other' );

		$this->assertSame( 0, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', $this->blank( 'ann@example.com' ) ) );
	}

	/**
	 * Nothing to look for, nothing done.
	 */
	public function test_an_empty_needle_does_nothing(): void {
		$this->queue( 'ann@example.com' );

		$this->assertSame( 0, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( '', static fn( array $event ): array => $event ) );
	}

	/**
	 * More than a page of them.
	 */
	public function test_every_page_is_reached(): void {
		for ( $i = 0; $i < 230; $i++ ) {
			$this->queue( 0 === $i % 2 ? 'ann@example.com' : 'ann@example.com.au' );
		}

		$this->assertSame( 115, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', $this->blank( 'ann@example.com' ) ) );
	}
}
