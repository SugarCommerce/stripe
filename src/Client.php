<?php
/**
 * Minimal Stripe API client.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

use SugarCommerce\HttpClient\Client as Http;
use SugarCommerce\HttpClient\Response;

/**
 * Class Client
 *
 * For a Connect platform: every request carries the platform key, and a
 * request made for a connected account adds a `Stripe-Account` header naming
 * it. That header is the whole multi-tenant story — the same key, scoped per
 * request to the account being acted for.
 *
 * Deliberately not the official SDK. A platform that calls a handful of
 * endpoints carries a large dependency for them, and the request shape is
 * easier to reason about when it is visible.
 *
 * Two ways to read. get() answers null for any failure, for callers with a
 * sensible fallback. fetch() throws, for callers where "no answer" must never
 * be mistaken for "an empty answer". Writes likewise: post() answers null,
 * attempt() throws with Stripe's reason. each() reads a whole list, however
 * many pages it runs to.
 *
 * Not final: applications substitute doubles for individual calls in tests.
 *
 * @since 1.0.0
 */
class Client {

	/**
	 * Where the API lives.
	 */
	private const string BASE = 'https://api.stripe.com/v1';

	/**
	 * Most pages each() reads by default: ten thousand objects.
	 *
	 * @since 1.2.0
	 */
	public const int MAX_PAGES = 100;

	/**
	 * The Stripe API version every request is pinned to.
	 *
	 * Pinned rather than left to the account default, so a Stripe-side
	 * version bump cannot change a response shape under a running
	 * deployment. Shape reads fields where this version puts them. Anything
	 * else talking to Stripe for the same application — a Stripe App's
	 * front end, say — should declare the same one, or two halves of one
	 * feature see different shapes of the same object.
	 *
	 * @since 1.0.0
	 */
	public const string API_VERSION = '2026-08-26.dahlia';

	/**
	 * @since 1.0.0
	 *
	 * @param string $secret_key Platform secret key.
	 * @param Http   $http       HTTP client. Substituted in tests.
	 */
	public function __construct(
		#[\SensitiveParameter] protected string $secret_key = '',
		protected Http $http = new Http(),
	) {}

	/**
	 * Whether this client can reach Stripe at all.
	 *
	 * An install with no key is not a broken install — it is one that has
	 * not been finished — and the difference is worth saying to whoever is
	 * looking at the refusal.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function configured(): bool {
		return '' !== $this->secret_key;
	}

	/**
	 * GET an object, or null on any failure.
	 *
	 * Callers decide what a failure means — a missing session during a
	 * webhook retry is normal, a failed link creation is not. The failure is
	 * logged either way.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $path    Path below /v1, e.g. 'checkout/sessions/cs_1'.
	 * @param string|null $account Connected account id, or null for the
	 *                             platform's own.
	 * @param array       $query   Query parameters.
	 *
	 * @return array|null The decoded object.
	 */
	public function get( string $path, ?string $account = null, array $query = array() ): ?array {
		return $this->unwrap( $this->http->get( $this->url( $path, $query ), $this->headers( $account ) ) );
	}

	/**
	 * GET an object that must exist, or throw.
	 *
	 * Where the answer is the whole point — a webhook resolving what a buyer
	 * paid for — a null read as "nothing" finishes the work wrongly and tells
	 * Stripe not to retry. Throwing turns the same outage into a retry.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param string|null $account Connected account id, or null for the
	 *                             platform's own.
	 * @param array       $query   Query parameters.
	 *
	 * @return array The decoded object.
	 *
	 * @throws StripeError On a non-2xx answer, a transport failure or a body
	 *                     that is not JSON.
	 */
	public function fetch( string $path, ?string $account = null, array $query = array() ): array {
		return $this->answer( $path, $this->http->get( $this->url( $path, $query ), $this->headers( $account ) ) );
	}

	/**
	 * POST form-encoded parameters, or null on any failure.
	 *
	 * Stripe takes form encoding, not JSON, and expects nested keys in
	 * `a[b][c]` form — which is exactly what http_build_query produces.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters, nested as Stripe documents them.
	 * @param string|null $account Connected account id, or null for the
	 *                             platform's own.
	 *
	 * @return array|null The decoded object.
	 */
	#[\NoDiscard( 'Null means Stripe did not do it.' )]
	public function post( string $path, array $params, ?string $account = null ): ?array {
		return $this->unwrap( $this->send( $path, $params, $account ) );
	}

	/**
	 * POST, and on failure throw with Stripe's reason.
	 *
	 * post() answers null and logs the body, which is right for most
	 * callers and wrong for the one where a person is looking at the
	 * result: "could not create a checkout session" is what a buyer sees
	 * when Stripe said, precisely, which item it would not take. Here that
	 * reason survives, parsed, on the StripeError.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters, nested as Stripe documents them.
	 * @param string|null $account Connected account id, or null for the
	 *                             platform's own.
	 *
	 * @return array The object Stripe created or changed.
	 *
	 * @throws StripeError On a non-2xx answer, a transport failure or a body
	 *                     that is not JSON; code(), param() and message()
	 *                     carry what Stripe said.
	 */
	#[\NoDiscard( 'The object is the only proof Stripe did it.' )]
	public function attempt( string $path, array $params, ?string $account = null ): array {
		return $this->answer( $path, $this->send( $path, $params, $account ) );
	}

	/**
	 * Every object in a list, page by page.
	 *
	 * Stripe lists answer at most a hundred at a time, and a caller that
	 * asks once gets the first hundred and no sign anything is missing —
	 * which is how a shop's hundred-and-first price silently vanished. This
	 * follows `has_more` with `starting_after` until the list ends.
	 *
	 * Each page is a fetch(), so an outage half-way through throws rather
	 * than answering with half a list. For list endpoints only: search
	 * endpoints page with `page`, not `starting_after`.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path      List path below /v1, e.g. 'prices'.
	 * @param string|null $account   Connected account id, or null for the
	 *                               platform's own.
	 * @param array       $query     Query parameters. `limit` defaults to
	 *                               100, the most Stripe allows.
	 * @param int         $max_pages Most pages to read, so a runaway list
	 *                               cannot hold a request open for ever.
	 *
	 * @return \Generator<int, array> Each object, in Stripe's order.
	 *
	 * @throws StripeError When a page cannot be read.
	 */
	public function each( string $path, ?string $account = null, array $query = array(), int $max_pages = self::MAX_PAGES ): \Generator {
		$query += array( 'limit' => 100 );
		$after  = '';

		for ( $page = 0; $page < $max_pages; ++$page ) {
			$list = $this->fetch( $path, $account, '' === $after ? $query : array( 'starting_after' => $after ) + $query );
			$last = $after;

			foreach ( (array) ( $list['data'] ?? array() ) as $object ) {
				if ( is_array( $object ) ) {
					$last = (string) ( $object['id'] ?? $last );

					yield $object;
				}
			}

			// The end, or a page that did not move the cursor — which would
			// ask for the same page again until the page limit.
			if ( empty( $list['has_more'] ) || '' === $last || $last === $after ) {
				return;
			}

			$after = $last;
		}
	}

	/**
	 * Every object in a list, as an array.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path      List path below /v1.
	 * @param string|null $account   Connected account id, or null.
	 * @param array       $query     Query parameters.
	 * @param int         $max_pages Most pages to read.
	 *
	 * @return array<int, array>
	 *
	 * @throws StripeError When a page cannot be read.
	 */
	public function fetch_all( string $path, ?string $account = null, array $query = array(), int $max_pages = self::MAX_PAGES ): array {
		return iterator_to_array( $this->each( $path, $account, $query, $max_pages ), false );
	}

	/**
	 * Every line item on a Checkout Session.
	 *
	 * Line items are not included in the webhook payload, so a purchase
	 * cannot be resolved to prices without this call.
	 *
	 * @since 1.0.0
	 *
	 * @param string $session_id Checkout Session id.
	 * @param string $account    Connected account id.
	 *
	 * @return array<int, array> Raw line item objects.
	 *
	 * @throws StripeError When Stripe does not answer. An empty list here
	 *                     would mean "they bought nothing", and a paid
	 *                     order would be finished with nothing in it.
	 */
	public function session_line_items( string $session_id, string $account ): array {
		$result = $this->fetch(
			"checkout/sessions/{$session_id}/line_items",
			$account,
			array( 'limit' => 100 )
		);

		return $result['data'] ?? array();
	}

	/**
	 * Stripe's own receipt for a payment, as a URL.
	 *
	 * Stripe already issues a receipt that knows about the seller's tax
	 * registration, their address and the customer's, so building a second
	 * one would mean building a worse one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $intent  Payment intent id.
	 * @param string $account Connected account id.
	 *
	 * @return string The URL, or '' when there is not one yet.
	 */
	public function receipt_url( string $intent, string $account ): string {
		return (string) ( $this->charge_for_intent( $intent, $account )['receipt_url'] ?? '' );
	}

	/**
	 * The charge behind a payment intent, or null.
	 *
	 * Where the receipt URL lives, and the payment method's billing details
	 * as they were when it paid — so a caller that wants both asks once.
	 * Read from the charge list rather than the intent, because charge
	 * access is the narrower permission for a Connect app to ask for.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $intent  Payment intent id.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array|null The latest charge, or null when there is none yet
	 *                    or Stripe could not be asked.
	 */
	public function charge_for_intent( string $intent, ?string $account = null ): ?array {
		if ( '' === $intent ) {
			return null;
		}

		$charge = $this->get( 'charges', $account, array( 'payment_intent' => $intent, 'limit' => 1 ) )['data'][0] ?? null;

		return is_array( $charge ) ? $charge : null;
	}

	/**
	 * A billing portal session's URL, or ''.
	 *
	 * The portal is Stripe's own page for changing a card, an invoice
	 * address or a plan, so nothing here builds one. A session lasts a few
	 * minutes: mint it when the customer asks, never ahead of time.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $customer   Customer id.
	 * @param string      $return_url Where the portal's "return" link goes.
	 * @param string|null $account    Connected account id, or null for the
	 *                                platform's own customers.
	 *
	 * @return string The URL, or '' when Stripe would not open one.
	 */
	public function portal_url( string $customer, string $return_url, ?string $account = null ): string {
		if ( '' === $customer ) {
			return '';
		}

		$session = $this->post( 'billing_portal/sessions', array(
			'customer'   => $customer,
			'return_url' => $return_url,
		), $account );

		return (string) ( $session['url'] ?? '' );
	}

	/**
	 * The full URL for a path and query.
	 *
	 * Protected so a subclass that sends something this class does not can
	 * build the same request, against the same pinned version.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Protected.
	 *
	 * @param string $path  Path below /v1.
	 * @param array  $query Query parameters.
	 *
	 * @return string
	 */
	protected function url( string $path, array $query = array() ): string {
		$url = self::BASE . '/' . ltrim( $path, '/' );

		return $query ? $url . '?' . http_build_query( $query ) : $url;
	}

	/**
	 * Headers for a request, optionally on behalf of a connected account.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Protected.
	 *
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array<string, string>
	 */
	protected function headers( ?string $account ): array {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->secret_key,
			'Stripe-Version' => self::API_VERSION,
		);

		if ( null !== $account ) {
			$headers['Stripe-Account'] = $account;
		}

		return $headers;
	}

	/**
	 * Send a form-encoded POST.
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return Response
	 */
	private function send( string $path, array $params, ?string $account ): Response {
		return $this->http->post(
			$this->url( $path ),
			http_build_query( $params ),
			$this->headers( $account ) + array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		);
	}

	/**
	 * Decode a response, or throw.
	 *
	 * @param string   $path     Path below /v1, for the error.
	 * @param Response $response What came back.
	 *
	 * @return array
	 *
	 * @throws StripeError When it is not an answer.
	 */
	private function answer( string $path, Response $response ): array {
		$decoded = $response->ok ? $response->json() : null;

		if ( ! is_array( $decoded ) ) {
			throw StripeError::from_response( $path, $response );
		}

		return $decoded;
	}

	/**
	 * Decode a response, or null on any failure.
	 *
	 * @param Response $response What came back.
	 *
	 * @return array|null
	 */
	private function unwrap( Response $response ): ?array {
		if ( ! $response->ok ) {
			error_log( sprintf(
				'Stripe API %d: %s',
				$response->status,
				substr( '' !== $response->body ? $response->body : $response->error, 0, 300 )
			) );

			return null;
		}

		$decoded = $response->json();

		return is_array( $decoded ) ? $decoded : null;
	}
}
