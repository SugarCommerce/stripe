<?php
/**
 * Shape test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\Shape;

/**
 * Class ShapeTest
 *
 * Each field is read where the pinned API version puts it, and where the
 * versions before basil put it, because stored payloads still carry both.
 */
final class ShapeTest extends TestCase {

	public function test_the_period_end_is_read_from_the_items(): void {
		$this->assertSame( 200, Shape::period_end( array( 'items' => array( 'data' => array(
			array( 'current_period_end' => 100 ),
			array( 'current_period_end' => 200 ),
		) ) ) ) );
	}

	public function test_the_period_end_falls_back_to_the_old_location(): void {
		$this->assertSame( 300, Shape::period_end( array( 'current_period_end' => 300 ) ) );
		$this->assertNull( Shape::period_end( array() ) );
	}

	/**
	 * `price_details.price` is an id, not an object. Reading `['id']` off it
	 * gave '' for every line on every subscription invoice.
	 */
	public function test_an_invoice_line_price_is_an_id_string(): void {
		$line = array( 'pricing' => array( 'price_details' => array( 'price' => 'price_1', 'product' => 'prod_1' ) ) );

		$this->assertSame( 'price_1', Shape::invoice_line_price( $line ) );
		$this->assertSame( 'prod_1', Shape::invoice_line_product( $line ) );
	}

	public function test_an_invoice_line_falls_back_to_the_old_price_object(): void {
		$line = array( 'price' => array( 'id' => 'price_2', 'product' => array( 'id' => 'prod_2' ) ) );

		$this->assertSame( 'price_2', Shape::invoice_line_price( $line ) );
		$this->assertSame( 'prod_2', Shape::invoice_line_product( $line ) );
	}

	public function test_invoice_payment_intents_are_read_from_payments(): void {
		$invoice = array( 'payments' => array( 'data' => array(
			array( 'payment' => array( 'payment_intent' => 'pi_1' ) ),
			array( 'payment' => array( 'payment_intent' => array( 'id' => 'pi_2' ) ) ),
			array( 'payment' => array( 'payment_intent' => 'pi_1' ) ),
		) ) );

		$this->assertSame( array( 'pi_1', 'pi_2' ), Shape::invoice_payment_intents( $invoice ) );
	}

	public function test_invoice_payment_intents_fall_back_to_the_old_field(): void {
		$this->assertSame( array( 'pi_9' ), Shape::invoice_payment_intents( array( 'payment_intent' => 'pi_9' ) ) );
		$this->assertSame( array(), Shape::invoice_payment_intents( array( 'payment_intent' => null ) ) );
	}

	public function test_amounts_cover_every_currency_option(): void {
		$price = array(
			'unit_amount'      => 2500,
			'currency'         => 'gbp',
			'currency_options' => array(
				'gbp' => array( 'unit_amount' => 2500 ),
				'EUR' => array( 'unit_amount' => 3000 ),
				'usd' => array( 'tiers_mode' => 'graduated' ),
			),
		);

		$this->assertSame( array( 'gbp' => 2500, 'eur' => 3000 ), Shape::amounts( $price ) );
	}

	/**
	 * Fetched without the expansion, a price still has its own amount.
	 */
	public function test_amounts_fall_back_to_the_price_itself(): void {
		$this->assertSame( array( 'jpy' => 500 ), Shape::amounts( array( 'unit_amount' => 500, 'currency' => 'JPY' ) ) );
		$this->assertSame( array(), Shape::amounts( array( 'currency' => 'gbp' ) ) );
	}

	public function test_a_pay_what_you_want_price_has_bounds(): void {
		$this->assertSame(
			array( 'minimum' => 100, 'preset' => 500, 'maximum' => null ),
			Shape::custom_amount( array( 'custom_unit_amount' => array( 'minimum' => 100, 'preset' => 500, 'enabled' => true ) ) )
		);
		$this->assertNull( Shape::custom_amount( array( 'unit_amount' => 100 ) ) );
	}

	/* ─── Ids ─── */

	public function test_an_id_is_read_expanded_or_not(): void {
		$this->assertSame( 'prod_1', Shape::id( 'prod_1' ) );
		$this->assertSame( 'prod_1', Shape::id( array( 'id' => 'prod_1', 'name' => 'x' ) ) );
		$this->assertSame( '', Shape::id( null ) );
		$this->assertSame( '', Shape::id( array( 'name' => 'no id' ) ) );
	}

	/* ─── Invoices and subscriptions ─── */

	/**
	 * Since dahlia the subscription is under `parent`. Reading only the old
	 * field logged every failed renewal as "not a subscription".
	 */
	public function test_an_invoice_subscription_is_read_from_its_parent(): void {
		$this->assertSame( 'sub_1', Shape::invoice_subscription( array(
			'parent' => array( 'type' => 'subscription_details', 'subscription_details' => array( 'subscription' => 'sub_1' ) ),
		) ) );
	}

	public function test_an_invoice_subscription_falls_back_to_the_old_field(): void {
		$this->assertSame( 'sub_2', Shape::invoice_subscription( array( 'subscription' => 'sub_2' ) ) );
		$this->assertSame( 'sub_3', Shape::invoice_subscription( array( 'subscription' => array( 'id' => 'sub_3' ) ) ) );
		$this->assertSame( '', Shape::invoice_subscription( array( 'parent' => null ) ) );
	}

	public function test_a_subscription_price_is_its_first_items(): void {
		$this->assertSame( 'price_1', Shape::subscription_price( array( 'items' => array( 'data' => array( array( 'price' => array( 'id' => 'price_1' ) ) ) ) ) ) );
		$this->assertSame( '', Shape::subscription_price( array() ) );
	}

	/* ─── Checkout Sessions ─── */

	public function test_a_session_email_prefers_what_the_buyer_typed(): void {
		$this->assertSame( 'typed@example.com', Shape::session_email( array( 'customer_details' => array( 'email' => 'typed@example.com' ), 'customer_email' => 'given@example.com' ) ) );
		$this->assertSame( 'given@example.com', Shape::session_email( array( 'customer_email' => 'given@example.com' ) ) );
		$this->assertSame( '', Shape::session_email( array() ) );
	}

	/**
	 * `completed` is not paid: a bank debit completes now and settles later.
	 */
	public function test_a_session_is_paid_only_when_the_money_is_in(): void {
		$this->assertTrue( Shape::session_paid( array( 'payment_status' => 'paid' ) ) );
		$this->assertTrue( Shape::session_paid( array( 'payment_status' => 'no_payment_required' ) ) );
		$this->assertFalse( Shape::session_paid( array() ), 'No status is not proof of payment.' );
		$this->assertFalse( Shape::session_paid( array( 'payment_status' => null ) ) );
		$this->assertFalse( Shape::session_paid( array( 'payment_status' => 'unpaid' ) ) );
	}

	public function test_a_custom_field_is_read_by_key(): void {
		$session = array( 'custom_fields' => array(
			array( 'key' => 'other', 'type' => 'text', 'text' => array( 'value' => 'no' ) ),
			array( 'key' => 'giftemail', 'type' => 'text', 'text' => array( 'value' => ' friend@example.com ' ) ),
			array( 'key' => 'size', 'type' => 'dropdown', 'dropdown' => array( 'value' => 'large' ) ),
		) );

		$this->assertSame( 'friend@example.com', Shape::custom_field( $session, 'giftemail' ) );
		$this->assertSame( 'large', Shape::custom_field( $session, 'size' ) );
		$this->assertSame( '', Shape::custom_field( $session, 'missing' ) );
	}

	/**
	 * Not asked is not "no".
	 */
	public function test_promotions_consent_has_three_answers(): void {
		$this->assertTrue( Shape::promotions_consent( array( 'consent' => array( 'promotions' => 'opt_in' ) ) ) );
		$this->assertFalse( Shape::promotions_consent( array( 'consent' => array( 'promotions' => 'opt_out' ) ) ) );
		$this->assertNull( Shape::promotions_consent( array( 'consent' => null ) ) );
	}

	/* ─── Charges, disputes, accounts ─── */

	public function test_a_partial_refund_is_not_a_full_one(): void {
		$this->assertTrue( Shape::fully_refunded( array( 'refunded' => true ) ) );
		$this->assertTrue( Shape::fully_refunded( array( 'amount' => 900, 'amount_refunded' => 900 ) ) );
		$this->assertFalse( Shape::fully_refunded( array( 'amount' => 900, 'amount_refunded' => 100 ) ) );
	}

	public function test_a_warning_dispute_is_an_inquiry(): void {
		$this->assertTrue( Shape::dispute_is_inquiry( array( 'status' => 'warning_needs_response' ) ) );
		$this->assertFalse( Shape::dispute_is_inquiry( array( 'status' => 'needs_response' ) ) );
	}

	public function test_a_rejected_account_is_told_from_one_waiting_for_paperwork(): void {
		$rejected = array( 'requirements' => array( 'disabled_reason' => 'rejected.fraud' ) );
		$waiting  = array( 'requirements' => array( 'disabled_reason' => 'requirements.past_due' ) );

		$this->assertSame( 'rejected.fraud', Shape::account_disabled_reason( $rejected ) );
		$this->assertTrue( Shape::account_rejected( $rejected ) );
		$this->assertFalse( Shape::account_rejected( $waiting ) );
		$this->assertNull( Shape::account_disabled_reason( array( 'requirements' => array( 'disabled_reason' => '' ) ) ) );
	}

	/**
	 * Since endive a platform's rejection has its own values, which read as
	 * `rejected.fraud` and the rest before. Missing them would stop seeing
	 * those rejections at the version bump, without an error.
	 */
	public function test_a_platform_rejection_is_still_a_rejection(): void {
		foreach ( array( 'rejected.platform_fraud', 'rejected.platform_terms_of_service', 'rejected.platform_other' ) as $reason ) {
			$this->assertTrue( Shape::account_rejected( array( 'requirements' => array( 'disabled_reason' => $reason ) ) ), $reason );
			$this->assertContains( $reason, Shape::PLATFORM_REJECTED );
		}

		$this->assertSame( array(), array_diff( Shape::PLATFORM_REJECTED, Shape::REJECTED ) );
		$this->assertNotContains( 'rejected.fraud', Shape::PLATFORM_REJECTED, 'Stripe\'s own rejection is not a platform\'s.' );
	}

	public function test_an_entitlement_feature_is_read_expanded_or_not(): void {
		$this->assertSame(
			array( 'id' => 'feat_1', 'name' => 'Pro', 'lookup_key' => 'pro' ),
			Shape::entitlement_feature( array( 'lookup_key' => 'pro', 'feature' => array( 'id' => 'feat_1', 'name' => 'Pro' ) ) )
		);
		$this->assertSame(
			array( 'id' => 'feat_2', 'name' => '', 'lookup_key' => 'basic' ),
			Shape::entitlement_feature( array( 'lookup_key' => 'basic', 'feature' => 'feat_2' ) )
		);
	}

	/* ─── Prices ─── */

	public function test_a_price_is_shaped_with_everything_a_cart_compares(): void {
		$shaped = Shape::price( array(
			'id'               => 'price_q',
			'nickname'         => 'Quarterly',
			'unit_amount'      => 2500,
			'currency'         => 'GBP',
			'recurring'        => array( 'interval' => 'month', 'interval_count' => 3, 'usage_type' => 'licensed' ),
			'currency_options' => array( 'gbp' => array( 'unit_amount' => 2500 ), 'eur' => array( 'unit_amount' => 3000 ) ),
		) );

		$this->assertSame( 'price_q', $shaped['id'] );
		$this->assertSame( 'gbp', $shaped['currency'] );
		$this->assertSame( 'month', $shaped['recurring'] );
		$this->assertSame( 3, $shaped['interval_count'] );
		$this->assertSame( array( 'gbp', 'eur' ), $shaped['currencies'] );
		$this->assertSame( array( 'gbp' => 2500, 'eur' => 3000 ), $shaped['amounts'] );
		$this->assertNull( $shaped['custom'] );
		$this->assertFalse( $shaped['tiered'] );
	}

	public function test_a_one_off_price_has_no_schedule(): void {
		$shaped = Shape::price( array( 'id' => 'p', 'unit_amount' => 100, 'currency' => 'usd' ) );

		$this->assertSame( '', $shaped['recurring'] );
		$this->assertSame( 1, $shaped['interval_count'] );
		$this->assertSame( 'licensed', $shaped['usage'] );
	}

	public function test_tiers_keep_their_integer_amounts_in_order(): void {
		$this->assertSame(
			array(
				array( 'up_to' => 5, 'unit' => 500, 'flat' => null ),
				array( 'up_to' => null, 'unit' => 400, 'flat' => 100 ),
			),
			Shape::tiers( array(
				array( 'up_to' => 5, 'unit_amount' => 500, 'unit_amount_decimal' => '500' ),
				'not a tier',
				array( 'up_to' => null, 'unit_amount' => 400, 'flat_amount' => 100 ),
			) )
		);
	}

	/**
	 * A pay-what-you-want price has no amount anywhere and can still be
	 * charged in every currency it names.
	 */
	public function test_currencies_do_not_depend_on_amounts(): void {
		$this->assertSame( array( 'usd', 'eur' ), Shape::currencies( array( 'currency' => 'USD', 'currency_options' => array( 'eur' => array(), 'usd' => array() ) ) ) );
		$this->assertSame( array(), Shape::currencies( array() ) );
	}

	/* ─── Loose input (1.5.0) ───────────────────────────────────────── */

	/**
	 * A field of the wrong type reads as absent, not as "Array" with a
	 * warning, and not as 0 where 0 would mean free.
	 */
	public function test_a_field_of_the_wrong_type_reads_as_absent(): void {
		$price = Shape::price( array( 'id' => array( 'price_1' ), 'currency' => array( 'usd' ), 'unit_amount' => 'twelve', 'recurring' => 'month' ) );

		$this->assertSame( '', $price['id'] );
		$this->assertSame( '', $price['currency'] );
		$this->assertNull( $price['amount'] );
		$this->assertSame( '', $price['recurring'] );
		$this->assertSame( 1200, Shape::price( array( 'unit_amount' => '1200' ) )['amount'] );
	}

	public function test_nothing_refunded_is_not_a_full_refund(): void {
		$this->assertFalse( Shape::fully_refunded( array( 'amount' => 0, 'amount_refunded' => 0, 'refunded' => false ) ) );
		$this->assertTrue( Shape::fully_refunded( array( 'amount' => 500, 'amount_refunded' => 500 ) ) );
	}
}
