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

/**
 * Class StripeError
 *
 * Thrown by Client::fetch(), for callers where "no answer" must never be read
 * as "an empty answer". A webhook handler that lets a failed line-item read
 * look like a session with nothing in it finishes a paid order with nothing
 * in it, and acknowledges the event so Stripe never retries. Throwing turns
 * the same outage into a 500 and a retry.
 *
 * @since 1.0.0
 */
final class StripeError extends RuntimeException {

	/**
	 * @since 1.0.0
	 *
	 * @param string $path   Path below /v1 that was requested.
	 * @param int    $status HTTP status, 0 when the request never completed.
	 * @param string $body   Start of the response body, for the log.
	 */
	public function __construct(
		public readonly string $path,
		public readonly int $status,
		string $body = '',
	) {
		parent::__construct( sprintf( 'Stripe %s answered %d: %s', $path, $status, substr( $body, 0, 200 ) ) );
	}
}
