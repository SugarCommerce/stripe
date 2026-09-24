<?php
/**
 * Client test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\HttpClient\{Fake, Response};
use SugarCommerce\Stripe\{Client, StripeError};

final class ClientTest extends TestCase {

	private string $log = '';

	/**
	 * get() and post() log what Stripe said. Keep that out of the output.
	 */
	protected function setUp(): void {
		$this->log = (string) ini_set( 'error_log', (string) tempnam( sys_get_temp_dir(), 'stripe' ) );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->log );
	}

	private function client( Fake $http ): Client {
		return new Client( 'sk_test_123', $http );
	}

	private static function json( array $body, int $status = 200 ): Response {
		return new Response( $status < 400, $status, (string) json_encode( $body ) );
	}

	public function test_it_is_configured_only_with_a_key(): void {
		$this->assertTrue( $this->client( new Fake() )->configured() );
		$this->assertFalse( ( new Client() )->configured() );
	}

	public function test_every_request_is_authenticated_and_pinned(): void {
		$http = ( new Fake() )->queue( self::json( array( 'id' => 'cus_1' ) ) );

		$this->client( $http )->get( 'customers/cus_1' );

		$headers = $http->requests[0]['headers'];

		$this->assertSame( 'https://api.stripe.com/v1/customers/cus_1', $http->requests[0]['url'] );
		$this->assertSame( 'Bearer sk_test_123', $headers['Authorization'] );
		$this->assertSame( Client::API_VERSION, $headers['Stripe-Version'] );
		$this->assertArrayNotHasKey( 'Stripe-Account', $headers, 'A platform request names no account.' );
	}

	public function test_a_connected_account_is_named_in_a_header(): void {
		$http = new Fake();

		$this->client( $http )->get( '/prices', 'acct_1', array( 'limit' => 3, 'expand' => array( 'data.product' ) ) );

		$this->assertSame( 'acct_1', $http->requests[0]['headers']['Stripe-Account'] );
		$this->assertSame( 'https://api.stripe.com/v1/prices?limit=3&expand%5B0%5D=data.product', $http->requests[0]['url'] );
	}

	public function test_get_answers_null_for_a_failure(): void {
		$this->assertNull( $this->client( ( new Fake() )->queue( self::json( array( 'error' => array() ), 404 ) ) )->get( 'x' ) );
		$this->assertNull( $this->client( ( new Fake() )->queue( new Response( true, 200, 'not json' ) ) )->get( 'x' ) );
	}

	public function test_fetch_returns_the_object(): void {
		$http = ( new Fake() )->queue( self::json( array( 'id' => 'cs_1' ) ) );

		$this->assertSame( array( 'id' => 'cs_1' ), $this->client( $http )->fetch( 'checkout/sessions/cs_1', 'acct_1' ) );
	}

	/**
	 * The point of fetch(): an outage is an exception, never an empty answer.
	 */
	public function test_fetch_throws_for_a_failure(): void {
		try {
			$this->client( ( new Fake() )->queue( Response::failure( 'timeout' ) ) )->fetch( 'checkout/sessions/cs_1' );
			$this->fail( 'An outage came back as an answer.' );
		} catch ( StripeError $e ) {
			$this->assertSame( 'checkout/sessions/cs_1', $e->path );
			$this->assertSame( 0, $e->status );
			$this->assertStringContainsString( 'timeout', $e->getMessage() );
		}
	}

	public function test_fetch_throws_for_a_refusal(): void {
		$this->expectException( StripeError::class );

		$this->client( ( new Fake() )->queue( self::json( array( 'error' => array( 'type' => 'invalid_request_error' ) ), 404 ) ) )->fetch( 'x' );
	}

	public function test_post_is_form_encoded_with_nested_keys(): void {
		$http = ( new Fake() )->queue( self::json( array( 'id' => 'plink_1' ) ) );

		$result = $this->client( $http )->post( 'payment_links', array( 'line_items' => array( array( 'price' => 'price_1', 'quantity' => 1 ) ) ), 'acct_1' );

		$this->assertSame( 'plink_1', $result['id'] );
		$this->assertSame( 'line_items%5B0%5D%5Bprice%5D=price_1&line_items%5B0%5D%5Bquantity%5D=1', $http->requests[0]['body'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $http->requests[0]['headers']['Content-Type'] );
	}

	public function test_session_line_items_are_the_list_data(): void {
		$http = ( new Fake() )->on( 'GET', '*/checkout/sessions/cs_1/line_items?limit=100', self::json( array( 'data' => array( array( 'id' => 'li_1' ) ) ) ) );

		$this->assertSame( array( array( 'id' => 'li_1' ) ), $this->client( $http )->session_line_items( 'cs_1', 'acct_1' ) );
	}

	public function test_session_line_items_throw_rather_than_answer_nothing(): void {
		$this->expectException( StripeError::class );

		$this->client( ( new Fake() )->queue( new Response( false, 500 ) ) )->session_line_items( 'cs_1', 'acct_1' );
	}

	public function test_a_receipt_is_read_from_the_charge(): void {
		$http = ( new Fake() )->on( 'GET', '*/charges?payment_intent=pi_1*', self::json( array( 'data' => array( array( 'receipt_url' => 'https://pay.stripe.com/r/1' ) ) ) ) );

		$this->assertSame( 'https://pay.stripe.com/r/1', $this->client( $http )->receipt_url( 'pi_1', 'acct_1' ) );
	}

	public function test_no_intent_means_no_receipt_and_no_request(): void {
		$http = new Fake();

		$this->assertSame( '', $this->client( $http )->receipt_url( '', 'acct_1' ) );
		$this->assertCount( 0, $http->requests );
	}
}
