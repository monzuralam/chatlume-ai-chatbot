<?php
/**
 * Tests for SessionManager and RateLimiter.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Security\RateLimiter;
use Chatlume\Security\SessionManager;
use Chatlume\Tests\TestCase;

/**
 * Signed tokens and fixed-window limits.
 */
class SessionAndRateLimitTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	public function test_session_round_trip_and_renewal(): void {
		$sessions = new SessionManager();
		$first    = $sessions->start();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]+\.[a-f0-9]{64}$/', $first['token'] );
		$this->assertFalse( $first['renewed'] );
		$this->assertSame( $first['session_id'], $sessions->verify( $first['token'] )['sid'] );
		$this->assertGreaterThan( time() + 23 * HOUR_IN_SECONDS, $first['expires'] );

		$renewed = $sessions->start( $first['token'] );
		$this->assertTrue( $renewed['renewed'] );
		$this->assertSame( $first['session_id'], $renewed['session_id'] );

		$fresh = $sessions->start( 'invalid.token' );
		$this->assertNotSame( $first['session_id'], $fresh['session_id'] );
	}

	public function test_rejects_tampered_foreign_and_expired_tokens(): void {
		$sessions = new SessionManager();
		$token    = $sessions->start()['token'];

		list( $payload, $signature ) = explode( '.', $token );

		$forged_payload = bin2hex(
			(string) wp_json_encode(
				array(
					'v'   => 1,
					'sid' => str_repeat( 'a', 32 ),
					'iat' => time(),
					'exp' => time() + 999,
				)
			)
		);
		$this->assertNull( $sessions->verify( $forged_payload . '.' . $signature ), 'Payload swapped.' );
		$this->assertNull( $sessions->verify( $payload . '.' . str_repeat( '0', 64 ) ), 'Signature forged.' );
		$this->assertNull( $sessions->verify( 'garbage' ) );
		$this->assertNull( $sessions->verify( '' ) );
		$this->assertNull( $sessions->verify( 'zz.zz' ) );

		Functions\when( 'wp_salt' )->justReturn( 'other-salt' );
		$this->assertNull( $sessions->verify( $token ), 'Tokens from another site/salt fail.' );

		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		$this->options['chatlume_settings'] = array( 'security' => array( 'session_hours' => 1 ) );
		$short                              = $sessions->start()['token'];
		$this->assertNotNull( $sessions->verify( $short ) );
	}

	public function test_object_cache_limits_and_reports_retry_after(): void {
		$this->ext_object_cache = true;
		$limiter                = new RateLimiter();

		$results = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$results[] = $limiter->hit( 'session:abc', 3, 600 );
		}

		$this->assertSame( array( true, true, true, false ), array_column( $results, 'allowed' ) );
		$this->assertSame( 2, $results[0]['remaining'] );
		$this->assertSame( 0, $results[3]['remaining'] );
		$this->assertGreaterThan( 0, $results[3]['retry_after'] );
		$this->assertLessThanOrEqual( 600, $results[3]['retry_after'] );

		$this->assertTrue( $limiter->hit( 'session:other', 3, 600 )['allowed'], 'Buckets are independent.' );
	}

	public function test_limits_are_filterable(): void {
		$this->ext_object_cache = true;
		Filters\expectApplied( 'chatlume_rate_limit' )->andReturn(
			array(
				'limit'  => 1,
				'window' => 60,
			)
		);

		$limiter = new RateLimiter();
		$this->assertTrue( $limiter->hit( 'ip:x', 100, 600 )['allowed'] );
		$this->assertFalse( $limiter->hit( 'ip:x', 100, 600 )['allowed'] );
	}

	public function test_database_fallback_uses_one_atomic_upsert(): void {
		global $wpdb;

		$counts = array();
		$wpdb   = new class( $counts ) {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Counters by bucket.
			 *
			 * @var array<string, int>
			 */
			private array $counts;

			/**
			 * Queries run.
			 *
			 * @var string[]
			 */
			public array $queries = array();

			/**
			 * Constructor.
			 *
			 * @param array $counts Counters.
			 */
			public function __construct( array $counts ) {
				$this->counts = $counts;
			}

			/**
			 * Records the prepared query (values appended).
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return $query . ' | ' . implode( ',', $args );
			}

			/**
			 * Simulates the upsert.
			 *
			 * @param string $query Query.
			 * @return int
			 */
			public function query( $query ) {
				$this->queries[]         = $query;
				$bucket                  = explode( ',', explode( ' | ', $query )[1] )[1];
				$this->counts[ $bucket ] = ( $this->counts[ $bucket ] ?? 0 ) + 1;
				return 1;
			}

			/**
			 * Returns the counter.
			 *
			 * @param string $query Query.
			 * @return int
			 */
			public function get_var( $query ) {
				$bucket = explode( ',', explode( ' | ', $query )[1] )[1];
				return $this->counts[ $bucket ] ?? 0;
			}
		};

		$limiter = new RateLimiter();
		$this->assertTrue( $limiter->hit( 'ip:abc', 2, 600 )['allowed'] );
		$this->assertTrue( $limiter->hit( 'ip:abc', 2, 600 )['allowed'] );
		$this->assertFalse( $limiter->hit( 'ip:abc', 2, 600 )['allowed'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE count = count + 1', $wpdb->queries[0] );

		$wpdb = null;
	}
}
