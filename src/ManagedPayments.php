<?php
/**
 * Stripe Managed Payments: what it asks for and what it refuses.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class ManagedPayments
 *
 * With `managed_payments[enabled]=true` on a Checkout Session or a Payment
 * Link, Stripe is the merchant of record: it works out, collects and remits
 * sales tax, VAT and GST, handles fraud and disputes, and answers the
 * buyer's questions about the transaction. In exchange it owns parts of the
 * checkout, and refuses the parameters that would let the caller decide them
 * — tax, payment methods, shipping, Connect money movement, invoices,
 * statement descriptors. A request that still carries one is refused whole,
 * so the switch and the removal have to happen together, in one place.
 *
 * The lists are Stripe's, as published on 7 October 2026 (docs.stripe.com/
 * payments/managed-payments/update-checkout and …/use-payment-links), kept
 * apart because the two endpoints differ: a Payment Link also refuses
 * `custom_text` and the donate and book submit buttons, and a session also
 * refuses payment-method exclusions and the receipt email. What is not in a
 * list is allowed — promotion codes, custom fields, consent collection, the
 * recovery of an expired session, metadata.
 *
 * Each product sold also needs a tax code from ELIGIBLE_TAX_CODES: digital
 * goods and services only. Stripe checks that itself; eligible() is for
 * saying so to a seller before a buyer finds out.
 *
 * Only for direct integrations: Managed Payments does not support Connect
 * platforms — application fees, transfers, `on_behalf_of` — or accounts a
 * platform controls.
 *
 * @since 1.4.0
 */
final class ManagedPayments {

	/**
	 * The parameter that turns it on.
	 *
	 * @since 1.4.0
	 */
	public const string ENABLED = 'managed_payments[enabled]';

	/**
	 * What a Checkout Session refuses with Managed Payments on, one-off and
	 * subscription alike, as dotted paths: `customer_update.name` is the form
	 * field `customer_update[name]`, and a path covers everything below it.
	 *
	 * @since 1.4.0
	 *
	 * @var string[]
	 */
	public const array SESSION_UNSUPPORTED = array(
		// Stripe calculates and withholds the tax.
		'automatic_tax',
		'tax_id_collection',
		'subscription_data.default_tax_rates',
		// Stripe chooses the payment methods, and always localises the price.
		'adaptive_pricing',
		'payment_method_configuration',
		'payment_method_types',
		'excluded_payment_method_types',
		// Stripe always writes the name and billing address it collects.
		'customer_update.name',
		'customer_update.address',
		// Digital goods only.
		'shipping_address_collection',
		'shipping_options',
		'payment_intent_data.shipping',
		// No Connect money movement.
		'payment_intent_data.application_fee_amount',
		'payment_intent_data.on_behalf_of',
		'payment_intent_data.transfer_data',
		'payment_intent_data.transfer_group',
		'subscription_data.application_fee_percent',
		'subscription_data.on_behalf_of',
		'subscription_data.transfer_data',
		// Stripe sends the receipts and invoices, under its own descriptor.
		'invoice_creation',
		'subscription_data.invoice_settings',
		'payment_intent_data.statement_descriptor',
		'payment_intent_data.statement_descriptor_suffix',
		'payment_intent_data.receipt_email',
	);

	/**
	 * What a Payment Link refuses with Managed Payments on, as dotted paths.
	 *
	 * @since 1.4.0
	 *
	 * @var string[]
	 */
	public const array LINK_UNSUPPORTED = array(
		'automatic_tax',
		'tax_id_collection',
		'payment_method_types',
		'shipping_address_collection',
		'shipping_options',
		'application_fee_amount',
		'application_fee_percent',
		'on_behalf_of',
		'transfer_data',
		'payment_intent_data.transfer_group',
		'invoice_creation',
		'subscription_data.invoice_settings',
		'payment_intent_data.statement_descriptor',
		'payment_intent_data.statement_descriptor_suffix',
		'consent_collection.payment_method_reuse_agreement.position',
		// A standard checkout: no custom text anywhere on it.
		'custom_text',
	);

	/**
	 * Values a Payment Link refuses for a parameter it otherwise takes:
	 * Managed Payments sells neither donations nor services.
	 *
	 * @since 1.4.0
	 *
	 * @var array<string, string[]>
	 */
	public const array LINK_UNSUPPORTED_VALUES = array(
		'submit_type' => array( 'donate', 'book' ),
	);

	/**
	 * Every product tax code Managed Payments sells under, with Stripe's name
	 * for it. Anything else — a physical good, a professional service, no
	 * code at all — is refused.
	 *
	 * @since 1.4.0
	 *
	 * @var array<string, string>
	 */
	public const array ELIGIBLE_TAX_CODES = array(
		'txcd_10000000' => 'General - Electronically Supplied Services',
		'txcd_10010001' => 'Infrastructure as a service (IaaS) - personal use',
		'txcd_10101000' => 'Infrastructure as a service (IaaS) - business use',
		'txcd_10102000' => 'Platform as a service (PaaS) - business use',
		'txcd_10102001' => 'Platform as a Service (PaaS) - personal use',
		'txcd_10103000' => 'Software as a service (SaaS) - personal use',
		'txcd_10103001' => 'Software as a service (SaaS) - business use',
		'txcd_10103100' => 'Software as a service (SaaS) - electronic download - personal use',
		'txcd_10103101' => 'Software as a service (SaaS) - electronic download - business use',
		'txcd_10105001' => 'Artificial Intelligence as a Service (AIaaS) - Cloud Based - Personal Use',
		'txcd_10105002' => 'Artificial Intelligence as a Service (AIaaS) - Cloud Based - Business Use',
		'txcd_10105003' => 'Artificial Intelligence as a Service (AIaaS) - Cloud Based & Downloaded - Personal Use',
		'txcd_10105004' => 'Artificial Intelligence as a Service (AIaaS) - Cloud Based & Downloaded - Business Use',
		'txcd_10201000' => 'Video Games - downloaded - non subscription - with permanent rights',
		'txcd_10201001' => 'Video Games - downloaded - non subscription - with limited rights',
		'txcd_10201002' => 'Video Games - downloaded - subscription - with conditional rights',
		'txcd_10201003' => 'Video Games - streamed - non subscription - with limited rights',
		'txcd_10201004' => 'Video Games - streamed - subscription - with conditional rights',
		'txcd_10202000' => 'Downloadable Software - personal use',
		'txcd_10202001' => 'Downloadable Software - non-recreational - personal use',
		'txcd_10202003' => 'Downloadable Software - business use',
		'txcd_10301000' => 'Audiobook',
		'txcd_10302000' => 'Digital Books - downloaded - non subscription - with permanent rights',
		'txcd_10302001' => 'Digital Books - downloaded - non subscription - with limited rights',
		'txcd_10302002' => 'Digital Books - downloaded - subscription - with conditional rights',
		'txcd_10302003' => 'Digital Books - viewable only - subscription - with conditional rights',
		'txcd_10303000' => 'Digital Magazines/Periodicals - downloadable - subscription - with conditional rights',
		'txcd_10303002' => 'Digital Magazines/Periodicals - viewable only - subscription - with conditional rights',
		'txcd_10303100' => 'Digital Magazines/Periodicals - downloadable - non subscription - with permanent rights',
		'txcd_10303101' => 'Digital Magazines/Periodicals - viewable only - non subscription - with limited rights',
		'txcd_10303102' => 'Digital Magazines/Periodicals - viewable only - non subscription - with permanent rights',
		'txcd_10303104' => 'Digital Magazines/Periodicals - downloadable - non subscription - with limited rights',
		'txcd_10304000' => 'Digital Newspapers - downloadable - non subscription - with permanent rights',
		'txcd_10304001' => 'Digital Newspapers - viewable only - non subscription - with limited rights',
		'txcd_10304002' => 'Digital Newspapers - viewable only - non subscription - with permanent rights',
		'txcd_10304003' => 'Digital Newspapers - downloadable - non subscription - with limited rights',
		'txcd_10304100' => 'Digital Newspapers - downloadable - subscription - with conditional rights',
		'txcd_10304102' => 'Digital Newspapers - viewable only - subscription - with conditional rights',
		'txcd_10305000' => 'Digital School Textbooks - downloaded - non subscription - with limited rights',
		'txcd_10305001' => 'Digital School Textbooks - downloaded - non subscription - with permanent rights',
		'txcd_10401000' => 'Digital Audio Works - streamed - non subscription - with limited rights',
		'txcd_10401001' => 'Digital Audio Works - downloaded - non subscription - with limited rights',
		'txcd_10401100' => 'Digital Audio Works - downloaded - non subscription - with permanent rights',
		'txcd_10401200' => 'Digital Audio Works - streamed - subscription - with conditional rights',
		'txcd_10402000' => 'Digital Audio Visual Works - streamed - non subscription - with limited rights',
		'txcd_10402100' => 'Digital Audio Visual Works - downloaded - non subscription - with permanent rights',
		'txcd_10402110' => 'Digital Audio Visual Works - downloaded - non subscription - with limited rights',
		'txcd_10402200' => 'Digital Audio Visual Works - streamed - subscription - with conditional rights',
		'txcd_10501000' => 'Digital Photographs/Images - downloaded - non subscription - with permanent rights',
		'txcd_10503000' => 'Digital other news or documents - downloadable - non subscription - with permanent rights',
		'txcd_10503001' => 'Digital other news or documents - downloadable - non subscription - with limited rights',
		'txcd_10503002' => 'Digital other news or documents - downloadable - subscription - with conditional rights',
		'txcd_10503003' => 'Digital other news or documents - viewable only - non subscription - with limited rights',
		'txcd_10503004' => 'Digital other news or documents - viewable only - non subscription - with permanent rights',
		'txcd_10503005' => 'Digital other news or documents - viewable only - subscription - with conditional rights',
		'txcd_10504003' => 'Electronic software documentation or user manuals - Prewritten, electronic delivery',
		'txcd_10505000' => 'Digital Finished Artwork - downloaded - non subscription - with limited rights',
		'txcd_10505001' => 'Digital Finished Artwork - downloaded - non subscription - with permanent rights',
		'txcd_10505002' => 'Digital Finished Artwork - downloaded - subscription - with conditional rights',
		'txcd_10506000' => 'Digital Greeting Cards - Audio Only',
		'txcd_10506001' => 'Digital Greeting Cards - Audio Visual',
		'txcd_10506002' => 'Digital Greeting Cards - Static text and/or images only',
		'txcd_10701100' => 'Website Hosting',
		'txcd_10701400' => 'Website Information Services - Business Use',
		'txcd_10701401' => 'Website Information Services - Personal Use',
		'txcd_10701410' => 'Electronically Delivered Information Services - Business Use',
		'txcd_10701411' => 'Electronically Delivered Information Services - Personal Use',
		'txcd_10804001' => 'Digital Audio Visual Works - bundle - downloaded with permanent rights and streamed - subscription - with conditional rights',
		'txcd_10804002' => 'Digital Audio Visual Works - bundle - downloaded with limited rights and streamed - non subscription',
		'txcd_10804003' => 'Digital Audio Visual Works - bundle - downloaded with permanent rights and streamed - non subscription',
		'txcd_10804010' => 'Digital Audio Works - bundle - downloaded with permanent rights and streamed - subscription - with conditional rights',
		'txcd_20060058' => 'Training Services - Self-study Web-based',
		'txcd_20060158' => 'On demand Online Courses - pre-recorded audio or audio visual content (streamed)',
		'txcd_20060258' => 'On demand Online Courses - pre-recorded audio or audio visual content (streamed and downloadable)',
		'txcd_20060358' => 'On demand Online Courses - written material content (viewable or viewable and downloadable)',
		'txcd_37071001' => 'Software Maintenance Agreement - Optional, Prewritten, Electronic Delivery, Updates Only',
	);

	/**
	 * Checkout Session parameters with Managed Payments on: everything it
	 * refuses taken out, and the switch put in.
	 *
	 * @since 1.4.0
	 *
	 * @param array $params Session parameters, as form fields
	 *                      (`automatic_tax[enabled]`) or nested arrays.
	 *
	 * @return array
	 */
	public static function session( array $params ): array {
		return self::strip( $params, self::SESSION_UNSUPPORTED ) + array( self::ENABLED => 'true' );
	}

	/**
	 * Payment Link parameters with Managed Payments on.
	 *
	 * @since 1.4.0
	 *
	 * @param array $params Link parameters, flat or nested.
	 *
	 * @return array
	 */
	public static function link( array $params ): array {
		return self::strip( $params, self::LINK_UNSUPPORTED, self::LINK_UNSUPPORTED_VALUES ) + array( self::ENABLED => 'true' );
	}

	/**
	 * Whether a product tax code can be sold with Managed Payments.
	 *
	 * A product's `tax_code` is an id, or the tax code object when it was
	 * expanded; both are read. No code is not eligible.
	 *
	 * @since 1.4.0
	 *
	 * @param string|array|null $tax_code The product's `tax_code`.
	 *
	 * @return bool
	 */
	public static function eligible( string|array|null $tax_code ): bool {
		return isset( self::ELIGIBLE_TAX_CODES[ Shape::id( $tax_code ) ] );
	}

	/**
	 * Which of the given paths a request carries, as dotted paths.
	 *
	 * For a test or a log line asserting none of them reached Stripe; empty
	 * is the answer every Managed Payments request should get.
	 *
	 * @since 1.4.0
	 *
	 * @param array    $params Parameters, flat or nested.
	 * @param string[] $paths  SESSION_UNSUPPORTED or LINK_UNSUPPORTED.
	 *
	 * @return string[]
	 */
	public static function unsupported( array $params, array $paths ): array {
		return array_values( array_filter(
			$paths,
			static fn( string $path ): bool => self::strip( $params, array( $path ) ) !== $params
		) );
	}

	/**
	 * Parameters without the given paths, or the given values of them.
	 *
	 * A form field matches a path when it is the path or something below it,
	 * so `automatic_tax` takes `automatic_tax[enabled]` and
	 * `automatic_tax[liability][type]` with it. Nested arrays are walked the
	 * same way.
	 *
	 * @param array                   $params Parameters, flat or nested.
	 * @param string[]                $paths  Dotted paths to remove.
	 * @param array<string, string[]> $values Path => the values refused for it.
	 *
	 * @return array
	 */
	private static function strip( array $params, array $paths, array $values = array() ): array {
		foreach ( $paths as $path ) {
			$params = self::without( $params, explode( '.', $path ) );
		}

		foreach ( $values as $path => $refused ) {
			$field = self::field( explode( '.', $path ) );

			if ( in_array( $params[ $field ] ?? null, $refused, true ) ) {
				unset( $params[ $field ] );
			}
		}

		return $params;
	}

	/**
	 * Parameters without one path, in either form.
	 *
	 * @param array    $params   Parameters.
	 * @param string[] $segments The path, split.
	 *
	 * @return array
	 */
	private static function without( array $params, array $segments ): array {
		$field = self::field( $segments );

		foreach ( array_keys( $params ) as $key ) {
			if ( (string) $key === $field || str_starts_with( (string) $key, $field . '[' ) ) {
				unset( $params[ $key ] );
			}
		}

		// The nested form: walk down and drop the last segment.
		$head = array_shift( $segments );

		if ( array() === $segments ) {
			unset( $params[ $head ] );
		} elseif ( is_array( $params[ $head ] ?? null ) ) {
			$params[ $head ] = self::without( $params[ $head ], $segments );
		}

		return $params;
	}

	/**
	 * A path as the form field Stripe reads: `a.b.c` is `a[b][c]`.
	 *
	 * @param string[] $segments The path, split.
	 *
	 * @return string
	 */
	private static function field( array $segments ): string {
		$head = array_shift( $segments );

		return $head . implode( '', array_map( static fn( string $segment ): string => '[' . $segment . ']', $segments ) );
	}
}
