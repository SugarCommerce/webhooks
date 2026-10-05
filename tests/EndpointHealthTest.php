<?php
/**
 * EndpointHealth test suite.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Webhooks\EndpointHealth;

final class EndpointHealthTest extends TestCase {

	private const NOW = 1_800_000_000;

	/* ─── Counting ──────────────────────────────────────────────────── */

	public function test_the_first_failure_starts_the_clock(): void {
		$health = ( new EndpointHealth() )->failed( self::NOW );

		$this->assertSame( 1, $health->failures );
		$this->assertSame( self::NOW, $health->failing_since );
		$this->assertFalse( $health->disabled );
	}

	public function test_later_failures_keep_the_first_time(): void {
		$health = ( new EndpointHealth( 3, self::NOW - 600 ) )->failed( self::NOW );

		$this->assertSame( 4, $health->failures );
		$this->assertSame( self::NOW - 600, $health->failing_since );
	}

	/* ─── Switching off ─────────────────────────────────────────────── */

	/**
	 * Twenty queued events meeting a one-minute outage are twenty failures,
	 * and not a reason to switch anything off.
	 */
	public function test_many_failures_in_a_short_time_do_not_switch_it_off(): void {
		$health = ( new EndpointHealth( 50, self::NOW - 60 ) )->failed( self::NOW );

		$this->assertFalse( $health->disabled );
	}

	/**
	 * Nor does a week of rare failures.
	 */
	public function test_a_long_time_with_few_failures_does_not_switch_it_off(): void {
		$health = ( new EndpointHealth( 2, self::NOW - 30 * 86400 ) )->failed( self::NOW );

		$this->assertFalse( $health->disabled );
	}

	public function test_failing_often_and_for_long_switches_it_off(): void {
		$health = ( new EndpointHealth( EndpointHealth::MAX_FAILURES - 1, self::NOW - EndpointHealth::DISABLE_AFTER ) )->failed( self::NOW );

		$this->assertTrue( $health->disabled );
	}

	public function test_the_thresholds_can_be_chosen(): void {
		$health = ( new EndpointHealth( 2, self::NOW - 100 ) )->failed( self::NOW, max_failures: 3, disable_after: 100 );

		$this->assertTrue( $health->disabled );
	}

	/* ─── Recovering ────────────────────────────────────────────────── */

	/**
	 * Consecutive: an endpoint that drops a delivery a month never adds up
	 * to being switched off.
	 */
	public function test_one_success_clears_the_record(): void {
		$health = ( new EndpointHealth( 19, self::NOW - 86400 ) )->succeeded();

		$this->assertSame( 0, $health->failures );
		$this->assertNull( $health->failing_since );
		$this->assertFalse( $health->disabled );
	}
}
