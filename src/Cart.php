<?php
/**
 * What Stripe Checkout accepts in one session.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class Cart
 *
 * Stripe's rules for a Checkout Session's items, checked before Stripe is
 * asked. A buyer who presses "Checkout" and gets Stripe's own error back has
 * been shown the inside of the plumbing; the same rule checked first, before
 * anything is created, is a cart that can say what to change.
 *
 * Pure. It is handed prices as Shape::price() shapes them — each may also
 * carry `active` and `product_active` (false refuses it) and `product` (for
 * extras) — and answers with codes, never sentences: the words, and the
 * language they are in, are the application's.
 *
 * The rules, from Stripe's Checkout Session documentation:
 *
 * - One currency, which every item must be priced in (its own or one of its
 *   currency_options).
 * - Any recurring item makes it a subscription. One-off items may ride
 *   along on the first invoice, but every recurring item must renew on the
 *   same interval and interval_count.
 * - At most 100 items in a payment; at most 20 recurring and 20 one-off in
 *   a subscription.
 * - A pay-what-you-want price must be the only item, and never recurring.
 * - Metered prices are refused outright: they bill for usage reported
 *   later, which a checkout cannot.
 *
 * An application adds its own rules for single prices — "free items are
 * claimed, not bought" — through the constructor, and they are applied in
 * the same places Stripe's are.
 *
 * @since 1.2.0
 *
 * @phpstan-type Checked array{ok: bool, code: string, mode: string, currency: string, currencies: list<string>, prices: list<string>, custom: bool}
 */
final readonly class Cart {

	/**
	 * Most items in a one-off checkout.
	 *
	 * @since 1.2.0
	 */
	public const int MAX_PAYMENT = 100;

	/**
	 * Most recurring items in a subscription checkout, and separately most
	 * one-off items alongside them.
	 *
	 * @since 1.2.0
	 */
	public const int MAX_SUBSCRIPTION = 20;

	/**
	 * Most optional items Stripe shows on one session.
	 *
	 * @since 1.2.0
	 */
	public const int MAX_OPTIONAL = 10;

	/*
	 * Refusal codes, in the order they are checked.
	 */

	/**
	 * Nothing in the cart.
	 *
	 * @since 1.2.0
	 */
	public const string EMPTY = 'empty';

	/**
	 * A price, or its product, is archived.
	 *
	 * @since 1.2.0
	 */
	public const string INACTIVE = 'inactive';

	/**
	 * A metered price, which bills for usage a checkout cannot report.
	 *
	 * @since 1.2.0
	 */
	public const string METERED = 'metered';

	/**
	 * A pay-what-you-want price that also recurs.
	 *
	 * @since 1.2.0
	 */
	public const string CUSTOM_RECURRING = 'custom_recurring';

	/**
	 * A pay-what-you-want price with anything else in the cart.
	 *
	 * @since 1.2.0
	 */
	public const string CUSTOM_ALONE = 'custom_alone';

	/**
	 * Recurring prices that renew on different schedules.
	 *
	 * @since 1.2.0
	 */
	public const string INTERVAL = 'interval';

	/**
	 * More items than one session takes.
	 *
	 * @since 1.2.0
	 */
	public const string TOO_MANY = 'too_many';

	/**
	 * No currency every item can be charged in.
	 *
	 * @since 1.2.0
	 */
	public const string CURRENCY = 'currency';

	/**
	 * @since 1.2.0
	 *
	 * @param \Closure|null $refuses The application's own rule for a single
	 *                               price: fn( array $price ): ?string, a
	 *                               refusal code or null. Checked after
	 *                               Stripe's rules for one price.
	 */
	public function __construct( private ?\Closure $refuses = null ) {}

	/**
	 * Whether these prices can be one checkout, and as what.
	 *
	 * @since 1.2.0
	 *
	 * @param array<array<mixed>> $prices   Shaped prices, in cart order.
	 *                                      Repeats are dropped: a cart holds
	 *                                      one of each.
	 * @param string              $currency The currency a page showed, or ''
	 *                                      to pick one.
	 *
	 * @return Checked `mode` is payment | subscription. `currency` is '' when one
	 *         item may be charged in whichever of its currencies Stripe
	 *         picks for the buyer; `currencies` is the set every item shares.
	 */
	public function check( array $prices, string $currency = '' ): array {
		$prices = self::unique( $prices );
		$code   = $this->refusal( $prices );

		if ( null !== $code ) {
			return self::refuse( $code );
		}

		$shared = self::shared_currencies( $prices );

		if ( ! $shared ) {
			return self::refuse( self::CURRENCY );
		}

		return array(
			'ok'         => true,
			'code'       => '',
			'mode'       => self::counts( $prices )['recurring'] > 0 ? 'subscription' : 'payment',
			'currency'   => self::currency( $prices, $shared, strtolower( trim( $currency ) ) ),
			'currencies' => $shared,
			'prices'     => array_map( static fn( array $price ): string => Fields::string( $price['id'] ?? null ), $prices ),
			'custom'     => self::has_custom( $prices ),
		);
	}

	/**
	 * Prices to offer as optional items alongside a checked cart.
	 *
	 * Never one already in the cart, never another price of a product in
	 * it, and only what Stripe would accept on that session: the same
	 * currencies, no pay-what-you-want on either side, no recurring extra
	 * on a one-off checkout, a recurring one only on the same schedule, and
	 * within the item limits counted with the cart's own items. The first
	 * price of each product that qualifies, in the order given.
	 *
	 * @since 1.2.0
	 *
	 * @param Checked             $checked    What check() answered, ok.
	 * @param array<array<mixed>> $items      The cart's shaped prices, each
	 *                                        with `product`.
	 * @param array<array<mixed>> $candidates Shaped prices to choose from,
	 *                                        each with `product`, in
	 *                                        preference order.
	 *
	 * @return list<string> Price ids, at most MAX_OPTIONAL.
	 */
	public function extras( array $checked, array $items, array $candidates ): array {
		if ( empty( $checked['ok'] ) || ! empty( $checked['custom'] ) ) {
			return array();
		}

		$taken    = array_flip( array_map( Fields::string( ... ), array_column( $items, 'product' ) ) );
		$schedule = array_values( array_filter( array_map( self::interval( ... ), $items ) ) )[0] ?? '';
		$room     = self::room( $checked['mode'], self::counts( $items ) );
		$wanted   = '' !== $checked['currency'] ? array( $checked['currency'] ) : $checked['currencies'];
		$picked   = array();

		foreach ( $candidates as $price ) {
			if ( count( $picked ) >= self::MAX_OPTIONAL ) {
				break;
			}

			$product = Fields::string( $price['product'] ?? null );
			$kind    = '' !== self::interval( $price ) ? 'recurring' : 'one_off';

			if ( isset( $taken[ $product ] ) || $room[ $kind ] <= 0 || ! $this->fits( $price, $checked['mode'], $schedule, $wanted ) ) {
				continue;
			}

			$picked[]          = Fields::string( $price['id'] ?? null );
			$taken[ $product ] = true;
			--$room[ $kind ];
		}

		return $picked;
	}

	/**
	 * Why one price could never be in a cart, or null.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $price A shaped price.
	 *
	 * @return string|null A refusal code.
	 *
	 * @throws \UnexpectedValueException When the application's rule answers
	 *                                   with something other than a code or
	 *                                   null — which is a broken rule, not a
	 *                                   price to let through.
	 */
	public function refusal_of( array $price ): ?string {
		return match ( true ) {
			false === ( $price['active'] ?? true ),
			false === ( $price['product_active'] ?? true ) => self::INACTIVE,
			'metered' === ( $price['usage'] ?? 'licensed' ) => self::METERED,
			null !== ( $price['custom'] ?? null ) && '' !== Fields::string( $price['recurring'] ?? null ) => self::CUSTOM_RECURRING,
			default => $this->own_refusal( $price ),
		};
	}

	/**
	 * What the application's own rule says about a price.
	 *
	 * @since 1.5.0
	 *
	 * @param array<mixed> $price A shaped price.
	 *
	 * @return string|null A refusal code, or null.
	 *
	 * @throws \UnexpectedValueException When the rule answers with anything else.
	 */
	private function own_refusal( array $price ): ?string {
		if ( null === $this->refuses ) {
			return null;
		}

		$code = ( $this->refuses )( $price );

		if ( null !== $code && ! is_string( $code ) ) {
			throw new \UnexpectedValueException( 'A cart rule must answer with a refusal code or null.' );
		}

		return $code;
	}

	/**
	 * A price's renewal schedule as "interval:count", or '' for one-off.
	 *
	 * The two things Stripe compares when it refuses subscriptions that
	 * renew on different schedules.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $price Shaped price.
	 *
	 * @return string E.g. "month:1", "month:3", or ''.
	 */
	public static function interval( array $price ): string {
		$interval = Fields::string( $price['recurring'] ?? null );

		return '' === $interval ? '' : $interval . ':' . max( 1, Fields::int( $price['interval_count'] ?? null, 1 ) );
	}

	/**
	 * Every currency one shaped price can be charged in.
	 *
	 * From `currencies` where Shape::price() made it, else from `amounts`
	 * and the price's own — a price shaped before `currencies` existed
	 * still answers.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $price Shaped price.
	 *
	 * @return list<string> Lower case.
	 */
	public static function currencies( array $price ): array {
		$codes = $price['currencies'] ?? array();
		$codes = is_array( $codes ) ? $codes : array( $codes );

		if ( ! $codes ) {
			$codes = array_merge( array( Fields::string( $price['currency'] ?? null ) ), array_keys( Fields::map( $price['amounts'] ?? null ) ) );
		}

		return array_values( array_unique( array_filter( array_map( static fn( mixed $c ): string => strtolower( Fields::string( $c ) ), $codes ) ) ) );
	}

	/**
	 * Recurring and one-off items, counted.
	 *
	 * @since 1.2.0
	 *
	 * @param array<array<mixed>> $prices Shaped prices.
	 *
	 * @return array{recurring: int, one_off: int}
	 */
	public static function counts( array $prices ): array {
		$recurring = count( array_filter( $prices, static fn( array $p ): bool => '' !== Fields::string( $p['recurring'] ?? null ) ) );

		return array( 'recurring' => $recurring, 'one_off' => count( $prices ) - $recurring );
	}

	/**
	 * Price ids as Stripe's indexed form fields, quantity one each.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string> $prices Price ids.
	 * @param string        $field  line_items | optional_items
	 *
	 * @return array<string, string|int>
	 */
	public static function line_items( array $prices, string $field = 'line_items' ): array {
		$lines = array();

		foreach ( array_values( $prices ) as $index => $price ) {
			$lines[ "{$field}[{$index}][price]" ]    = $price;
			$lines[ "{$field}[{$index}][quantity]" ] = 1;
		}

		return $lines;
	}

	/**
	 * Who a session is for, as the parameters each mode accepts.
	 *
	 * A subscription creates a customer whatever it is told, and refuses
	 * `customer_creation` outright — passing it is an error, not a no-op. A
	 * one-off purchase creates none unless asked, which leaves the seller's
	 * customer list empty; so it is asked, with or without an address.
	 * `customer_creation` is invalid beside `customer`, so naming an
	 * existing customer sends that alone.
	 *
	 * @since 1.2.0
	 *
	 * @param string $email     The buyer's address, already validated, or ''.
	 * @param bool   $recurring Whether the session is a subscription.
	 * @param string $customer  An existing customer to attach, or ''. Only
	 *                          for an address the caller has proven.
	 *
	 * @return array<string, string>
	 */
	public static function customer_params( string $email, bool $recurring, string $customer = '' ): array {
		if ( '' !== $customer ) {
			return array( 'customer' => $customer );
		}

		$params = '' === $email ? array() : array( 'customer_email' => $email );

		return $recurring ? $params : $params + array( 'customer_creation' => 'always' );
	}

	/**
	 * The first rule these prices break, or null.
	 *
	 * @param list<array<mixed>> $prices Shaped prices, de-duplicated.
	 *
	 * @return string|null
	 */
	private function refusal( array $prices ): ?string {
		if ( ! $prices ) {
			return self::EMPTY;
		}

		foreach ( $prices as $price ) {
			$code = $this->refusal_of( $price );

			if ( null !== $code ) {
				return $code;
			}
		}

		if ( self::has_custom( $prices ) && count( $prices ) > 1 ) {
			return self::CUSTOM_ALONE;
		}

		if ( count( array_unique( array_filter( array_map( self::interval( ... ), $prices ) ) ) ) > 1 ) {
			return self::INTERVAL;
		}

		return self::too_many( self::counts( $prices ) ) ? self::TOO_MANY : null;
	}

	/**
	 * Whether there are more items than one session takes.
	 *
	 * @param array{recurring: int, one_off: int} $counts Items, counted.
	 *
	 * @return bool
	 */
	private static function too_many( array $counts ): bool {
		if ( $counts['recurring'] > 0 ) {
			return $counts['recurring'] > self::MAX_SUBSCRIPTION || $counts['one_off'] > self::MAX_SUBSCRIPTION;
		}

		return $counts['one_off'] > self::MAX_PAYMENT;
	}

	/**
	 * How many more of each kind a session of this mode takes.
	 *
	 * @param string                              $mode   payment | subscription
	 * @param array{recurring: int, one_off: int} $counts What is in it already.
	 *
	 * @return array{recurring: int, one_off: int}
	 */
	private static function room( string $mode, array $counts ): array {
		if ( 'subscription' === $mode ) {
			return array(
				'recurring' => self::MAX_SUBSCRIPTION - $counts['recurring'],
				'one_off'   => self::MAX_SUBSCRIPTION - $counts['one_off'],
			);
		}

		// Stripe has no recurring optional item in payment mode.
		return array( 'recurring' => 0, 'one_off' => self::MAX_PAYMENT - $counts['one_off'] );
	}

	/**
	 * Whether one candidate can be an optional item on this session.
	 *
	 * @param array<mixed> $price    Shaped candidate price.
	 * @param string       $mode     payment | subscription
	 * @param string       $schedule The cart's interval, or ''.
	 * @param list<string> $wanted   Currencies it must be chargeable in.
	 *
	 * @return bool
	 */
	private function fits( array $price, string $mode, string $schedule, array $wanted ): bool {
		$interval = self::interval( $price );

		return null === $this->refusal_of( $price )
			&& null === ( $price['custom'] ?? null )
			&& ( '' === $interval || ( 'subscription' === $mode && ( '' === $schedule || $interval === $schedule ) ) )
			&& ! array_diff( $wanted, self::currencies( $price ) );
	}

	/**
	 * The currencies every price can be charged in, the first price's order
	 * kept.
	 *
	 * @param list<array<mixed>> $prices Shaped prices.
	 *
	 * @return list<string>
	 */
	private static function shared_currencies( array $prices ): array {
		$shared = self::currencies( $prices[0] );

		foreach ( $prices as $price ) {
			$shared = array_values( array_intersect( $shared, self::currencies( $price ) ) );
		}

		return $shared;
	}

	/**
	 * The currency to name on the session, or '' to let Stripe choose.
	 *
	 * What the page showed when everything can be charged in it. Otherwise,
	 * with one item, nothing: Stripe picks from the buyer's location among
	 * that price's own currencies, which is the better default. With
	 * several, Stripe has to be told, or it may pick one an item is not
	 * priced in — so the first item's own currency when they all share it,
	 * else the first they share.
	 *
	 * @param list<array<mixed>> $prices    Shaped prices.
	 * @param list<string>       $shared    Currencies they all take.
	 * @param string             $requested What the page showed, or ''.
	 *
	 * @return string
	 */
	private static function currency( array $prices, array $shared, string $requested ): string {
		if ( '' !== $requested && in_array( $requested, $shared, true ) ) {
			return $requested;
		}

		if ( 1 === count( $prices ) ) {
			return '';
		}

		$own = strtolower( Fields::string( $prices[0]['currency'] ?? null ) );

		return in_array( $own, $shared, true ) ? $own : $shared[0];
	}

	/**
	 * Whether any price is pay-what-you-want.
	 *
	 * @param list<array<mixed>> $prices Shaped prices.
	 *
	 * @return bool
	 */
	private static function has_custom( array $prices ): bool {
		foreach ( $prices as $price ) {
			if ( null !== ( $price['custom'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The prices with repeats dropped, first occurrence kept.
	 *
	 * @param array<array<mixed>> $prices Shaped prices.
	 *
	 * @return list<array<mixed>>
	 */
	private static function unique( array $prices ): array {
		$seen = array();

		foreach ( $prices as $price ) {
			$seen[ Fields::string( $price['id'] ?? null ) ] ??= $price;
		}

		unset( $seen[''] );

		return array_values( $seen );
	}

	/**
	 * A refusal.
	 *
	 * @param string $code Refusal code.
	 *
	 * @return Checked
	 */
	private static function refuse( string $code ): array {
		return array( 'ok' => false, 'code' => $code, 'mode' => '', 'currency' => '', 'currencies' => array(), 'prices' => array(), 'custom' => false );
	}
}
