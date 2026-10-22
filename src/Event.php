<?php
/**
 * A Stripe event, verified and unpacked.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

use SugarCommerce\Webhooks\Signature;

/**
 * Class Event
 *
 * The envelope every webhook arrives in: which event, of what type, for which
 * connected account, in which mode, and when Stripe created it.
 *
 * `account` is the field a Connect platform lives by. An event with one
 * happened in a connected account; an event without one happened in the
 * platform's own. Code that reads the object and forgets the envelope cannot
 * tell a seller's customer paying the seller from a seller paying the
 * platform.
 *
 * `created` is the other one worth keeping. Stripe does not deliver in order,
 * so a handler that applies every update as it arrives can let an older state
 * overwrite a newer one; comparing `created` is how it does not.
 *
 * @since 1.0.0
 */
final readonly class Event {

	/**
	 * Keys whose values are personal and never needed to act on an event.
	 */
	private const array PERSONAL = array( 'name', 'phone', 'line1', 'line2', 'postal_code', 'address' );

	/**
	 * Sections of an object that describe a person as a whole.
	 */
	private const array IDENTITY = array( 'customer_details', 'billing_details', 'shipping_details' );

	/**
	 * Fields within those sections that are removed wholesale.
	 */
	private const array IDENTITY_FIELDS = array( 'address', 'name', 'phone', 'tax_ids' );

	/**
	 * @since 1.0.0
	 *
	 * @param string       $id      Event id, `evt_…`.
	 * @param string       $type    Event type, e.g. `checkout.session.completed`.
	 * @param string       $account Connected account id, or '' for the
	 *                              platform's own account.
	 * @param bool         $live    Whether it happened in live mode.
	 * @param int          $created When Stripe created it. 0 when absent.
	 * @param array<mixed> $object `data.object`: the thing the event is about.
	 * @param array<mixed> $data   The whole decoded event.
	 */
	public function __construct(
		public string $id,
		public string $type,
		public string $account,
		public bool $live,
		public int $created,
		public array $object,
		public array $data,
	) {}

	/**
	 * Verify a webhook request and unpack it.
	 *
	 * Stripe signs `timestamp.payload` with HMAC-SHA256 and sends it as
	 * `t=…,v1=…` — the same construction sugarcommerce/webhooks signs with,
	 * so its verifier checks Stripe's header unchanged, including the replay
	 * window.
	 *
	 * Verified means Stripe sent it, not that it is new: Stripe delivers an
	 * event more than once, and a genuine request can be replayed inside the
	 * tolerance. Record each event's id and do its work once.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $body      Raw request body, exactly as received.
	 * @param string   $header    The Stripe-Signature header.
	 * @param string   $secret    The endpoint's signing secret, `whsec_…`.
	 * @param int      $tolerance Seconds of clock skew allowed.
	 * @param int|null $now       Current time. For tests.
	 *
	 * @return self
	 *
	 * @throws InvalidEvent When the signature does not verify or the body is
	 *                      not an event.
	 */
	public static function receive(
		string $body,
		string $header,
		#[\SensitiveParameter] string $secret,
		int $tolerance = Signature::DEFAULT_TOLERANCE,
		?int $now = null
	): self {
		$check = Signature::verify( $body, $header, $secret, $tolerance, $now );

		if ( $check->failed_check() ) {
			throw new InvalidEvent( $check->reason );
		}

		return self::parse( $body ) ?? throw new InvalidEvent( 'Malformed event payload.' );
	}

	/**
	 * Verify a webhook request against several secrets, and say which one
	 * signed it.
	 *
	 * Stripe gives every endpoint its own secret, so one URL registered
	 * twice — once for the platform's own events, once for connected
	 * accounts', or once in a sandbox — has several, and which one verifies
	 * is itself an answer: where the event came from. Tried in the order
	 * given, so put the common case first and it costs one HMAC; put a
	 * secret whose label grants less trust last, so a secret shared by
	 * mistake resolves to the label listed earlier.
	 *
	 * @since 1.2.0
	 *
	 * @param string                    $body      Raw request body.
	 * @param string                    $header    The Stripe-Signature header.
	 * @param array<int|string, string> $secrets   Signing secrets keyed by a
	 *                                             label of the caller's
	 *                                             choosing. Empty ones are
	 *                                             skipped.
	 * @param int                       $tolerance Seconds of clock skew.
	 * @param int|null                  $now       Current time. For tests.
	 *
	 * @return array{0: self, 1: int|string} The event, and the label of the
	 *                                       secret that verified it.
	 *
	 * @throws InvalidEvent When none verifies it — with the first secret's
	 *                      reason, which with one secret is exactly what
	 *                      receive() says — or none is configured.
	 */
	public static function receive_any(
		string $body,
		string $header,
		#[\SensitiveParameter] array $secrets,
		int $tolerance = Signature::DEFAULT_TOLERANCE,
		?int $now = null
	): array {
		$failure = null;

		foreach ( $secrets as $label => $secret ) {
			if ( '' === (string) $secret ) {
				continue;
			}

			try {
				return array( self::receive( $body, $header, (string) $secret, $tolerance, $now ), $label );
			} catch ( InvalidEvent $e ) {
				$failure ??= $e;
			}
		}

		throw $failure ?? new InvalidEvent( 'No signing secret is configured for this endpoint.' );
	}

	/**
	 * Unpack an event without verifying it.
	 *
	 * For a payload that was verified when it arrived — a stored one being
	 * replayed. Never for a request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Event JSON.
	 *
	 * @return self|null Null when the body is not an event.
	 */
	public static function parse( string $body ): ?self {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! is_string( $data['id'] ?? null ) || ! is_string( $data['type'] ?? null ) ) {
			return null;
		}

		$object = Fields::at( $data, 'data', 'object' );

		return new self(
			$data['id'],
			$data['type'],
			is_string( $data['account'] ?? null ) ? $data['account'] : '',
			! empty( $data['livemode'] ),
			Fields::int( $data['created'] ?? null ),
			is_array( $object ) ? $object : array(),
			$data
		);
	}

	/**
	 * 'live' or 'test'.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->live ? 'live' : 'test';
	}

	/**
	 * Whether it happened in the platform's own account rather than a
	 * connected one.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function platform(): bool {
		return '' === $this->account;
	}

	/**
	 * The same event attributed to a connected account.
	 *
	 * For development: Stripe creates no Connection when an app is installed
	 * in the account that built it, so every event arrives with no account
	 * and the connected-account path cannot be exercised with one account.
	 *
	 * @since 1.0.0
	 *
	 * @param string $account Connected account id.
	 *
	 * @return self
	 */
	public function for_account( string $account ): self {
		return clone( $this, array( 'account' => $account ) );
	}

	/**
	 * An event body with the buyer's identity removed, for storage.
	 *
	 * Keeping payloads lets a bug be replayed against fixed code, but a raw
	 * event carries the buyer's name, postal address, phone and tax ids —
	 * rarely needed, and a liability once stored. Email survives: most
	 * replays need it, and applications usually hold it already.
	 *
	 * A body that is not JSON comes back unchanged, so the caller still has
	 * something to look at.
	 *
	 * @since 1.0.0
	 *
	 * @param string $body Event JSON.
	 *
	 * @return string Redacted JSON.
	 */
	public static function redact( string $body ): string {
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return $body;
		}

		array_walk_recursive( $data, static function ( mixed &$value, int|string $key ): void {
			if ( in_array( $key, self::PERSONAL, true ) ) {
				$value = '[redacted]';
			}
		} );

		// array_walk_recursive only visits leaves, so a key whose value is
		// an array — an address object, a list of tax ids — is never seen
		// by the walk above.
		$object = Fields::at( $data, 'data', 'object' );

		if ( is_array( $object ) ) {
			foreach ( self::IDENTITY as $section ) {
				if ( ! is_array( $object[ $section ] ?? null ) ) {
					continue;
				}

				foreach ( self::IDENTITY_FIELDS as $field ) {
					if ( array_key_exists( $field, $object[ $section ] ) ) {
						$object[ $section ][ $field ] = '[redacted]';
					}
				}
			}

			$envelope           = Fields::map( $data['data'] ?? null );
			$envelope['object'] = $object;
			$data['data']       = $envelope;
		}

		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
