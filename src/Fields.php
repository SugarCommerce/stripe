<?php
/**
 * Typed reads from a decoded Stripe object.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class Fields
 *
 * A decoded Stripe object is an array of anything: a field may be a string
 * in one API version and an object in the next, absent from a stored
 * payload, or null where Stripe has nothing to say. A bare `(string)` or
 * `(int)` on one of those turns an array into "Array" with a warning, and an
 * offset on a string into a fatal error. Every read in this package goes
 * through here instead, and gets the type it asked for or the default.
 *
 * Internal: the readers in Shape, Cart and Event are the public API.
 *
 * @internal
 *
 * @since 1.5.0
 */
final class Fields {

	/**
	 * The value at a path into nested arrays, or null where the path stops.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed      $data The decoded object.
	 * @param int|string ...$path Keys, outermost first.
	 *
	 * @return mixed
	 */
	public static function at( mixed $data, int|string ...$path ): mixed {
		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}

			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * A value as an array, or an empty one.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value Anything.
	 *
	 * @return array<mixed>
	 */
	public static function map( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The arrays in a list, in order, anything else dropped.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value A list of objects, or anything.
	 *
	 * @return list<array<mixed>>
	 */
	public static function objects( mixed $value ): array {
		return array_values( array_filter( self::map( $value ), 'is_array' ) );
	}

	/**
	 * A scalar as a string; anything else as the default.
	 *
	 * The same answer `(string)` gives a scalar, without "Array" for an
	 * object that should have been an id.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed  $value   Anything.
	 * @param string $default For null, an array or an object.
	 *
	 * @return string
	 */
	public static function string( mixed $value, string $default = '' ): string {
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * A number as an integer; anything else as the default.
	 *
	 * An integer, a float or a numeric string, as `(int)` reads it. Amounts
	 * from Stripe are integers in minor units already; this exists so that a
	 * `"1000"` from a form or a stored payload still reads as 1000, and a
	 * `null` does not read as 0 where 0 would mean "free".
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value   Anything.
	 * @param int   $default For anything that is not a number.
	 *
	 * @return int
	 */
	public static function int( mixed $value, int $default = 0 ): int {
		return self::int_or_null( $value ) ?? $default;
	}

	/**
	 * A number as an integer, or null when it is not one.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value Anything.
	 *
	 * @return int|null
	 */
	public static function int_or_null( mixed $value ): ?int {
		if ( is_string( $value ) && is_numeric( $value ) ) {
			$value = 0 + $value;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		// A float outside the integer range, or not finite, has no integer
		// to be; casting it is a warning and a meaningless number.
		if ( is_float( $value ) ) {
			return is_finite( $value ) && abs( $value ) < 9.2e18 ? (int) $value : null;
		}

		return is_bool( $value ) ? (int) $value : null;
	}

	/**
	 * Scalars in a list, as strings, anything else dropped.
	 *
	 * @since 1.5.0
	 *
	 * @param mixed $value A list, or anything.
	 *
	 * @return list<string>
	 */
	public static function strings( mixed $value ): array {
		$out = array();

		foreach ( self::map( $value ) as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}

		return $out;
	}
}
