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
}
