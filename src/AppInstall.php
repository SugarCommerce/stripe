<?php
/**
 * What an event says about a Stripe App's install on an account.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Enum AppInstall
 *
 * Stripe tells an app's backend about an install twice. Every API version
 * sends `account.application.authorized` and `.deauthorized`; alongside
 * them, since 2026-09-30.endive, it sends `apps.install.created`,
 * `.updated` and `.deleted`, which carry an Install object — named
 * `app.install.*` on endpoints pinned to an earlier version. So one seller
 * installing is two events with two ids, and the event-id idempotency every
 * webhook handler already has does not make them one. This reads either
 * family into the same answer, so a handler can see that the second says
 * nothing the first did not.
 *
 * Not every `apps.install` event is a change an application can act on.
 * The Install object has a lifecycle — `installing`, `installed`,
 * `install_failed`, `uninstalling`, `uninstall_failed` — and only
 * `installed` is an app the account can use: `created` can arrive while
 * the install is still in progress or after it failed, and `updated` is
 * also sent when the seller approves new permissions. Those read as no
 * change. `deleted` is the uninstall, whatever its object says — at endive
 * it carries only the install's id, so the event's `account` is what names
 * the account that left.
 *
 * What to do about an install — create a row, close one, ignore a stale
 * one — is the application's; which events mean one is Stripe's, and here.
 *
 * @since 1.3.0
 */
enum AppInstall: string {

	case Installed   = 'installed';
	case Uninstalled = 'uninstalled';

	/**
	 * Every event type that can carry an install or an uninstall, in both
	 * families and both namings. For a webhook's dispatch, and for the list
	 * of events an endpoint subscribes to.
	 *
	 * @since 1.3.0
	 */
	public const array TYPES = array(
		'account.application.authorized',
		'account.application.deauthorized',
		'apps.install.created',
		'apps.install.updated',
		'apps.install.deleted',
		'app.install.created',
		'app.install.updated',
		'app.install.deleted',
	);

	/**
	 * The only Install status in which the app can be used.
	 *
	 * @since 1.3.0
	 */
	public const string USABLE = 'installed';

	/**
	 * What an event says happened to the install, if anything.
	 *
	 * An Install object with no `status` — a payload stored or written
	 * before the field, or a test fixture — is read by its event: `created`
	 * as an install, `updated` as no change.
	 *
	 * @since 1.3.0
	 *
	 * @param Event $event Any event.
	 *
	 * @return self|null Null for an event that is not about an install, or
	 *                   one that leaves it as it was.
	 */
	public static function of( Event $event ): ?self {
		$type = $event->type;

		if ( 'account.application.authorized' === $type ) {
			return self::Installed;
		}

		if ( 'account.application.deauthorized' === $type ) {
			return self::Uninstalled;
		}

		if ( ! str_starts_with( $type, 'apps.install.' ) && ! str_starts_with( $type, 'app.install.' ) ) {
			return null;
		}

		$verb = substr( $type, strrpos( $type, '.' ) + 1 );

		if ( 'deleted' === $verb ) {
			return self::Uninstalled;
		}

		if ( 'created' !== $verb && 'updated' !== $verb ) {
			return null;
		}

		$status = self::status( $event->object ) ?? ( 'created' === $verb ? self::USABLE : null );

		return self::USABLE === $status ? self::Installed : null;
	}

	/**
	 * An Install object's lifecycle status, or null when it carries none.
	 *
	 * @since 1.3.0
	 *
	 * @param array $install Install object, `data.object` of an
	 *                       `apps.install.*` event.
	 *
	 * @return string|null E.g. `installed`, `installing`, `install_failed`.
	 */
	public static function status( array $install ): ?string {
		$status = $install['status'] ?? null;

		return is_string( $status ) && '' !== $status ? $status : null;
	}
}
