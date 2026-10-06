<?php
/**
 * ManagedPayments test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\ManagedPayments;

/**
 * Class ManagedPaymentsTest
 *
 * A Managed Payments request that still carries one refused parameter is
 * refused whole, so these check the switch and the removal happen together,
 * and that nothing Stripe allows is taken out with them.
 */
final class ManagedPaymentsTest extends TestCase {

	/**
	 * Every parameter a seller's checkout might send, as form fields.
	 *
	 * @return array<string, string>
	 */
	private static function everything(): array {
		return array(
			'mode'                                          => 'payment',
			'line_items[0][price]'                          => 'price_1',
			'line_items[0][quantity]'                       => '1',
			'success_url'                                   => 'https://example.com/thanks',
			'customer'                                      => 'cus_1',
			'metadata[order]'                               => '7',
			'allow_promotion_codes'                         => 'true',
			'consent_collection[promotions]'                => 'auto',
			'custom_fields[0][key]'                         => 'giftemail',
			'after_expiration[recovery][enabled]'           => 'true',
			'custom_text[submit][message]'                  => 'Instant download.',
			'automatic_tax[enabled]'                        => 'true',
			'automatic_tax[liability][type]'                => 'self',
			'tax_id_collection[enabled]'                    => 'true',
			'invoice_creation[enabled]'                     => 'true',
			'customer_update[name]'                         => 'auto',
			'customer_update[address]'                      => 'auto',
			'customer_update[shipping]'                     => 'auto',
			'payment_method_types[0]'                       => 'card',
			'excluded_payment_method_types[0]'              => 'klarna',
			'adaptive_pricing[enabled]'                     => 'true',
			'shipping_address_collection[allowed_countries][0]' => 'GB',
			'payment_intent_data[statement_descriptor]'     => 'SHOP',
			'payment_intent_data[receipt_email]'            => 'a@example.com',
			'payment_intent_data[application_fee_amount]'   => '100',
			'payment_intent_data[metadata][order]'          => '7',
			'subscription_data[default_tax_rates][0]'       => 'txr_1',
			'subscription_data[metadata][order]'            => '7',
		);
	}

	public function test_a_session_loses_exactly_what_stripe_refuses(): void {
		$this->assertSame(
			array(
				'mode'                                => 'payment',
				'line_items[0][price]'                => 'price_1',
				'line_items[0][quantity]'             => '1',
				'success_url'                         => 'https://example.com/thanks',
				'customer'                            => 'cus_1',
				'metadata[order]'                     => '7',
				'allow_promotion_codes'               => 'true',
				'consent_collection[promotions]'      => 'auto',
				'custom_fields[0][key]'               => 'giftemail',
				'after_expiration[recovery][enabled]' => 'true',
				'custom_text[submit][message]'        => 'Instant download.',
				// Only name and address are Stripe's; shipping is not listed.
				'customer_update[shipping]'           => 'auto',
				'payment_intent_data[metadata][order]' => '7',
				'subscription_data[metadata][order]'  => '7',
				'managed_payments[enabled]'           => 'true',
			),
			ManagedPayments::session( self::everything() )
		);
	}

	/**
	 * A link is a standard checkout with no custom text on it, and keeps
	 * what a session would refuse but a link takes.
	 */
	public function test_a_link_loses_its_own_list(): void {
		$link = ManagedPayments::link( self::everything() + array( 'submit_type' => 'donate' ) );

		$this->assertArrayNotHasKey( 'custom_text[submit][message]', $link );
		$this->assertArrayNotHasKey( 'automatic_tax[enabled]', $link );
		$this->assertArrayNotHasKey( 'invoice_creation[enabled]', $link );
		$this->assertArrayNotHasKey( 'submit_type', $link );
		$this->assertSame( 'true', $link['allow_promotion_codes'] );
		$this->assertSame( 'true', $link[ ManagedPayments::ENABLED ] );
		$this->assertSame( array(), ManagedPayments::unsupported( $link, ManagedPayments::LINK_UNSUPPORTED ) );
	}

	public function test_a_link_keeps_a_submit_button_it_takes(): void {
		$this->assertSame( 'pay', ManagedPayments::link( array( 'submit_type' => 'pay' ) )['submit_type'] );
	}

	public function test_nested_parameters_are_walked(): void {
		$params = ManagedPayments::session( array(
			'automatic_tax'       => array( 'enabled' => true ),
			'customer_update'     => array( 'name' => 'auto', 'shipping' => 'auto' ),
			'payment_intent_data' => array( 'receipt_email' => 'a@example.com', 'metadata' => array( 'a' => 'b' ) ),
		) );

		$this->assertArrayNotHasKey( 'automatic_tax', $params );
		$this->assertSame( array( 'shipping' => 'auto' ), $params['customer_update'] );
		$this->assertSame( array( 'metadata' => array( 'a' => 'b' ) ), $params['payment_intent_data'] );
	}

	public function test_unsupported_names_what_a_request_carries(): void {
		$this->assertSame(
			array( 'automatic_tax', 'customer_update.name' ),
			ManagedPayments::unsupported( array( 'automatic_tax[enabled]' => 'true', 'customer_update[name]' => 'auto', 'customer_update[shipping]' => 'auto' ), ManagedPayments::SESSION_UNSUPPORTED )
		);
		$this->assertSame( array(), ManagedPayments::unsupported( ManagedPayments::session( self::everything() ), ManagedPayments::SESSION_UNSUPPORTED ) );
	}

	/**
	 * A prefix is not a path: `automatic_tax` must not take a parameter
	 * that merely starts with the same letters.
	 */
	public function test_only_whole_fields_match(): void {
		$this->assertSame( 'x', ManagedPayments::session( array( 'automatic_taxes' => 'x' ) )['automatic_taxes'] );
	}

	public function test_eligible_tax_codes(): void {
		$this->assertTrue( ManagedPayments::eligible( 'txcd_10401100' ), 'Downloaded audio, permanent rights.' );
		$this->assertTrue( ManagedPayments::eligible( 'txcd_10202000' ), 'Downloadable software.' );
		$this->assertTrue( ManagedPayments::eligible( 'txcd_10103001' ), 'SaaS, business use.' );
		$this->assertTrue( ManagedPayments::eligible( array( 'id' => 'txcd_10302000', 'object' => 'tax_code' ) ), 'An expanded code.' );
		$this->assertFalse( ManagedPayments::eligible( 'txcd_99999999' ), 'General - Tangible Goods.' );
		$this->assertFalse( ManagedPayments::eligible( 'txcd_20030000' ), 'General - Services.' );
		$this->assertFalse( ManagedPayments::eligible( null ) );
		$this->assertFalse( ManagedPayments::eligible( '' ) );
	}

	public function test_every_listed_code_is_a_tax_code(): void {
		foreach ( ManagedPayments::ELIGIBLE_TAX_CODES as $code => $name ) {
			$this->assertMatchesRegularExpression( '/^txcd_\d{8}$/', $code );
			$this->assertNotSame( '', $name );
		}

		$this->assertCount( 76, ManagedPayments::ELIGIBLE_TAX_CODES, "Stripe's table on 7 October 2026." );
	}
}
