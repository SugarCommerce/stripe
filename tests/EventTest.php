<?php
/**
 * Event test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\{Event, InvalidEvent};

final class EventTest extends TestCase {

	private const SECRET = 'whsec_test';

	private const NOW = 1_800_000_000;

	private static function body( array $overrides = array() ): string {
		return (string) json_encode( array_merge( array(
			'id'       => 'evt_1',
			'type'     => 'checkout.session.completed',
			'account'  => 'acct_1',
			'livemode' => true,
			'created'  => 1_799_999_000,
			'data'     => array( 'object' => array( 'id' => 'cs_1', 'object' => 'checkout.session' ) ),
		), $overrides ) );
	}

	/**
	 * A Stripe-Signature header, built the way Stripe builds one.
	 */
	private static function sign( string $body, int $at = self::NOW, string $secret = self::SECRET ): string {
		return 't=' . $at . ',v1=' . hash_hmac( 'sha256', $at . '.' . $body, $secret );
	}

	public function test_a_signed_event_is_unpacked(): void {
		$body  = self::body();
		$event = Event::receive( $body, self::sign( $body ), self::SECRET, now: self::NOW );

		$this->assertSame( 'evt_1', $event->id );
		$this->assertSame( 'checkout.session.completed', $event->type );
		$this->assertSame( 'acct_1', $event->account );
		$this->assertSame( 'live', $event->mode() );
		$this->assertSame( 1_799_999_000, $event->created );
		$this->assertSame( 'cs_1', $event->object['id'] );
		$this->assertFalse( $event->platform() );
	}

	public function test_a_wrong_signature_is_refused(): void {
		$body = self::body();

		$this->expectException( InvalidEvent::class );

		Event::receive( $body, self::sign( $body, secret: 'whsec_other' ), self::SECRET, now: self::NOW );
	}

	public function test_a_changed_body_is_refused(): void {
		$this->expectException( InvalidEvent::class );

		Event::receive( self::body( array( 'livemode' => false ) ), self::sign( self::body() ), self::SECRET, now: self::NOW );
	}

	/**
	 * A captured request replayed an hour later.
	 */
	public function test_a_stale_signature_is_refused(): void {
		$body = self::body();

		$this->expectException( InvalidEvent::class );

		Event::receive( $body, self::sign( $body, self::NOW - 3600 ), self::SECRET, now: self::NOW );
	}

	public function test_no_secret_refuses_everything(): void {
		$body = self::body();

		$this->expectException( InvalidEvent::class );

		Event::receive( $body, self::sign( $body, secret: '' ), '', now: self::NOW );
	}

	public function test_a_signed_body_that_is_not_an_event_is_refused(): void {
		$body = '{"hello":"world"}';

		$this->expectExceptionMessage( 'Malformed event payload.' );

		Event::receive( $body, self::sign( $body ), self::SECRET, now: self::NOW );
	}

	public function test_an_event_without_an_account_is_the_platforms_own(): void {
		$event = Event::parse( self::body( array( 'account' => null, 'livemode' => false ) ) );

		$this->assertTrue( $event->platform() );
		$this->assertSame( '', $event->account );
		$this->assertSame( 'test', $event->mode() );
	}

	public function test_an_event_can_be_attributed_to_an_account(): void {
		$event = Event::parse( self::body( array( 'account' => null ) ) )->for_account( 'acct_dev' );

		$this->assertSame( 'acct_dev', $event->account );
		$this->assertSame( 'evt_1', $event->id );
	}

	public function test_parse_refuses_what_is_not_an_event(): void {
		$this->assertNull( Event::parse( 'not json' ) );
		$this->assertNull( Event::parse( '{"id":1,"type":"x"}' ) );
	}

	public function test_redaction_removes_identity_and_keeps_email(): void {
		$body = self::body( array( 'data' => array( 'object' => array(
			'id'               => 'cs_1',
			'customer_details' => array(
				'email'   => 'buyer@example.com',
				'name'    => 'Jordan Smith',
				'phone'   => '+447700900000',
				'address' => array( 'line1' => '1 Test Street', 'postal_code' => 'SW1A 1AA' ),
				'tax_ids' => array( array( 'type' => 'gb_vat', 'value' => 'GB123456789' ) ),
			),
			'shipping_details' => array( 'name' => 'Jordan Smith', 'address' => array( 'city' => 'London' ) ),
		) ) ) );

		$redacted = Event::redact( $body );

		foreach ( array( 'Jordan Smith', '447700900000', 'SW1A 1AA', '1 Test Street', 'GB123456789', 'London' ) as $personal ) {
			$this->assertStringNotContainsString( $personal, $redacted );
		}

		$this->assertStringContainsString( 'buyer@example.com', $redacted );
		$this->assertSame( 'evt_1', Event::parse( $redacted )->id, 'Still an event.' );
	}

	public function test_redaction_leaves_a_body_it_cannot_read(): void {
		$this->assertSame( 'not json', Event::redact( 'not json' ) );
	}

	/* ─── Several secrets ─── */

	public function test_receive_any_says_which_secret_signed_it(): void {
		$body = self::body();

		[ $event, $label ] = Event::receive_any( $body, self::sign( $body, secret: 'whsec_sandbox' ), array(
			'platform' => self::SECRET,
			'sandbox'  => 'whsec_sandbox',
		), now: self::NOW );

		$this->assertSame( 'evt_1', $event->id );
		$this->assertSame( 'sandbox', $label );
	}

	/**
	 * A secret listed twice resolves to the earlier label, so the one that
	 * grants less trust belongs last.
	 */
	public function test_the_first_secret_that_verifies_wins(): void {
		$body = self::body();

		[ , $label ] = Event::receive_any( $body, self::sign( $body ), array( 0 => self::SECRET, 'sandbox' => self::SECRET ), now: self::NOW );

		$this->assertSame( 0, $label );
	}

	public function test_empty_secrets_are_skipped(): void {
		$body = self::body();

		[ , $label ] = Event::receive_any( $body, self::sign( $body ), array( 'blank' => '', 'real' => self::SECRET ), now: self::NOW );

		$this->assertSame( 'real', $label );
	}

	/**
	 * With one secret it refuses exactly as receive() does.
	 */
	public function test_receive_any_reports_the_first_refusal(): void {
		$body   = self::body();
		$header = self::sign( $body, secret: 'whsec_other' );

		try {
			Event::receive( $body, $header, self::SECRET, now: self::NOW );
			$this->fail( 'An unverified body was accepted.' );
		} catch ( InvalidEvent $single ) {
			$this->expectExceptionMessage( $single->getMessage() );
		}

		Event::receive_any( $body, $header, array( self::SECRET, 'whsec_two' ), now: self::NOW );
	}

	public function test_receive_any_with_no_secrets_refuses(): void {
		$this->expectException( InvalidEvent::class );

		Event::receive_any( self::body(), 't=1,v1=x', array( '', '' ) );
	}
}
