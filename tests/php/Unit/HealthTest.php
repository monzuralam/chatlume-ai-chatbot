<?php
/**
 * Tests for the Health check, logs, and maintenance routes.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Admin\HealthCheck;
use Chatlume\Chat\ResponseCache;
use Chatlume\Providers\ErrorLog;
use Chatlume\Providers\ProviderError;
use Chatlume\REST\HealthController;
use Chatlume\Settings\Settings;
use Chatlume\Support\DebugLog;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Health checks, provider error explanations, the debug log, the
 * streaming test, and cache clearing.
 */
class HealthTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		// Every table exists: SHOW TABLES LIKE returns the table asked for.
		$wpdb = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Returns the first placeholder value.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return mixed
			 */
			public function prepare( $query, ...$args ) {
				unset( $query );
				return $args[0] ?? '';
			}

			/**
			 * Escapes LIKE wildcards (not needed here).
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return $text;
			}

			/**
			 * Echoes the prepared value.
			 *
			 * @param mixed $prepared Prepared query.
			 * @return mixed
			 */
			public function get_var( $prepared ) {
				return $prepared;
			}
		};

		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-json/' . $path;
			}
		);
		Functions\when( 'wp_remote_get' )->justReturn( array( 'response' => array( 'code' => 200 ) ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 3600 );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-admin/' . $path;
			}
		);
		$this->options['chatlume_db_version']         = \Chatlume\Installer\Migrations::DB_VERSION;
		$this->options['chatlume_fulltext_available'] = 'yes';
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		parent::tear_down();
	}

	/**
	 * Checks keyed by ID.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function checks(): array {
		$checks = array();
		foreach ( ( new HealthCheck() )->checks() as $check ) {
			$checks[ $check['id'] ] = $check;
		}
		return $checks;
	}

	public function test_every_check_has_a_status_label_and_detail(): void {
		$checks = $this->checks();

		$this->assertSame(
			array( 'php', 'database', 'encryption', 'http', 'rest', 'cron', 'fulltext', 'time_limit', 'object_cache', 'optimizers', 'ai_client' ),
			array_keys( $checks )
		);
		foreach ( $checks as $id => $check ) {
			$this->assertContains( $check['status'], array( 'critical', 'recommended', 'good', 'info' ), $id );
			$this->assertNotSame( '', $check['label'], $id );
			$this->assertNotSame( '', $check['detail'], $id );
			if ( in_array( $check['status'], array( 'critical', 'recommended' ), true ) ) {
				$this->assertNotSame( '', $check['fix'], "$id explains how to fix it" );
			}
		}
		$this->assertSame( 'good', $checks['database']['status'] );
		$this->assertSame( 'good', $checks['rest']['status'] );
		$this->assertSame( 'good', $checks['cron']['status'] );
		$this->assertSame( 'good', $checks['fulltext']['status'] );
	}

	public function test_problems_come_with_fixes(): void {
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		Functions\when( 'wp_next_scheduled' )->justReturn( time() - 86400 );
		$this->options['chatlume_db_version']         = 0;
		$this->options['chatlume_fulltext_available'] = 'no';

		$checks = $this->checks();

		$this->assertSame( 'critical', $checks['rest']['status'] );
		$this->assertStringContainsString( 'cURL error 7', $checks['rest']['detail'] );
		$this->assertSame( 'recommended', $checks['cron']['status'] );
		$this->assertSame( 'critical', $checks['database']['status'] );
		$this->assertSame( 'recommended', $checks['fulltext']['status'] );
	}

	public function test_changed_salts_are_critical(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$this->markTestSkipped( 'libsodium is not available.' );
		}
		$this->options['chatlume_key_fingerprint'] = 'from-other-salts';

		$check = $this->checks()['encryption'];

		$this->assertSame( 'critical', $check['status'] );
		$this->assertStringContainsString( 'wp-config.php', $check['fix'] );
	}

	public function test_provider_errors_are_explained(): void {
		$entry = HealthController::explain_error(
			array(
				'time'     => 100,
				'provider' => 'openai',
				'model'    => 'm1',
				'type'     => ProviderError::INVALID_KEY,
				'status'   => 401,
				'detail'   => 'Incorrect API key',
			)
		);

		$this->assertSame( 'invalid_key', $entry['type'] );
		$this->assertSame( 'The API key was rejected', $entry['title'] );
		$this->assertNotSame( '', $entry['explanation'] );
		$this->assertStringContainsString( '#/providers', $entry['fix']['url'] );
	}

	public function test_health_route_returns_checks_and_logs(): void {
		ErrorLog::record( new ProviderError( ProviderError::QUOTA, 'openai', 'quota exceeded', 429 ), 'm1' );

		$data = ( new HealthController() )->get_health()->get_data();

		$this->assertCount( 11, $data['checks'] );
		$this->assertSame( 'quota', $data['errors'][0]['type'] );
		$this->assertFalse( $data['debug']['enabled'] );

		( new HealthController() )->clear_errors();
		$this->assertSame( array(), ErrorLog::all() );
	}

	public function test_admin_routes_require_the_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new HealthController() )->permissions_check() );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( ( new HealthController() )->permissions_check() );
	}

	public function test_debug_log_records_summaries_only_when_enabled(): void {
		DebugLog::record( 'reply', array( 'source' => 'ai' ) );
		$this->assertSame( array(), DebugLog::all(), 'Off by default.' );

		$this->options[ Settings::OPTION ] = array( 'debug' => array( 'enabled' => true ) );
		DebugLog::record(
			'reply',
			array(
				'source'     => 'ai',
				'latency_ms' => 120,
				'streamed'   => true,
				'message'    => 'Visitor text must never be stored',
				'api_key'    => 'sk-secret',
			)
		);

		$entry = DebugLog::all()[0];
		$this->assertSame( 'reply', $entry['event'] );
		$this->assertSame( 'ai', $entry['source'] );
		$this->assertSame( 120, $entry['latency_ms'] );
		$this->assertTrue( $entry['streamed'] );
		$this->assertArrayNotHasKey( 'message', $entry );
		$this->assertArrayNotHasKey( 'api_key', $entry );
		$this->assertFalse( $this->autoload[ DebugLog::OPTION ] );

		for ( $index = 0; $index < DebugLog::LIMIT + 5; $index++ ) {
			DebugLog::record( 'reply', array( 'source' => 'cache' ) );
		}
		$this->assertCount( DebugLog::LIMIT, DebugLog::all() );

		( new HealthController() )->clear_debug_log();
		$this->assertSame( array(), DebugLog::all() );
	}

	public function test_clearing_the_cache_changes_every_key(): void {
		$before = ResponseCache::key( 'Hello', 'en', 'prompt', 'openai/m1' );
		( new HealthController() )->clear_cache();

		$this->assertNotSame( $before, ResponseCache::key( 'Hello', 'en', 'prompt', 'openai/m1' ) );
		$this->assertFalse( $this->autoload[ ResponseCache::GENERATION_OPTION ] );
	}

	public function test_stream_test_sends_spaced_events(): void {
		$controller = new class() extends HealthController {
			/**
			 * Calls in order.
			 *
			 * @var string[]
			 */
			public array $calls = array();

			/**
			 * Records opening.
			 *
			 * @return void
			 */
			protected function open(): void {
				$this->calls[] = 'open';
			}

			/**
			 * Records frames.
			 *
			 * @param string $frame Frame.
			 * @return void
			 */
			protected function write( string $frame ): void {
				$this->calls[] = strtok( $frame, "\n" );
			}

			/**
			 * Records pauses.
			 *
			 * @return void
			 */
			protected function pause(): void {
				$this->calls[] = 'pause';
			}

			/**
			 * Does not exit.
			 *
			 * @return void
			 */
			protected function finish(): void {
				$this->calls[] = 'finish';
			}
		};

		$controller->stream_test();

		$expected = array( 'open' );
		for ( $index = 1; $index <= HealthController::TEST_EVENTS; $index++ ) {
			$expected[] = 'event: tick';
			if ( $index < HealthController::TEST_EVENTS ) {
				$expected[] = 'pause';
			}
		}
		$expected[] = 'event: done';
		$expected[] = 'finish';

		$this->assertSame( $expected, $controller->calls );
	}
}
