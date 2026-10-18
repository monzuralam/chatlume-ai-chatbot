<?php
/**
 * Tests for the Command Palette commands loader.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Admin\Commands;
use Chatlume\Tests\TestCase;

/**
 * Loads only for Chatlume managers, once, with the Chatlume screen address.
 */
class CommandsTest extends TestCase {

	/**
	 * Calls recorded by the script stubs.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $calls = array();

	protected function set_up(): void {
		parent::set_up();

		if ( ! is_readable( CHATLUME_PATH . 'build/commands/index.asset.php' ) ) {
			$this->markTestSkipped( 'Run `npm run build` first: the loader needs the built script.' );
		}

		$this->calls = array(
			'register' => array(),
			'inline'   => array(),
			'enqueue'  => array(),
		);
		$enqueued    = false;
		Functions\when( 'wp_script_is' )->alias(
			static function () use ( &$enqueued ) {
				return $enqueued;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( ...$args ) {
				$this->calls['register'][] = $args;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code ) {
				$this->calls['inline'][] = $code;
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) use ( &$enqueued ) {
				$this->calls['enqueue'][] = $handle;
				$enqueued                 = true;
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
	}

	public function test_only_chatlume_managers_get_the_commands(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		( new Commands() )->enqueue();

		$this->assertSame( array(), $this->calls['enqueue'] );
	}

	public function test_commands_load_once_with_the_screen_address(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$commands = new Commands();
		$commands->enqueue();
		$commands->enqueue();

		$this->assertSame( array( Commands::HANDLE ), $this->calls['enqueue'] );
		$this->assertStringContainsString( 'build/commands/index.js', $this->calls['register'][0][1] );
		$this->assertContains( 'wp-commands', $this->calls['register'][0][2], 'Depends on the core palette.' );
		$this->assertStringContainsString( 'admin.php?page=chatlume', $this->calls['inline'][0] );
	}
}
