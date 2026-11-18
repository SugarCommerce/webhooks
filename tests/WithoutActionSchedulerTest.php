<?php
/**
 * Tests for a site where Action Scheduler could not load.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Webhooks\Dispatcher;
use SugarCommerce\Webhooks\Queued;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * No scheduler: sent once, now, and not again.
 *
 * Run on their own, with WITHOUT_ACTION_SCHEDULER set -- `composer test`
 * does both runs.
 */
#[Group( 'without-action-scheduler' )]
final class WithoutActionSchedulerTest extends TestCase {

	/**
	 * Empty everything; skip in the run that has a scheduler.
	 */
	protected function setUp(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Runs without Action Scheduler: WITHOUT_ACTION_SCHEDULER=1.' );
		}

		wh_reset();

		update_option(
			'shop_webhook_endpoints',
			array(
				array(
					'id'     => 'we_all',
					'url'    => 'https://hooks.example/all',
					'secret' => 'whsec_test',
					'events' => array(),
					'active' => 1,
				),
			)
		);
	}

	/**
	 * A dispatcher.
	 *
	 * @return Dispatcher
	 */
	private function webhooks(): Dispatcher {
		return new Dispatcher(
			array(
				'prefix'   => 'shop',
				'events'   => array( 'order.completed' => 'Order completed' ),
				'resolver' => static fn(): array => array( '93.184.216.34' ),
			)
		);
	}

	/**
	 * Dispatching sends there and then.
	 */
	public function test_an_event_is_sent_now(): void {
		$this->assertSame( 1, $this->webhooks()->dispatch( 'order.completed', array( 'id' => 1 ) ) );
		$this->assertCount( 1, $GLOBALS['wh_requests'] );
	}

	/**
	 * A failure is not tried again in the same request.
	 */
	public function test_a_failure_is_not_retried(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 503 );

		$this->webhooks()->dispatch( 'order.completed', array( 'id' => 1 ) );

		$this->assertCount( 1, $GLOBALS['wh_requests'] );
	}

	/**
	 * Nothing queued, nothing to rewrite.
	 */
	public function test_there_is_nothing_to_rewrite(): void {
		$this->assertSame( 0, ( new Queued( 'shop_send_webhook', 'shop' ) )->rewrite( 'ann@example.com', static fn( array $event ): array => $event ) );
	}
}
