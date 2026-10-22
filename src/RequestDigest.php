<?php
/**
 * A digest of a request, for a Stripe App to sign.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Class RequestDigest
 *
 * A Stripe App's front end can ask Stripe to sign a payload for its
 * backend, and what Stripe signs is only who is asking: the user and the
 * account. A captured signature is then good for any route and any body
 * for five minutes. Signing a digest of the request as well binds the
 * signature to this method, this route and these bytes.
 *
 * Both ends must build the same string, and a difference of one character
 * is every call refused. So the rules are small and fixed:
 *
 * - The digest is sha256 of "METHOD /route sha256(content)", hashed twice
 *   so the signed value is hex whatever the body holds — a slash or an
 *   accent would otherwise depend on two JSON encoders escaping it alike.
 * - The content of a POST is its raw body; of a GET, its canonical query:
 *   keys sorted, keys and values percent-encoded per RFC 3986 (PHP's
 *   rawurlencode). Not the query on the wire: URLSearchParams writes a
 *   space as `+`, and the backend only ever sees decoded values.
 *
 * `@arraypress/stripe-app` builds the same digest in the browser, against
 * the same test vectors.
 *
 * @since 1.2.0
 */
final readonly class RequestDigest {

	/**
	 * The digest of a request.
	 *
	 * @since 1.2.0
	 *
	 * @param string $method  GET or POST.
	 * @param string $path    The route as the server sees it, e.g. '/api/files'.
	 * @param string $content The exact body of a POST, or canonical_query()
	 *                        of a GET.
	 *
	 * @return string Lower-case hex.
	 */
	public static function of( string $method, string $path, string $content ): string {
		return hash( 'sha256', strtoupper( $method ) . ' ' . $path . ' ' . hash( 'sha256', $content ) );
	}

	/**
	 * The digest of a request as a server received it, or ''.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $method GET or POST.
	 * @param string      $path   The route.
	 * @param array<mixed> $query Decoded query parameters.
	 * @param string|null $body   Raw body.
	 *
	 * @return string Hex digest, or '' for a request no client could have
	 *                signed: a GET whose query holds an array.
	 */
	public static function of_request( string $method, string $path, array $query, ?string $body ): string {
		$content = 'GET' === strtoupper( $method ) ? self::canonical_query( $query ) : $body;

		return null === $content ? '' : self::of( $method, $path, $content );
	}

	/**
	 * A query in the one form both ends can rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @param array<mixed> $query Decoded query parameters.
	 *
	 * @return string|null Null when a value is not a plain scalar, which a
	 *                     signing client never sends.
	 */
	public static function canonical_query( array $query ): ?string {
		ksort( $query, SORT_STRING );

		$pairs = array();

		foreach ( $query as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}

			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $pairs );
	}
}
