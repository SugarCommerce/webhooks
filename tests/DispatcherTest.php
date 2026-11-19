<?php
/**
 * Dispatcher tests.
 *
 * @package SugarCommerce\Webhooks
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks\Tests;

use SugarCommerce\Webhooks\Dispatcher;
use SugarCommerce\Webhooks\Signature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sending events, retrying the ones worth retrying, and stopping for
 * endpoints that have gone.
 *
 * The names a receiver sees -- headers, the body's shape, the event id --
 * and the names an add-on hooks are a contract, so they are asserted as
 * literal strings rather than built the way the code builds them.
 */
final class DispatcherTest extends TestCase {

	/**
	 * Empty everything.
	 */
	protected function setUp(): void {
		wh_reset();
	}

	/**
	 * A dispatcher with two endpoints, as a plugin would configure one.
	 *
	 * @param array<string, mixed> $config Anything to change.
	 *
	 * @return Dispatcher
	 */
	private function webhooks( array $config = array() ): Dispatcher {
		$GLOBALS['wh_options']['shop_webhook_endpoints'] ??= array(
			array(
				'id'     => 'we_all',
				'url'    => 'https://hooks.example/all',
				'secret' => 'whsec_test',
				'events' => array(),
				'active' => 1,
			),
			array(
				'id'     => 'we_refunds',
				'url'    => 'https://hooks.example/refunds',
				'secret' => '',
				'events' => array( 'order.refunded' ),
				'active' => 1,
			),
		);

		$webhooks = new Dispatcher(
			$config + array(
				'prefix'     => 'shop',
				'header'     => 'Shop',
				'user_agent' => 'Shop/1.2.3',
				'events'     => static fn(): array => array(
					'order.completed' => 'Order completed',
					'order.refunded'  => 'Order refunded',
				),
				'resolver'   => static fn(): array => array( '93.184.216.34' ),
			)
		);

		$webhooks->init();

		return $webhooks;
	}

	/**
	 * The one delivery queued for an endpoint.
	 *
	 * @param string $endpoint The endpoint id.
	 *
	 * @return array<string, mixed>
	 */
	private function delivery( string $endpoint = 'we_all' ): array {
		foreach ( wh_queued() as $action ) {
			if ( $action['args'][0] === $endpoint ) {
				return $action;
			}
		}

		$this->fail( 'Nothing was queued for ' . $endpoint );
	}

	/**
	 * Run what was queued, once, in order.
	 *
	 * @param Dispatcher $webhooks The dispatcher.
	 *
	 * @return void
	 */
	private function run_queue( Dispatcher $webhooks ): void {
		$actions               = wh_queued();
		$GLOBALS['as_actions'] = array();

		foreach ( $actions as $action ) {
			$webhooks->send( ...$action['args'] );
		}
	}

	/**
	 * An event is queued for every endpoint that wants it, and only those.
	 */
	public function test_an_event_goes_to_the_endpoints_that_want_it(): void {
		$webhooks = $this->webhooks();

		$this->assertSame( 1, $webhooks->dispatch( 'order.completed', array( 'id' => 1001 ) ) );
		$this->assertSame( 2, $webhooks->dispatch( 'order.refunded', array( 'id' => 1001 ) ) );
		$this->assertCount( 3, wh_queued() );
	}

	/**
	 * An event nobody catalogued is not sent.
	 */
	public function test_an_unknown_event_is_not_sent(): void {
		$this->assertSame( 0, $this->webhooks()->dispatch( 'order.exploded', array() ) );
		$this->assertSame( array(), wh_queued() );
	}

	/**
	 * The queued action: its hook, its group and its arguments.
	 */
	public function test_the_delivery_is_queued_under_the_prefix(): void {
		$this->webhooks()->dispatch( 'order.completed', array( 'id' => 1001 ) );

		$action = $this->delivery();

		$this->assertSame( 'shop_send_webhook', $action['hook'] );
		$this->assertSame( 'shop', $action['group'] );
		$this->assertSame( 'we_all', $action['args'][0] );
		$this->assertSame( 'order.completed', $action['args'][1] );
		$this->assertSame( 1, $action['args'][3] );
		$this->assertEqualsWithDelta( time(), $action['timestamp'], 2 );
	}

	/**
	 * The body's shape, which receivers parse.
	 */
	public function test_the_body_has_the_documented_shape(): void {
		$this->webhooks( array( 'test' => true ) )->dispatch( 'order.completed', array( 'id' => 1001 ) );

		$body = json_decode( $this->delivery()['args'][2], true );

		$this->assertSame( array( 'id', 'type', 'created', 'test', 'data' ), array_keys( $body ) );
		$this->assertMatchesRegularExpression( '/^evt_[a-f0-9]{32}$/', $body['id'] );
		$this->assertSame( 'order.completed', $body['type'] );
		$this->assertEqualsWithDelta( time(), $body['created'], 2 );
		$this->assertTrue( $body['test'] );
		$this->assertSame( array( 'id' => 1001 ), $body['data'] );
	}

	/**
	 * One event id across every endpoint, so a receiver can tell a repeat.
	 */
	public function test_one_event_has_one_id_everywhere(): void {
		$this->webhooks()->dispatch( 'order.refunded', array( 'id' => 1001 ) );

		$this->assertSame( $this->delivery( 'we_all' )['args'][2], $this->delivery( 'we_refunds' )['args'][2] );
	}

	/**
	 * The data filter, named from the prefix.
	 */
	public function test_the_data_can_be_filtered(): void {
		add_filter( 'shop_webhook_data', static fn( array $data, string $type ): array => $data + array( 'seen' => $type ), 10, 2 );

		$this->webhooks()->dispatch( 'order.completed', array( 'id' => 1001 ) );

		$this->assertSame( 'order.completed', json_decode( $this->delivery()['args'][2], true )['data']['seen'] );
	}

	/**
	 * An add-on's own kind of event, added through the filter, is sent.
	 */
	public function test_an_add_on_can_add_an_event(): void {
		add_filter( 'shop_webhook_events', static fn( array $events ): array => $events + array( 'catalogue.changed' => 'Products changed' ) );

		$this->assertSame( 1, $this->webhooks()->dispatch( 'catalogue.changed', array() ) );
	}

	/**
	 * The request: where, what, signed, and nothing followed.
	 */
	public function test_a_delivery_is_posted_signed(): void {
		$webhooks = $this->webhooks();

		$webhooks->dispatch( 'order.completed', array( 'id' => 1001 ) );
		$body = $this->delivery()['args'][2];

		$this->assertTrue( $webhooks->send( 'we_all', 'order.completed', $body, 1 ) );

		$request = $GLOBALS['wh_requests'][0];
		$headers = $request['args']['headers'];

		$this->assertSame( 'https://hooks.example/all', $request['url'] );
		$this->assertSame( $body, $request['args']['body'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertSame( 10, $request['args']['timeout'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( 'Shop/1.2.3; https://shop.example/', $headers['User-Agent'] );
		$this->assertSame( 'order.completed', $headers['Shop-Event'] );
		$this->assertTrue( Signature::verify( $body, $headers['Shop-Signature'], 'whsec_test' )->valid );
	}

	/**
	 * An endpoint without a secret is sent unsigned, not refused.
	 */
	public function test_an_endpoint_without_a_secret_is_unsigned(): void {
		$this->webhooks()->send( 'we_refunds', 'order.refunded', '{}', 1 );

		$this->assertArrayNotHasKey( 'Shop-Signature', $GLOBALS['wh_requests'][0]['args']['headers'] );
	}

	/**
	 * Without a header name, the generic one.
	 */
	public function test_the_header_defaults_to_webhook(): void {
		$headers = $this->webhooks( array( 'header' => 'Webhook' ) )->headers( 'x', '{}', 'whsec_test' );

		$this->assertArrayHasKey( 'Webhook-Signature', $headers );
		$this->assertArrayHasKey( 'Webhook-Event', $headers );
	}

	/**
	 * Removed or switched off since it was queued: nothing sent, nothing retried.
	 */
	public function test_a_gone_endpoint_is_not_sent_to(): void {
		$webhooks = $this->webhooks();

		$this->assertFalse( $webhooks->send( 'we_nobody', 'order.completed', '{}', 1 ) );
		$this->assertSame( array(), $GLOBALS['wh_requests'] );
		$this->assertSame( array(), wh_queued() );
	}

	/**
	 * Statuses, and whether each is tried again.
	 *
	 * @param int  $status  The answer.
	 * @param bool $retried Whether another attempt is queued.
	 */
	#[DataProvider( 'answers' )]
	public function test_which_answers_are_retried( int $status, bool $retried ): void {
		$GLOBALS['wh_answers'][] = wh_answer( $status );

		$this->assertFalse( $this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 ) );
		$this->assertCount( $retried ? 1 : 0, wh_queued() );
	}

	/**
	 * Answers and whether each is worth another go.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function answers(): array {
		return array(
			'server error'   => array( 500, true ),
			'bad gateway'    => array( 502, true ),
			'rate limited'   => array( 429, true ),
			'timeout'        => array( 408, true ),
			'bad request'    => array( 400, false ),
			'not found'      => array( 404, false ),
			'gone'           => array( 410, false ),
			'a redirect'     => array( 301, false ),
			'unprocessable'  => array( 422, false ),
		);
	}

	/**
	 * Nothing answered at all: retried.
	 */
	public function test_no_answer_is_retried(): void {
		$GLOBALS['wh_answers'][] = new \WP_Error( 'http_request_failed', 'Connection refused' );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertCount( 1, wh_queued() );
	}

	/**
	 * A retry is the same bytes, one attempt on, after the stated wait.
	 */
	public function test_a_retry_is_the_same_body_later(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 500 );

		$this->webhooks()->send( 'we_all', 'order.completed', '{"same":true}', 2 );

		$action = $this->delivery();

		$this->assertSame( array( 'we_all', 'order.completed', '{"same":true}', 3 ), $action['args'] );
		$this->assertEqualsWithDelta( time() + 300, $action['timestamp'], 30 + 2, 'Five minutes, give or take a tenth.' );
	}

	/**
	 * A minute, five, half an hour, two hours -- and five tries in all --
	 * each give or take a tenth.
	 */
	public function test_the_default_schedule(): void {
		$webhooks = $this->webhooks();

		foreach ( array( 1 => 60, 2 => 300, 3 => 1800, 4 => 7200 ) as $attempt => $wait ) {
			$this->assertEqualsWithDelta( $wait, $webhooks->retry_delay( $attempt ), intdiv( $wait, 10 ) );
		}

		$this->assertTrue( $webhooks->should_retry( 500, 4 ) );
		$this->assertFalse( $webhooks->should_retry( 500, 5 ) );
	}

	/**
	 * After an outage, a thousand queued deliveries do not all come back in
	 * the same second: the SaaS retry policy spread them, and so does this.
	 */
	public function test_deliveries_that_failed_together_come_back_apart(): void {
		$webhooks = $this->webhooks();
		$waits    = array();

		for ( $i = 0; $i < 200; $i++ ) {
			$waits[] = $webhooks->retry_delay( 3 );
		}

		$this->assertGreaterThan( 20, count( array_unique( $waits ) ) );
		$this->assertSame( array( 60, 300 ), array_map( array( $this->webhooks( array( 'jitter' => 0 ) ), 'retry_delay' ), array( 1, 2 ) ), 'Nought waits exactly.' );
	}

	/**
	 * The fifth failure is the last.
	 */
	public function test_the_last_attempt_is_not_retried(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 500 );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 5 );

		$this->assertSame( array(), wh_queued() );
	}

	/**
	 * A Retry-After on a 429 or a 503 is the wait.
	 *
	 * @param int $status The answer.
	 */
	#[DataProvider( 'later' )]
	public function test_retry_after_is_honoured( int $status ): void {
		$GLOBALS['wh_answers'][] = wh_answer( $status, array( 'Retry-After' => '120' ) );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertEqualsWithDelta( time() + 120, $this->delivery()['timestamp'], 2 );
	}

	/**
	 * The two answers that mean "later, and here is when".
	 *
	 * @return array<string, array{0: int}>
	 */
	public static function later(): array {
		return array(
			'too many requests'   => array( 429 ),
			'service unavailable' => array( 503 ),
		);
	}

	/**
	 * As a date, too.
	 */
	public function test_retry_after_as_a_date_is_honoured(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 503, array( 'retry-after' => gmdate( 'D, d M Y H:i:s', time() + 900 ) . ' GMT' ) );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertEqualsWithDelta( time() + 900, $this->delivery()['timestamp'], 3 );
	}

	/**
	 * On any other answer it is not read: a 500 has not said when.
	 */
	public function test_retry_after_on_a_500_is_ignored(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 500, array( 'Retry-After' => '120' ) );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertEqualsWithDelta( time() + 60, $this->delivery()['timestamp'], 6 + 2, 'The schedule, not the 120 asked for.' );
	}

	/**
	 * A receiver cannot park a delivery for ever: a day at most.
	 */
	public function test_retry_after_is_capped_at_a_day(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 429, array( 'Retry-After' => '9999999' ) );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertEqualsWithDelta( time() + 86400, $this->delivery()['timestamp'], 2 );
	}

	/**
	 * Every failure is announced, retried or not.
	 */
	public function test_a_failure_is_announced(): void {
		$GLOBALS['wh_answers'][] = wh_answer( 500 );

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 2 );

		$fired = $GLOBALS['wh_fired']['shop_webhook_failed'][0];

		$this->assertSame( 'we_all', $fired[0]['id'] );
		$this->assertSame( array( 'order.completed', 500, 2 ), array_slice( $fired, 1 ) );
	}

	/**
	 * An address inside the network is not posted to, and counts as no answer.
	 */
	public function test_a_private_address_is_refused(): void {
		$webhooks = $this->webhooks( array( 'resolver' => static fn(): array => array( '10.0.0.5' ) ) );

		$this->assertFalse( $webhooks->send( 'we_all', 'order.completed', '{}', 1 ) );

		$this->assertSame( array(), $GLOBALS['wh_requests'] );
		$this->assertSame( 0, $GLOBALS['wh_fired']['shop_webhook_failed'][0][2] );
	}

	/**
	 * The connection is held to the address that was checked.
	 */
	public function test_the_connection_is_pinned(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'No curl.' );
		}

		$this->webhooks()->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertSame( array( 'hooks.example:443:93.184.216.34' ), $GLOBALS['wh_requests'][0]['pin'] );
	}

	/**
	 * Failing often enough for long enough switches an endpoint off.
	 */
	public function test_a_dead_endpoint_is_switched_off(): void {
		$webhooks = $this->webhooks();

		// Fourteen already, the first a day and a minute ago.
		update_option(
			'shop_webhook_health',
			array(
				'we_all' => array(
					'failures' => 14,
					'since'    => time() - 86460,
					'last'     => time() - 60,
					'status'   => 503,
					'error'    => '',
					'off_at'   => 0,
				),
			)
		);

		$GLOBALS['wh_answers'][] = wh_answer( 503 );

		$webhooks->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertSame( 0, get_option( 'shop_webhook_endpoints' )[0]['active'], 'Its Active toggle is the switch.' );
		$this->assertSame( array(), wh_queued(), 'No retry for an endpoint switched off.' );
		$this->assertArrayHasKey( 'shop_webhook_switched_off', $GLOBALS['wh_fired'] );
		$this->assertNull( $webhooks->endpoints()->find( 'we_all' ) );

		$off = $webhooks->breaker()->switched_off();

		$this->assertCount( 1, $off );
		$this->assertSame( 'https://hooks.example/all', $off[0]['url'] );
		$this->assertSame( 15, $off[0]['failures'] );
	}

	/**
	 * Fifteen in a minute is a blip, not a dead endpoint.
	 */
	public function test_many_failures_quickly_do_not_switch_it_off(): void {
		$webhooks = $this->webhooks();

		for ( $i = 0; $i < 20; $i++ ) {
			$GLOBALS['wh_answers'][] = wh_answer( 503 );
			$webhooks->send( 'we_all', 'order.completed', '{}', 1 );
		}

		$this->assertSame( 1, get_option( 'shop_webhook_endpoints' )[0]['active'] );
		$this->assertSame( 20, get_option( 'shop_webhook_health' )['we_all']['failures'] );
	}

	/**
	 * One answer clears the record.
	 */
	public function test_a_success_forgets_the_failures(): void {
		$webhooks = $this->webhooks();

		$GLOBALS['wh_answers'][] = wh_answer( 503 );
		$webhooks->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertArrayHasKey( 'we_all', get_option( 'shop_webhook_health' ) );

		$webhooks->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertArrayNotHasKey( 'we_all', get_option( 'shop_webhook_health' ) );
	}

	/**
	 * The limit can be filtered, and nought never switches one off.
	 */
	public function test_the_limit_can_be_switched_off(): void {
		add_filter( 'shop_webhook_failure_limit', static fn(): int => 0 );

		$webhooks = $this->webhooks( array( 'failure_period' => 0 ) );

		for ( $i = 0; $i < 30; $i++ ) {
			$GLOBALS['wh_answers'][] = wh_answer( 503 );
			$webhooks->send( 'we_all', 'order.completed', '{}', 1 );
		}

		$this->assertSame( 1, get_option( 'shop_webhook_endpoints' )[0]['active'] );
	}

	/**
	 * A test reaches a switched-off endpoint and, answered, switches it on.
	 */
	public function test_an_answered_test_switches_it_back_on(): void {
		$webhooks = $this->webhooks( array( 'failure_limit' => 1, 'failure_period' => 0 ) );

		$GLOBALS['wh_answers'][] = wh_answer( 503 );
		$webhooks->send( 'we_all', 'order.completed', '{}', 1 );

		$this->assertNotNull( $webhooks->breaker()->off( 'we_all' ) );

		$result = $webhooks->test( 'we_all' );

		$this->assertSame(
			array(
				'found'  => true,
				'ok'     => true,
				'status' => 200,
				'error'  => '',
				'url'    => 'https://hooks.example/all',
			),
			$result
		);
		$this->assertSame( 1, get_option( 'shop_webhook_endpoints' )[0]['active'] );
		$this->assertNull( $webhooks->breaker()->off( 'we_all' ) );
		$this->assertSame( array(), $webhooks->breaker()->switched_off() );
	}

	/**
	 * An endpoint the owner switched off is theirs to switch on: a test it
	 * answers says it is reachable, not that they want it back.
	 */
	public function test_an_answered_test_leaves_one_switched_off_by_hand_off(): void {
		$GLOBALS['wh_options']['shop_webhook_endpoints'] = array(
			array(
				'id'     => 'we_paused',
				'url'    => 'https://hooks.example/paused',
				'secret' => '',
				'events' => array(),
				'active' => 0,
			),
		);

		$this->assertTrue( $this->webhooks()->test( 'we_paused' )['ok'] );
		$this->assertSame( 0, get_option( 'shop_webhook_endpoints' )[0]['active'] );
	}

	/**
	 * A test sends a `ping`, now, and is never queued.
	 */
	public function test_a_test_is_a_ping(): void {
		$this->webhooks( array( 'test_message' => static fn(): string => 'Hello' ) )->test( 'we_all' );

		$body = json_decode( $GLOBALS['wh_requests'][0]['args']['body'], true );

		$this->assertSame( 'ping', $body['type'] );
		$this->assertSame( array( 'message' => 'Hello' ), $body['data'] );
		$this->assertSame( 'ping', $GLOBALS['wh_requests'][0]['args']['headers']['Shop-Event'] );
		$this->assertSame( array(), wh_queued() );
	}

	/**
	 * A failed test changes nothing and is not counted.
	 */
	public function test_a_failed_test_changes_nothing(): void {
		$webhooks = $this->webhooks();

		$GLOBALS['wh_answers'][] = new \WP_Error( 'http_request_failed', 'Could not resolve host' );

		$result = $webhooks->test( 'we_all' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 0, $result['status'] );
		$this->assertSame( 'Could not resolve host', $result['error'] );
		$this->assertFalse( get_option( 'shop_webhook_health', false ) );
	}

	/**
	 * A test for an endpoint nobody has.
	 */
	public function test_a_test_for_nothing_is_not_found(): void {
		$this->assertFalse( $this->webhooks()->test( 'we_nobody' )['found'] );
		$this->assertSame( array(), $GLOBALS['wh_requests'] );
	}

	/**
	 * Saving with an endpoint switched back on forgets its failures.
	 */
	public function test_saving_it_back_on_forgets_the_failures(): void {
		$webhooks = $this->webhooks( array( 'failure_limit' => 1, 'failure_period' => 0 ) );

		$GLOBALS['wh_answers'][] = wh_answer( 503 );
		$webhooks->send( 'we_all', 'order.completed', '{}', 1 );

		$before = get_option( 'shop_webhook_endpoints' );
		$after  = $before;

		$after[0]['active'] = 1;

		do_action( 'update_option_shop_webhook_endpoints', $before, $after );

		$this->assertArrayNotHasKey( 'we_all', get_option( 'shop_webhook_health' ) );
	}

	/**
	 * A queue run, end to end: deliver, fail, retry, succeed.
	 */
	public function test_end_to_end(): void {
		$webhooks = $this->webhooks();

		$webhooks->dispatch( 'order.completed', array( 'id' => 1001 ) );

		$GLOBALS['wh_answers'][] = wh_answer( 502 );
		$this->run_queue( $webhooks );

		$this->assertSame( 2, $this->delivery()['args'][3] );

		$this->run_queue( $webhooks );

		$this->assertSame( array(), wh_queued() );
		$this->assertCount( 2, $GLOBALS['wh_requests'] );
		$this->assertSame( $GLOBALS['wh_requests'][0]['args']['body'], $GLOBALS['wh_requests'][1]['args']['body'] );
	}

	/**
	 * The job is attached, for its four arguments.
	 */
	public function test_the_job_is_attached(): void {
		$webhooks = $this->webhooks();

		$this->assertContains( array( $webhooks, 'send' ), $GLOBALS['wh_hooks']['shop_send_webhook'] );
	}

	/**
	 * A dispatcher must be told what to call its hooks.
	 */
	public function test_a_prefix_is_required(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Dispatcher( array( 'prefix' => '' ) );
	}

	/**
	 * A group of its own, when asked.
	 */
	public function test_the_group_can_differ_from_the_prefix(): void {
		$this->webhooks( array( 'group' => 'other' ) )->dispatch( 'order.completed', array() );

		$this->assertSame( 'other', $this->delivery()['group'] );
	}
}
