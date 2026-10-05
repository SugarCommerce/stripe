<?php
/**
 * SubscriptionStatus test suite.
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
use SugarCommerce\Stripe\SubscriptionStatus;

/**
 * Class SubscriptionStatusTest
 *
 * Every status lands in the right group, and the groups agree with the
 * constants other code puts in SQL.
 */
final class SubscriptionStatusTest extends TestCase {

	private const int NOW = 1_800_000_000;

	/**
	 * @return array<string, array{string, bool, bool, bool, bool}>
	 */
	public static function statuses(): array {
		return array(
			// status => running, paid, lapsed, ended
			'active'             => array( 'active', true, true, false, false ),
			'trialing'           => array( 'trialing', true, true, false, false ),
			'past_due'           => array( 'past_due', true, false, false, false ),
			'unpaid'             => array( 'unpaid', false, false, true, false ),
			'paused'             => array( 'paused', false, false, true, false ),
			'incomplete'         => array( 'incomplete', false, false, false, false ),
			'incomplete_expired' => array( 'incomplete_expired', false, false, false, true ),
			'canceled'           => array( 'canceled', false, false, false, true ),
		);
	}

	#[DataProvider( 'statuses' )]
	public function test_each_status_is_sorted( string $status, bool $running, bool $paid, bool $lapsed, bool $ended ): void {
		$case = SubscriptionStatus::of( $status );

		$this->assertNotNull( $case );
		$this->assertSame( $running, $case->running() );
		$this->assertSame( $paid, $case->paid() );
		$this->assertSame( $lapsed, $case->lapsed() );
		$this->assertSame( $ended, $case->ended() );
	}

	public function test_an_undocumented_status_is_none_of_them(): void {
		$this->assertNull( SubscriptionStatus::of( 'Active' ) );
		$this->assertNull( SubscriptionStatus::of( '' ) );
	}

	/**
	 * Every constant names real statuses, so a typo in one cannot quietly
	 * match nothing in SQL.
	 */
	public function test_the_lists_name_only_real_statuses(): void {
		foreach ( array( SubscriptionStatus::RUNNING, SubscriptionStatus::PAID, SubscriptionStatus::LAPSED, SubscriptionStatus::ENDED ) as $list ) {
			foreach ( $list as $status ) {
				$this->assertNotNull( SubscriptionStatus::of( $status ), $status );
			}
		}
	}

	/* ─── access_until ─── */

	public function test_a_paid_subscription_holds_to_its_period_end_and_a_little(): void {
		$this->assertSame( 1000 + SubscriptionStatus::RENEWAL_GRACE, SubscriptionStatus::Active->access_until( 1000 ) );
	}

	public function test_a_past_due_one_holds_for_the_lapse_window(): void {
		$this->assertSame( 1000 + 3600, SubscriptionStatus::PastDue->access_until( 1000, lapse_grace: 3600 ) );
	}

	/**
	 * Otherwise cancelling buys the rest of a free month.
	 */
	public function test_anything_not_running_is_over_now(): void {
		$this->assertSame( self::NOW - 1, SubscriptionStatus::Canceled->access_until( self::NOW + 86400, now: self::NOW ) );
		$this->assertSame( self::NOW - 1, SubscriptionStatus::Unpaid->access_until( null, now: self::NOW ) );
	}

	public function test_a_running_one_with_no_period_has_no_end(): void {
		$this->assertNull( SubscriptionStatus::Trialing->access_until( null ) );
	}
}
