<?php
/**
 * Tests for the chat and quick answer REST controllers, plus new settings formats.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\REST\ChatController;
use Chatlume\REST\QuickAnswersController;
use Chatlume\Security\SessionManager;
use Chatlume\Settings\Settings;
use Chatlume\Tests\Support\HeaderRequest;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Same-origin checks, sanitizers, routes, sessions, honeypot, quick answer permissions.
 */
class ChatControllersTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->ext_object_cache = true;
		Functions\when( 'home_url' )->justReturn( 'https://shop.example.test' );
		Functions\when( 'site_url' )->justReturn( 'https://shop.example.test/wp' );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				return preg_match( '#^https?://#i', (string) $url ) ? (string) $url : '';
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
	}

	protected function tear_down(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	/**
	 * Origin cases.
	 *
	 * @return array<string, array{0: array<string, string>, 1: bool}>
	 */
	public function origin_cases(): array {
		return array(
			'same origin'          => array( array( 'origin' => 'https://shop.example.test' ), true ),
			'referer fallback'     => array( array( 'referer' => 'https://shop.example.test/products/' ), true ),
			'null origin uses ref' => array(
				array(
					'origin'  => 'null',
					'referer' => 'https://shop.example.test/',
				),
				true,
			),
			'other site'           => array( array( 'origin' => 'https://evil.test' ), false ),
			'look-alike host'      => array( array( 'origin' => 'https://shop.example.test.evil.test' ), false ),
			'other port'           => array( array( 'origin' => 'https://shop.example.test:8443' ), false ),
			'no headers'           => array( array(), false ),
		);
	}

	/**
	 * Same-origin check.
	 *
	 * @dataProvider origin_cases
	 *
	 * @param array<string, string> $headers Headers.
	 * @param bool                  $allowed Expected.
	 */
	public function test_origin_check( array $headers, bool $allowed ): void {
		$result = ( new ChatController() )->check_origin( new HeaderRequest( array(), $headers ) );

		$this->assertSame( $allowed, true === $result );
		if ( ! $allowed ) {
			$this->assertSame( 403, $result->get_error_data()['status'] );
		}
	}

	public function test_allowed_origins_are_filterable(): void {
		Filters\expectApplied( 'chatlume_allowed_origins' )->andReturn( array( 'https://partner.test' ) );

		$this->assertTrue( ( new ChatController() )->check_origin( new HeaderRequest( array(), array( 'origin' => 'https://partner.test' ) ) ) );
	}

	public function test_sanitizers(): void {
		$this->assertSame( 'abc.0f', ChatController::sanitize_token( 'ABC.0F <x>!' ) );
		$this->assertSame( 'https://shop.example.test/p/1', ChatController::sanitize_page_url( 'https://shop.example.test/p/1' ) );
		$this->assertSame( '', ChatController::sanitize_page_url( 'https://evil.test/' ), 'Only pages of this site.' );
		$this->assertSame( '', ChatController::sanitize_page_url( 'javascript:alert(1)' ) );
		$this->assertSame( 'pt-BR', ChatController::language( 'pt_BR' ) );
		$this->assertSame( 'zh-Hans-CN', ChatController::language( 'zh-Hans-CN' ) );
		$this->assertSame( 'en-US', ChatController::language( '' ), 'Defaults to the site language.' );
		$this->assertSame( 'en-US', ChatController::language( 'not a language!' ) );
		$this->assertSame( -1, ChatController::sanitize_rating( '-1', null, 'rating' ) );
	}

	public function test_public_routes_are_open_and_admin_routes_protected(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $route_namespace, $route, $endpoints ) use ( &$routes ) {
				unset( $route_namespace );
				$routes[ $route ] = isset( $endpoints['methods'] ) ? array( $endpoints ) : $endpoints;
				return true;
			}
		);

		( new ChatController() )->register_routes();
		( new QuickAnswersController() )->register_routes();

		foreach ( array( '/session', '/chat', '/feedback' ) as $public ) {
			$this->assertSame( '__return_true', $routes[ $public ][0]['permission_callback'], $public );
		}
		$this->assertTrue( $routes['/chat'][0]['args']['token']['required'] );
		$this->assertSame( 8000, $routes['/chat'][0]['args']['message']['maxLength'] );
		$this->assertArrayHasKey( 'website', $routes['/chat'][0]['args'], 'Honeypot field.' );

		foreach ( array( '/quick-answers', '/quick-answers/(?P<id>\d+)' ) as $admin ) {
			foreach ( $routes[ $admin ] as $endpoint ) {
				$this->assertIsArray( $endpoint['permission_callback'] );
			}
		}

		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new QuickAnswersController() )->permissions_check() );
	}

	public function test_session_route_returns_token_and_fresh_config(): void {
		$response = ( new ChatController() )->session(
			new HeaderRequest(
				array(
					'token'    => '',
					'language' => 'en',
				),
				array( 'origin' => 'https://shop.example.test' )
			)
		);
		$data     = $response->get_data();

		$this->assertNotNull( ( new SessionManager() )->verify( $data['token'] ) );
		$this->assertSame( 'Assistant', $data['config']['bot_name'] );
		$this->assertCount( 3, $data['config']['starter_questions'] );
		$this->assertSame( 1000, $data['config']['max_message_length'] );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
	}

	public function test_chat_rejects_honeypot_and_bad_sessions_before_doing_work(): void {
		$controller = new ChatController();
		$origin     = array( 'origin' => 'https://shop.example.test' );

		$honeypot = $controller->chat(
			new HeaderRequest(
				array(
					'token'   => 'x',
					'message' => 'hi',
					'website' => 'http://spam.test',
				),
				$origin
			)
		);
		$this->assertSame( 'chatlume_invalid_request', $honeypot->get_error_code() );

		$expired = $controller->chat(
			new HeaderRequest(
				array(
					'token'   => 'abc.def',
					'message' => 'hi',
					'website' => '',
				),
				$origin
			)
		);
		$this->assertSame( 'chatlume_invalid_session', $expired->get_error_code() );
		$this->assertSame( 401, $expired->get_error_data()['status'] );

		$foreign = $controller->chat( new HeaderRequest( array( 'message' => 'hi' ), array( 'origin' => 'https://evil.test' ) ) );
		$this->assertSame( 'chatlume_forbidden_origin', $foreign->get_error_code() );
	}

	public function test_feedback_on_unstored_conversations_is_accepted_without_saving(): void {
		$token = ( new SessionManager() )->start()['token'];

		$data = ( new ChatController() )->feedback(
			new HeaderRequest(
				array(
					'token'           => $token,
					'conversation_id' => 't_' . str_repeat( 'a', 24 ),
					'message_id'      => 0,
					'rating'          => 1,
				),
				array( 'origin' => 'https://shop.example.test' )
			)
		)->get_data();

		$this->assertFalse( $data['saved'] );
	}

	public function test_new_settings_formats(): void {
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'security' => array( 'trusted_proxy_ips' => array( 'not-an-ip' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'costs' => array( 'alert_email' => 'nope' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'chatbot' => array( 'preset' => 'pirate' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'chatbot' => array( 'starter_questions' => array( 'a', 'b', 'c', 'd', 'e' ) ) ) ) );

		$saved = Settings::update(
			array(
				'security' => array( 'trusted_proxy_ips' => array( '10.0.0.0/8', '2001:db8::/32' ) ),
				'chatbot'  => array(
					'welcome_message' => 'Hi <strong>there</strong><script>x</script>',
					'temperature'     => 9,
				),
				'costs'    => array( 'daily_budget' => -5 ),
			)
		);

		$this->assertSame( array( '10.0.0.0/8', '2001:db8::/32' ), $saved['security']['trusted_proxy_ips'] );
		$this->assertSame( 'Hi <strong>there</strong>x', $saved['chatbot']['welcome_message'] );
		$this->assertEquals( 2.0, $saved['chatbot']['temperature'], 'Clamped.' );
		$this->assertEquals( 0.0, $saved['costs']['daily_budget'], 'Clamped.' );
	}
}
