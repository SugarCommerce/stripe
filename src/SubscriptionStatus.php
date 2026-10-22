<?php
/**
 * A subscription's status, and what it means for access.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.2.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe;

/**
 * Enum SubscriptionStatus
 *
 * Stripe's eight subscription statuses, sorted into the four answers access
 * control actually needs. Without one place for them, every reader of a
 * status keeps its own list — and they drift: licences that treated
 * `past_due` as live for ever while downloads were cut off, or that died the
 * moment a subscription went `unpaid` while the files stayed for ever.
 *
 * - **running**: Stripe still considers it going. `past_due` is in here —
 *   Stripe is still collecting, and most recover — but only for a grace
 *   window past the period end.
 * - **paid**: access is due in full. Not `past_due`: becoming past due is
 *   not a reason to give anything back.
 * - **lapsed**: stopped for now, and paying again undoes it. `unpaid` is
 *   dunning given up; `paused` is a trial that ended without a card, or,
 *   since 2026-09-30.endive, a subscription paused on demand or after a
 *   failed payment — none of which is being paid for while it lasts.
 * - **ended**: never coming back. A cancelled subscription is not resumed —
 *   the customer subscribes again, which is a new subscription — and
 *   `incomplete_expired` never took a first payment.
 *
 * `incomplete` is none of them: a first payment still being attempted.
 *
 * The lists are constants as well as methods, so they can sit in another
 * class's constant expression and in SQL `IN (…)` clauses.
 *
 * @since 1.2.0
 */
enum SubscriptionStatus: string {

	/** Paid up and renewing. */
	case Active = 'active';

	/** In a free trial. */
	case Trialing = 'trialing';

	/** A renewal failed and Stripe is still retrying it. */
	case PastDue = 'past_due';

	/** Stripe gave up retrying; open invoices stay open. */
	case Unpaid = 'unpaid';

	/** Paused: a trial ended without a card, on demand, or after a failure. */
	case Paused = 'paused';

	/** The first payment is still being attempted. */
	case Incomplete = 'incomplete';

	/** The first payment never arrived; the subscription is dead. */
	case IncompleteExpired = 'incomplete_expired';

	/** Cancelled, for good. */
	case Canceled = 'canceled';

	/**
	 * Statuses in which Stripe still considers the subscription running.
	 *
	 * @since 1.2.0
	 */
	public const array RUNNING = array( 'active', 'trialing', 'past_due' );

	/**
	 * Statuses in which access is due in full.
	 *
	 * @since 1.2.0
	 */
	public const array PAID = array( 'active', 'trialing' );

	/**
	 * Statuses that stop access now and may come back.
	 *
	 * @since 1.2.0
	 */
	public const array LAPSED = array( 'unpaid', 'paused' );

	/**
	 * Statuses Stripe never moves out of.
	 *
	 * @since 1.2.0
	 */
	public const array ENDED = array( 'canceled', 'incomplete_expired' );

	/**
	 * How long past a paid period's end access holds while the renewal
	 * lands, by default: two days.
	 *
	 * Stripe's renewal invoice and its webhook are not instantaneous, and a
	 * customer whose software deactivates in the gap blames the software.
	 *
	 * @since 1.2.0
	 */
	public const int RENEWAL_GRACE = 2 * 86400;

	/**
	 * How long a `past_due` subscription holds past its period end, by
	 * default: thirty days, well past Stripe's default retry schedule.
	 *
	 * @since 1.2.0
	 */
	public const int LAPSE_GRACE = 30 * 86400;

	/**
	 * The status for a string from Stripe, or null for one it has not
	 * documented.
	 *
	 * @since 1.2.0
	 *
	 * @param string $status Stripe's status, verbatim.
	 *
	 * @return self|null
	 */
	public static function of( string $status ): ?self {
		return self::tryFrom( $status );
	}

	/**
	 * Whether Stripe still considers it running.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function running(): bool {
		return in_array( $this->value, self::RUNNING, true );
	}

	/**
	 * Whether access is due in full.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function paid(): bool {
		return in_array( $this->value, self::PAID, true );
	}

	/**
	 * Whether it is stopped for now, and paying again would undo it.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function lapsed(): bool {
		return in_array( $this->value, self::LAPSED, true );
	}

	/**
	 * Whether it is over for good.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function ended(): bool {
		return in_array( $this->value, self::ENDED, true );
	}

	/**
	 * When access ends, for a subscription in this state.
	 *
	 * Anything not running is over now, whatever the period said —
	 * otherwise cancelling buys the rest of a free month. A running one
	 * holds to its period end plus a grace: the renewal grace while it is
	 * paid, the longer lapse grace while Stripe is still collecting.
	 *
	 * @since 1.2.0
	 *
	 * @param int|null $period_end    End of the current period, or null.
	 * @param int      $lapse_grace   Seconds a past_due subscription holds.
	 * @param int      $renewal_grace Seconds a paid one holds.
	 * @param int|null $now           Current time. For tests.
	 *
	 * @return int|null Unix time, or null when it is running and no period
	 *                  end is known.
	 */
	public function access_until( ?int $period_end, int $lapse_grace = self::LAPSE_GRACE, int $renewal_grace = self::RENEWAL_GRACE, ?int $now = null ): ?int {
		if ( ! $this->running() ) {
			return ( $now ?? time() ) - 1;
		}

		if ( null === $period_end ) {
			return null;
		}

		return $period_end + ( self::PastDue === $this ? $lapse_grace : $renewal_grace );
	}
}
