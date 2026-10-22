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
	 * @param array<mixed> $subscription Subscription object.
	 *
	 * @return int|null Unix time, or null when the object carries none.
	 */
	public static function period_end( array $subscription ): ?int {
		$ends = array();

		foreach ( Fields::objects( Fields::at( $subscription, 'items', 'data' ) ) as $item ) {
			$end = Fields::int_or_null( $item['current_period_end'] ?? null );

			if ( null !== $end ) {
				$ends[] = $end;
			}
		}

		if ( $ends ) {
			return max( $ends );
		}

		return Fields::int_or_null( $subscription['current_period_end'] ?? null );
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
	 * @param array<mixed> $line Invoice line item.
	 *
	 * @return string Price id, or ''.
	 */
	public static function invoice_line_price( array $line ): string {
		$price = Fields::at( $line, 'pricing', 'price_details', 'price' );

		if ( is_string( $price ) ) {
			return $price;
		}

		if ( is_array( $price ) ) {
			return Fields::string( $price['id'] ?? null );
		}

		return Fields::string( Fields::at( $line, 'price', 'id' ) );
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
	 * @param array<mixed> $invoice Invoice object.
	 *
	 * @return list<string> Intent ids, possibly none.
	 */
	public static function invoice_payment_intents( array $invoice ): array {
		$intents = array();

		foreach ( Fields::objects( Fields::at( $invoice, 'payments', 'data' ) ) as $payment ) {
			$intent = self::id( Fields::at( $payment, 'payment', 'payment_intent' ) );

			if ( '' !== $intent ) {
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
	 * @param array<mixed> $line Invoice line item.
	 *
	 * @return string Product id, or ''.
	 */
	public static function invoice_line_product( array $line ): string {
		$product = Fields::at( $line, 'pricing', 'price_details', 'product' ) ?? Fields::at( $line, 'price', 'product' );

		return is_array( $product ) ? Fields::string( $product['id'] ?? null ) : Fields::string( $product );
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
	 * @param array<mixed> $price Price object, ideally with currency_options expanded.
	 *
	 * @return array<string, int>
	 */
	public static function amounts( array $price ): array {
		$amounts = array();

		foreach ( Fields::map( $price['currency_options'] ?? null ) as $code => $option ) {
			$amount = Fields::int_or_null( Fields::at( $option, 'unit_amount' ) );

			if ( null !== $amount ) {
				$amounts[ strtolower( (string) $code ) ] = $amount;
			}
		}

		$own = Fields::int_or_null( $price['unit_amount'] ?? null );

		if ( ! $amounts && null !== $own && is_scalar( $price['currency'] ?? null ) ) {
			$amounts[ strtolower( (string) $price['currency'] ) ] = $own;
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
	 * @param array<mixed> $price Price object.
	 *
	 * @return array{minimum: int|null, preset: int|null, maximum: int|null}|null
	 */
	public static function custom_amount( array $price ): ?array {
		$custom = $price['custom_unit_amount'] ?? null;

		if ( ! is_array( $custom ) ) {
			return null;
		}

		return array(
			'minimum' => Fields::int_or_null( $custom['minimum'] ?? null ),
			'preset'  => Fields::int_or_null( $custom['preset'] ?? null ),
			'maximum' => Fields::int_or_null( $custom['maximum'] ?? null ),
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
	 * @param array<mixed> $invoice Invoice object.
	 *
	 * @return string Subscription id, or ''.
	 */
	public static function invoice_subscription( array $invoice ): string {
		$current = self::id( Fields::at( $invoice, 'parent', 'subscription_details', 'subscription' ) );

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
	 * @param array<mixed> $subscription Subscription object.
	 *
	 * @return string Price id, or ''.
	 */
	public static function subscription_price( array $subscription ): string {
		return self::id( Fields::at( $subscription, 'items', 'data', 0, 'price' ) );
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
	 * @param array<mixed> $session Checkout Session.
	 *
	 * @return string
	 */
	public static function session_email( array $session ): string {
		$email = Fields::at( $session, 'customer_details', 'email' ) ?? $session['customer_email'] ?? '';

		return is_string( $email ) ? trim( $email ) : '';
	}

	/**
	 * Whether a completed Checkout Session's money has arrived.
	 *
	 * `completed` does not mean paid. A bank debit, a voucher or any
	 * delayed-notification method completes the session immediately and
	 * settles days later; `async_payment_succeeded` is the event that says
	 * it did. A session with nothing to pay — a free trial, a 100% coupon —
	 * is as paid as it will ever be.
	 *
	 * A payload with no `payment_status` is not paid. Every API version a
	 * Connect platform can receive carries the field; one without it is a
	 * hand-made or truncated payload, and reading that as paid hands over
	 * what was bought for money nobody has seen.
	 *
	 * @since 1.2.0
	 * @since 1.5.0 A missing `payment_status` is unpaid, not paid.
	 *
	 * @param array<mixed> $session Checkout Session.
	 *
	 * @return bool
	 */
	public static function session_paid( array $session ): bool {
		return in_array( $session['payment_status'] ?? null, array( 'paid', 'no_payment_required' ), true );
	}

	/**
	 * What the buyer typed into one of a session's custom fields, or ''.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed>  $session Checkout Session.
	 * @param string $key     The field's key, as it was created.
	 *
	 * @return string The value, trimmed; '' when absent or not a text field.
	 */
	public static function custom_field( array $session, string $key ): string {
		foreach ( Fields::objects( $session['custom_fields'] ?? null ) as $field ) {
			if ( $key === ( $field['key'] ?? null ) ) {
				$type  = Fields::string( $field['type'] ?? null, 'text' );
				$value = Fields::at( $field, $type, 'value' ) ?? Fields::at( $field, 'text', 'value' ) ?? '';

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
	 * @param array<mixed> $session Checkout Session.
	 *
	 * @return bool|null True for opt-in, false for opt-out, null when not asked.
	 */
	public static function promotions_consent( array $session ): ?bool {
		return match ( Fields::at( $session, 'consent', 'promotions' ) ) {
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
	 * says nothing: a goodwill £1 back is not the sale undone. Nothing
	 * refunded is never a full refund, even of a charge for nothing.
	 *
	 * @since 1.2.0
	 * @since 1.5.0 A charge with nothing refunded is not fully refunded.
	 *
	 * @param array<mixed> $charge Charge object.
	 *
	 * @return bool
	 */
	public static function fully_refunded( array $charge ): bool {
		$amount   = Fields::int_or_null( $charge['amount'] ?? null );
		$refunded = Fields::int_or_null( $charge['amount_refunded'] ?? null );

		return ! empty( $charge['refunded'] )
			|| ( null !== $amount && null !== $refunded && $refunded > 0 && $refunded >= $amount );
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
	 * @param array<mixed> $dispute Dispute object.
	 *
	 * @return bool
	 */
	public static function dispute_is_inquiry( array $dispute ): bool {
		return str_starts_with( Fields::string( $dispute['status'] ?? null ), 'warning_' );
	}

	/* ─── Connected accounts ─── */

	/**
	 * Why Stripe disabled an account, or null when it has not.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $account Account object.
	 *
	 * @return string|null E.g. `requirements.past_due`, `rejected.fraud`.
	 */
	public static function account_disabled_reason( array $account ): ?string {
		$reason = Fields::at( $account, 'requirements', 'disabled_reason' );

		return is_string( $reason ) && '' !== $reason ? $reason : null;
	}

	/**
	 * Whether the business was rejected — fraud, the terms, a listed party,
	 * by Stripe or by a platform the account belongs to — rather than paused
	 * over overdue paperwork.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $account Account object.
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
	 * @param array<mixed> $entitlement Active entitlement object.
	 *
	 * @return array{id: string, name: string, lookup_key: string}
	 */
	public static function entitlement_feature( array $entitlement ): array {
		$feature = $entitlement['feature'] ?? '';

		return array(
			'id'         => self::id( $feature ),
			'name'       => is_array( $feature ) ? Fields::string( $feature['name'] ?? null ) : '',
			'lookup_key' => Fields::string( $entitlement['lookup_key'] ?? ( is_array( $feature ) ? ( $feature['lookup_key'] ?? null ) : null ) ),
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
	 * @param array<mixed> $price Price object, with `currency_options` and `tiers`
	 *                     expanded where possible.
	 *
	 * @return array{id: string, nickname: string, amount: int|null, currency: string, recurring: string, interval_count: int, usage: string, tiered: bool, tiers_mode: string, tiers: list<array{up_to: int|null, unit: int|null, flat: int|null}>, currencies: list<string>, amounts: array<string, int>, custom: array{minimum: int|null, preset: int|null, maximum: int|null}|null}
	 */
	public static function price( array $price ): array {
		return array(
			'id'             => Fields::string( $price['id'] ?? null ),
			'nickname'       => Fields::string( $price['nickname'] ?? null ),
			'amount'         => Fields::int_or_null( $price['unit_amount'] ?? null ),
			'currency'       => strtolower( Fields::string( $price['currency'] ?? null ) ),
			'recurring'      => Fields::string( Fields::at( $price, 'recurring', 'interval' ) ),
			// Every three months is not every month. Stripe refuses a
			// subscription whose items renew on different schedules, and
			// the interval alone cannot tell them apart.
			'interval_count' => max( 1, Fields::int( Fields::at( $price, 'recurring', 'interval_count' ), 1 ) ),
			// Metered prices bill for usage reported later, so a cart has
			// to be able to see them to refuse them.
			'usage'          => Fields::string( Fields::at( $price, 'recurring', 'usage_type' ), 'licensed' ),
			'tiered'         => 'tiered' === ( $price['billing_scheme'] ?? '' ),
			'tiers_mode'     => Fields::string( $price['tiers_mode'] ?? null ),
			'tiers'          => self::tiers( Fields::map( $price['tiers'] ?? null ) ),
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
	 * @param array<mixed> $tiers Stripe tiers, from a price with `tiers` expanded.
	 *
	 * @return list<array{up_to: int|null, unit: int|null, flat: int|null}>
	 */
	public static function tiers( array $tiers ): array {
		return array_map(
			static fn( array $tier ): array => array(
				'up_to' => Fields::int_or_null( $tier['up_to'] ?? null ),
				'unit'  => Fields::int_or_null( $tier['unit_amount'] ?? null ),
				'flat'  => Fields::int_or_null( $tier['flat_amount'] ?? null ),
			),
			Fields::objects( $tiers )
		);
	}

	/**
	 * The currencies a price can be charged in, lower case, its own first.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $price Price object, ideally with currency_options expanded.
	 *
	 * @return list<string>
	 */
	public static function currencies( array $price ): array {
		$codes = array( strtolower( Fields::string( $price['currency'] ?? null ) ) );

		foreach ( array_keys( Fields::map( $price['currency_options'] ?? null ) ) as $code ) {
			$codes[] = strtolower( (string) $code );
		}

		return array_values( array_unique( array_filter( $codes ) ) );
	}
}
