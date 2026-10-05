<?php
/**
 * Results that must not be ignored.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\{Client, Keyless, Keyring};

/**
 * Class MustCheckTest
 *
 * These answer "did it work?" or "is it allowed?", and code that ignores the
 * answer behaves as though it were yes. #[\NoDiscard] makes PHP warn where a
 * result is dropped; this keeps the attribute from being lost in a refactor.
 */
final class MustCheckTest extends TestCase {

	/**
	 * Every class that answers post() or attempt(), including the ones that
	 * override them: an override without the attribute drops the warning.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function methods(): array {
		$cases = array();

		foreach ( array( Client::class, Keyring::class, Keyless::class ) as $class ) {
			foreach ( array( 'post', 'attempt' ) as $method ) {
				$cases[ $class . '::' . $method ] = array( $class, $method );
			}
		}

		return $cases;
	}

	#[DataProvider( 'methods' )]
	public function test_the_result_must_be_used( string $class, string $method ): void {
		$attributes = ( new \ReflectionMethod( $class, $method ) )->getAttributes( \NoDiscard::class );

		$this->assertCount( 1, $attributes, $class . '::' . $method . ' lost #[\NoDiscard].' );
		$this->assertNotSame( '', $attributes[0]->newInstance()->message, 'Say why it matters.' );
	}
}
