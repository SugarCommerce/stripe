<?php
/**
 * AppInstall test suite.
 *
 * @package   SugarCommerce\Stripe
 * @copyright Copyright (c) 2026, ArrayPress Limited
 * @license   GPL-2.0-or-later
 * @since     1.3.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Stripe\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Stripe\{AppInstall, Event};

/**
 * Class AppInstallTest
 *
 * Both families of install event, under both namings, read into the same
 * two answers; and the Install object's lifecycle decides when a `created`
 * or an `updated` is one.
 */
final class AppInstallTest extends TestCase {

	/**
	 * @return array<string, array{string, array, AppInstall|null}>
	 */
	public static function events(): array {
		$installed = array( 'object' => 'apps.install', 'status' => 'installed' );

		return array(
			'connect authorized'          => array( 'account.application.authorized', array( 'id' => 'ca_1' ), AppInstall::Installed ),
			'connect deauthorized'        => array( 'account.application.deauthorized', array( 'id' => 'ca_1' ), AppInstall::Uninstalled ),
			'endive created'              => array( 'apps.install.created', $installed, AppInstall::Installed ),
			'endive deleted'              => array( 'apps.install.deleted', $installed, AppInstall::Uninstalled ),
			'endive updated, usable'      => array( 'apps.install.updated', $installed, AppInstall::Installed ),
			'pre-endive created'          => array( 'app.install.created', array(), AppInstall::Installed ),
			'pre-endive deleted'          => array( 'app.install.deleted', array(), AppInstall::Uninstalled ),
			'created, still installing'   => array( 'apps.install.created', array( 'status' => 'installing' ), null ),
			'created, failed'             => array( 'apps.install.created', array( 'status' => 'install_failed' ), null ),
			'updated, uninstalling'       => array( 'apps.install.updated', array( 'status' => 'uninstalling' ), null ),
			'updated, uninstall failed'   => array( 'apps.install.updated', array( 'status' => 'uninstall_failed' ), null ),
			'updated, no status'          => array( 'apps.install.updated', array(), null ),
			'deleted, whatever it says'   => array( 'apps.install.deleted', array( 'status' => 'uninstalling' ), AppInstall::Uninstalled ),
			'not an install event'        => array( 'checkout.session.completed', $installed, null ),
			'a lookalike'                 => array( 'apps.installer.created', $installed, null ),
		);
	}

	#[DataProvider( 'events' )]
	public function test_an_event_is_read_as_an_install_change( string $type, array $object, ?AppInstall $expected ): void {
		$this->assertSame( $expected, AppInstall::of( self::event( $type, $object ) ) );
	}

	/**
	 * A dispatch built on TYPES must route every event of() can answer.
	 */
	public function test_every_type_that_can_change_an_install_is_listed(): void {
		foreach ( self::events() as [ $type, $object, $expected ] ) {
			if ( null !== $expected ) {
				$this->assertContains( $type, AppInstall::TYPES );
			}
		}

		$this->assertContains( 'apps.install.updated', AppInstall::TYPES );
		$this->assertNotContains( 'checkout.session.completed', AppInstall::TYPES );
	}

	public function test_an_install_status_is_read_or_absent(): void {
		$this->assertSame( 'installing', AppInstall::status( array( 'status' => 'installing' ) ) );
		$this->assertNull( AppInstall::status( array( 'status' => '' ) ) );
		$this->assertNull( AppInstall::status( array( 'status' => array( 'installed' ) ) ) );
		$this->assertNull( AppInstall::status( array() ) );
	}

	private static function event( string $type, array $object ): Event {
		return new Event( 'evt_1', $type, 'acct_1', false, 1_800_000_000, $object, array() );
	}
}
