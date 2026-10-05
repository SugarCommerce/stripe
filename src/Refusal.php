<?php
/**
 * What kind of no Stripe said to a Checkout Session.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class Refusal
 *
 * The net under Cart. Cart checks everything Stripe is known to refuse
 * before Stripe is asked; this sorts what gets through anyway — a price
 * archived a minute ago, a seller who switched on tax collection before
 * setting Stripe Tax up, a rule Stripe adds next year — into a reason an
 * application can put into words, and the HTTP status that fits it.
 *
 * Matched on Stripe's `code` and `param` first and its message second,
 * because codes are documented and messages are not — but a message is all
 * some refusals carry.
 *
 * Nothing here is meant to be shown as Stripe wrote it. Stripe's wording
 * names parameters and account settings that mean nothing to a buyer, and
 * some of it says more about the seller's setup than a stranger should read.
 *
 * @since 1.2.0
 */
final readonly class Refusal {

	/**
	 * Patterns, in order: [what to look for in "code param message",
	 * reason, status].
	 *
	 * @since 1.2.0
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	public const array RULES = array(
		array( '/automatic_tax|stripe tax|tax settings|origin address|head office/i', 'tax_not_ready', 503 ),
		array( '/amount_too_small|amount must (?:be at least|convert to at least)/i', 'amount_too_small', 422 ),
		array( '/resource_missing.*price|no such price|price.*(inactive|not active|archived)|product.*(inactive|not active|archived)/i', 'unavailable', 422 ),
		array( '/interval/i', 'interval', 422 ),
		array( '/custom_unit_amount|customer.chosen|pay what you want/i', 'custom_alone', 422 ),
		array( '/currenc/i', 'currency', 422 ),
		array( '/promotion|discount|coupon/i', 'promotion', 422 ),
		array( '/line_items|optional_items|too many|maximum/i', 'too_many', 422 ),
		array( '/no such customer/i', 'customer', 422 ),
	);

	/**
	 * The reason for a refusal nothing above recognises: Stripe failed, or
	 * nothing came back to read.
	 *
	 * @since 1.2.0
	 */
	public const string UNKNOWN = 'stripe';

	/**
	 * Sort a refusal into a reason and a status.
	 *
	 * @since 1.2.0
	 *
	 * @param StripeError|array $error What attempt() threw, or Stripe's
	 *                                 `error` object, or [] when nothing
	 *                                 came back.
	 *
	 * @return array{reason: string, status: int} A reason from RULES and
	 *         its status, or UNKNOWN and 502.
	 */
	public static function classify( StripeError|array $error ): array {
		$fields = $error instanceof StripeError ? $error->error : $error;
		$said   = trim( implode( ' ', array_map(
			static fn( mixed $value ): string => is_scalar( $value ) ? (string) $value : '',
			array( $fields['code'] ?? '', $fields['param'] ?? '', $fields['message'] ?? '' )
		) ) );

		foreach ( '' === $said ? array() : self::RULES as [ $pattern, $reason, $status ] ) {
			if ( 1 === preg_match( $pattern, $said ) ) {
				return array( 'reason' => $reason, 'status' => $status );
			}
		}

		return array( 'reason' => self::UNKNOWN, 'status' => 502 );
	}
}
