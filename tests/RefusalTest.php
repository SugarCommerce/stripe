<?php
/**
 * Refusal test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\{Refusal, StripeError};

/**
 * Class RefusalTest
 *
 * What Stripe refused, sorted into something an application can say.
 */
final class RefusalTest extends TestCase {

	/**
	 * @return array<string, array{array, string, int}>
	 */
	public static function refusals(): array {
		return array(
			'tax not set up'   => array( array( 'param' => 'automatic_tax[enabled]', 'message' => 'Stripe Tax has not been activated on your account.' ), 'tax_not_ready', 503 ),
			'too small'        => array( array( 'code' => 'amount_too_small', 'message' => 'Amount must convert to at least 50 pence.' ), 'amount_too_small', 422 ),
			'archived price'   => array( array( 'code' => 'resource_missing', 'param' => 'line_items[0][price]', 'message' => 'No such price: price_x' ), 'unavailable', 422 ),
			'inactive product' => array( array( 'message' => 'The product prod_x is not active.' ), 'unavailable', 422 ),
			'two schedules'    => array( array( 'message' => 'All prices must have the same billing interval.' ), 'interval', 422 ),
			'custom amount'    => array( array( 'param' => 'line_items[0][price_data][custom_unit_amount]' ), 'custom_alone', 422 ),
			'currency'         => array( array( 'message' => 'The price is not available in the currency eur.' ), 'currency', 422 ),
			'coupon'           => array( array( 'param' => 'discounts[0][coupon]' ), 'promotion', 422 ),
			'too many'         => array( array( 'param' => 'line_items', 'message' => 'Too many line items.' ), 'too_many', 422 ),
			'customer'         => array( array( 'message' => 'No such customer: cus_x' ), 'customer', 422 ),
			'tax failed'       => array( array( 'code' => 'failed_tax_calculation', 'message' => 'Stripe Tax could not calculate tax for this payment.' ), Refusal::UNKNOWN, 502 ),
			'something new'    => array( array( 'message' => 'Something Stripe adds next year.' ), Refusal::UNKNOWN, 502 ),
			'nothing at all'   => array( array(), Refusal::UNKNOWN, 502 ),
		);
	}

	#[DataProvider( 'refusals' )]
	public function test_a_refusal_is_sorted( array $error, string $reason, int $status ): void {
		$this->assertSame( array( 'reason' => $reason, 'status' => $status ), Refusal::classify( $error ) );
	}

	public function test_a_stripe_error_is_sorted_by_its_error_object(): void {
		$error = new StripeError( 'checkout/sessions', 400, '', array( 'code' => 'resource_missing', 'message' => 'No such price: price_x' ) );

		$this->assertSame( 'unavailable', Refusal::classify( $error )['reason'] );
	}

	/**
	 * A transport failure has nothing to read, which is Stripe failing, not
	 * Stripe refusing.
	 */
	public function test_an_outage_is_unknown(): void {
		$this->assertSame( 502, Refusal::classify( new StripeError( 'checkout/sessions', 0, 'timed out' ) )['status'] );
	}
}
