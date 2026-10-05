<?php
/**
 * A Stripe client with nothing to call with.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class Keyless
 *
 * Refuses every call without making it. The base client would send it to
 * api.stripe.com with an empty bearer, wait for the 401, and log something
 * that reads like an outage — when the answer is a key missing from the
 * configuration, and saying so is the whole fix.
 *
 * Refuses the way the base client fails, so nothing downstream has to know
 * it is talking to one: reads that may fail and writes answer null, reads
 * that must succeed and attempt() throw. A webhook in a mode with no key
 * therefore fails and is retried until one is set, rather than finishing
 * with nothing.
 *
 * The reason is the caller's: an application knows the name of the setting
 * that is missing, and this package does not.
 *
 * @since 1.2.0
 */
final class Keyless extends Client {

	/**
	 * @since 1.2.0
	 *
	 * @param string $reason Why there is no key, as a sentence for the log,
	 *                       e.g. "no test-mode key is set (STRIPE_KEY_TEST)."
	 */
	public function __construct( public readonly string $reason ) {
		parent::__construct();
	}

	/**
	 * Never: there is nothing to call with.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param string|null $account Connected account id, or null.
	 * @param array       $query   Query parameters.
	 *
	 * @return array|null Always null.
	 */
	#[\Override]
	public function get( string $path, ?string $account = null, array $query = array() ): ?array {
		return $this->refuse( $path );
	}

	/**
	 * Never, and loudly.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param string|null $account Connected account id, or null.
	 * @param array       $query   Query parameters.
	 *
	 * @return array
	 *
	 * @throws StripeError Always.
	 */
	#[\Override]
	public function fetch( string $path, ?string $account = null, array $query = array() ): array {
		throw new StripeError( $path, 0, $this->reason );
	}

	/**
	 * Never: there is nothing to call with.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array|null Always null.
	 */
	#[\Override]
	#[\NoDiscard( 'Null means Stripe did not do it.' )]
	public function post( string $path, array $params, ?string $account = null ): ?array {
		return $this->refuse( $path );
	}

	/**
	 * Never, and loudly.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array
	 *
	 * @throws StripeError Always.
	 */
	#[\Override]
	#[\NoDiscard( 'The object is the only proof Stripe did it.' )]
	public function attempt( string $path, array $params, ?string $account = null ): array {
		throw new StripeError( $path, 0, $this->reason );
	}

	/**
	 * Log what was not called, and why.
	 *
	 * @param string $path Path below /v1.
	 *
	 * @return null
	 */
	private function refuse( string $path ): null {
		error_log( 'Stripe ' . $path . ' not called: ' . $this->reason );

		return null;
	}
}
