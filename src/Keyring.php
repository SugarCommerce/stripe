<?php
/**
 * One Stripe client per mode.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

use SugarCommerce\HttpClient\Client as Http;

/**
 * Class Keyring
 *
 * A secret key reaches its own mode of every connected account and nothing
 * else: a live key cannot read a test-mode checkout, and a test key cannot
 * read a live one. A platform configured with one key fails every call for
 * the other mode — a live sale whose line items cannot be read, a test shop
 * with an empty catalogue. So there is one client per mode, and every call
 * made for a connected account picks one by the mode it is acting in.
 *
 * And a third. An account installed into a Stripe *sandbox* is connected not
 * to the platform but to the managed sandbox Stripe made for the app, so
 * neither platform key reaches it — only the sandbox's. Which accounts those
 * are is the caller's knowledge, asked through a closure; it should come
 * from something the platform verified, never from a request.
 *
 * It is a Client itself, so it can be handed to anything that takes one.
 * Used bare, it is the platform's own client. Acting for an account goes
 * through pick(), which leaves any other client alone — a test double, or
 * a single-key client a script built — so a call site changes by one
 * expression and every test that injects a fake keeps working.
 *
 * Not final: an application may extend it with its own factory.
 *
 * @since 1.2.0
 */
class Keyring extends Client {

	/**
	 * Why a live-mode call for a sandbox install is refused.
	 *
	 * @since 1.2.0
	 */
	public const string NO_LIVE_MODE = 'a sandbox install has no live mode, so live-mode calls for it are refused.';

	/**
	 * @since 1.2.0
	 *
	 * @param Client        $live      For live-mode calls.
	 * @param Client        $test      For test-mode calls.
	 * @param Client|null   $platform  For the platform's own account. Null
	 *                                 uses $live.
	 * @param Client|null   $sandbox   The managed sandbox's, for accounts
	 *                                 installed in a sandbox. Null refuses
	 *                                 them.
	 * @param \Closure|null $sandboxed Whether an account id is a sandbox
	 *                                 install: fn( string ): bool. Null
	 *                                 treats none as one.
	 */
	public function __construct(
		private readonly Client $live,
		private readonly Client $test,
		private readonly ?Client $platform = null,
		private readonly ?Client $sandbox = null,
		private readonly ?\Closure $sandboxed = null,
	) {
		parent::__construct();
	}

	/**
	 * A keyring from secret keys, each mode refusing plainly when its key
	 * is missing.
	 *
	 * @since 1.2.0
	 *
	 * @param string        $live      Live secret or restricted key, or ''.
	 * @param string        $test      Test key, or ''.
	 * @param string        $sandbox   The managed sandbox's key, or ''.
	 * @param string        $platform  Which of live | test is the
	 *                                 platform's own account.
	 * @param \Closure|null $sandboxed Whether an account is a sandbox install.
	 * @param Http|null     $http      HTTP client for every mode.
	 *
	 * @return self
	 */
	public static function from_keys(
		#[\SensitiveParameter] string $live,
		#[\SensitiveParameter] string $test,
		#[\SensitiveParameter] string $sandbox = '',
		string $platform = 'live',
		?\Closure $sandboxed = null,
		?Http $http = null,
	): self {
		$live_client = self::client( $live, 'no live-mode key is set.', $http );
		$test_client = self::client( $test, 'no test-mode key is set.', $http );

		return new self(
			$live_client,
			$test_client,
			'test' === $platform ? $test_client : $live_client,
			self::client( $sandbox, 'no managed sandbox key is set.', $http ),
			$sandboxed
		);
	}

	/**
	 * The client for a mode, whatever kind of client the caller holds.
	 *
	 * A Keyring answers with its client for that mode and account; anything
	 * else is already the only client there is.
	 *
	 * @since 1.2.0
	 *
	 * @param Client      $client  What the caller holds.
	 * @param string      $mode    live | test
	 * @param string|null $account The connected account acted for, so a
	 *                             sandbox install gets the sandbox's key.
	 *
	 * @return Client
	 */
	public static function pick( Client $client, string $mode, ?string $account = null ): Client {
		return $client instanceof self ? $client->mode( $mode, $account ) : $client;
	}

	/**
	 * The client for a mode, and for an account in it.
	 *
	 * Anything but 'live' is test, matching Event::mode(): a typo should
	 * never reach live money.
	 *
	 * A sandbox has no live mode, so a live-mode call for one is refused
	 * rather than sent with the live key. That key cannot reach a sandbox
	 * anyway, and asking with it turns a caller's mistake into a Stripe
	 * error that reads like an outage.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $mode    live | test
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return Client
	 */
	public function mode( string $mode, ?string $account = null ): Client {
		$sandbox = null !== $account && '' !== $account && null !== $this->sandboxed && ( $this->sandboxed )( $account );

		if ( 'live' === $mode ) {
			return $sandbox ? new Keyless( self::NO_LIVE_MODE ) : $this->live;
		}

		if ( $sandbox ) {
			return $this->sandbox ?? new Keyless( 'no managed sandbox key is set.' );
		}

		return $this->test;
	}

	/**
	 * Which mode a key is for, by its prefix, or ''.
	 *
	 * The prefix is how Stripe marks it: `sk_live_`, `rk_live_` and their
	 * `_test_` twins. Anything else — a publishable key, a typo, a webhook
	 * secret pasted into the wrong field — is trusted to be neither.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Secret or restricted key.
	 *
	 * @return string live | test | ''
	 */
	public static function key_mode( #[\SensitiveParameter] string $key ): string {
		return 1 === preg_match( '/^(?:sk|rk)_(live|test)_/', $key, $match ) ? $match[1] : '';
	}

	/**
	 * Whether the platform's own client has a key.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	#[\Override]
	public function configured(): bool {
		return $this->platform()->configured();
	}

	/**
	 * A read on the platform's own client.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param string|null $account Connected account id, or null.
	 * @param array       $query   Query parameters.
	 *
	 * @return array|null
	 */
	#[\Override]
	public function get( string $path, ?string $account = null, array $query = array() ): ?array {
		return $this->platform()->get( $path, $account, $query );
	}

	/**
	 * A read on the platform's own client that must succeed.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param string|null $account Connected account id, or null.
	 * @param array       $query   Query parameters.
	 *
	 * @return array
	 *
	 * @throws StripeError When Stripe does not answer.
	 */
	#[\Override]
	public function fetch( string $path, ?string $account = null, array $query = array() ): array {
		return $this->platform()->fetch( $path, $account, $query );
	}

	/**
	 * A write on the platform's own client.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array|null
	 */
	#[\Override]
	#[\NoDiscard( 'Null means Stripe did not do it.' )]
	public function post( string $path, array $params, ?string $account = null ): ?array {
		return $this->platform()->post( $path, $params, $account );
	}

	/**
	 * A write on the platform's own client that says why it failed.
	 *
	 * @since 1.2.0
	 *
	 * @param string      $path    Path below /v1.
	 * @param array       $params  Parameters.
	 * @param string|null $account Connected account id, or null.
	 *
	 * @return array
	 *
	 * @throws StripeError With Stripe's reason.
	 */
	#[\Override]
	#[\NoDiscard( 'The object is the only proof Stripe did it.' )]
	public function attempt( string $path, array $params, ?string $account = null ): array {
		return $this->platform()->attempt( $path, $params, $account );
	}

	/**
	 * The platform's own client.
	 *
	 * @return Client
	 */
	private function platform(): Client {
		return $this->platform ?? $this->live;
	}

	/**
	 * A client for a key, or one that refuses when there is none.
	 *
	 * @param string    $key    Secret key, or ''.
	 * @param string    $reason What to say when there is none.
	 * @param Http|null $http   HTTP client.
	 *
	 * @return Client
	 */
	private static function client( #[\SensitiveParameter] string $key, string $reason, ?Http $http ): Client {
		if ( '' === $key ) {
			return new Keyless( $reason );
		}

		return null === $http ? new Client( $key ) : new Client( $key, $http );
	}
}
