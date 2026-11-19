<?php
/**
 * Sending webhooks.
 *
 * @package   SugarCommerce\Webhooks
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Webhooks;

use SugarCommerce\Dates\Clock;
use SugarCommerce\Jobs\Retry;
use SugarCommerce\Jobs\Scheduler;
use SugarCommerce\Security\Random;

/**
 * Class Dispatcher
 *
 * Telling a site owner's other systems what happened: an event is posted
 * as JSON to every endpoint that asked for that kind -- a mailing list, a
 * Discord bot, a spreadsheet, whatever they run.
 *
 * Little of it is new machinery. The endpoints are a setting. The queue
 * and the record of each attempt are Action Scheduler, through
 * sugarcommerce/jobs, which has a screen under Tools for seeing what ran
 * and what failed; whether a failure is worth another go, and when, is
 * that library's {@see Retry}. The request is WordPress's own HTTP API,
 * behind {@see SafeUrl}. What this adds is the signature, so a receiver can
 * tell the post came from this site, and the switch that stops posting to
 * an endpoint that has gone.
 *
 *   $webhooks = new Dispatcher( [
 *       'prefix'     => 'myplugin',
 *       'header'     => 'MyPlugin',
 *       'user_agent' => 'MyPlugin/' . MYPLUGIN_VERSION,
 *       'events'     => fn() => [ 'order.completed' => __( 'Order completed', 'myplugin' ) ],
 *       'endpoints'  => 'myplugin_webhook_endpoints',
 *   ] );
 *
 *   $webhooks->init();                                       // every request
 *   $webhooks->dispatch( 'order.completed', [ 'id' => 1001 ] );
 *
 * Every name a receiver or an add-on can see is built from `prefix` and
 * `header`, so several plugins can use this side by side:
 *
 *   - the queued action `{prefix}_send_webhook`, in the Action Scheduler
 *     group `group` (the prefix by default);
 *   - filters `{prefix}_webhook_events`, `{prefix}_webhook_data`,
 *     `{prefix}_webhook_failure_limit` and `{prefix}_webhook_failure_period`;
 *   - actions `{prefix}_webhook_failed` and `{prefix}_webhook_switched_off`;
 *   - the option `{prefix}_webhook_endpoints`, unless `endpoints` says
 *     otherwise, and `{prefix}_webhook_health`, the record of failures;
 *   - headers `{header}-Signature` and `{header}-Event`.
 *
 * The body is the same bytes on every endpoint and every retry, so a
 * receiver that is sent it twice can tell:
 *
 *   {"id":"evt_…","type":"order.completed","created":1767312000,"test":false,"data":{…}}
 *
 * @since 1.0.0
 */
final class Dispatcher {

	/**
	 * The waits between attempts, by default: a minute, five minutes, half
	 * an hour, two hours. Five tries.
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	public const WAITS = [ 60, 300, 1800, 7200 ];

	/**
	 * What hooks are named from.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * The Action Scheduler group.
	 *
	 * @var Scheduler
	 */
	private Scheduler $queue;

	/**
	 * The catalogue.
	 *
	 * @var Events
	 */
	private Events $events;

	/**
	 * Where to send.
	 *
	 * @var Endpoints
	 */
	private Endpoints $endpoints;

	/**
	 * One signed POST, behind the private-network check.
	 *
	 * @var Sender
	 */
	private Sender $sender;

	/**
	 * Whether events are test events: fn(): bool.
	 *
	 * @var callable
	 */
	private $test;

	/**
	 * Before an event id.
	 *
	 * @var string
	 */
	private string $id_prefix;

	/**
	 * Whether, and how long, to wait before trying again.
	 *
	 * @var Retry
	 */
	private Retry $retry;

	/**
	 * Switches off an endpoint that has stopped answering.
	 *
	 * @var Breaker
	 */
	private Breaker $breaker;

	/**
	 * The type a test is sent as.
	 *
	 * @var string
	 */
	private string $test_event;

	/**
	 * What a test says: fn(): string, or the string.
	 *
	 * @var callable|string
	 */
	private $test_message;

	/**
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $config {
	 *     @type string                 $prefix         Required. Hooks are
	 *                                                  named from it.
	 *     @type string                 $group          Action Scheduler group.
	 *                                                  The prefix by default.
	 *     @type callable|array|Events  $events         The catalogue: type =>
	 *                                                  label or label and
	 *                                                  description.
	 *     @type string|array|Endpoints $endpoints      An option name
	 *                                                  (`{prefix}_webhook_endpoints`
	 *                                                  by default); or
	 *                                                  `read`/`write`
	 *                                                  callables; or an
	 *                                                  Endpoints.
	 *     @type string                 $endpoint_prefix Before a minted
	 *                                                  endpoint id. `we_`.
	 *     @type string                 $secret_prefix  Before a minted secret.
	 *                                                  `whsec_`.
	 *     @type string                 $header         `Webhook` by default.
	 *     @type string                 $user_agent     Product/version. The
	 *                                                  site's address is added.
	 *     @type callable|bool          $test           Whether events are test
	 *                                                  events.
	 *     @type string                 $id_prefix      Before an event id.
	 *                                                  `evt_` by default.
	 *     @type Retry|int[]|object     $retry          A Retry; the waits
	 *                                                  after each failure; or
	 *                                                  a policy such as a
	 *                                                  Backoff. A minute, five,
	 *                                                  half an hour and two
	 *                                                  hours by default.
	 *     @type int                    $attempts       Total tries, with a
	 *                                                  list of waits. One more
	 *                                                  than there are waits.
	 *     @type int                    $jitter         How far each listed
	 *                                                  wait is spread, as a
	 *                                                  percentage either way:
	 *                                                  a tenth by default, so
	 *                                                  deliveries that failed
	 *                                                  together do not all
	 *                                                  come back together. 0
	 *                                                  waits exactly.
	 *     @type int                    $timeout        10 seconds by default.
	 *     @type int                    $redirects      0: a 3xx is the answer.
	 *     @type bool                   $allow_private  Post to private and
	 *                                                  loopback addresses. For
	 *                                                  development only.
	 *     @type int                    $failure_limit  Failures in a row before
	 *                                                  an endpoint is switched
	 *                                                  off; 0 never. 15.
	 *     @type int                    $failure_period Seconds it must have
	 *                                                  been failing for, too.
	 *                                                  A day.
	 *     @type string                 $health_option  Where the record of
	 *                                                  failures is kept.
	 *                                                  `{prefix}_webhook_health`.
	 *     @type array                  $health         Or `read`/`write`
	 *                                                  callables for it.
	 *     @type string                 $test_event     The type a test is sent
	 *                                                  as. `ping`.
	 *     @type callable|string        $test_message   What a test says.
	 *     @type callable|null          $resolver       fn( $host ): string[].
	 *                                                  For tests.
	 * }
	 *
	 * @throws \InvalidArgumentException Without a prefix.
	 */
	public function __construct( array $config ) {
		$this->prefix = sanitize_key( (string) ( $config['prefix'] ?? '' ) );

		if ( '' === $this->prefix ) {
			throw new \InvalidArgumentException( 'A webhook dispatcher needs a prefix to name its hooks.' );
		}

		$events       = $config['events'] ?? [];
		$this->events = $events instanceof Events ? $events : new Events( $events, $this->prefix . '_webhook_events' );

		$this->endpoints = $this->make_endpoints( $config );

		$test  = $config['test'] ?? false;
		$retry = $config['retry'] ?? self::WAITS;

		$this->queue        = Scheduler::group( (string) ( $config['group'] ?? $this->prefix ) );
		$this->test         = is_callable( $test ) ? $test : static fn(): bool => (bool) $test;
		$this->id_prefix    = (string) ( $config['id_prefix'] ?? 'evt_' );
		$this->retry        = $retry instanceof Retry ? $retry : new Retry( is_object( $retry ) ? $retry : (array) $retry, isset( $config['attempts'] ) ? (int) $config['attempts'] : null, null, (int) ( $config['jitter'] ?? Retry::JITTER ) );
		$this->test_event   = (string) ( $config['test_event'] ?? 'ping' );
		$this->test_message = $config['test_message'] ?? 'A test webhook. Answer with any 2xx status.';
		$this->sender       = new Sender(
			new SafeUrl( (bool) ( $config['allow_private'] ?? false ), $config['resolver'] ?? null ),
			(string) ( $config['header'] ?? 'Webhook' ),
			(string) ( $config['user_agent'] ?? '' ),
			(int) ( $config['timeout'] ?? 10 ),
			(int) ( $config['redirects'] ?? 0 )
		);
		$this->breaker      = new Breaker(
			$this->endpoints,
			$this->prefix,
			is_array( $config['health'] ?? null ) ? $config['health'] : (string) ( $config['health_option'] ?? $this->prefix . '_webhook_health' ),
			(int) ( $config['failure_limit'] ?? Breaker::LIMIT ),
			(int) ( $config['failure_period'] ?? Breaker::PERIOD )
		);
	}

	/**
	 * Attach the delivery job. Call it on every request.
	 *
	 * Action Scheduler fires the job on a later request than the one that
	 * queued it, and a callback attached only sometimes is a delivery that
	 * runs and sends nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( $this->hook(), [ $this, 'send' ], 10, 4 );

		// Endpoints in an option of their own: forget the failures of one
		// switched back on or removed when it is saved. Kept anywhere else,
		// the owner of that setting calls breaker()->saved().
		$option = $this->endpoints->option_name();

		if ( '' !== $option ) {
			add_action( 'update_option_' . $option, [ $this->breaker, 'saved' ], 10, 2 );
		}
	}

	/**
	 * The queued action that sends one event to one endpoint.
	 *
	 * Its arguments are `[ endpoint id, type, body, attempt ]`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->prefix . '_send_webhook';
	}

	/**
	 * The Action Scheduler group deliveries are queued in.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function group(): string {
		return $this->queue->name();
	}

	/**
	 * The catalogue.
	 *
	 * @since 1.0.0
	 *
	 * @return Events
	 */
	public function events(): Events {
		return $this->events;
	}

	/**
	 * The endpoints.
	 *
	 * @since 1.0.0
	 *
	 * @return Endpoints
	 */
	public function endpoints(): Endpoints {
		return $this->endpoints;
	}

	/**
	 * What switches off an endpoint that has stopped answering.
	 *
	 * @since 1.0.0
	 *
	 * @return Breaker
	 */
	public function breaker(): Breaker {
		return $this->breaker;
	}

	/**
	 * What posts one delivery.
	 *
	 * @since 1.0.0
	 *
	 * @return Sender
	 */
	public function sender(): Sender {
		return $this->sender;
	}

	/**
	 * How failed deliveries are retried.
	 *
	 * @since 1.0.0
	 *
	 * @return Retry
	 */
	public function retry(): Retry {
		return $this->retry;
	}

	/**
	 * The deliveries waiting in the queue, to search and rewrite.
	 *
	 * @since 1.0.0
	 *
	 * @return Queued
	 */
	public function queued(): Queued {
		return new Queued( $this->hook(), $this->group() );
	}

	/**
	 * Queue an event for every endpoint that wants it.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $type The event type, from the catalogue.
	 * @param array<string, mixed> $data What happened.
	 *
	 * @return int How many deliveries were queued.
	 */
	public function dispatch( string $type, array $data ): int {
		if ( ! $this->events->has( $type ) ) {
			return 0;
		}

		$endpoints = $this->endpoints->wanting( $type );

		if ( [] === $endpoints ) {
			return 0;
		}

		/**
		 * Filter what an outgoing webhook says.
		 *
		 * Named `{prefix}_webhook_data`.
		 *
		 * @param array<string, mixed> $data What happened.
		 * @param string               $type The event type.
		 */
		$data = (array) apply_filters( $this->prefix . '_webhook_data', $data, $type );

		// One id for the event, the same on every endpoint and on every
		// retry, so a receiver that gets it twice can tell.
		$body = $this->body( $type, $data );

		foreach ( $endpoints as $endpoint ) {
			$this->queue( $endpoint['id'], $type, $body, 1, 0 );
		}

		return count( $endpoints );
	}

	/**
	 * Send one event to one endpoint. The queued action.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $endpoint_id Which endpoint.
	 * @param mixed $type        The event type.
	 * @param mixed $body        The JSON body.
	 * @param mixed $attempt     Which attempt this is.
	 *
	 * @return bool Whether the endpoint accepted it.
	 */
	public function send( $endpoint_id, $type, $body, $attempt = 1 ): bool {
		$endpoint = $this->endpoints->find( (string) $endpoint_id );

		// Removed or switched off since this was queued. Nothing to do, and
		// nothing to retry.
		if ( null === $endpoint ) {
			return false;
		}

		$body   = (string) $body;
		$result = $this->sender->send( $endpoint['url'], (string) $type, $body, $endpoint['secret'] );

		if ( $result->sent ) {
			$this->breaker->succeeded( $endpoint['id'] );

			return true;
		}

		$attempt = (int) $attempt;

		// Counted towards switching it off first: an endpoint switched off
		// by this failure is not one to queue another attempt for.
		$off = $this->breaker->failed( $endpoint, $result->status, $result->error );

		if ( ! $off && $this->should_retry( $result->status, $attempt ) && Scheduler::available() ) {
			$this->queue( (string) $endpoint_id, (string) $type, $body, $attempt + 1, $this->retry_delay( $attempt, $result->retry_after ) );
		}

		/**
		 * Fires when an outgoing webhook was not accepted.
		 *
		 * Named `{prefix}_webhook_failed`.
		 *
		 * @param array<string, mixed> $endpoint The endpoint.
		 * @param string               $type     The event type.
		 * @param int                  $status   The HTTP status, or 0 when
		 *                                       the request itself failed.
		 * @param int                  $attempt  Which attempt this was.
		 */
		do_action( $this->prefix . '_webhook_failed', $endpoint, (string) $type, $result->status, $attempt );

		return false;
	}

	/**
	 * Send a test event to one endpoint, now, whether it is switched on or
	 * not.
	 *
	 * Not queued and not retried: somebody is waiting to see what it
	 * answers. Answered, an endpoint that was switched off for failing is
	 * switched back on; a test that fails changes nothing and is not
	 * counted as a delivery that failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint_id Which endpoint.
	 *
	 * @return array{found: bool, ok: bool, status: int, error: string, url: string}
	 */
	public function test( string $endpoint_id ): array {
		$endpoint = $this->endpoints->find( $endpoint_id, true );

		if ( null === $endpoint ) {
			return [
				'found'  => false,
				'ok'     => false,
				'status' => 0,
				'error'  => '',
				'url'    => '',
			];
		}

		$message = is_callable( $this->test_message ) ? ( $this->test_message )() : $this->test_message;
		$body    = $this->body( $this->test_event, [ 'message' => (string) $message ] );
		$result  = $this->sender->send( $endpoint['url'], $this->test_event, $body, $endpoint['secret'] );

		if ( $result->sent ) {
			$this->breaker->recovered( $endpoint['id'] );
		}

		return [
			'found'  => true,
			'ok'     => $result->sent,
			'status' => $result->status,
			'error'  => $result->error,
			'url'    => $endpoint['url'],
		];
	}

	/**
	 * The headers one delivery is sent with.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type   The event type.
	 * @param string $body   The exact bytes being sent.
	 * @param string $secret The endpoint's signing secret; unsigned when
	 *                       empty.
	 *
	 * @return array<string, string>
	 */
	public function headers( string $type, string $body, #[\SensitiveParameter] string $secret ): array {
		return $this->sender->headers( $type, $body, $secret );
	}

	/**
	 * The signature header for a body.
	 *
	 * For a request of the plugin's own that should be signed the way its
	 * webhooks are.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $body      The exact bytes being sent.
	 * @param string   $secret    The signing secret.
	 * @param int|null $timestamp When it is being signed. Null for now.
	 *
	 * @return string
	 */
	public function signature( string $body, #[\SensitiveParameter] string $secret, ?int $timestamp = null ): string {
		return Signature::sign( $body, $secret, $timestamp );
	}

	/**
	 * Whether a delivery that was not accepted is worth trying again.
	 *
	 * @since 1.0.0
	 *
	 * @param int $status  The HTTP status, or 0 when the request failed.
	 * @param int $attempt Which attempt that was, counting from one.
	 *
	 * @return bool
	 */
	public function should_retry( int $status, int $attempt ): bool {
		return $this->retry->again( $status, $attempt );
	}

	/**
	 * Seconds to wait after a failed attempt before the next.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $attempt     Which attempt just failed, from one.
	 * @param int|null $retry_after What the receiver asked for, if it did.
	 *
	 * @return int
	 */
	public function retry_delay( int $attempt, ?int $retry_after = null ): int {
		return $this->retry->delay( $attempt, $retry_after );
	}

	/**
	 * The endpoints, from whichever form the configuration gave.
	 *
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return Endpoints
	 */
	private function make_endpoints( array $config ): Endpoints {
		$endpoints = $config['endpoints'] ?? $this->prefix . '_webhook_endpoints';
		$id        = (string) ( $config['endpoint_prefix'] ?? 'we_' );
		$secret    = (string) ( $config['secret_prefix'] ?? 'whsec_' );

		if ( $endpoints instanceof Endpoints ) {
			return $endpoints;
		}

		if ( is_array( $endpoints ) && isset( $endpoints['read'] ) && is_callable( $endpoints['read'] ) ) {
			return new Endpoints( $endpoints['read'], $endpoints['write'] ?? null, $this->events, $id, $secret );
		}

		return Endpoints::option( (string) $endpoints, $this->events, $id, $secret );
	}

	/**
	 * The JSON body of one event.
	 *
	 * @param string               $type The event type.
	 * @param array<string, mixed> $data What happened.
	 *
	 * @return string
	 */
	private function body( string $type, array $data ): string {
		return (string) wp_json_encode(
			[
				'id'      => $this->id_prefix . Random::hex( 16 ),
				'type'    => $type,
				'created' => Clock::time(),
				'test'    => (bool) ( $this->test )(),
				'data'    => $data,
			]
		);
	}

	/**
	 * Put one delivery in the queue.
	 *
	 * @param string $endpoint_id Which endpoint.
	 * @param string $type        The event type.
	 * @param string $body        The JSON body.
	 * @param int    $attempt     Which attempt this will be.
	 * @param int    $delay       Seconds to wait first.
	 *
	 * @return void
	 */
	private function queue( string $endpoint_id, string $type, string $body, int $attempt, int $delay ): void {
		$args = [ $endpoint_id, $type, $body, $attempt ];

		// No scheduler: once, now, and not again. Better than never, and it
		// must not hold the request that caused it up while it retries.
		if ( ! $this->queue->later( $this->hook(), $args, $delay ) ) {
			$this->send( ...$args );
		}
	}
}
