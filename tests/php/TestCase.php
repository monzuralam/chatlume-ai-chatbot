<?php
/**
 * Base test case.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up Brain Monkey, translation stubs, and an in-memory options table.
 */
abstract class TestCase extends PolyfillTestCase {

	/**
	 * In-memory options: name => value.
	 *
	 * @var array<string, mixed>
	 */
	protected array $options = array();

	/**
	 * Autoload flag passed for each option.
	 *
	 * @var array<string, mixed>
	 */
	protected array $autoload = array();

	/**
	 * Sets up mocks.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		$this->options  = array();
		$this->autoload = array();

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				unset( $deprecated );
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	/**
	 * Tears down mocks.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
