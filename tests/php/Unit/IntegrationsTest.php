<?php
/**
 * Tests for webhooks, Turnstile, and the Integrations routes.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Integrations\Webhooks;
use Chatlume\Providers\ApiKeys;
use Chatlume\REST\IntegrationsController;
use Chatlume\Security\Turnstile;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Signed deliveries, retries, the log, ended conversations, the bot check,
 * and the admin routes.
 */
class IntegrationsTest extends TestCase {

	/**
	 * Scheduled events: [timestamp, hook, args].
	 *
	 * @var array<int, array>
	 */
	private array $scheduled = array();

	/**
	 * Fake database.
	 *
	 * @var object
	 */
	private object $db;

	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->scheduled = array();

		Functions\when( 'wp_salt' )->alias(
			static function ( $scheme = 'auth' ) {
				return 'salt-' . $scheme;
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return preg_match( '#^https?://#i', (string) $url ) ? (string) $url : '';
			}
		);
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args = array() ) {
				return wp_remote_request( $url, $args );
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook, $args = array() ) {
				$this->scheduled[] = array( $timestamp, $hook, $args );
				return true;
			}
		);
		Functions\when( 'spawn_cron' )->justReturn( true );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );

		$this->db = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Rows returned by get_results(), in order.
			 *
			 * @var array<int, array>
			 */
			public array $results = array();

			/**
			 * Row returned by get_row().
			 *
			 * @var array|null
			 */
			public $row = null;

			/**
			 * Updates: [table, data, where].
			 *
			 * @var array<int, array>
			 */
			public array $updates = array();

			/**
			 * Prepared queries.
			 *
			 * @var array<int, array>
			 */
			public array $prepared = array();

			/**
			 * Records a query.
			 *
			 * @param string $sql  SQL.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $sql, ...$args ) {
				$this->prepared[] = array( $sql, $args );
				return $sql;
			}

			/**
			 * Escapes LIKE.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Next result set.
			 *
			 * @return array
			 */
			public function get_results() {
				return array_shift( $this->results ) ?? array();
			}

			/**
			 * The row.
			 *
			 * @return array|null
			 */
			public function get_row() {
				return $this->row;
			}

			/**
			 * Records an update.
			 *
			 * @param string $table Table.
			 * @param array  $data  Data.
			 * @param array  $where Where.
			 * @return int
			 */
			public function update( $table, $data, $where ) {
				$this->updates[] = array( $table, $data, $where );
				return 1;
			}
		};
		$wpdb     = $this->db;
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		parent::tear_down();
	}

	/**
	 * Sets the webhooks.
	 *
	 * @param array<int, array> $webhooks Webhooks.
	 * @return void
	 */
	private function webhooks( array $webhooks ): void {
		$this->options[ Settings::OPTION ] = array(
			'integrations' => array(
				'webhooks'           => $webhooks,
				'analytics'          => false,
				'turnstile'          => false,
				'turnstile_site_key' => '',
			),
		);
	}

	public function test_events_queue_only_for_listening_webhooks(): void {
		$this->webhooks(
			array(
				array(
					'url'    => 'https://crm.example.test/hook',
					'events' => array( 'lead_captured' ),
				),
				array(
					'url'    => 'https://chat.example.test/in',
					'events' => array( 'handoff_requested', 'lead_captured' ),
				),
				array(
					'url'    => 'javascript:alert(1)',
					'events' => array( 'lead_captured' ),
				),
			)
		);

		$webhooks = new Webhooks();
		$webhooks->on_lead(
			array(
				'name'  => 'Rina',
				'email' => 'rina@example.test',
			),
			'42',
			'https://example.test/shop'
		);

		$this->assertCount( 2, $this->scheduled );
		list( , $hook, $args ) = $this->scheduled[0];
		$this->assertSame( Webhooks::DELIVER_HOOK, $hook );
		$this->assertSame( md5( 'https://crm.example.test/hook' ), $args[0] );
		$this->assertSame( 'lead_captured', $args[1] );
		$this->assertSame( 'rina@example.test', $args[2]['data']['lead']['email'] );
		$this->assertSame( '42', $args[2]['data']['conversation_id'] );
		$this->assertSame( 1, $args[3] );
		$this->assertSame( $args[4], $args[2]['id'] );

		$this->scheduled = array();
		$webhooks->on_handoff( 'email_team', '42', null, '' );
		$this->assertCount( 1, $this->scheduled );
		$this->assertNull( $this->scheduled[0][2][2]['data']['lead'] );
		$this->assertTrue( Webhooks::listens( 'handoff_requested' ) );
		$this->assertFalse( Webhooks::listens( 'conversation_ended' ) );
	}

	public function test_deliveries_are_signed_logged_and_retried_once(): void {
		$url = 'https://crm.example.test/hook';
		$this->webhooks(
			array(
				array(
					'url'    => $url,
					'events' => array( 'lead_captured' ),
				),
			)
		);
		$payload = Webhooks::payload( 'lead_captured', array( 'lead' => array( 'name' => 'A' ) ), 'delivery-1' );

		$this->queue_response( 500, 'down' );
		$result = ( new Webhooks() )->deliver( md5( $url ), 'lead_captured', $payload, 1, 'delivery-1' );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 500, $result['status'] );
		list( $sent_url, $args ) = $this->http_requests[0];
		$this->assertSame( $url, $sent_url );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'lead_captured', $args['headers']['X-Chatlume-Event'] );
		$this->assertSame( 'delivery-1', $args['headers']['X-Chatlume-Delivery'] );
		$this->assertSame( 'sha256=' . hash_hmac( 'sha256', $args['body'], Webhooks::secret() ), $args['headers']['X-Chatlume-Signature'] );
		$this->assertSame( 'Example Site', $this->sent_body()['site']['name'] );

		// One retry five minutes later.
		$this->assertCount( 1, $this->scheduled );
		$this->assertGreaterThanOrEqual( time() + 299, $this->scheduled[0][0] );
		$this->assertSame( 2, $this->scheduled[0][2][3] );

		$this->queue_response( 500, 'still down' );
		( new Webhooks() )->deliver( md5( $url ), 'lead_captured', $payload, 2, 'delivery-1' );
		$this->assertCount( 1, $this->scheduled, 'The second attempt is the last.' );

		$log = Webhooks::deliveries();
		$this->assertCount( 2, $log );
		$this->assertSame( 2, $log[0]['attempt'] );
		$this->assertSame( 'crm.example.test', $log[0]['host'] );
		$this->assertArrayNotHasKey( 'body', $log[0] );
		$this->assertFalse( $this->autoload[ Webhooks::LOG_OPTION ] );

		Webhooks::clear_log();
		$this->assertSame( array(), Webhooks::deliveries() );
	}

	public function test_overlapping_cron_runs_send_each_attempt_once(): void {
		$url = 'https://crm.example.test/hook';
		$this->webhooks(
			array(
				array(
					'url'    => $url,
					'events' => array( 'conversation_ended' ),
				),
			)
		);
		$payload = Webhooks::payload( 'conversation_ended', array(), 'delivery-2' );

		$this->queue_response( 200, 'ok' );
		$this->assertTrue( ( new Webhooks() )->deliver( md5( $url ), 'conversation_ended', $payload, 1, 'delivery-2' )['ok'] );
		$again = ( new Webhooks() )->deliver( md5( $url ), 'conversation_ended', $payload, 1, 'delivery-2' );

		$this->assertSame( 'duplicate', $again['error'] );
		$this->assertCount( 1, $this->http_requests );
		$this->assertCount( 1, Webhooks::deliveries() );
		$this->assertSame( array(), $this->scheduled, 'A duplicate is not retried.' );
	}

	public function test_deliveries_for_removed_webhooks_are_dropped(): void {
		$this->webhooks(
			array(
				array(
					'url'    => 'https://crm.example.test/hook',
					'events' => array( 'handoff_requested' ),
				),
			)
		);

		$result = ( new Webhooks() )->deliver( md5( 'https://crm.example.test/hook' ), 'lead_captured', array(), 1, 'x' );
		$this->assertSame( 'removed', $result['error'] );
		$result = ( new Webhooks() )->deliver( md5( 'https://gone.example.test/' ), 'handoff_requested', array(), 1, 'x' );
		$this->assertSame( 'removed', $result['error'] );
		$this->assertSame( array(), $this->http_requests );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_log_keeps_the_last_fifty(): void {
		for ( $i = 0; $i < 55; $i++ ) {
			$this->queue_response( 200, 'ok' );
			( new Webhooks() )->test( 'https://crm.example.test/hook' );
		}

		$this->assertCount( Webhooks::LOG_SIZE, Webhooks::deliveries() );
		$this->assertTrue( Webhooks::deliveries()[0]['ok'] );
		$this->assertSame( 'test', Webhooks::deliveries()[0]['event'] );
	}

	public function test_the_payload_can_be_filtered_and_secret_is_stable(): void {
		Filters\expectApplied( 'chatlume_webhook_payload' )->once()->andReturnUsing(
			static function ( $payload ) {
				$payload['data']['extra'] = 'yes';
				return $payload;
			}
		);

		$payload = Webhooks::payload( 'test', array(), 'id-1' );
		$this->assertSame( 'yes', $payload['data']['extra'] );
		$this->assertSame( 'https://example.test/', $payload['site']['url'] );

		$secret = Webhooks::secret();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $secret );
		$this->assertSame( $secret, Webhooks::secret() );
		$this->assertNotSame( $secret, Webhooks::regenerate_secret() );
	}

	public function test_only_negative_feedback_is_sent(): void {
		$this->webhooks(
			array(
				array(
					'url'    => 'https://crm.example.test/hook',
					'events' => array( 'negative_feedback' ),
				),
			)
		);
		$this->db->row = array(
			'role'    => 'assistant',
			'content' => 'We ship in 3 days.',
		);

		$webhooks = new Webhooks();
		$webhooks->on_feedback( 7, 70, 1 );
		$this->assertSame( array(), $this->scheduled );

		$webhooks->on_feedback( 7, 70, -1 );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( 'We ship in 3 days.', $this->scheduled[0][2][2]['data']['answer'] );
		$this->assertSame( 70, $this->scheduled[0][2][2]['data']['message_id'] );
	}

	public function test_ended_conversations_are_reported_once(): void {
		$this->assertSame( 0, ( new Webhooks() )->find_ended(), 'Nothing runs without a listener.' );
		$this->assertSame( array(), $this->db->prepared );

		$this->webhooks(
			array(
				array(
					'url'    => 'https://crm.example.test/hook',
					'events' => array( 'conversation_ended' ),
				),
			)
		);
		$this->db->results = array(
			array(
				array(
					'id'         => '9',
					'page_url'   => 'https://example.test/faq',
					'language'   => 'en',
					'lead_name'  => '',
					'lead_email' => '',
					'rating'     => null,
					'created_at' => '2026-10-18 09:00:00',
					'updated_at' => '2026-10-18 09:10:00',
				),
			),
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			),
		);
		$this->db->row     = array( 'meta' => '{"source":"widget"}' );

		$this->assertSame( 1, ( new Webhooks() )->find_ended() );

		$query = $this->db->prepared[0];
		$this->assertStringContainsString( 'meta NOT LIKE', $query[0] );
		$this->assertStringContainsString( 'is_test = 0', $query[0] );
		$this->assertSame( gmdate( 'Y-m-d H:i', time() - Webhooks::IDLE ), substr( $query[1][1], 0, 16 ) );

		$meta = json_decode( $this->db->updates[0][1]['meta'], true );
		$this->assertSame( 'widget', $meta['source'] );
		$this->assertArrayHasKey( 'ended_at', $meta );

		$data = $this->scheduled[0][2][2]['data'];
		$this->assertSame( '9', $data['conversation_id'] );
		$this->assertNull( $data['lead'] );
		$this->assertSame( '2026-10-18T09:10:00Z', $data['last_message_at'] );
		$this->assertSame( 'Hello', $data['messages'][0]['content'] );
	}

	public function test_turnstile_stays_off_without_both_keys(): void {
		$this->assertFalse( Turnstile::enabled() );

		$this->options[ Settings::OPTION ] = array(
			'integrations' => array(
				'webhooks'           => array(),
				'analytics'          => false,
				'turnstile'          => true,
				'turnstile_site_key' => ' site-4AAAA ',
			),
		);
		$this->assertFalse( Turnstile::enabled(), 'The secret key is missing.' );
		$this->assertSame( 'site-4AAAA', Turnstile::site_key() );

		ApiKeys::save( Turnstile::KEY_ID, '0x4SECRET' );
		$this->assertTrue( Turnstile::enabled() );
	}

	public function test_turnstile_verification(): void {
		ApiKeys::save( Turnstile::KEY_ID, '0x4SECRET' );

		$this->assertFalse( Turnstile::verify( '' ) );
		$this->assertFalse( Turnstile::verify( str_repeat( 'a', 2049 ) ) );
		$this->assertSame( array(), $this->http_requests );

		$this->queue_response( 200, array( 'success' => true ) );
		$this->assertTrue( Turnstile::verify( 'token-1', '203.0.113.5' ) );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $this->http_requests[0][0] );
		$this->assertSame(
			array(
				'secret'   => '0x4SECRET',
				'response' => 'token-1',
				'remoteip' => '203.0.113.5',
			),
			$this->http_requests[0][1]['body']
		);

		$this->queue_response( 200, array( 'success' => false ) );
		$this->assertFalse( Turnstile::verify( 'token-2' ), 'An explicit no refuses.' );

		$this->queue_response( 502, 'bad gateway' );
		$this->assertTrue( Turnstile::verify( 'token-3' ), 'An outage keeps the chat available.' );
		$this->assertTrue( Turnstile::verify( 'token-4' ), 'A network error keeps the chat available.' );
	}

	public function test_routes_are_admin_only_with_args(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $endpoints ) use ( &$routes ) {
				$routes[ $route ] = isset( $endpoints['methods'] ) ? array( $endpoints ) : $endpoints;
				return true;
			}
		);

		$controller = new IntegrationsController();
		$controller->register_routes();

		$this->assertSame(
			array( '/integrations', '/integrations/secret', '/integrations/deliveries', '/integrations/test-webhook', '/integrations/test-email', '/integrations/turnstile-key' ),
			array_keys( $routes )
		);
		foreach ( $routes as $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				$this->assertSame( array( $controller, 'permissions_check' ), $endpoint['permission_callback'] );
				$this->assertArrayHasKey( 'args', $endpoint );
			}
		}
		$this->assertSame( '^https?://\S+$', $routes['/integrations/test-webhook'][0]['args']['url']['pattern'] );

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, $controller->permissions_check() );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( $controller->permissions_check() );
	}

	public function test_the_turnstile_secret_is_never_returned(): void {
		$this->options['admin_email'] = 'owner@example.test';
		$controller                   = new IntegrationsController();

		$saved = $controller->save_turnstile_key( new WP_REST_Request( array( 'secret' => '0x4AAAAsecretvalue1234' ) ) );
		$this->assertSame( 'settings', $saved->get_data()['turnstile']['source'] );

		$response = $controller->get_item();
		$this->assertStringNotContainsString( 'secretvalue', (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'owner@example.test', $response->get_data()['admin_email'] );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$deleted = $controller->delete_turnstile_key();
		$this->assertSame( 'none', $deleted->get_data()['turnstile']['source'] );
	}

	public function test_test_webhook_and_email_routes(): void {
		$this->options['admin_email'] = 'owner@example.test';
		$controller                   = new IntegrationsController();

		$this->queue_response( 204, '' );
		$data = $controller->test_webhook( new WP_REST_Request( array( 'url' => 'https://crm.example.test/hook' ) ) )->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 204, $data['status'] );
		$this->assertSame( 'test', $data['deliveries'][0]['event'] );
		$this->assertInstanceOf( WP_Error::class, $controller->test_webhook( new WP_REST_Request( array( 'url' => '' ) ) ) );

		$sent = array();
		Functions\when( 'wp_mail' )->alias(
			static function ( $to ) use ( &$sent ) {
				$sent[] = $to;
				return true;
			}
		);
		$this->assertSame( 'owner@example.test', $controller->test_email()->get_data()['sent_to'] );
		$this->assertSame( array( 'owner@example.test' ), $sent );

		Functions\when( 'wp_mail' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, $controller->test_email() );
	}
}
