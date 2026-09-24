<?php
/**
 * Where a field lives in the Stripe API version we pin.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class Shape
 *
 * Stripe moves fields between API versions, and a field that moved reads as
 * null rather than failing — which is how `current_period_end` went missing
 * from every subscription when the pinned version passed 2025-03-31.basil,
 * switching off the billing grace window and the lapsed-subscription sweep
 * without a single error.
 *
 * So every read of a field that has moved goes through here, and reads the
 * current location first and the old one second. The old one stays because
 * stored payloads and test fixtures still carry it.
 *
 * Also here: the reads that are easy to get subtly wrong even where nothing
 * moved — a multi-currency price's amounts, a pay-what-you-want price's
 * bounds.
 *
 * @since 1.0.0
 */
final class Shape {

	/**
	 * When a subscription's current period ends.
	 *
	 * Per item since basil: a subscription's items can renew on different
	 * days, so the subscription as a whole is paid up until the latest of
	 * them. The top-level field is the pre-basil location.
	 *
	 * @since 1.0.0
	 *
	 * @param array $subscription Subscription object.
	 *
	 * @return int|null Unix time, or null when the object carries none.
	 */
	public static function period_end( array $subscription ): ?int {
		$ends = array();

		foreach ( $subscription['items']['data'] ?? array() as $item ) {
			if ( isset( $item['current_period_end'] ) ) {
				$ends[] = (int) $item['current_period_end'];
			}
		}

		if ( $ends ) {
			return max( $ends );
		}

		return isset( $subscription['current_period_end'] ) ? (int) $subscription['current_period_end'] : null;
	}

	/**
	 * The price id behind an invoice line.
	 *
	 * Since basil a line says `pricing.price_details.price`, and that value
	 * is an id string, not an object. The code once read `['id']` from it and
	 * got '' for every line, so files attached to a specific price never
	 * reached a subscriber. `price.id` is the pre-basil location.
	 *
	 * @since 1.0.0
	 *
	 * @param array $line Invoice line item.
	 *
	 * @return string Price id, or ''.
	 */
	public static function invoice_line_price( array $line ): string {
		$price = $line['pricing']['price_details']['price'] ?? null;

		if ( is_string( $price ) ) {
			return $price;
		}

		if ( is_array( $price ) ) {
			return (string) ( $price['id'] ?? '' );
		}

		return (string) ( $line['price']['id'] ?? '' );
	}

	/**
	 * The payment intents that paid an invoice.
	 *
	 * Basil removed `payment_intent` from the invoice; payments are listed
	 * under `payments`, each with `payment.payment_intent`. The same list
	 * shape is what `GET /v1/invoice_payments?invoice=` returns, so either
	 * can be handed in as `payments`.
	 *
	 * @since 1.0.0
	 *
	 * @param array $invoice Invoice object.
	 *
	 * @return string[] Intent ids, possibly none.
	 */
	public static function invoice_payment_intents( array $invoice ): array {
		$intents = array();

		foreach ( $invoice['payments']['data'] ?? array() as $payment ) {
			$intent = $payment['payment']['payment_intent'] ?? null;

			if ( is_array( $intent ) ) {
				$intent = $intent['id'] ?? null;
			}

			if ( is_string( $intent ) && '' !== $intent ) {
				$intents[] = $intent;
			}
		}

		if ( ! $intents && is_string( $invoice['payment_intent'] ?? null ) && '' !== $invoice['payment_intent'] ) {
			$intents[] = $invoice['payment_intent'];
		}

		return array_values( array_unique( $intents ) );
	}

	/**
	 * The product id behind an invoice line.
	 *
	 * @since 1.0.0
	 *
	 * @param array $line Invoice line item.
	 *
	 * @return string Product id, or ''.
	 */
	public static function invoice_line_product( array $line ): string {
		$product = $line['pricing']['price_details']['product'] ?? $line['price']['product'] ?? '';

		return is_array( $product ) ? (string) ( $product['id'] ?? '' ) : (string) $product;
	}

	/**
	 * What a price costs in each currency it supports.
	 *
	 * Keyed by lower-case code, in minor units, exactly as Stripe reports
	 * them. A seller who prices something at £25 and €30 has made a pricing
	 * decision, not a rounding error, so nothing here converts.
	 *
	 * `currency_options` only comes back when it is expanded. Without it a
	 * price set in four currencies looks like a price in one, so the price's
	 * own amount is the fallback rather than the answer.
	 *
	 * @since 1.0.0
	 *
	 * @param array $price Price object, ideally with currency_options expanded.
	 *
	 * @return array<string, int>
	 */
	public static function amounts( array $price ): array {
		$amounts = array();

		foreach ( (array) ( $price['currency_options'] ?? array() ) as $code => $option ) {
			if ( isset( $option['unit_amount'] ) ) {
				$amounts[ strtolower( (string) $code ) ] = (int) $option['unit_amount'];
			}
		}

		if ( ! $amounts && isset( $price['unit_amount'], $price['currency'] ) ) {
			$amounts[ strtolower( (string) $price['currency'] ) ] = (int) $price['unit_amount'];
		}

		return $amounts;
	}

	/**
	 * The bounds of a pay-what-you-want price, or null for a fixed one.
	 *
	 * The buyer types the amount into Checkout, so such a price has no
	 * `unit_amount` at all — a page that reads only that renders the row
	 * with no number on it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $price Price object.
	 *
	 * @return array{minimum: int|null, preset: int|null, maximum: int|null}|null
	 */
	public static function custom_amount( array $price ): ?array {
		$custom = $price['custom_unit_amount'] ?? null;

		if ( ! is_array( $custom ) ) {
			return null;
		}

		return array(
			'minimum' => isset( $custom['minimum'] ) ? (int) $custom['minimum'] : null,
			'preset'  => isset( $custom['preset'] ) ? (int) $custom['preset'] : null,
			'maximum' => isset( $custom['maximum'] ) ? (int) $custom['maximum'] : null,
		);
	}
}
