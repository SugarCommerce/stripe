<?php
/**
 * Keyring and Keyless test suite.
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
use SugarCommerce\HttpClient\{Fake, Response};
use SugarCommerce\Stripe\{Client, Keyless, Keyring, StripeError};

/**
 * Class KeyringTest
 *
 * One key per mode, a sandbox's own key for a sandbox install, and a mode
 * with no key that says so instead of asking Stripe with an empty bearer.
 */
final class KeyringTest extends TestCase {

	private string $log = '';

	private string $file = '';

	protected function setUp(): void {
		$this->file = (string) tempnam( sys_get_temp_dir(), 'keyring' );
		$this->log  = (string) ini_set( 'error_log', $this->file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->log );
		@unlink( $this->file );
	}

	/* ─── Picking ─── */

	public function test_a_client_that_is_not_a_keyring_is_left_alone(): void {
		$client = new Client( 'sk_test_1', new Fake() );

		$this->assertSame( $client, Keyring::pick( $client, 'live', 'acct_1' ) );
	}

	public function test_each_mode_gets_its_own_client(): void {
		$live = new Client( 'sk_live_1', new Fake() );
		$test = new Client( 'sk_test_1', new Fake() );
		$ring = new Keyring( $live, $test );

		$this->assertSame( $live, Keyring::pick( $ring, 'live', 'acct_1' ) );
		$this->assertSame( $test, Keyring::pick( $ring, 'test', 'acct_1' ) );
	}

	/**
	 * Anything but live is test: a typo should never reach live money.
	 */
	public function test_an_unknown_mode_is_test(): void {
		$live = new Client( 'sk_live_1', new Fake() );
		$test = new Client( 'sk_test_1', new Fake() );

		$this->assertSame( $test, ( new Keyring( $live, $test ) )->mode( 'LIVE' ) );
	}

	public function test_a_sandbox_install_gets_the_sandbox_key_and_no_live_mode(): void {
		$live    = new Client( 'sk_live_1', new Fake() );
		$test    = new Client( 'sk_test_1', new Fake() );
		$sandbox = new Client( 'sk_test_sbx', new Fake() );
		$ring    = new Keyring( $live, $test, null, $sandbox, static fn ( string $account ): bool => 'acct_SBX' === $account );

		$this->assertSame( $sandbox, $ring->mode( 'test', 'acct_SBX' ) );
		$this->assertSame( $test, $ring->mode( 'test', 'acct_STD' ) );
		$this->assertSame( $live, $ring->mode( 'live', 'acct_STD' ) );

		$refused = $ring->mode( 'live', 'acct_SBX' );

		$this->assertInstanceOf( Keyless::class, $refused );
		$this->assertSame( Keyring::NO_LIVE_MODE, $refused->reason );
	}

	public function test_a_sandbox_install_with_no_sandbox_key_is_refused(): void {
		$ring = new Keyring( new Client( 'sk_live_1' ), new Client( 'sk_test_1' ), sandboxed: static fn (): bool => true );

		$this->assertFalse( $ring->mode( 'test', 'acct_SBX' )->configured() );
	}

	/**
	 * Used bare, it is the platform's own client.
	 */
	public function test_bare_calls_go_to_the_platform_client(): void {
		$http = ( new Fake() )->queue( new Response( true, 200, '{"id":"sub_1"}' ) );
		$ring = new Keyring( new Client( 'sk_live_1', new Fake() ), new Client( 'sk_test_1', $http ), new Client( 'sk_test_1', $http ) );

		$this->assertSame( 'sub_1', $ring->fetch( 'subscriptions/sub_1' )['id'] );
		$this->assertTrue( $ring->configured() );
	}

	public function test_from_keys_builds_a_client_per_mode(): void {
		$http = new Fake();
		$ring = Keyring::from_keys( 'sk_live_1', '', platform: 'live', http: $http );

		$this->assertTrue( $ring->mode( 'live' )->configured() );
		$this->assertInstanceOf( Keyless::class, $ring->mode( 'test' ) );
		$this->assertTrue( $ring->configured() );

		$ring->mode( 'live' )->get( 'account' );

		$this->assertSame( 'Bearer sk_live_1', $http->requests[0]['headers']['Authorization'] );
	}

	/* ─── Keys ─── */

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function keys(): array {
		return array(
			'live secret'     => array( 'sk_live_abc', 'live' ),
			'test secret'     => array( 'sk_test_abc', 'test' ),
			'live restricted' => array( 'rk_live_abc', 'live' ),
			'test restricted' => array( 'rk_test_abc', 'test' ),
			'publishable'     => array( 'pk_live_abc', '' ),
			'webhook secret'  => array( 'whsec_abc', '' ),
			'nonsense'        => array( 'sk_livex_abc', '' ),
			'empty'           => array( '', '' ),
		);
	}

	#[DataProvider( 'keys' )]
	public function test_a_keys_mode_is_its_prefix( string $key, string $mode ): void {
		$this->assertSame( $mode, Keyring::key_mode( $key ) );
	}

	/* ─── Keyless ─── */

	/**
	 * A read that must succeed throws, so a webhook in a mode with no key is
	 * retried rather than finished with nothing; and nothing is sent.
	 */
	public function test_keyless_refuses_without_calling(): void {
		$client = new Keyless( 'no test-mode key is set (STRIPE_KEY_TEST).' );

		$this->assertNull( $client->get( 'prices' ) );
		$this->assertNull( $client->post( 'payment_links', array() ) );
		$this->assertNull( $client->charge_for_intent( 'pi_1' ) );
		$this->assertFalse( $client->configured() );
		$this->assertStringContainsString( 'Stripe prices not called: no test-mode key', (string) file_get_contents( $this->file ) );
	}

	/**
	 * @return array<string, array{\Closure}>
	 */
	public static function must_succeed(): array {
		return array(
			'fetch'      => array( static fn ( Keyless $c ): mixed => $c->fetch( 'checkout/sessions/cs_1' ) ),
			'attempt'    => array( static fn ( Keyless $c ): mixed => $c->attempt( 'checkout/sessions', array() ) ),
			'line items' => array( static fn ( Keyless $c ): mixed => $c->session_line_items( 'cs_1', 'acct_1' ) ),
			'each'       => array( static fn ( Keyless $c ): mixed => $c->fetch_all( 'prices' ) ),
		);
	}

	#[DataProvider( 'must_succeed' )]
	public function test_keyless_throws_where_an_answer_is_required( \Closure $call ): void {
		$this->expectException( StripeError::class );
		$this->expectExceptionMessage( 'STRIPE_KEY_TEST' );

		$call( new Keyless( 'no test-mode key is set (STRIPE_KEY_TEST).' ) );
	}

	public function test_a_dumped_keyring_shows_none_of_its_keys(): void {
		$dump = print_r( Keyring::from_keys( 'sk_live_secret1', 'sk_test_secret2', 'sk_test_secret3' ), true );

		$this->assertStringNotContainsString( 'secret', $dump );
	}
}
