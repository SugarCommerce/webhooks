# Webhooks

Webhook signing, verification and retry scheduling. Stripe-style `t=,v1=` signatures with replay protection, constant-time comparison, and a retry policy that distinguishes the receiver's problems from your own. Zero dependencies.

## Why

A webhook is an unauthenticated POST from the internet claiming to be from you. Without a signature, anyone who learns the endpoint URL can send "payment succeeded" and get a licence issued.

Three mistakes make a signature useless, and all three are common:

- **Signing the payload alone.** Without a timestamp inside the signed material, a captured request replays forever.
- **Comparing with `===`.** String comparison short-circuits on the first differing byte, which leaks the correct prefix through timing and lets an attacker recover the signature byte by byte.
- **Retrying everything.** A 404 or a 401 will fail identically on the twentieth attempt. Retrying them buries the real failures and hammers a receiver that already said no.

## Features

- ✍️ **Stripe-compatible format** — `t=1234567890,v1=abc…`, so existing receivers already know how to verify it.
- ⏰ **Replay protection** — the timestamp is inside the signed material and checked against a tolerance.
- ⏱️ **Constant-time comparison** — `hash_equals`, always.
- 🔑 **Multiple valid signatures** — several `v1=` values in one header, so a secret can be rotated without downtime.
- 🔁 **Sane retry policy** — exponential backoff with jitter, and a status-aware decision about whether to retry at all.
- 🫡 **Respects `Retry-After`** — when a receiver tells you when to come back, it is honoured.

## Requirements

PHP 8.5+

## Installation

```bash
composer require sugarcommerce/webhooks
```

## Sending

```php
use Sugar\Webhooks\Signature;

$payload   = json_encode( $event );
$signature = Signature::sign( $payload, $endpoint_secret );

$response = $client->post( $endpoint_url, $payload, [
    'Content-Type'         => 'application/json',
    Signature::HEADER      => $signature,   // X-Webhook-Signature
] );
```

Sign the **exact bytes** you send. Re-encoding the payload after signing — even a whitespace difference — invalidates the signature, and it is the most common reason a correct implementation on both ends still fails.

## Receiving

```php
$result = Signature::verify(
    file_get_contents( 'php://input' ),
    $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
    $secret
);

if ( $result->failed_check() ) {
    http_response_code( 400 );
    error_log( 'Rejected webhook: ' . $result->reason );
    exit;
}

$timestamp = $result->timestamp;
```

Read the **raw body**, not a parsed one. `json_decode` then `json_encode` will not reproduce the original bytes — key order, unicode escaping and float formatting all change — and the signature will not match.

### Tolerance

```php
Signature::verify( $payload, $header, $secret, tolerance: 600 );
```

The default is 300 seconds, matching Stripe. It exists because clocks drift and a queued delivery may arrive late; widening it widens the window in which a captured request can be replayed. Do not disable it.

### Rotating a secret

```php
$header = Signature::sign( $payload, $new_secret )
    . ',' . explode( ',', Signature::sign( $payload, $old_secret ) )[1];
```

Both `v1=` values are checked, so receivers can move to the new secret at their own pace. Drop the old one once they have.

## Retrying

```php
use Sugar\Webhooks\RetryPolicy;

$policy = new RetryPolicy( max_attempts: 6, base_delay: 60, max_delay: 86400, jitter: 25 );

if ( $policy->should_retry( $response->status, $attempt ) ) {
    $seconds = $policy->delay( $attempt, $response->header( 'retry-after' ) );
    $queue->push( $delivery, delay: $seconds );
}
```

| Status | Retried | Why |
|---|---|---|
| 2xx | No | It worked. |
| 408, 429 | Yes | Timeout or rate limit — temporary by definition. |
| 5xx | Yes | The receiver is broken, not the request. |
| Other 4xx | No | The request is wrong and will stay wrong. |
| Transport failure | Yes | Nothing was served, so nothing was refused. |

```php
$policy->schedule();   // [60, 120, 240, 480, 960] — the whole ladder, for a UI
```

**Jitter is not cosmetic.** When a receiver comes back after an outage, every queued delivery retries at the same instant and knocks it over again. A percentage of random spread turns a thundering herd into a ramp.

## Testing

```bash
composer install
composer test
```

50 tests — signature round trips, tampered payloads and headers, expired and future timestamps, multi-signature rotation, malformed headers of every shape, and the full backoff ladder.

## License

GPL-2.0-or-later
