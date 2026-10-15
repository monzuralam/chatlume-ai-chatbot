<?php
/**
 * REST: Health check, logs, and maintenance tools.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Admin\HealthCheck;
use Chatlume\Chat\ResponseCache;
use Chatlume\Chat\StreamHandler;
use Chatlume\Plugin;
use Chatlume\Providers\ErrorLog;
use Chatlume\Providers\ProviderError;
use Chatlume\Support\DebugLog;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin routes under chatlume/v1:
 * - GET    /health              Checks, provider error log, debug log.
 * - POST   /health/stream-test  A short event stream; the admin app measures
 *                               whether the events arrive one by one.
 * - DELETE /health/errors       Clears the provider error log.
 * - DELETE /health/debug-log    Clears the debug log.
 * - POST   /cache/clear         Invalidates every cached answer.
 */
class HealthController {

	/**
	 * Events sent by the streaming test.
	 */
	public const TEST_EVENTS = 6;

	/**
	 * Microseconds between test events.
	 */
	public const TEST_INTERVAL = 250000;

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$admin = array( $this, 'permissions_check' );

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => $admin,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/health/stream-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'stream_test' ),
				'permission_callback' => $admin,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/health/errors',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_errors' ),
				'permission_callback' => $admin,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/health/debug-log',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clear_debug_log' ),
				'permission_callback' => $admin,
				'args'                => array(),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/cache/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_cache' ),
				'permission_callback' => $admin,
				'args'                => array(),
			)
		);
	}

	/**
	 * Only Chatlume managers.
	 *
	 * @return true|WP_Error
	 */
	public function permissions_check() {
		if ( Plugin::current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage Chatlume.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /health.
	 *
	 * @return WP_REST_Response
	 */
	public function get_health(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'checks' => ( new HealthCheck() )->checks(),
				'errors' => array_map( array( self::class, 'explain_error' ), ErrorLog::all() ),
				'debug'  => array(
					'enabled' => DebugLog::enabled(),
					'entries' => DebugLog::all(),
				),
			)
		);
	}

	/**
	 * A provider error log entry with the same explanation and fix shown on
	 * the Providers page. Details are redacted when recorded.
	 *
	 * @param array<string, mixed> $entry Log entry.
	 * @return array<string, mixed>
	 */
	public static function explain_error( array $entry ): array {
		$error = new ProviderError(
			(string) ( $entry['type'] ?? ProviderError::SERVER_ERROR ),
			(string) ( $entry['provider'] ?? '' ),
			(string) ( $entry['detail'] ?? '' ),
			(int) ( $entry['status'] ?? 0 )
		);

		return array(
			'time'        => (int) ( $entry['time'] ?? 0 ),
			'provider'    => $error->provider(),
			'model'       => (string) ( $entry['model'] ?? '' ),
			'type'        => $error->type(),
			'status'      => $error->status(),
			'detail'      => $error->detail(),
			'title'       => $error->admin_title(),
			'explanation' => $error->admin_explanation(),
			'fix'         => $error->fix_link(),
		);
	}

	/**
	 * POST /health/stream-test: sends a few events a quarter second apart.
	 * The request ends here.
	 *
	 * @return WP_Error Never returned in practice (the request exits).
	 */
	public function stream_test() {
		$this->open();

		for ( $index = 1; $index <= self::TEST_EVENTS; $index++ ) {
			$this->write( 'event: tick' . "\n" . 'data: ' . wp_json_encode( array( 'n' => $index ) ) . "\n\n" );
			if ( $index < self::TEST_EVENTS ) {
				$this->pause();
			}
		}
		$this->write( "event: done\ndata: {}\n\n" );
		$this->finish();

		return new WP_Error( 'chatlume_stream_ended', '', array( 'status' => 200 ) );
	}

	/**
	 * Starts the event stream (replaceable in tests).
	 *
	 * @return void
	 */
	protected function open(): void {
		StreamHandler::open_stream();
	}

	/**
	 * Writes one frame (replaceable in tests).
	 *
	 * @param string $frame SSE frame.
	 * @return void
	 */
	protected function write( string $frame ): void {
		StreamHandler::write_frame( $frame );
	}

	/**
	 * Waits between test events (replaceable in tests).
	 *
	 * @return void
	 */
	protected function pause(): void {
		usleep( self::TEST_INTERVAL );
	}

	/**
	 * Ends the request after the stream (replaceable in tests).
	 *
	 * @return void
	 */
	protected function finish(): void {
		exit;
	}

	/**
	 * DELETE /health/errors.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_errors(): WP_REST_Response {
		ErrorLog::clear();

		return new WP_REST_Response( array( 'cleared' => true ) );
	}

	/**
	 * DELETE /health/debug-log.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_debug_log(): WP_REST_Response {
		DebugLog::clear();

		return new WP_REST_Response( array( 'cleared' => true ) );
	}

	/**
	 * POST /cache/clear.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_cache(): WP_REST_Response {
		ResponseCache::flush();

		return new WP_REST_Response( array( 'cleared' => true ) );
	}
}
