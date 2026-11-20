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

use SugarCommerce\Jobs\Retry;
use SugarCommerce\Jobs\Scheduler;
use SugarCommerce\Network\Remote\SafeUrl;
use SugarCommerce\Webhooks\Traits\Dispatcher\Delivery;

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

	use Delivery;

	/**
	 * The waits between attempts, by default: a minute, five minutes, half
	 * an hour, two hours. Five tries.
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	public const array WAITS = [ 60, 300, 1800, 7200 ];

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
	 *                                                  development only; never
	 *                                                  a metadata or link-local
	 *                                                  one.
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
}
