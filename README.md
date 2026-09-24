# Stripe

A small Stripe client for Connect platforms: pinned API version, `Stripe-Account` per request, a fetch that throws instead of answering null, signed event parsing with payload redaction, and readers for the fields Stripe moves between API versions.

## Why

A platform that calls a handful of Stripe endpoints does not need the official SDK, but the few things it does need are each easy to get quietly wrong:

- **"No answer" read as "an empty answer".** A webhook that treats a failed line-item read as a session with nothing in it finishes a paid order empty and acknowledges the event, so Stripe never retries.
- **Fields that move.** When the pinned version passed `2025-03-31.basil`, `current_period_end` left the subscription for its items and read as `null`. Nothing failed; grace windows and lapse sweeps just switched off.
- **The envelope is forgotten.** `account` is the only thing telling a connected account's event from the platform's own, and `created` is the only defence against Stripe's out-of-order delivery.
- **Payloads kept verbatim.** Stored events are useful for replay and carry names, addresses, phone numbers and tax ids nobody needed.

## Features

- 📌 **Pinned** — every request sends `Stripe-Version: Client::API_VERSION`.
- 🏢 **Connect-first** — pass an account id and the request is made as that account.
- 💥 **`fetch()` throws** — `StripeError` with the path and status; `get()`/`post()` answer `null` where a fallback is fine.
- ✍️ **`Event::receive()`** — verifies `Stripe-Signature` (HMAC + replay window, via `sugarcommerce/webhooks`) and unpacks id, type, account, mode, created and object.
- 🙈 **`Event::redact()`** — strips name, phone, address and tax ids; keeps email.
- 🧭 **`Shape`** — `period_end()`, `invoice_line_price()`, `invoice_line_product()`, `invoice_payment_intents()`, `amounts()`, `custom_amount()`: current location first, pre-basil second.

## Requirements

PHP 8.5+, `sugarcommerce/http-client`, `sugarcommerce/webhooks`

## Installation

```bash
composer require sugarcommerce/stripe
```

## Calling the API

```php
use SugarCommerce\Stripe\{Client, StripeError};

$stripe = new Client( $secret_key );

$prices = $stripe->get( 'prices', 'acct_123', array( 'active' => 'true', 'expand' => array( 'data.product' ) ) );
$link   = $stripe->post( 'payment_links', array( 'line_items' => array( array( 'price' => 'price_1', 'quantity' => 1 ) ) ), 'acct_123' );

try {
    $items = $stripe->session_line_items( 'cs_123', 'acct_123' );
} catch ( StripeError $e ) {
    http_response_code( 500 ); // Stripe will retry
}
```

## Receiving webhooks

```php
use SugarCommerce\Stripe\{Event, InvalidEvent};

try {
    $event = Event::receive( $body, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $webhook_secret );
} catch ( InvalidEvent $e ) {
    http_response_code( 400 );
    exit( $e->getMessage() );
}

$event->platform();   // true when it happened in your own account
$event->account;      // otherwise, the connected account
$event->mode();       // 'live' | 'test'
$event->created;      // compare before applying an update
$event->object;       // data.object

$store( Event::redact( $body ) );
```

## Testing

Pass `SugarCommerce\HttpClient\Fake` as the client's HTTP client:

```php
$http   = ( new Fake() )->on( 'GET', 'https://api.stripe.com/v1/charges*', new Response( true, 200, '{"data":[]}' ) );
$stripe = new Client( 'sk_test_x', $http );
```

## License

GPL-2.0-or-later
