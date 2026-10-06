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
 * bounds, an invoice's subscription, a refund that is only partial.
 *
 * @since 1.0.0
 */
final class Shape {

	/**
	 * Disabled reasons that mean the business itself was rejected, as
	 * opposed to waiting for paperwork.
	 *
	 * The `platform_` three are the same rejections made by a Connect
	 * platform rather than by Stripe. Before 2026-09-30.endive they read as
	 * `rejected.fraud`, `rejected.terms_of_service` and `rejected.other`, so
	 * a list without them would quietly stop seeing rejections the moment
	 * the pinned version passed endive. Kept in one list because that is
	 * what every reader of the old values was already treating as one;
	 * PLATFORM_REJECTED is there for a reader that wants to tell them apart.
	 *
	 * @since 1.2.0
	 * @since 1.3.0 Includes the platform rejections endive split out.
	 */
	public const array REJECTED = array(
		'rejected.fraud',
		'rejected.terms_of_service',
		'rejected.listed',
		'rejected.other',
		'rejected.platform_fraud',
		'rejected.platform_terms_of_service',
		'rejected.platform_other',
	);

	/**
	 * Disabled reasons for a rejection made by a Connect platform, not by
	 * Stripe. Only distinguishable since 2026-09-30.endive.
	 *
	 * @since 1.3.0
	 */
	public const array PLATFORM_REJECTED = array(
		'rejected.platform_fraud',
		'rejected.platform_terms_of_service',
		'rejected.platform_other',
	);

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

	/* ─── Ids and expansion ─── */

	/**
	 * The id of an expandable field, expanded or not.
	 *
	 * Stripe answers `"product": "prod_1"` or `"product": {"id": "prod_1", …}`
	 * depending on what was asked to be expanded, and a caller that reads
	 * only one of them gets '' or an "Array to string conversion" for the
	 * other.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $field An id, an expanded object, or nothing.
	 *
	 * @return string The id, or ''.
	 */
	public static function id( mixed $field ): string {
		if ( is_array( $field ) ) {
			$field = $field['id'] ?? '';
		}

		return is_string( $field ) ? $field : '';
	}

	/* ─── Invoices and subscriptions ─── */

	/**
	 * The subscription an invoice bills, or ''.
	 *
	 * Since dahlia under `parent.subscription_details.subscription`; the
	 * top-level `subscription` is where earlier versions put it. A handler
	 * that read only the old field logged every failed renewal as "not a
	 * subscription".
	 *
	 * @since 1.2.0
	 *
	 * @param array $invoice Invoice object.
	 *
	 * @return string Subscription id, or ''.
	 */
	public static function invoice_subscription( array $invoice ): string {
		$current = self::id( $invoice['parent']['subscription_details']['subscription'] ?? null );

		return '' !== $current ? $current : self::id( $invoice['subscription'] ?? null );
	}

	/**
	 * The price of a subscription's first item, or ''.
	 *
	 * For a subscription that is one plan — which is what a platform
	 * billing its own sellers sells. A subscription of several items has
	 * several prices; read `items.data` for those.
	 *
	 * @since 1.2.0
	 *
	 * @param array $subscription Subscription object.
	 *
	 * @return string Price id, or ''.
	 */
	public static function subscription_price( array $subscription ): string {
		return self::id( $subscription['items']['data'][0]['price'] ?? null );
	}

	/* ─── Checkout Sessions ─── */

	/**
	 * The buyer's email on a Checkout Session, or ''.
	 *
	 * `customer_details.email` is what they typed at Checkout;
	 * `customer_email` is what the session was created with, and the only
	 * one present on a session that has not completed.
	 *
	 * @since 1.2.0
	 *
	 * @param array $session Checkout Session.
	 *
	 * @return string
	 */
	public static function session_email( array $session ): string {
		$email = $session['customer_details']['email'] ?? $session['customer_email'] ?? '';

		return is_string( $email ) ? trim( $email ) : '';
	}

	/**
	 * Whether a completed Checkout Session's money has arrived.
	 *
	 * `completed` does not mean paid. A bank debit, a voucher or any
	 * delayed-notification method completes the session immediately and
	 * settles days later; `async_payment_succeeded` is the event that says
	 * it did. A session with nothing to pay — a free trial, a 100% coupon —
	 * is as paid as it will ever be. A payload with no `payment_status` at
	 * all predates the field and was paid.
	 *
	 * @since 1.2.0
	 *
	 * @param array $session Checkout Session.
	 *
	 * @return bool
	 */
	public static function session_paid( array $session ): bool {
		return in_array( $session['payment_status'] ?? 'paid', array( 'paid', 'no_payment_required' ), true );
	}

	/**
	 * What the buyer typed into one of a session's custom fields, or ''.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $session Checkout Session.
	 * @param string $key     The field's key, as it was created.
	 *
	 * @return string The value, trimmed; '' when absent or not a text field.
	 */
	public static function custom_field( array $session, string $key ): string {
		foreach ( (array) ( $session['custom_fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && $key === ( $field['key'] ?? null ) ) {
				$type  = (string) ( $field['type'] ?? 'text' );
				$value = $field[ $type ]['value'] ?? $field['text']['value'] ?? '';

				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		return '';
	}

	/**
	 * The buyer's answer to Checkout's marketing-consent checkbox.
	 *
	 * Three answers, not two: a buyer who was never shown the box — Stripe
	 * shows it only where the law asks — has not said no, and a record that
	 * says they did is wrong in the other direction.
	 *
	 * @since 1.2.0
	 *
	 * @param array $session Checkout Session.
	 *
	 * @return bool|null True for opt-in, false for opt-out, null when not asked.
	 */
	public static function promotions_consent( array $session ): ?bool {
		return match ( $session['consent']['promotions'] ?? null ) {
			'opt_in'  => true,
			'opt_out' => false,
			default   => null,
		};
	}

	/* ─── Charges and disputes ─── */

	/**
	 * Whether a charge has been refunded in full.
	 *
	 * `charge.refunded` fires for partial refunds too, so the event alone
	 * says nothing: a goodwill £1 back is not the sale undone.
	 *
	 * @since 1.2.0
	 *
	 * @param array $charge Charge object.
	 *
	 * @return bool
	 */
	public static function fully_refunded( array $charge ): bool {
		return ! empty( $charge['refunded'] )
			|| ( isset( $charge['amount'], $charge['amount_refunded'] ) && (int) $charge['amount_refunded'] >= (int) $charge['amount'] );
	}

	/**
	 * Whether a dispute is an inquiry rather than a chargeback.
	 *
	 * The `warning_*` statuses are the card network asking a question; most
	 * close with no money moving. Treating one as a chargeback punishes a
	 * buyer who did nothing but ask their bank.
	 *
	 * @since 1.2.0
	 *
	 * @param array $dispute Dispute object.
	 *
	 * @return bool
	 */
	public static function dispute_is_inquiry( array $dispute ): bool {
		return str_starts_with( (string) ( $dispute['status'] ?? '' ), 'warning_' );
	}

	/* ─── Connected accounts ─── */

	/**
	 * Why Stripe disabled an account, or null when it has not.
	 *
	 * @since 1.2.0
	 *
	 * @param array $account Account object.
	 *
	 * @return string|null E.g. `requirements.past_due`, `rejected.fraud`.
	 */
	public static function account_disabled_reason( array $account ): ?string {
		$reason = $account['requirements']['disabled_reason'] ?? null;

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Whether the business was rejected — fraud, the terms, a listed party,
	 * by Stripe or by a platform the account belongs to — rather than paused
	 * over overdue paperwork.
	 *
	 * @since 1.2.0
	 *
	 * @param array $account Account object.
	 *
	 * @return bool
	 */
	public static function account_rejected( array $account ): bool {
		return in_array( self::account_disabled_reason( $account ), self::REJECTED, true );
	}

	/* ─── Entitlements ─── */

	/**
	 * The feature behind an active entitlement.
	 *
	 * `feature` is an id unless `data.feature` was expanded, and only the
	 * expanded form has a name.
	 *
	 * @since 1.2.0
	 *
	 * @param array $entitlement Active entitlement object.
	 *
	 * @return array{id: string, name: string, lookup_key: string}
	 */
	public static function entitlement_feature( array $entitlement ): array {
		$feature = $entitlement['feature'] ?? '';

		return array(
			'id'         => self::id( $feature ),
			'name'       => is_array( $feature ) ? (string) ( $feature['name'] ?? '' ) : '',
			'lookup_key' => (string) ( $entitlement['lookup_key'] ?? ( is_array( $feature ) ? ( $feature['lookup_key'] ?? '' ) : '' ) ),
		);
	}

	/* ─── Prices ─── */

	/**
	 * A price, flattened into what a page and the cart rules read.
	 *
	 * One shape for every caller, because two shapes of one price are two
	 * answers to "can these be bought together?" — and a hand copy of this
	 * array in a script had drifted, leaving out tiers and currencies.
	 *
	 * @since 1.2.0
	 *
	 * @param array $price Price object, with `currency_options` and `tiers`
	 *                     expanded where possible.
	 *
	 * @return array{id: string, nickname: string, amount: int|null, currency: string, recurring: string, interval_count: int, usage: string, tiered: bool, tiers_mode: string, tiers: array, currencies: string[], amounts: array<string, int>, custom: array|null}
	 */
	public static function price( array $price ): array {
		return array(
			'id'             => (string) ( $price['id'] ?? '' ),
			'nickname'       => (string) ( $price['nickname'] ?? '' ),
			'amount'         => isset( $price['unit_amount'] ) ? (int) $price['unit_amount'] : null,
			'currency'       => strtolower( (string) ( $price['currency'] ?? '' ) ),
			'recurring'      => isset( $price['recurring']['interval'] ) ? (string) $price['recurring']['interval'] : '',
			// Every three months is not every month. Stripe refuses a
			// subscription whose items renew on different schedules, and
			// the interval alone cannot tell them apart.
			'interval_count' => max( 1, (int) ( $price['recurring']['interval_count'] ?? 1 ) ),
			// Metered prices bill for usage reported later, so a cart has
			// to be able to see them to refuse them.
			'usage'          => (string) ( $price['recurring']['usage_type'] ?? 'licensed' ),
			'tiered'         => 'tiered' === ( $price['billing_scheme'] ?? '' ),
			'tiers_mode'     => (string) ( $price['tiers_mode'] ?? '' ),
			'tiers'          => self::tiers( (array) ( $price['tiers'] ?? array() ) ),
			// Not the keys of `amounts`: a pay-what-you-want or tiered price
			// has no fixed amount in any currency and can be charged in all
			// of its own.
			'currencies'     => self::currencies( $price ),
			'amounts'        => self::amounts( $price ),
			'custom'         => self::custom_amount( $price ),
		);
	}

	/**
	 * A tiered price's steps, in order, as a page can print them.
	 *
	 * `up_to` null is Stripe's "and everything above". The integer fields
	 * only: Stripe returns them beside the decimal ones, rounded to a minor
	 * unit, which is as precise as a page needs to be.
	 *
	 * @since 1.2.0
	 *
	 * @param array $tiers Stripe tiers, from a price with `tiers` expanded.
	 *
	 * @return array<int, array{up_to: int|null, unit: int|null, flat: int|null}>
	 */
	public static function tiers( array $tiers ): array {
		return array_values( array_map(
			static fn( array $tier ): array => array(
				'up_to' => isset( $tier['up_to'] ) ? (int) $tier['up_to'] : null,
				'unit'  => isset( $tier['unit_amount'] ) ? (int) $tier['unit_amount'] : null,
				'flat'  => isset( $tier['flat_amount'] ) ? (int) $tier['flat_amount'] : null,
			),
			array_filter( $tiers, 'is_array' )
		) );
	}

	/**
	 * The currencies a price can be charged in, lower case, its own first.
	 *
	 * @since 1.2.0
	 *
	 * @param array $price Price object, ideally with currency_options expanded.
	 *
	 * @return string[]
	 */
	public static function currencies( array $price ): array {
		$codes = array( strtolower( (string) ( $price['currency'] ?? '' ) ) );

		foreach ( array_keys( (array) ( $price['currency_options'] ?? array() ) ) as $code ) {
			$codes[] = strtolower( (string) $code );
		}

		return array_values( array_unique( array_filter( $codes ) ) );
	}
}
