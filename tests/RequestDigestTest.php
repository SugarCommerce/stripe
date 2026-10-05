<?php
/**
 * RequestDigest and AppSignature test suite.
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
use SugarCommerce\Stripe\{AppSignature, RequestDigest};

/**
 * Class RequestDigestTest
 *
 * The vectors in fixtures/request-digest.json are shared, byte for byte,
 * with @arraypress/stripe-app: the browser and the server must build the
 * same digest, or every signed call is refused.
 */
final class RequestDigestTest extends TestCase {

	private const string SECRET = 'absec_test';

	private const int NOW = 1_800_000_000;

	/**
	 * The shared vectors.
	 *
	 * @return array
	 */
	private static function vectors(): array {
		return (array) json_decode( (string) file_get_contents( __DIR__ . '/fixtures/request-digest.json' ), true );
	}

	/**
	 * @return array<int, array{array, string}>
	 */
	public static function queries(): array {
		return array_map( static fn ( array $v ): array => array( (array) $v['query'], $v['canonical'] ), self::vectors()['queries'] );
	}

	/**
	 * @return array<int, array{string, string, string, string}>
	 */
	public static function digests(): array {
		return array_map( static fn ( array $v ): array => array( $v['method'], $v['path'], $v['content'], $v['digest'] ), self::vectors()['digests'] );
	}

	/* ─── Digests ─── */

	#[DataProvider( 'queries' )]
	public function test_a_query_is_canonical( array $query, string $canonical ): void {
		$this->assertSame( $canonical, RequestDigest::canonical_query( $query ) );
	}

	#[DataProvider( 'digests' )]
	public function test_a_digest_matches_the_shared_vector( string $method, string $path, string $content, string $digest ): void {
		$this->assertSame( $digest, RequestDigest::of( $method, $path, $content ) );
	}

	public function test_the_encoding_is_rfc_3986(): void {
		foreach ( self::vectors()['encode'] as $vector ) {
			$this->assertSame( 'k=' . $vector['encoded'], RequestDigest::canonical_query( array( 'k' => $vector['value'] ) ) );
		}
	}

	/**
	 * A GET is digested by its query, a POST by its raw body.
	 */
	public function test_a_received_request_is_digested_by_what_it_carries(): void {
		$query = array( 'b' => '2', 'a' => '1' );

		$this->assertSame( RequestDigest::of( 'GET', '/api/x', 'a=1&b=2' ), RequestDigest::of_request( 'GET', '/api/x', $query, 'ignored' ) );
		$this->assertSame( RequestDigest::of( 'POST', '/api/x', '{"a":1}' ), RequestDigest::of_request( 'POST', '/api/x', $query, '{"a":1}' ) );
	}

	/**
	 * No client sends an array in a signed query, so none is digested.
	 */
	public function test_a_query_no_client_could_sign_has_no_digest(): void {
		$this->assertNull( RequestDigest::canonical_query( array( 'a' => array( 'b' ) ) ) );
		$this->assertSame( '', RequestDigest::of_request( 'GET', '/api/x', array( 'a' => array( 'b' ) ), null ) );
	}

	/* ─── App signatures ─── */

	/**
	 * A header as Stripe builds one for fetchStripeSignature().
	 *
	 * @param array $fields Signed fields, in order.
	 */
	private static function sign( array $fields, string $secret = self::SECRET ): string {
		$payload = (string) json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return 't=' . self::NOW . ',v1=' . hash_hmac( 'sha256', self::NOW . '.' . $payload, $secret );
	}

	public function test_a_signed_identity_verifies(): void {
		$header = self::sign( array( 'user_id' => 'usr_1', 'account_id' => 'acct_1' ) );

		$this->assertTrue( AppSignature::verify( $header, self::SECRET, 'usr_1', 'acct_1', now: self::NOW ) );
		$this->assertFalse( AppSignature::verify( $header, self::SECRET, 'usr_1', 'acct_2', now: self::NOW ), 'A claimed account is proven, not believed.' );
		$this->assertFalse( AppSignature::verify( $header, 'absec_other', 'usr_1', 'acct_1', now: self::NOW ) );
		$this->assertFalse( AppSignature::verify( $header, '', 'usr_1', 'acct_1', now: self::NOW ) );
	}

	/**
	 * Stripe does not document where extra fields land, so both placements
	 * verify — and a digest of another request does not.
	 */
	public function test_extra_fields_verify_wherever_they_land(): void {
		$digest = array( 'request' => RequestDigest::of( 'POST', '/api/x', '{}' ) );

		$after  = self::sign( array( 'user_id' => 'usr_1', 'account_id' => 'acct_1' ) + $digest );
		$before = self::sign( $digest + array( 'user_id' => 'usr_1', 'account_id' => 'acct_1' ) );

		$this->assertTrue( AppSignature::verify( $after, self::SECRET, 'usr_1', 'acct_1', $digest, now: self::NOW ) );
		$this->assertTrue( AppSignature::verify( $before, self::SECRET, 'usr_1', 'acct_1', $digest, now: self::NOW ) );
		$this->assertFalse( AppSignature::verify( $after, self::SECRET, 'usr_1', 'acct_1', array( 'request' => 'another' ), now: self::NOW ) );
	}

	/**
	 * The proof is what cannot change without failing: appending to the
	 * header still verifies, and still proves the same thing.
	 */
	public function test_the_proof_ignores_what_can_be_added_to_a_header(): void {
		$header = self::sign( array( 'user_id' => 'usr_1', 'account_id' => 'acct_1' ) );
		$proof  = AppSignature::proof( $header, self::SECRET, 'usr_1', 'acct_1', now: self::NOW );

		$this->assertStringStartsWith( self::NOW . '.', $proof );
		$this->assertSame( $proof, AppSignature::proof( $header . ',x=1', self::SECRET, 'usr_1', 'acct_1', now: self::NOW ) );
		$this->assertSame( '', AppSignature::proof( '', self::SECRET, 'usr_1', 'acct_1', now: self::NOW ) );
	}
}
