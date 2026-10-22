<?php
/**
 * Cart test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\Cart;

/**
 * Class CartTest
 *
 * Every rule Stripe enforces on a Checkout Session's items, answered as a
 * code before Stripe is asked.
 */
final class CartTest extends TestCase {

	/**
	 * A shaped price.
	 *
	 * @param string $id    Price id.
	 * @param array  $extra Overrides.
	 *
	 * @return array
	 */
	private static function price( string $id, array $extra = array() ): array {
		return $extra + array(
			'id'             => $id,
			'product'        => 'prod_' . $id,
			'amount'         => 900,
			'currency'       => 'gbp',
			'recurring'      => '',
			'interval_count' => 1,
			'usage'          => 'licensed',
			'currencies'     => array( 'gbp' ),
			'amounts'        => array( 'gbp' => 900 ),
			'custom'         => null,
		);
	}

	private static function monthly( string $id, array $extra = array() ): array {
		return self::price( $id, $extra + array( 'recurring' => 'month' ) );
	}

	private static function custom( string $id, array $extra = array() ): array {
		return self::price( $id, $extra + array( 'amount' => null, 'amounts' => array(), 'custom' => array( 'minimum' => 300 ) ) );
	}

	/* ─── Modes ─── */

	public function test_one_off_items_are_a_payment(): void {
		$checked = ( new Cart() )->check( array( self::price( 'a' ), self::price( 'b' ) ) );

		$this->assertTrue( $checked['ok'] );
		$this->assertSame( '', $checked['code'] );
		$this->assertSame( 'payment', $checked['mode'] );
		$this->assertSame( array( 'a', 'b' ), $checked['prices'] );
	}

	public function test_a_recurring_item_makes_a_subscription_and_one_offs_may_join(): void {
		$checked = ( new Cart() )->check( array( self::monthly( 's' ), self::price( 'a' ) ) );

		$this->assertTrue( $checked['ok'] );
		$this->assertSame( 'subscription', $checked['mode'] );
	}

	public function test_nothing_is_not_a_checkout(): void {
		$this->assertSame( Cart::EMPTY, ( new Cart() )->check( array() )['code'] );
	}

	public function test_a_repeat_is_a_double_click_not_two(): void {
		$this->assertSame( array( 'a' ), ( new Cart() )->check( array( self::price( 'a' ), self::price( 'a' ) ) )['prices'] );
	}

	/* ─── Currency ─── */

	public function test_items_with_no_currency_in_common_are_refused(): void {
		$checked = ( new Cart() )->check( array( self::price( 'a' ), self::price( 'b', array( 'currency' => 'usd', 'currencies' => array( 'usd' ) ) ) ) );

		$this->assertFalse( $checked['ok'] );
		$this->assertSame( Cart::CURRENCY, $checked['code'] );
	}

	public function test_a_shared_currency_option_is_named(): void {
		$checked = ( new Cart() )->check( array(
			self::price( 'a', array( 'currencies' => array( 'gbp', 'eur' ) ) ),
			self::price( 'b', array( 'currency' => 'eur', 'currencies' => array( 'eur' ) ) ),
		) );

		$this->assertSame( 'eur', $checked['currency'], 'Stripe has to be told, or it may pick one an item lacks.' );
	}

	public function test_the_page_currency_is_used_when_everything_has_it(): void {
		$this->assertSame( 'eur', ( new Cart() )->check( array( self::price( 'a', array( 'currencies' => array( 'gbp', 'eur' ) ) ) ), 'EUR' )['currency'] );
	}

	/**
	 * One item: leave it to Stripe, which picks among the price's own.
	 */
	public function test_a_currency_an_item_lacks_is_not_named(): void {
		$this->assertSame( '', ( new Cart() )->check( array( self::price( 'a' ) ), 'usd' )['currency'] );
	}

	public function test_currencies_fall_back_to_amounts(): void {
		$old = self::price( 'a', array( 'amounts' => array( 'gbp' => 900, 'EUR' => 1000 ) ) );
		unset( $old['currencies'] );

		$this->assertSame( array( 'gbp', 'eur' ), Cart::currencies( $old ) );
	}

	/* ─── Schedules ─── */

	public function test_a_schedule_is_interval_and_count(): void {
		$this->assertSame( 'month:3', Cart::interval( self::monthly( 'q', array( 'interval_count' => 3 ) ) ) );
		$this->assertSame( 'month:1', Cart::interval( self::monthly( 'm', array( 'interval_count' => 0 ) ) ) );
		$this->assertSame( '', Cart::interval( self::price( 'a' ) ) );
	}

	/**
	 * Every month and every three months share an interval and are still
	 * two schedules.
	 */
	public function test_different_schedules_are_refused(): void {
		$cart = new Cart();

		$this->assertSame( Cart::INTERVAL, $cart->check( array( self::monthly( 's' ), self::price( 'y', array( 'recurring' => 'year' ) ) ) )['code'] );
		$this->assertSame( Cart::INTERVAL, $cart->check( array( self::monthly( 's' ), self::monthly( 'q', array( 'interval_count' => 3 ) ) ) )['code'] );
		$this->assertTrue( $cart->check( array( self::monthly( 's' ), self::monthly( 't' ) ) )['ok'] );
	}

	/* ─── Pay what you want, metered, inactive ─── */

	public function test_pay_what_you_want_goes_alone(): void {
		$cart = new Cart();

		$this->assertTrue( $cart->check( array( self::custom( 'p' ) ) )['custom'] );
		$this->assertSame( Cart::CUSTOM_ALONE, $cart->check( array( self::custom( 'p' ), self::price( 'a' ) ) )['code'] );
		$this->assertSame( Cart::CUSTOM_RECURRING, $cart->check( array( self::custom( 'p', array( 'recurring' => 'month' ) ) ) )['code'] );
	}

	public function test_metered_and_inactive_prices_are_refused(): void {
		$cart = new Cart();

		$this->assertSame( Cart::METERED, $cart->check( array( self::monthly( 'm', array( 'usage' => 'metered' ) ) ) )['code'] );
		$this->assertSame( Cart::INACTIVE, $cart->check( array( self::price( 'a', array( 'active' => false ) ) ) )['code'] );
		$this->assertSame( Cart::INACTIVE, $cart->check( array( self::price( 'a', array( 'product_active' => false ) ) ) )['code'] );
	}

	/**
	 * The application's own rules sit beside Stripe's, after them.
	 */
	public function test_an_applications_rule_is_applied_after_stripes(): void {
		$cart = new Cart( static fn ( array $price ): ?string => 0 === $price['amount'] ? 'free' : null );

		$this->assertSame( 'free', $cart->check( array( self::price( 'f', array( 'amount' => 0 ) ), self::price( 'a' ) ) )['code'] );
		$this->assertSame( Cart::METERED, $cart->check( array( self::monthly( 'f', array( 'amount' => 0, 'usage' => 'metered' ) ) ) )['code'] );
		$this->assertSame( 'free', $cart->refusal_of( self::price( 'f', array( 'amount' => 0 ) ) ) );
		$this->assertNull( $cart->refusal_of( self::price( 'a' ) ) );
	}

	/* ─── Limits ─── */

	public function test_a_payment_takes_a_hundred_items(): void {
		$items = array_map( static fn( int $n ): array => self::price( 'p' . $n ), range( 1, 101 ) );

		$this->assertTrue( ( new Cart() )->check( array_slice( $items, 0, 100 ) )['ok'] );
		$this->assertSame( Cart::TOO_MANY, ( new Cart() )->check( $items )['code'] );
	}

	public function test_a_subscription_takes_twenty_of_each(): void {
		$recurring = array_map( static fn( int $n ): array => self::monthly( 's' . $n ), range( 1, 21 ) );
		$once      = array_map( static fn( int $n ): array => self::price( 'p' . $n ), range( 1, 21 ) );
		$cart      = new Cart();

		$this->assertTrue( $cart->check( array_merge( array_slice( $recurring, 0, 20 ), array_slice( $once, 0, 20 ) ) )['ok'] );
		$this->assertSame( Cart::TOO_MANY, $cart->check( $recurring )['code'] );
		$this->assertSame( Cart::TOO_MANY, $cart->check( array_merge( array( $recurring[0] ), $once ) )['code'] );
	}

	/* ─── Extras ─── */

	public function test_extras_skip_what_is_in_the_cart_and_its_products(): void {
		$cart  = new Cart();
		$items = array( self::price( 'a' ) );

		$this->assertSame( array( 'b' ), $cart->extras( $cart->check( $items ), $items, array(
			self::price( 'a' ),
			self::price( 'a2', array( 'product' => 'prod_a' ) ),
			self::price( 'b' ),
			self::price( 'b2', array( 'product' => 'prod_b' ) ),
		) ) );
	}

	public function test_a_recurring_extra_only_on_the_same_schedule(): void {
		$cart = new Cart();
		$once = array( self::price( 'a' ) );
		$subs = array( self::monthly( 's' ) );

		$this->assertSame( array(), $cart->extras( $cart->check( $once ), $once, array( self::monthly( 't' ) ) ) );
		$this->assertSame( array( 't' ), $cart->extras( $cart->check( $subs ), $subs, array( self::price( 'y', array( 'recurring' => 'year' ) ), self::monthly( 't' ) ) ) );
	}

	public function test_extras_must_take_every_currency_stripe_might_pick(): void {
		$cart  = new Cart();
		$items = array( self::price( 'a', array( 'currencies' => array( 'gbp', 'eur' ) ) ) );

		$this->assertSame( array( 'both' ), $cart->extras( $cart->check( $items ), $items, array(
			self::price( 'gbp', array( 'currencies' => array( 'gbp' ) ) ),
			self::price( 'both', array( 'currencies' => array( 'gbp', 'eur' ) ) ),
		) ) );
	}

	public function test_no_extras_with_pay_what_you_want_or_a_refused_price(): void {
		$cart   = new Cart( static fn ( array $price ): ?string => 'nope' === $price['id'] ? 'mine' : null );
		$custom = array( self::custom( 'p' ) );
		$items  = array( self::price( 'a' ) );

		$this->assertSame( array(), $cart->extras( $cart->check( $custom ), $custom, array( self::price( 'b' ) ) ) );
		$this->assertSame( array(), $cart->extras( $cart->check( $items ), $items, array( $custom[0], self::price( 'nope' ) ) ) );
	}

	public function test_at_most_ten_extras(): void {
		$cart       = new Cart();
		$items      = array( self::price( 'a' ) );
		$candidates = array_map( static fn( int $n ): array => self::price( 'c' . $n ), range( 1, 15 ) );

		$this->assertCount( Cart::MAX_OPTIONAL, $cart->extras( $cart->check( $items ), $items, $candidates ) );
	}

	/* ─── Parameters ─── */

	public function test_line_items_are_indexed_form_fields(): void {
		$this->assertSame(
			array(
				'line_items[0][price]'    => 'price_a',
				'line_items[0][quantity]' => 1,
				'line_items[1][price]'    => 'price_b',
				'line_items[1][quantity]' => 1,
			),
			Cart::line_items( array( 3 => 'price_a', 7 => 'price_b' ) )
		);
		$this->assertArrayHasKey( 'optional_items[0][price]', Cart::line_items( array( 'price_c' ), 'optional_items' ) );
	}

	/**
	 * A subscription refuses customer_creation; a one-off creates no
	 * customer unless asked; customer_creation is invalid beside customer.
	 */
	public function test_customer_params_fit_the_mode(): void {
		$this->assertSame( array( 'customer_creation' => 'always' ), Cart::customer_params( '', false ) );
		$this->assertSame( array(), Cart::customer_params( '', true ) );
		$this->assertSame( array( 'customer_email' => 'b@example.com', 'customer_creation' => 'always' ), Cart::customer_params( 'b@example.com', false ) );
		$this->assertSame( array( 'customer_email' => 'b@example.com' ), Cart::customer_params( 'b@example.com', true ) );
		$this->assertSame( array( 'customer' => 'cus_1' ), Cart::customer_params( 'b@example.com', false, 'cus_1' ) );
	}

	/**
	 * A rule that answers with something other than a code or null is
	 * broken; reading its answer as "no objection" would let the price
	 * through.
	 */
	public function test_a_rule_that_answers_nonsense_is_not_a_pass(): void {
		$this->expectException( \UnexpectedValueException::class );

		( new Cart( static fn ( array $price ): bool => true ) )->refusal_of( array( 'id' => 'price_1' ) );
	}
}
