<?php
/**
 * A webhook request that is not a genuine Stripe event.
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
 * Class InvalidEvent
 *
 * Thrown by Event::receive(). The message says why — a bad signature, a stale
 * timestamp, a body that is not an event — and is safe to answer with: it
 * describes the request, never the secret.
 *
 * Answer 400. Stripe retries anything else, and a request that failed its
 * signature will fail it again.
 *
 * @since 1.0.0
 */
final class InvalidEvent extends RuntimeException {}
