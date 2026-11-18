<?php
/**
 * Switch-off tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Webhooks\Breaker;
use SugarCommerce\Webhooks\EndpointHealth;
use SugarCommerce\Webhooks\Endpoints;
use PHPUnit\Framework\TestCase;

/**
 * When an endpoint has failed often enough, for long enough, to stop.
 */
final class BreakerTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/**
	 * A record kept in an option.
	 *
	 * @param int $limit  Failures in a row.
	 * @param int $period Seconds failing.
	 *
	 * @return EndpointHealth
	 */
	private function health( int $limit = 3, int $period = 100 ): EndpointHealth {
		return EndpointHealth::option( 'shop_webhook_health', $limit, $period );
	}

	/**
	 * Both conditions: enough failures and long enough.
	 */
	public function test_it_takes_both_count_and_time(): void {
		$health = $this->health();

		$this->assertNull( $health->failed( 'we_a', 500, '', 1000 ) );
		$this->assertNull( $health->failed( 'we_a', 500, '', 1010 ) );
		$this->assertNull( $health->failed( 'we_a', 500, '', 1020 ), 'Three, but in twenty seconds.' );
		$this->assertNull( $health->failed( 'we_a', 500, '', 1099 ), 'Four, but in 99 seconds.' );

		$record = $health->failed( 'we_a', 503, 'nope', 1100 );

		$this->assertSame(
			array(
				'failures' => 5,
				'since'    => 1000,
				'last'     => 1100,
				'status'   => 503,
				'error'    => 'nope',
				'off_at'   => 1100,
			),
			$record
		);
	}

	/**
	 * Long enough, but not enough failures.
	 */
	public function test_time_alone_is_not_enough(): void {
		$health = $this->health();

		$health->failed( 'we_a', 500, '', 1000 );

		$this->assertNull( $health->failed( 'we_a', 500, '', 99999 ) );
	}

	/**
	 * It is switched off once: the next failure is not news.
	 */
	public function test_it_is_switched_off_once(): void {
		$health = $this->health( 1, 0 );

		$this->assertNotNull( $health->failed( 'we_a', 500, '', 1000 ) );
		$this->assertNull( $health->failed( 'we_a', 500, '', 1001 ) );
		$this->assertSame( array( 'we_a' ), array_keys( $health->switched_off() ) );
	}

	/**
	 * A limit of nought never switches one off.
	 */
	public function test_a_limit_of_nought_never_switches_off(): void {
		$health = $this->health( 0, 0 );

		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertNull( $health->failed( 'we_a', 500, '', 1000 + $i ) );
		}
	}

	/**
	 * Endpoints are counted apart, and forgetting one leaves the rest.
	 */
	public function test_endpoints_are_counted_apart(): void {
		$health = $this->health();

		$health->failed( 'we_a', 500, '', 1000 );
		$health->failed( 'we_b', 500, '', 1000 );
		$health->forget( 'we_a' );

		$this->assertNull( $health->get( 'we_a' ) );
		$this->assertSame( 1, $health->get( 'we_b' )['failures'] );
	}

	/**
	 * Forgetting nothing writes nothing.
	 */
	public function test_forgetting_a_healthy_endpoint_writes_nothing(): void {
		$this->health()->forget( 'we_a' );

		$this->assertArrayNotHasKey( 'shop_webhook_health', $GLOBALS['wh_options'] );
	}

	/**
	 * Saving: an endpoint switched back on, or removed, is forgotten; others are not.
	 */
	public function test_saving_forgets_what_was_turned_on_or_removed(): void {
		$breaker = new Breaker( Endpoints::option( 'shop_webhook_endpoints' ), 'shop', 'shop_webhook_health' );
		$health  = $breaker->health();

		foreach ( array( 'we_on', 'we_gone', 'we_still_off', 'we_failing' ) as $id ) {
			$health->failed( $id, 500, '', 1000 );
		}

		$breaker->saved(
			array(
				array( 'id' => 'we_on', 'active' => 0 ),
				array( 'id' => 'we_gone', 'active' => 0 ),
				array( 'id' => 'we_still_off', 'active' => 0 ),
				array( 'id' => 'we_failing', 'active' => 1 ),
			),
			array(
				array( 'id' => 'we_on', 'active' => 1 ),
				array( 'id' => 'we_still_off', 'active' => 0 ),
				array( 'id' => 'we_failing', 'active' => 1 ),
			)
		);

		$this->assertSame( array( 'we_still_off', 'we_failing' ), array_keys( get_option( 'shop_webhook_health' ) ) );
	}

	/**
	 * Only the ones still off are reported off.
	 */
	public function test_switched_off_lists_only_those_still_off(): void {
		update_option(
			'shop_webhook_endpoints',
			array(
				array( 'id' => 'we_off', 'url' => 'https://a.example/', 'active' => 0 ),
				array( 'id' => 'we_on_again', 'url' => 'https://b.example/', 'active' => 1 ),
			)
		);

		$breaker = new Breaker( Endpoints::option( 'shop_webhook_endpoints' ), 'shop', 'shop_webhook_health', 1, 0 );

		$breaker->health()->failed( 'we_off', 500, '', 1000 );
		$breaker->health()->failed( 'we_on_again', 500, '', 1000 );

		$this->assertSame( array( 'we_off' ), array_column( $breaker->switched_off(), 'id' ) );
		$this->assertSame( 'https://a.example/', $breaker->switched_off()[0]['url'] );
	}

	/**
	 * Fifteen in a row, over a day, by default; both filterable.
	 */
	public function test_the_defaults_and_their_filters(): void {
		$this->assertSame( 15, Breaker::LIMIT );
		$this->assertSame( 86400, Breaker::PERIOD );

		add_filter( 'shop_webhook_failure_limit', static fn(): int => 2 );
		add_filter( 'shop_webhook_failure_period', static fn(): int => 0 );

		$health = ( new Breaker( Endpoints::option( 'shop_webhook_endpoints' ), 'shop', 'shop_webhook_health' ) )->health();

		$this->assertNull( $health->failed( 'we_a', 500, '', 1000 ) );
		$this->assertNotNull( $health->failed( 'we_a', 500, '', 1000 ) );
	}
}
