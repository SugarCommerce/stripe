<?php
/**
 * A Stripe call that did not produce an answer.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

use RuntimeException;
use SugarCommerce\HttpClient\Response;

/**
 * Class StripeError
 *
 * Thrown by Client::fetch(), for callers where "no answer" must never be read
 * as "an empty answer". A webhook handler that lets a failed line-item read
 * look like a session with nothing in it finishes a paid order with nothing
 * in it, and acknowledges the event so Stripe never retries. Throwing turns
 * the same outage into a 500 and a retry.
 *
 * Carries Stripe's own `error` object, parsed. A caller with a person looking
 * at the result — a buyer whose checkout was refused — needs to know *which*
 * rule Stripe applied, and that is in `code` and `param`, not in a status.
 * Without the structured fields every caller that wanted them re-sent the
 * request itself to keep the body, copying the client's private headers to
 * do it.
 *
 * @since 1.0.0
 */
final class StripeError extends RuntimeException {

	/**
	 * @since 1.0.0
	 * @since 1.2.0 Takes Stripe's parsed `error` object.
	 *
	 * @param string $path   Path below /v1 that was requested.
	 * @param int    $status HTTP status, 0 when the request never completed.
	 * @param string $body   Start of the response body, for the log.
	 * @param array<mixed> $error Stripe's `error` object (type, code, param,
	 *                       message, decline_code), or [] when nothing came
	 *                       back to read.
	 */
	public function __construct(
		public readonly string $path,
		public readonly int $status,
		string $body = '',
		public readonly array $error = array(),
	) {
		parent::__construct( sprintf( 'Stripe %s answered %d: %s', $path, $status, substr( $body, 0, 200 ) ) );
	}

	/**
	 * The error for a response that was not an answer.
	 *
	 * Reads Stripe's `error` object out of the body when there is one; a
	 * transport failure has no body, and its message stands in for one.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $path     Path below /v1 that was requested.
	 * @param Response $response What came back.
	 *
	 * @return self
	 */
	public static function from_response( string $path, Response $response ): self {
		$decoded = '' !== $response->body ? json_decode( $response->body, true ) : null;
		$error   = is_array( $decoded ) && is_array( $decoded['error'] ?? null ) ? $decoded['error'] : array();

		return new self( $path, $response->status, '' !== $response->body ? $response->body : $response->error, $error );
	}

	/**
	 * Stripe's error type: `card_error`, `invalid_request_error`, `api_error`…
	 *
	 * @since 1.2.0
	 *
	 * @return string The type, or ''.
	 */
	public function type(): string {
		return $this->field( 'type' );
	}

	/**
	 * Stripe's machine-readable error code, e.g. `resource_missing`.
	 *
	 * Documented and stable, unlike the message, so it is what rules should
	 * match on first.
	 *
	 * @since 1.2.0
	 *
	 * @return string The code, or ''.
	 */
	public function code(): string {
		return $this->field( 'code' );
	}

	/**
	 * The request parameter Stripe objected to, e.g. `line_items[0][price]`.
	 *
	 * @since 1.2.0
	 *
	 * @return string The parameter, or ''.
	 */
	public function param(): string {
		return $this->field( 'param' );
	}

	/**
	 * Why a card issuer declined, for a card error.
	 *
	 * @since 1.2.0
	 *
	 * @return string The decline code, or ''.
	 */
	public function decline_code(): string {
		return $this->field( 'decline_code' );
	}

	/**
	 * Stripe's own sentence about what went wrong.
	 *
	 * Written for the developer, not the customer: it names parameters and
	 * account settings. Log it; do not show it to a buyer.
	 *
	 * @since 1.2.0
	 *
	 * @return string The message, or ''.
	 */
	public function message(): string {
		return $this->field( 'message' );
	}

	/**
	 * One string field of the error object.
	 *
	 * @param string $name Field name.
	 *
	 * @return string
	 */
	private function field( string $name ): string {
		$value = $this->error[ $name ] ?? '';

		return is_scalar( $value ) ? (string) $value : '';
	}
}
