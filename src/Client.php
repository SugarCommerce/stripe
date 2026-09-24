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
 * be mistaken for "an empty answer".
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
		$response = $this->http->get( $this->url( $path, $query ), $this->headers( $account ) );
		$decoded  = $response->ok ? $response->json() : null;

		if ( ! is_array( $decoded ) ) {
			throw new StripeError( $path, $response->status, '' !== $response->body ? $response->body : $response->error );
		}

		return $decoded;
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
		return $this->unwrap( $this->http->post(
			$this->url( $path ),
			http_build_query( $params ),
			$this->headers( $account ) + array( 'Content-Type' => 'application/x-www-form-urlencoded' )
		) );
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
	 * Read from the charge rather than the payment intent, because charge
	 * access is the narrower permission for a Connect app to ask for.
	 *
	 * @since 1.0.0
	 *
	 * @param string $intent  Payment intent id.
	 * @param string $account Connected account id.
	 *
	 * @return string The URL, or '' when there is not one yet.
	 */
	public function receipt_url( string $intent, string $account ): string {
		if ( '' === $intent ) {
			return '';
		}

		$result = $this->get( 'charges', $account, array( 'payment_intent' => $intent, 'limit' => 1 ) );

		return (string) ( $result['data'][0]['receipt_url'] ?? '' );
	}

	/**
	 * The full URL for a path and query.
	 *
	 * @param string $path  Path below /v1.
	 * @param array  $query Query parameters.
	 *
	 * @return string
	 */
	private function url( string $path, array $query = array() ): string {
		$url = self::BASE . '/' . ltrim( $path, '/' );

		return $query ? $url . '?' . http_build_query( $query ) : $url;
	}

	/**
	 * Headers for a request, optionally on behalf of a connected account.
	 *
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array<string, string>
	 */
	private function headers( ?string $account ): array {
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
