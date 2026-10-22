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

	/**
	 * The version every Shape read, the Stripe App's front end and the
	 * application's webhook endpoints are written against. A change here is
	 * a migration, not a constant: this test makes it a deliberate one.
	 */
	public function test_the_pinned_version_is_endive(): void {
		$this->assertSame( '2026-09-30.endive', Client::API_VERSION );
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

	/* ─── Structured errors ─── */

	public function test_a_refusal_carries_stripes_error_object(): void {
		$http = ( new Fake() )->queue( self::json( array( 'error' => array(
			'type'    => 'invalid_request_error',
			'code'    => 'resource_missing',
			'param'   => 'line_items[0][price]',
			'message' => 'No such price: price_gone',
		) ), 400 ) );

		try {
			$this->client( $http )->fetch( 'prices/price_gone' );
			$this->fail( 'A refusal came back as an answer.' );
		} catch ( StripeError $e ) {
			$this->assertSame( 400, $e->status );
			$this->assertSame( 'invalid_request_error', $e->type() );
			$this->assertSame( 'resource_missing', $e->code() );
			$this->assertSame( 'line_items[0][price]', $e->param() );
			$this->assertSame( 'No such price: price_gone', $e->message() );
			$this->assertSame( '', $e->decline_code() );
		}
	}

	public function test_a_transport_failure_has_no_error_object(): void {
		$error = StripeError::from_response( 'x', Response::failure( 'Could not resolve host' ) );

		$this->assertSame( array(), $error->error );
		$this->assertSame( '', $error->code() );
		$this->assertStringContainsString( 'Could not resolve host', $error->getMessage() );
	}

	/* ─── attempt() ─── */

	public function test_attempt_returns_the_object(): void {
		$http = ( new Fake() )->queue( self::json( array( 'id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/1' ) ) );

		$session = $this->client( $http )->attempt( 'checkout/sessions', array( 'mode' => 'payment' ), 'acct_1' );

		$this->assertSame( 'cs_1', $session['id'] );
		$this->assertSame( 'mode=payment', $http->requests[0]['body'] );
		$this->assertSame( 'acct_1', $http->requests[0]['headers']['Stripe-Account'] );
		$this->assertSame( Client::API_VERSION, $http->requests[0]['headers']['Stripe-Version'] );
	}

	/**
	 * The point of attempt(): Stripe's reason survives to the caller.
	 */
	public function test_attempt_throws_with_the_reason(): void {
		$http = ( new Fake() )->queue( self::json( array( 'error' => array( 'code' => 'amount_too_small' ) ), 400 ) );

		try {
			$session = $this->client( $http )->attempt( 'checkout/sessions', array() );
			$this->fail( 'Got ' . json_encode( $session ) );
		} catch ( StripeError $e ) {
			$this->assertSame( 'amount_too_small', $e->code() );
			$this->assertSame( 'checkout/sessions', $e->path );
		}
	}

	/* ─── Paging ─── */

	/**
	 * A shop's hundred-and-first price used to vanish: one call, no paging.
	 */
	public function test_each_follows_the_cursor_to_the_end(): void {
		$http = ( new Fake( null ) )
			->on( 'GET', '*/prices?starting_after=price_2*', self::json( array( 'has_more' => false, 'data' => array( array( 'id' => 'price_3' ) ) ) ) )
			->on( 'GET', '*/prices?*', self::json( array( 'has_more' => true, 'data' => array( array( 'id' => 'price_1' ), array( 'id' => 'price_2' ) ) ) ) );

		$ids = array_column( $this->client( $http )->fetch_all( 'prices', 'acct_1', array( 'active' => 'true' ) ), 'id' );

		$this->assertSame( array( 'price_1', 'price_2', 'price_3' ), $ids );
		$this->assertCount( 2, $http->requests );
		$this->assertStringContainsString( 'active=true', $http->requests[1]['url'], 'Every page keeps the filters.' );
		$this->assertStringContainsString( 'limit=100', $http->requests[0]['url'] );
	}

	public function test_a_failed_page_throws_rather_than_answering_half_a_list(): void {
		$http = ( new Fake( null ) )
			->on( 'GET', '*/prices?starting_after=*', new Response( false, 500, 'down' ) )
			->on( 'GET', '*/prices?*', self::json( array( 'has_more' => true, 'data' => array( array( 'id' => 'price_1' ) ) ) ) );

		$this->expectException( StripeError::class );

		$this->client( $http )->fetch_all( 'prices' );
	}

	/**
	 * A list that says "more" without moving the cursor would be asked for
	 * the same page until the page limit.
	 */
	public function test_each_stops_when_the_cursor_does_not_move(): void {
		$http = ( new Fake( self::json( array( 'has_more' => true, 'data' => array() ) ) ) );

		$this->assertSame( array(), $this->client( $http )->fetch_all( 'prices' ) );
		$this->assertCount( 1, $http->requests );
	}

	public function test_each_reads_no_more_than_it_is_allowed(): void {
		$page = 0;
		$http = ( new Fake( null ) )->on( 'GET', '*', static function () use ( &$page ): Response {
			++$page;

			return self::json( array( 'has_more' => true, 'data' => array( array( 'id' => 'obj_' . $page ) ) ) );
		} );

		$this->assertCount( 3, $this->client( $http )->fetch_all( 'customers', null, array(), 3 ) );
	}

	/* ─── Charges and the portal ─── */

	public function test_the_charge_for_an_intent_is_read_once(): void {
		$http = ( new Fake() )->on( 'GET', '*/charges?payment_intent=pi_1*', self::json( array( 'data' => array( array( 'id' => 'ch_1', 'billing_details' => array( 'address' => array( 'country' => 'FR' ) ) ) ) ) ) );

		$charge = $this->client( $http )->charge_for_intent( 'pi_1', 'acct_1' );

		$this->assertSame( 'ch_1', $charge['id'] );
		$this->assertNull( $this->client( new Fake() )->charge_for_intent( '' ) );
		$this->assertNull( $this->client( ( new Fake() )->queue( self::json( array( 'data' => array() ) ) ) )->charge_for_intent( 'pi_2' ) );
	}

	public function test_a_portal_session_is_minted_for_a_customer(): void {
		$http = ( new Fake() )->queue( self::json( array( 'url' => 'https://billing.stripe.com/p/1' ) ) );

		$this->assertSame( 'https://billing.stripe.com/p/1', $this->client( $http )->portal_url( 'cus_1', 'https://example.com/back', 'acct_1' ) );
		$this->assertSame( 'customer=cus_1&return_url=https%3A%2F%2Fexample.com%2Fback', $http->requests[0]['body'] );
		$this->assertSame( '', $this->client( new Fake() )->portal_url( '', 'https://example.com' ), 'No customer, no call.' );
	}

	/* ─── Hardening (1.5.0) ─────────────────────────────────────────── */

	/**
	 * `'prices/' . $id` with an id from a buyer: dot segments are collapsed
	 * on the way out, and a `?` starts a query, so the platform key would
	 * be used on an endpoint the caller never named.
	 */
	public function test_a_path_that_is_not_names_and_ids_is_never_sent(): void {
		$http   = new Fake();
		$client = $this->client( $http );

		$this->assertNull( $client->get( 'prices/../customers/cus_1', 'acct_1' ) );
		$this->assertNull( $client->get( 'prices/price_1?expand[]=customer' ) );
		$this->assertNull( $client->post( 'subscriptions/sub_1/../../customers', array( 'email' => 'x@example.com' ) ) );
		$this->assertNull( $client->get( 'prices//price_1' ) );
		$this->assertSame( array(), $http->requests->getArrayCopy() );

		$this->expectException( StripeError::class );

		$client->fetch( 'checkout/sessions/%2e%2e' );
	}

	/**
	 * Stripe-Account is a header naming who to act for; anything that is
	 * not an account id is not sent to name one.
	 */
	public function test_an_account_that_is_not_an_account_id_is_never_sent(): void {
		$http = new Fake();

		$this->assertNull( $this->client( $http )->get( 'customers/cus_1', 'acct_1 ' ) );
		$this->assertNull( $this->client( $http )->get( 'customers/cus_1', '' ) );
		$this->assertSame( array(), $http->requests->getArrayCopy() );
	}

	public function test_a_dumped_client_does_not_show_its_key(): void {
		$dump = print_r( $this->client( new Fake() ), true );

		$this->assertStringNotContainsString( 'sk_test_123', $dump );
		$this->assertStringContainsString( Client::API_VERSION, $dump );
	}
}
