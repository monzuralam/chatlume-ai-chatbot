<?php
/**
 * Tests for Activation.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Installer\Activation;
use Chatlume\Tests\TestCase;

/**
 * Redirect rules and deactivation cleanup.
 */
class ActivationTest extends TestCase {

	/**
	 * A context in which the redirect should happen.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function context( array $overrides = array() ): array {
		return array_merge(
			array(
				'flag_user'        => 7,
				'current_user'     => 7,
				'can_manage'       => true,
				'bulk'             => false,
				'is_network_admin' => false,
				'is_ajax'          => false,
				'is_rest'          => false,
				'is_cli'           => false,
			),
			$overrides
		);
	}

	public function test_flag_is_set_only_for_single_site_non_cli_activation(): void {
		$this->assertTrue( Activation::should_flag_redirect( false, false ) );
		$this->assertFalse( Activation::should_flag_redirect( true, false ), 'Network activation.' );
		$this->assertFalse( Activation::should_flag_redirect( false, true ), 'WP-CLI.' );
		$this->assertFalse( Activation::should_flag_redirect( true, true ) );
	}

	public function test_redirects_after_single_activation(): void {
		$this->assertTrue( Activation::should_redirect( $this->context() ) );
	}

	/**
	 * Contexts that must never redirect.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function blocked_contexts(): array {
		return array(
			'no flag'            => array( array( 'flag_user' => false ) ),
			'bulk activation'    => array( array( 'bulk' => true ) ),
			'network admin'      => array( array( 'is_network_admin' => true ) ),
			'ajax'               => array( array( 'is_ajax' => true ) ),
			'rest'               => array( array( 'is_rest' => true ) ),
			'wp-cli'             => array( array( 'is_cli' => true ) ),
			'another user'       => array( array( 'current_user' => 8 ) ),
			'missing capability' => array( array( 'can_manage' => false ) ),
		);
	}

	/**
	 * Blocked contexts never redirect.
	 *
	 * @dataProvider blocked_contexts
	 *
	 * @param array<string, mixed> $overrides Context overrides.
	 */
	public function test_does_not_redirect( array $overrides ): void {
		$this->assertFalse( Activation::should_redirect( $this->context( $overrides ) ) );
	}

	public function test_deactivate_unschedules_only_chatlume_hooks(): void {
		Functions\expect( 'delete_transient' )->once()->with( Activation::REDIRECT_TRANSIENT );
		Functions\when( '_get_cron_array' )->justReturn(
			array(
				1700000000 => array(
					'chatlume_retention_cleanup' => array(),
					'wp_version_check'           => array(),
				),
				1700003600 => array(
					'chatlume_index_queue'       => array(),
					'chatlume_retention_cleanup' => array(),
				),
			)
		);

		$unscheduled = array();
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $hook ) use ( &$unscheduled ) {
				$unscheduled[] = $hook;
				return 1;
			}
		);

		Activation::deactivate();

		$this->assertSame( array( 'chatlume_retention_cleanup', 'chatlume_index_queue' ), $unscheduled );
	}

	public function test_network_deactivation_clears_every_site(): void {
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_sites' )->justReturn( array( 1, 2, 3 ) );

		$site   = 0;
		$events = array();
		Functions\when( 'switch_to_blog' )->alias(
			static function ( $id ) use ( &$site ) {
				$site = (int) $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->justReturn( true );
		Functions\when( '_get_cron_array' )->alias(
			static function () use ( &$site ) {
				return array( 1700000000 => array( 'chatlume_daily_cleanup' => array() ) );
			}
		);
		Functions\when( 'wp_unschedule_hook' )->alias(
			static function ( $hook ) use ( &$site, &$events ) {
				$events[] = $site . ':' . $hook;
				return 1;
			}
		);

		Activation::deactivate( true );

		$this->assertSame( array( '1:chatlume_daily_cleanup', '2:chatlume_daily_cleanup', '3:chatlume_daily_cleanup' ), $events );
	}
}
