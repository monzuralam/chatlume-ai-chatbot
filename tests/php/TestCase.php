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
use Chatlume\Providers\ModelSuggestions;
use Chatlume\Providers\ProviderRegistry;
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
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	protected array $transients = array();

	/**
	 * Queued HTTP responses for wp_remote_request() (array responses or WP_Error).
	 *
	 * @var array<int, mixed>
	 */
	protected array $http_queue = array();

	/**
	 * Requests sent through wp_remote_request(): [url, args].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	protected array $http_requests = array();

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

		$this->transients    = array();
		$this->http_queue    = array();
		$this->http_requests = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args = array() ) {
				$this->http_requests[] = array( $url, $args );
				return array_shift( $this->http_queue ) ?? new \WP_Error( 'http_request_failed', 'No queued response' );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Site' );

		ProviderRegistry::reset();
		ModelSuggestions::set_data( null );
	}

	/**
	 * Queues an HTTP response for the next wp_remote_request().
	 *
	 * @param int                   $status  HTTP status.
	 * @param mixed                 $body    Body (arrays are JSON-encoded).
	 * @param array<string, string> $headers Headers.
	 * @return void
	 */
	protected function queue_response( int $status, $body, array $headers = array() ): void {
		$this->http_queue[] = array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : json_encode( $body ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			'headers'  => $headers,
		);
	}

	/**
	 * JSON body of the Nth request sent.
	 *
	 * @param int $index Request index.
	 * @return array<string, mixed>
	 */
	protected function sent_body( int $index = 0 ): array {
		return json_decode( (string) ( $this->http_requests[ $index ][1]['body'] ?? '' ), true ) ?? array();
	}

	/**
	 * Tears down mocks.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
