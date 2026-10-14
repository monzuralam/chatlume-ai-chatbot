<?php
/**
 * Tests for Migrations.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Installer\Migrations;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Settings migrations and the upgrade trigger.
 */
class MigrationsTest extends TestCase {

	public function test_migrates_unversioned_settings_to_version_1(): void {
		$migrated = ( new Migrations() )->migrate_settings( array( 'privacy' => 'broken' ) );

		$this->assertSame(
			array(
				'privacy' => array(),
				'setup'   => array(),
			),
			$migrated
		);
	}

	public function test_skips_steps_already_applied_and_future_steps(): void {
		$migrations = new class() extends Migrations {
			/**
			 * Steps that record which versions ran.
			 *
			 * @return array<int, callable>
			 */
			protected function settings_steps(): array {
				return array(
					1 => static function ( array $settings ): array {
						$settings['ran'][] = 1;
						return $settings;
					},
					2 => static function ( array $settings ): array {
						$settings['ran'][] = 2;
						return $settings;
					},
				);
			}
		};

		// Version 0 runs step 1 only: step 2 is newer than Settings::SCHEMA_VERSION.
		$this->assertSame( array( 1 ), $migrations->migrate_settings( array( 'schema_version' => 0 ) )['ran'] );

		// Already at version 1: nothing runs, and schema_version is removed (it is stamped on save).
		$this->assertSame(
			array( 'x' => 1 ),
			$migrations->migrate_settings(
				array(
					'schema_version' => 1,
					'x'              => 1,
				)
			)
		);
	}

	public function test_needs_upgrade(): void {
		$this->assertTrue( Migrations::needs_upgrade(), 'Fresh site.' );

		$this->options[ Migrations::DB_VERSION_OPTION ] = Migrations::DB_VERSION;
		$this->assertTrue( Migrations::needs_upgrade(), 'Settings missing.' );

		$this->options[ Settings::OPTION ] = array( 'schema_version' => 0 );
		$this->assertTrue( Migrations::needs_upgrade(), 'Settings outdated.' );

		$this->options[ Settings::OPTION ] = array( 'schema_version' => Settings::SCHEMA_VERSION );
		$this->assertFalse( Migrations::needs_upgrade(), 'Up to date.' );

		$this->options[ Migrations::DB_VERSION_OPTION ] = 0;
		$this->assertTrue( Migrations::needs_upgrade(), 'Tables outdated.' );
	}

	public function test_maybe_upgrade_never_runs_on_frontend_requests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\expect( 'get_option' )->never();

		( new Migrations() )->maybe_upgrade();

		$this->assertTrue( true );
	}
}
