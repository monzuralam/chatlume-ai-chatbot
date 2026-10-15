<?php
/**
 * Tests for the conversation log.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Data\ConversationLog;
use Chatlume\REST\ConversationsController;
use Chatlume\Tests\Support\HeaderRequest;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Filters, prepared queries, transcripts, deletion, and the REST routes.
 */
class ConversationsTest extends TestCase {

	/**
	 * Fake database that checks every prepared query.
	 *
	 * @var object
	 */
	private object $db;

	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->db = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Prepared queries: [sql, values].
			 *
			 * @var array<int, array{0: string, 1: array}>
			 */
			public array $prepared = array();

			/**
			 * Rows returned by get_results.
			 *
			 * @var array
			 */
			public array $results = array();

			/**
			 * Row returned by get_row.
			 *
			 * @var array|null
			 */
			public ?array $row = null;

			/**
			 * Rows affected by query().
			 *
			 * @var int
			 */
			public int $affected = 0;

			/**
			 * Checks that placeholders and values match.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values (or one array of values).
			 * @return string
			 * @throws \RuntimeException On a mismatch.
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$placeholders = preg_match_all( '/%[sdfi]/', $query );
				if ( count( $args ) !== $placeholders ) {
					// Test double: the message is asserted on, never printed.
					throw new \RuntimeException( "Expected $placeholders values, got " . count( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			/**
			 * Escapes LIKE wildcards like core.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Count.
			 *
			 * @return string
			 */
			public function get_var() {
				return '2';
			}

			/**
			 * Rows.
			 *
			 * @return array
			 */
			public function get_results() {
				return $this->results;
			}

			/**
			 * Column.
			 *
			 * @return array
			 */
			public function get_col() {
				return array( 'en-US', 'bn-BD' );
			}

			/**
			 * Row.
			 *
			 * @return array|null
			 */
			public function get_row() {
				return $this->row;
			}

			/**
			 * Write query.
			 *
			 * @return int
			 */
			public function query() {
				return $this->affected;
			}
		};
		$wpdb     = $this->db;

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Asia/Dhaka' ) );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		parent::tear_down();
	}

	public function test_filters_are_validated_with_safe_defaults(): void {
		$filters = ConversationLog::filters(
			array(
				'search'    => '  50%_off  ',
				'date_from' => '2026-10-01',
				'date_to'   => '2026-10-15',
				'rating'    => 'excellent',
				'source'    => 'drop table',
				'lead'      => 'yes',
				'language'  => "en-US'; --",
			)
		);

		$this->assertSame( '50%_off', $filters['search'] );
		$this->assertSame( '%50\%\_off%', $filters['like'] );
		// Dhaka is UTC+6: local midnight is 18:00 UTC the day before.
		$this->assertSame( '2026-09-30 18:00:00', $filters['from'] );
		$this->assertSame( '2026-10-15 18:00:00', $filters['to'], 'The end date includes the whole day.' );
		$this->assertSame( 'any', $filters['rating'] );
		$this->assertSame( '', $filters['source'] );
		$this->assertSame( 'yes', $filters['lead'] );
		$this->assertSame( 'en-US--', $filters['language'] );
	}

	public function test_empty_dates_use_bounds_strict_sql_accepts(): void {
		$filters = ConversationLog::filters( array( 'date_from' => 'yesterday' ) );

		$this->assertSame( '1970-01-01 00:00:00', $filters['from'] );
		$this->assertSame( '9999-12-31 00:00:00', $filters['to'] );
	}

	public function test_search_prepares_every_value_and_hides_private_columns(): void {
		$this->db->results = array(
			array(
				'id'             => '7',
				'user_id'        => '0',
				'page_url'       => 'https://example.test/shop/',
				'language'       => 'en-US',
				'provider'       => 'openai',
				'model'          => 'm1',
				'lead_name'      => '',
				'lead_email'     => '',
				'rating'         => null,
				'message_count'  => '4',
				'total_cost'     => '0.001200',
				'created_at'     => '2026-10-15 08:00:00',
				'updated_at'     => '2026-10-15 08:05:00',
				'first_question' => 'Hello?',
				'sources'        => 'ai,cache',
				'session_id'     => 'secret-session',
				'visitor_hash'   => 'hash',
			),
		);

		$found = ( new ConversationLog() )->search(
			array(
				'search' => 'hello',
				'rating' => 'up',
				'source' => 'ai',
			),
			2,
			500
		);

		$this->assertSame( 2, $found['total'] );
		$item = $found['items'][0];
		$this->assertSame( 7, $item['id'] );
		$this->assertSame( array( 'ai', 'cache' ), $item['sources'] );
		$this->assertSame( '2026-10-15T08:00:00Z', $item['created_at'] );
		$this->assertNull( $item['rating'] );
		$this->assertArrayNotHasKey( 'session_id', $item );
		$this->assertArrayNotHasKey( 'visitor_hash', $item );

		$list = end( $this->db->prepared );
		$this->assertStringContainsString( 'c.is_test = 0', $list[0], 'Test conversations are never listed.' );
		$this->assertSame( array( ConversationLog::MAX_PER_PAGE, ConversationLog::MAX_PER_PAGE ), array_slice( $list[1], -2 ), 'Page size is capped; page 2 starts after one full page.' );
	}

	public function test_transcript_decodes_messages(): void {
		$this->db->row     = array(
			'id'            => '7',
			'user_id'       => '3',
			'page_url'      => '',
			'language'      => 'en',
			'provider'      => '',
			'model'         => '',
			'lead_name'     => '',
			'lead_email'    => '',
			'rating'        => '-1',
			'message_count' => '2',
			'total_cost'    => '0',
			'is_test'       => '0',
			'created_at'    => '2026-10-15 08:00:00',
			'updated_at'    => '2026-10-15 08:00:00',
		);
		$this->db->results = array(
			array(
				'id'              => '1',
				'conversation_id' => '7',
				'role'            => 'assistant',
				'content'         => 'Hi',
				'source'          => 'ai',
				'input_tokens'    => '10',
				'output_tokens'   => '5',
				'cost'            => '0.000100',
				'latency_ms'      => '800',
				'feedback'        => '1',
				'sources'         => '[{"title":"Docs","url":"https://example.test/docs/"}]',
				'error_type'      => '',
				'meta'            => '{"interrupted":"stopped"}',
				'created_at'      => '2026-10-15 08:00:01',
			),
		);

		$transcript = ( new ConversationLog() )->transcript( 7 );

		$this->assertTrue( $transcript['conversation']['logged_in'] );
		$this->assertSame( -1, $transcript['conversation']['rating'] );
		$this->assertFalse( $transcript['conversation']['is_test'] );
		$message = $transcript['messages'][0];
		$this->assertSame( 1, $message['feedback'] );
		$this->assertSame( 'Docs', $message['sources'][0]['title'] );
		$this->assertSame( 'stopped', $message['interrupted'] );

		$this->db->row = null;
		$this->assertNull( ( new ConversationLog() )->transcript( 8 ) );
	}

	public function test_delete_removes_messages_too(): void {
		$this->db->affected = 2;

		$this->assertSame( 2, ( new ConversationLog() )->delete( array( 3, '4', 0, 3, -1 ) ) );
		$this->assertCount( 2, $this->db->prepared );
		$this->assertSame( array( 'wp_chatlume_messages', 3, 4 ), $this->db->prepared[0][1] );
		$this->assertSame( array( 'wp_chatlume_conversations', 3, 4 ), $this->db->prepared[1][1] );
		$this->assertSame( 0, ( new ConversationLog() )->delete( array() ) );
	}

	public function test_routes_are_admin_only_and_report_missing_items(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $endpoints ) use ( &$routes ) {
				unset( $route_namespace );
				$routes[ $route ] = isset( $endpoints['methods'] ) ? array( $endpoints ) : $endpoints;
				return true;
			}
		);
		$controller = new ConversationsController();
		$controller->register_routes();

		$this->assertSame( array( '/conversations', '/conversations/export', '/conversations/(?P<id>\d+)' ), array_keys( $routes ) );
		foreach ( $routes as $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( array( $controller, 'permissions_check' ), $endpoint['permission_callback'] );
			}
		}
		$this->assertSame( 500, $routes['/conversations'][1]['args']['ids']['maxItems'] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, $controller->permissions_check() );

		$this->db->row = null;
		$missing       = $controller->get_item( new HeaderRequest( array( 'id' => 99 ) ) );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
		$this->assertSame( array( 4, 5 ), ConversationsController::sanitize_ids( array( '4', 'x', 5, -2 ) ) );
	}
}
