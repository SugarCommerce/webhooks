# Webhooks

Send webhooks from a plugin that receivers can verify, retry the ones worth
retrying, stop posting to endpoints that have gone -- and receive them the
same way, each event handled once.

## What it does

A plugin that tells other systems what happened needs the same parts every
time: a list of endpoints on a settings screen, a catalogue of events to tick,
a queue so a slow receiver never holds up a checkout, retries that know a 503
from a 422, a signature, and a check that the address the site owner typed in
is not `169.254.169.254`. A plugin that is told needs the other half: the
signature checked over the raw body, in constant time, inside a window, and
the same event never handled twice when the sender retries.

The signature is Stripe's scheme, so receivers can verify it with code they
already have. It signs `timestamp.payload`, sends both in the header, and
rejects anything outside a tolerance window on the way back in.

The queue, the retry rule and the once-only inbox are `sugarcommerce/jobs`;
"is this address public" is `sugarcommerce/network`; the HMAC is
`sugarcommerce/security`'s keyed `Hash`; the time is `sugarcommerce/dates`'
`Clock`, so a test can stop it.

## Features

* Post an event to every endpoint that ticked it, queued and signed
* Let add-ons add their own events to the catalogue, with labels and descriptions
* Retry outages and rate limits on a stated schedule, honouring `Retry-After`
* Switch off an endpoint that has failed for a day, and back on when a test answers
* Refuse addresses inside the server's network: private, loopback, link-local, IPv6 included
* Erase a person from deliveries still waiting in the queue
* Verify a received webhook in constant time, and handle each event once
* Rotate a secret without dropping deliveries, at either end

## Installation

```bash
composer require sugarcommerce/webhooks
```

## Quick start

```php
use SugarCommerce\Webhooks\Dispatcher;

$webhooks = new Dispatcher( [
	'prefix'     => 'myshop',
	'header'     => 'MyShop',                     // MyShop-Signature, MyShop-Event
	'user_agent' => 'MyShop/' . MYSHOP_VERSION,
	'events'     => fn() => [
		'order.completed' => __( 'Order completed', 'myshop' ),
		'order.refunded'  => [
			'label'       => __( 'Order refunded', 'myshop' ),
			'description' => __( 'Sent for a full or part refund.', 'myshop' ),
		],
	],
	'endpoints'  => 'myshop_webhook_endpoints',  // an option of its own
] );

$webhooks->init();   // every request: attaches the delivery job

add_action( 'myshop_order_completed', function ( $order ) use ( $webhooks ) {
	$webhooks->dispatch( 'order.completed', [ 'id' => $order->id, 'total' => $order->total ] );
} );
```

Each endpoint that wants the event gets one queued delivery of the same body:

```json
{"id":"evt_3f2a…","type":"order.completed","created":1767312000,"test":false,"data":{"id":1001,"total":4900}}
```

## Names a plugin owns

Everything an add-on or a receiver can see is built from `prefix` and
`header`, so several plugins use this side by side. Each can be set:

| What | Name | Set with |
|---|---|---|
| Queued action, args `[ endpoint id, type, body, attempt ]` | `{prefix}_send_webhook` in Action Scheduler group `{prefix}` | `prefix`, `group` |
| Filters | `{prefix}_webhook_events`, `{prefix}_webhook_data`, `{prefix}_webhook_failure_limit`, `{prefix}_webhook_failure_period` | `prefix` |
| Actions | `{prefix}_webhook_failed`, `{prefix}_webhook_switched_off` | `prefix` |
| Option, the endpoints | `{prefix}_webhook_endpoints` | `endpoints` (a name, or `read`/`write`) |
| Option, the record of failures | `{prefix}_webhook_health` | `health_option`, or `health` (`read`/`write`) |
| Headers | `{header}-Signature`, `{header}-Event` | `header` (`Webhook`) |
| Minted ids and secrets | `evt_…`, `we_…`, `whsec_…` | `id_prefix`, `endpoint_prefix`, `secret_prefix` |
| A test delivery | type `ping` | `test_event`, `test_message` |

## Endpoints

Use `endpoints()->sanitize()` as the setting's sanitize callback. It keeps
http(s) addresses with a host, mints an id (`we_…`) once and keeps it -- a
queued delivery remembers its endpoint by id -- and mints a `whsec_…` secret
when the field is left empty. Ticking no events means all of them.

Endpoints kept inside another settings array take `read`/`write` callables:

```php
'endpoints' => [
	'read'  => fn() => myshop_get_option( 'webhook_endpoints', [] ),
	'write' => fn( array $rows ) => myshop_update_option( 'webhook_endpoints', $rows ),
],
```

`active()`, `find( $id )` and `wanting( $type )` read them; `set_active()` and
`update()` change one.

## Retries

A minute, five minutes, half an hour, two hours: five tries, each wait give or
take a tenth, so deliveries that failed in one outage do not all come back in
the same second and knock the receiver over again (`'jitter' => 0` waits
exactly). Whether a failure is worth another go is `SugarCommerce\Jobs\Retry`'s rule: no
answer, a 408, a 429 or a 5xx is tried again; any other answer -- a 410 most
of all -- is a refusal and is final. A 429 or 503 with `Retry-After` (seconds
or an HTTP-date) waits that long instead, exactly, up to a day. Without Action
Scheduler an event is sent once, there and then.

```php
'retry'    => [ 60, 600, 3600 ],                        // waits after each failure
'attempts' => 8,                                        // the last wait repeats
'retry'    => new Backoff(),                            // doubling from a minute, 25% jitter, six tries
'jitter'   => 25,                                       // spread a listed wait by a quarter
'retry'    => Backoff::fixed( [ 60, 300, 1800 ] ),      // a tenth either way, as a list is
'retry'    => new Retry( [ 60, 300 ], retry_if: fn( int $status ) => 0 === $status || $status >= 500 ),
```

`Backoff` also stands alone for a sweep that keeps its own count:
`exhausted( $tries )`, `next_attempt_at( $tries, $now )`, `schedule()`.

## A dead endpoint is switched off

Fifteen failures in a row that span at least a day turn the endpoint's own
Active toggle off and fire `{prefix}_webhook_switched_off`. Both conditions,
because twenty failures can be one minute of a restart. A success resets the
count. `breaker()->switched_off()` lists them for a notice or Site Health;
`breaker()->off( $id )` says why one is.

It comes back when someone turns Active on and saves, or sends it a test:

```php
$result = $webhooks->test( $endpoint_id );   // a signed `ping`, now, never queued
// [ 'found' => true, 'ok' => true, 'status' => 200, 'error' => '', 'url' => '…' ]
```

An answered test switches back on an endpoint that was switched off for
failing -- never one its owner switched off. With endpoints in `read`/`write`
callables, call `breaker()->saved( $old_rows, $new_rows )` when they are saved.

The record of failures is one option, or anywhere with `read`/`write`:

```php
'health' => [
	'read'  => fn() => myshop_get_option( 'webhook_health', [] ),
	'write' => fn( array $records ) => myshop_update_option( 'webhook_health', $records ),
],
```

## Addresses inside the network

Every delivery goes through `SafeUrl`, on top of `wp_safe_remote_post()`.
Every A and AAAA answer must be public by `SugarCommerce\Network\IP::is_public()`
-- loopback, RFC 1918, link-local, the metadata service, unique-local and
site-local IPv6, and an IPv4 address carried inside IPv6 (mapped, SIIT, NAT64,
6to4) judged by the address it carries. URLs with credentials or odd hosts are
refused, curl is pinned to the address that was checked, and redirects (none
followed by default; `'redirects' => 3`) are re-checked hop by hop.
WordPress's `http_request_host_is_external` filter still lets a development
host through, and `'allow_private' => true` lets every one through.

```php
$reason = ( new SafeUrl() )->refuse( $url );   // null when it may be posted to; log the reason
```

## One delivery

`Sender` is the single POST under the dispatcher, for a request of your own
that should look like one of your webhooks. It signs at the moment of sending,
so a delivery that waited in the queue is not older than the receiver's
window, and it never throws:

```php
$attempt = $webhooks->sender()->send( $url, 'order.completed', $body, $secret );

$attempt->sent;          // 2xx
$attempt->status;        // 0 when nothing came back
$attempt->error;         // why nothing came back
$attempt->retry_after;   // seconds the receiver asked for, from a 429 or 503
```

## Erasing a person from the queue

A queued delivery carries its whole body, a buyer's email in it. For a
privacy eraser:

```php
$webhooks->queued()->rewrite( $email, function ( array $event ) use ( $email ): ?array {
	if ( ( $event['data']['email'] ?? '' ) !== $email ) {
		return null;   // only mentions them: leave it
	}
	$event['data']['email'] = '';
	return $event;
} );
```

Pending ones are re-queued for the same moment with the new body; finished
ones are deleted.

## Receiving

```php
use SugarCommerce\Jobs\Inbox;
use SugarCommerce\Webhooks\Receiver;

$inbox    = new Inbox( $wpdb->prefix . 'myshop_events' );   // ->schema() for dbDelta()
$receiver = new Receiver( fn() => get_option( 'myshop_inbound_secret' ), $inbox, header: 'Partner-Signature' );

register_rest_route( 'myshop/v1', '/webhook', [
	'methods'             => 'POST',
	'permission_callback' => '__return_true',   // the signature is the permission
	'callback'            => fn( WP_REST_Request $request ) => $receiver->rest( $request, function ( array $event ) {
		// act on $event['type'] and $event['data']; throw, or return false, to fail
	} ),
] );
```

The signature is checked over the raw body before anything is decoded. With an
inbox, the event's `id` is claimed by a unique index before the handler runs:
a repeat of a handled event is answered 200 and not handled again, one being
handled right now is answered 409, and a handler that throws answers 500 and
leaves the event for the sender's retry. `receive( $body, $header, $handle )`
does the same without a REST request and returns a `Received` with the
`status`, a `reason` to log (never to answer with) and what the handler threw.

Or check a signature by hand:

```php
$result = Signature::verify( $raw_body, $header, $secret );   // the raw body, never re-encoded

if ( ! $result->valid ) {
	status_header( 400 );   // log $result->reason; do not echo it
	exit;
}
```

### Rotating a secret

Sending: pass both and each signs, at the same timestamp, as its own `v1=`.
Receiving: pass both and either verifies. Drop the old one once the other end
has moved.

```php
Signature::sign( $body, [ $new_secret, $old_secret ] );
Signature::verify( $body, $header, [ $new_secret, $old_secret ] );
new Receiver( [ $new_secret, $old_secret ] );
```

A negative tolerance fails every request rather than switching the check off,
and a header with two `t=` values is malformed.

## What it does not do

WP-Cron, and Action Scheduler on top of it, runs on traffic: a quiet site
sends a queued delivery late. Delivery is at least once, not exactly once --
give the receiver the event's `id` to deduplicate on, as `Receiver` does.

## Security

* Server-side request forgery: every delivery, test and followed redirect
  goes through `SafeUrl`, which refuses a host that is not a plain name or
  IP, credentials in the address, and any host with even one private or
  reserved address among its answers, then pins the connection to the
  address it checked so DNS cannot change in between.
* Forged or replayed deliveries: signatures are HMAC-SHA256 over the
  timestamp and body, compared with `hash_equals()` against every secret
  without an early exit; the timestamp is inside what is signed, a missing
  header is a refusal, and a negative window fails rather than switching the
  check off. With an inbox, one event id is handled once.
* A received body is checked with `json_validate()` before it is decoded.
* Secrets are never logged: what a failed handler leaves in the inbox is the
  exception's class, not its message.
* An endpoint failing for a day is switched off, so a dead or hostile
  address cannot keep a queue busy.

## Layout

* Sending: `Dispatcher.php` (with `src/Traits/Dispatcher/Delivery.php`),
  `Sender.php`, `SafeUrl.php`, `Queued.php`, `Backoff.php`.
* Endpoints: `Endpoints.php`, `Events.php`, `Breaker.php`, `EndpointHealth.php`.
* Receiving: `Receiver.php`, `Signature.php`.
* `src/Models/`: data shapes and nothing that does work. `Attempt.php` (how one
  delivery went), `Received.php` (what to answer a sender) and `Verification.php`
  (whether a signature held, and why not).

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later
* Action Scheduler 3.6 or later, for queued delivery (see `sugarcommerce/jobs`)

## License

GPL-2.0-or-later
