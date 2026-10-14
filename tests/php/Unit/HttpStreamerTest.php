<?php
/**
 * Tests for HttpStreamer.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Providers\HttpStreamer;
use Chatlume\Providers\StreamAbortedException;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Chunk delivery through WordPress HTTP API hooks, request matching, and aborts.
 *
 * wp_remote_request() is replaced by a simulated transport that fires the
 * same actions WordPress fires, in the same order.
 */
class HttpStreamerTest extends TestCase {

	/**
	 * Registered action callbacks by hook.
	 *
	 * @var array<string, callable[]>
	 */
	private array $actions = array();

	protected function set_up(): void {
		parent::set_up();

		$this->actions = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) {
				$this->actions[ $hook ][] = $callback;
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( $hook, $callback ) {
				$this->actions[ $hook ] = array_values(
					array_filter(
						$this->actions[ $hook ] ?? array(),
						static function ( $existing ) use ( $callback ) {
							return $existing !== $callback;
						}
					)
				);
				return true;
			}
		);
	}

	/**
	 * Fires a simulated action.
	 *
	 * @param string $hook Hook.
	 * @param mixed  ...$args Arguments.
	 * @return void
	 */
	private function fire( string $hook, ...$args ): void {
		foreach ( $this->actions[ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}

	/**
	 * Simulates a cURL transport delivering chunks.
	 *
	 * @param string[]            $chunks         Chunks.
	 * @param int                 $status         HTTP status.
	 * @param array<int, string>  $foreign_chunks Chunks of another request delivered before ours starts.
	 * @return void
	 */
	private function simulate_curl( array $chunks, int $status = 200, array $foreign_chunks = array() ): void {
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) use ( $chunks, $status, $foreign_chunks ) {
				unset( $url );
				foreach ( $foreign_chunks as $chunk ) {
					$this->fire( 'requests-request.progress', $chunk, 0, 0 );
				}
				$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- A real handle for the callback to configure.
				$this->fire( 'http_api_curl', $handle, $args, 'https://example.test' );
				$body = '';
				foreach ( $chunks as $chunk ) {
					$body .= $chunk;
					$this->fire( 'requests-request.progress', $chunk, strlen( $body ), 0 );
				}
				return array(
					'response' => array( 'code' => $status ),
					'body'     => $body,
					'headers'  => array( 'Content-Type' => 'text/event-stream' ),
				);
			}
		);
	}

	public function test_delivers_chunks_in_order_and_returns_body(): void {
		$this->simulate_curl( array( "data: a\n\n", "data: b\n\n" ) );

		$received = array();
		$result   = ( new HttpStreamer() )->request(
			'https://example.test',
			array( 'method' => 'POST' ),
			static function ( $chunk ) use ( &$received ) {
				$received[] = $chunk;
			}
		);

		$this->assertSame( array( "data: a\n\n", "data: b\n\n" ), $received );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( "data: a\n\ndata: b\n\n", $result['body'] );
		$this->assertSame( '', $result['aborted'] );
		$this->assertSame( array( 'content-type' => 'text/event-stream' ), $result['headers'] );
	}

	public function test_ignores_data_from_other_requests_and_removes_hooks(): void {
		$this->simulate_curl( array( 'ours' ), 200, array( 'someone else' ) );

		$received = array();
		( new HttpStreamer() )->request(
			'https://example.test',
			array(),
			static function ( $chunk ) use ( &$received ) {
				$received[] = $chunk;
			}
		);

		$this->assertSame( array( 'ours' ), $received );
		$this->assertSame( array(), $this->actions['requests-request.progress'] );
		$this->assertSame( array(), $this->actions['http_api_curl'] );
	}

	public function test_requests_identity_encoding_and_keeps_caller_headers(): void {
		$sent = array();
		Functions\when( 'wp_remote_request' )->alias(
			static function ( $url, $args ) use ( &$sent ) {
				unset( $url );
				$sent = $args;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
					'headers'  => array(),
				);
			}
		);

		( new HttpStreamer() )->request( 'https://example.test', array( 'headers' => array( 'Authorization' => 'Bearer x' ) ), static function () {} );

		$this->assertSame( 'identity', $sent['headers']['Accept-Encoding'] );
		$this->assertSame( 'Bearer x', $sent['headers']['Authorization'] );
		$this->assertFalse( $sent['decompress'] );
		$this->assertTrue( $sent['blocking'] );
	}

	public function test_curl_abort_stops_delivery_and_reports_reason(): void {
		// After an abort cURL returns an error; the streamer must report the abort instead.
		Functions\when( 'wp_remote_request' )->alias(
			function ( $url, $args ) {
				unset( $url );
				$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- A real handle for the callback to configure.
				$this->fire( 'http_api_curl', $handle, $args, 'https://example.test' );
				foreach ( array( 'one', 'two', 'three' ) as $chunk ) {
					$this->fire( 'requests-request.progress', $chunk, 0, 0 );
				}
				return new WP_Error( 'http_request_failed', 'cURL error 42: Callback aborted' );
			}
		);

		$received = array();
		$result   = ( new HttpStreamer() )->request(
			'https://example.test',
			array(),
			static function ( $chunk ) use ( &$received ) {
				$received[] = $chunk;
			},
			static function () use ( &$received ) {
				return count( $received ) >= 1 ? 'client_disconnected' : '';
			}
		);

		$this->assertSame( array( 'one' ), $received );
		$this->assertSame( 'client_disconnected', $result['aborted'] );
		$this->assertSame( 'one', $result['body'] );
	}

	public function test_fsockopen_abort_unwinds_with_exception(): void {
		Functions\when( 'wp_remote_request' )->alias(
			function () {
				$this->fire( 'requests-fsockopen.before_send' );
				foreach ( array( 'one', 'two' ) as $chunk ) {
					$this->fire( 'requests-request.progress', $chunk, 0, 0 );
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'one two',
					'headers'  => array(),
				);
			}
		);

		$received = array();
		$result   = ( new HttpStreamer() )->request(
			'https://example.test',
			array(),
			static function ( $chunk ) use ( &$received ) {
				$received[] = $chunk;
				throw new StreamAbortedException( 'caller_stop' );
			}
		);

		$this->assertSame( array( 'one' ), $received );
		$this->assertSame( 'caller_stop', $result['aborted'] );
	}

	public function test_transport_errors_and_http_errors_pass_through(): void {
		Functions\when( 'wp_remote_request' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		$this->assertInstanceOf( WP_Error::class, ( new HttpStreamer() )->request( 'https://example.test', array(), static function () {} ) );

		$this->simulate_curl( array( '{"error":{"message":"Invalid key"}}' ), 401 );
		$result = ( new HttpStreamer() )->request( 'https://example.test', array(), static function () {} );
		$this->assertSame( 401, $result['status'] );
		$this->assertSame( '{"error":{"message":"Invalid key"}}', $result['body'] );
	}
}
