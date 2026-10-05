<?php
/**
 * Verifying a request signed by a Stripe App's front end.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

use SugarCommerce\Webhooks\Signature;

/**
 * Class AppSignature
 *
 * A Stripe App's view calls `fetchStripeSignature()`, and Stripe signs the
 * JSON of `{user_id, account_id, …}` with the app's signing secret, in the
 * same `t=…,v1=…` form as a webhook. The backend rebuilds that JSON from the
 * identity the request claims and checks the header — so the identity is
 * proven by Stripe, not asserted by the browser.
 *
 * Extra fields are signed too; a RequestDigest is the one worth adding,
 * because without it a captured signature is good for any route and any
 * body for five minutes.
 *
 * Stripe documents the identity fields' order — `user_id` before
 * `account_id` — but not where additional fields land, so both placements
 * are accepted. Either way every field is inside the signed bytes, which is
 * the only thing that matters.
 *
 * @since 1.2.0
 */
final readonly class AppSignature {

	/**
	 * Whether a request's signature proves the identity it claims.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $header    The Stripe-Signature header.
	 * @param string   $secret    The app's signing secret, `absec_…`.
	 * @param string   $user      Claimed user id.
	 * @param string   $account   Claimed account id.
	 * @param array    $extra     Other signed fields, e.g.
	 *                            `array( 'request' => RequestDigest::of(…) )`.
	 * @param int      $tolerance Seconds of clock skew allowed.
	 * @param int|null $now       Current time. For tests.
	 *
	 * @return bool
	 */
	public static function verify(
		string $header,
		#[\SensitiveParameter] string $secret,
		string $user,
		string $account,
		array $extra = array(),
		int $tolerance = Signature::DEFAULT_TOLERANCE,
		?int $now = null
	): bool {
		return '' !== self::proof( $header, $secret, $user, $account, $extra, $tolerance, $now );
	}

	/**
	 * What a valid signature proves, as a string that cannot vary, or ''.
	 *
	 * The signed timestamp and the v1 value that matched — the part of the
	 * header that cannot change without failing to verify. The rest can:
	 * `,x=1` appended, a second v1, the parts reordered, all still verify.
	 * So a single-use guard must be keyed on this, never on the raw header,
	 * or a copy steps round it by adding a comma.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $header    The Stripe-Signature header.
	 * @param string   $secret    The app's signing secret.
	 * @param string   $user      Claimed user id.
	 * @param string   $account   Claimed account id.
	 * @param array    $extra     Other signed fields.
	 * @param int      $tolerance Seconds of clock skew allowed.
	 * @param int|null $now       Current time. For tests.
	 *
	 * @return string "{timestamp}.{v1}", or '' when it does not verify.
	 */
	public static function proof(
		string $header,
		#[\SensitiveParameter] string $secret,
		string $user,
		string $account,
		array $extra = array(),
		int $tolerance = Signature::DEFAULT_TOLERANCE,
		?int $now = null
	): string {
		if ( '' === $secret || '' === $header ) {
			return '';
		}

		$identity = array( 'user_id' => $user, 'account_id' => $account );

		foreach ( $extra ? array( $identity + $extra, $extra + $identity ) : array( $identity ) as $fields ) {
			$payload = self::payload( $fields );
			$check   = Signature::verify( $payload, $header, $secret, $tolerance, $now );

			if ( ! $check->failed_check() ) {
				// The v1 that matched is, by definition, this HMAC.
				return $check->timestamp . '.' . hash_hmac( 'sha256', $check->timestamp . '.' . $payload, $secret );
			}
		}

		return '';
	}

	/**
	 * The JSON Stripe signs, as the front end's JSON.stringify writes it.
	 *
	 * @since 1.2.0
	 *
	 * @param array $fields Signed fields, in order.
	 *
	 * @return string
	 */
	public static function payload( array $fields ): string {
		return (string) json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
