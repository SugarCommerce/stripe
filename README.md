# Stripe

A small Stripe client for Connect platforms: pinned API version, `Stripe-Account` per request, a fetch that throws instead of answering null, signed event parsing with payload redaction, and readers for the fields Stripe moves between API versions.

## Why

A platform that calls a handful of Stripe endpoints does not need the official SDK, but the few things it does need are each easy to get quietly wrong:

- **"No answer" read as "an empty answer".** A webhook that treats a failed line-item read as a session with nothing in it finishes a paid order empty and acknowledges the event, so Stripe never retries.
- **Fields that move.** When the pinned version passed `2025-03-31.basil`, `current_period_end` left the subscription for its items and read as `null`. Nothing failed; grace windows and lapse sweeps just switched off.
- **The envelope is forgotten.** `account` is the only thing telling a connected account's event from the platform's own, and `created` is the only defence against Stripe's out-of-order delivery.
- **Payloads kept verbatim.** Stored events are useful for replay and carry names, addresses, phone numbers and tax ids nobody needed.
- **The first page read as the whole list.** One `GET prices?limit=100` was a shop's whole catalogue, and the hundred-and-first price silently vanished.
- **One key for two modes.** A live key cannot read a test checkout; a platform with one key fails every call for the other mode, and an account installed in a Stripe sandbox needs the sandbox's key.
- **Stripe's reason thrown away.** `post()` answering `null` is right until a buyer is looking at the result and Stripe said exactly which item it would not take.
- **One install, two events.** Since `2026-09-30.endive` Stripe announces an app install as `account.application.authorized` *and* `apps.install.created` (`app.install.*` on older endpoints) — two ids, so event-id idempotency lets both through, and a handler that treats each as news records the install twice.
- **A version split that removes a value.** Endive reports a platform's rejection as `rejected.platform_fraud`, not `rejected.fraud`; a list of rejection reasons written before it stops matching without an error.
- **Cart rules learned from Stripe's error.** One currency, one renewal schedule, item limits, pay-what-you-want alone — a buyer should read that before a session is created, not after.

## Features

- 📌 **Pinned** — every request sends `Stripe-Version: Client::API_VERSION`, currently `2026-09-30.endive`. Create your webhook endpoints at the same version: an event's shape, and for app installs its name, follow the endpoint's version, not the request's.
- 🏢 **Connect-first** — pass an account id and the request is made as that account.
- 💥 **`fetch()` and `attempt()` throw** — `StripeError` with the path, status and Stripe's parsed `error` (`code()`, `param()`, `type()`, `decline_code()`, `message()`); `get()`/`post()` answer `null` where a fallback is fine.
- 📚 **`each()` / `fetch_all()`** — every object in a list, following `has_more` with `starting_after`; a failed page throws rather than answering half a list.
- 🧾 **`charge_for_intent()`, `receipt_url()`, `portal_url()`** — one charge read for the receipt and the billing details; a billing portal session on request.
- 🔑 **`Keyring`** — a client per mode (and the managed sandbox's), picked per call with `Keyring::pick()`; `Keyring::key_mode()` reads a key's mode from its prefix. **`Keyless`** refuses every call without making it, saying which key is missing.
- ✍️ **`Event::receive()` / `receive_any()`** — verifies `Stripe-Signature` (HMAC + replay window, via `sugarcommerce/webhooks`) against one secret or several, says which one signed it, and unpacks id, type, account, mode, created and object.
- 🙈 **`Event::redact()`** — strips name, phone, address and tax ids; keeps email.
- 🧭 **`Shape`** — reads for fields that move or are easy to misread: `period_end()`, `invoice_subscription()`, `invoice_line_price()`, `invoice_line_product()`, `invoice_payment_intents()`, `subscription_price()`, `session_email()`, `session_paid()`, `custom_field()`, `promotions_consent()`, `fully_refunded()`, `dispute_is_inquiry()`, `account_disabled_reason()`, `account_rejected()` (Stripe's rejections and, since endive, a platform's: `Shape::PLATFORM_REJECTED`), `entitlement_feature()`, `id()`, and a flat `price()` (with `tiers()`, `currencies()`, `amounts()`, `custom_amount()`).
- 🛒 **`Cart`** — Stripe Checkout's item rules as codes, an application's own rule beside them, optional extras that Stripe will accept, `line_items()` and the `customer_params()` each mode takes.
- 🚫 **`Refusal::classify()`** — what Stripe refused, as a reason and a status an application can put into words.
- 📲 **`AppInstall::of()`** — `Installed`, `Uninstalled` or `null` for any event, reading `account.application.*`, `apps.install.*` and the pre-endive `app.install.*` alike, and only an Install whose status is `installed` as an install; `AppInstall::TYPES` lists them for a dispatch.
- 🔁 **`SubscriptionStatus`** — Stripe's eight statuses sorted into running, paid, lapsed and ended, with `access_until()`.
- 🔏 **`RequestDigest` and `AppSignature`** — verify a Stripe App's signed request, bound to its route and body, with the digest `@arraypress/stripe-app` builds in the browser.

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

// Every page of a list, not the first hundred.
$all = $stripe->fetch_all( 'prices', 'acct_123', array( 'active' => 'true', 'expand' => array( 'data.product' ) ) );

// A write where Stripe's reason matters.
try {
    $session = $stripe->attempt( 'checkout/sessions', $params, 'acct_123' );
} catch ( StripeError $e ) {
    $e->code();   // 'resource_missing'
    $e->param();  // 'line_items[0][price]'
}
```

## One key per mode

```php
use SugarCommerce\Stripe\Keyring;

$stripe = Keyring::from_keys(
    live: $live_key,
    test: $test_key,
    sandbox: $sandbox_key,
    sandboxed: fn ( string $account ): bool => $installs->is_sandbox( $account ),
);

Keyring::pick( $stripe, $event->mode(), $event->account )->session_line_items( $id, $event->account );
Keyring::key_mode( 'rk_live_…' ); // 'live'
```

A mode with no key is a `Keyless`: reads that may fail answer `null`, reads that must succeed throw, and nothing is sent to Stripe with an empty bearer.

## Checkout carts

```php
use SugarCommerce\Stripe\{Cart, Refusal, Shape};

$cart    = new Cart( fn ( array $price ): ?string => 0 === $price['amount'] ? 'free' : null );
$checked = $cart->check( array_map( Shape::price( ... ), $prices ), 'gbp' );

if ( ! $checked['ok'] ) {
    echo $words[ $checked['code'] ]; // 'currency', 'interval', 'too_many', 'custom_alone', 'metered', 'free'…
}

$params = Cart::line_items( $checked['prices'] ) + Cart::customer_params( $email, 'subscription' === $checked['mode'] );

try {
    $session = $stripe->attempt( 'checkout/sessions', $params, $account );
} catch ( StripeError $e ) {
    [ 'reason' => $reason, 'status' => $status ] = Refusal::classify( $e ); // 'tax_not_ready', 503
}
```

## Subscription standing

```php
use SugarCommerce\Stripe\SubscriptionStatus;

$status = SubscriptionStatus::of( $subscription['status'] );
$status?->lapsed();                                   // unpaid, paused
$status?->access_until( Shape::period_end( $subscription ) );
SubscriptionStatus::ENDED;                            // for SQL: canceled, incomplete_expired
```

## Stripe App requests

```php
use SugarCommerce\Stripe\{AppSignature, RequestDigest};

$digest = RequestDigest::of_request( $method, $path, $query, $body );
$proof  = AppSignature::proof( $signature_header, $app_secret, $user_id, $account_id, array( 'request' => $digest ) );
// '' when it does not verify; otherwise "{timestamp}.{v1}", stable enough to spend once.
```

The browser half is [`@arraypress/stripe-app`](https://github.com/arraypress/stripe-app); both are tested against `tests/fixtures/request-digest.json`.

## Receiving webhooks

```php
use SugarCommerce\Stripe\{AppInstall, Event, InvalidEvent};

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

// An install or an uninstall, whichever family of event said so. Both arrive for one install:
// compare $event->created with the last change you recorded rather than acting on each.
match ( AppInstall::of( $event ) ) {
    AppInstall::Installed   => $installs->open( $event->account, $event->mode(), $event->created ),
    AppInstall::Uninstalled => $installs->close( $event->account, $event->mode(), $event->created ),
    null                    => null, // not an install event, or one still in progress
};

// One URL registered as several endpoints: which secret verified it says where it came from.
[ $event, $from ] = Event::receive_any( $body, $header, array( 'platform' => $ours, 'sandbox' => $sandbox_secret ) );
```

## Testing

```bash
composer test
```

173 tests: the client (pinning to endive, Connect headers, structured errors, `attempt()`, paging and its failure and runaway cases, charges, the portal), events (verification, several secrets, redaction), every `Shape` read in its current and pre-basil location (and endive's platform rejections), both families of app install event and the Install lifecycle, a failed tax calculation sorted as Stripe's failure, the keyring and keyless refusals, every subscription status, the cart rules and extras, refusal classification, the shared request-digest vectors and app signatures, and that every must-check result keeps `#[\NoDiscard]`.

In your own tests, pass `SugarCommerce\HttpClient\Fake` as the client's HTTP client:

```php
$http   = ( new Fake() )->on( 'GET', 'https://api.stripe.com/v1/charges*', new Response( true, 200, '{"data":[]}' ) );
$stripe = new Client( 'sk_test_x', $http );
```

## License

GPL-2.0-or-later
