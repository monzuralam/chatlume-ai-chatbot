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
use Chatlume\Appearance\TokenResolver;
use Chatlume\Chat\StopSignal;
use Chatlume\Chat\StreamHandler;
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

		foreach ( array( '/session', '/chat', '/chat/stream', '/chat/stop', '/feedback' ) as $public ) {
			$this->assertSame( '__return_true', $routes[ $public ][0]['permission_callback'], $public );
		}
		$this->assertTrue( $routes['/chat'][0]['args']['token']['required'] );
		$this->assertSame( 8000, $routes['/chat'][0]['args']['message']['maxLength'] );
		$this->assertArrayHasKey( 'website', $routes['/chat'][0]['args'], 'Honeypot field.' );
		$this->assertSame( array_keys( $routes['/chat'][0]['args'] ), array_keys( $routes['/chat/stream'][0]['args'] ) );
		$this->assertSame( '^[a-f0-9]*$', $routes['/session'][0]['args']['v']['pattern'] );

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

	public function test_session_sends_the_theme_only_to_stale_pages(): void {
		$current = TokenResolver::config()['v'];
		$session = function ( string $version ) {
			return ( new ChatController() )->session(
				new HeaderRequest(
					array(
						'token' => '',
						'v'     => $version,
					),
					array( 'origin' => 'https://shop.example.test' )
				)
			)->get_data();
		};

		$this->assertArrayNotHasKey( 'theme', $session( $current ) );
		$this->assertArrayNotHasKey( 'theme', $session( '' ) );
		$this->assertSame( $current, $session( 'abc123' )['theme']['v'] );
	}

	public function test_quick_actions_become_safe_links(): void {
		$this->options[ Settings::OPTION ] = array(
			'chatbot' => array(
				'quick_actions' => array(
					array(
						'label' => 'Shop',
						'type'  => 'url',
						'value' => 'https://shop.example.test/store',
					),
					array(
						'label' => 'Script',
						'type'  => 'url',
						'value' => 'javascript:alert(1)',
					),
					array(
						'label' => 'Mail',
						'type'  => 'email',
						'value' => 'help@example.test',
					),
					array(
						'label' => 'Call',
						'type'  => 'phone',
						'value' => '+44 (20) 1234-5678',
					),
					array(
						'label' => 'WhatsApp',
						'type'  => 'whatsapp',
						'value' => '+44 7700 900123',
					),
					array(
						'label' => 'Prices',
						'type'  => 'message',
						'value' => 'What are your prices?',
					),
					array(
						'label' => '',
						'type'  => 'url',
						'value' => 'https://shop.example.test/',
					),
				),
			),
		);

		$this->assertSame(
			array(
				array(
					'label' => 'Shop',
					'type'  => 'url',
					'href'  => 'https://shop.example.test/store',
				),
				array(
					'label' => 'Mail',
					'type'  => 'email',
					'href'  => 'mailto:help@example.test',
				),
				array(
					'label' => 'Call',
					'type'  => 'phone',
					'href'  => 'tel:+442012345678',
				),
				array(
					'label' => 'WhatsApp',
					'type'  => 'whatsapp',
					'href'  => 'https://wa.me/447700900123',
				),
				array(
					'label'   => 'Prices',
					'type'    => 'message',
					'message' => 'What are your prices?',
				),
			),
			ChatController::quick_actions()
		);
	}

	public function test_stream_route_validates_before_streaming(): void {
		$ran        = array();
		$controller = new class( $ran ) extends ChatController {
			/**
			 * Requests that reached the stream handler.
			 *
			 * @var array
			 */
			public array $ran;

			/**
			 * Constructor.
			 *
			 * @param array $ran Collector.
			 */
			public function __construct( array &$ran ) {
				$this->ran = &$ran;
			}

			/**
			 * Records instead of streaming.
			 *
			 * @return StreamHandler
			 */
			protected function stream_handler(): StreamHandler {
				$ran = &$this->ran;
				return new class( $ran ) extends StreamHandler {
					/**
					 * Collector.
					 *
					 * @var array
					 */
					private array $seen;

					/**
					 * Constructor.
					 *
					 * @param array $seen Collector.
					 */
					public function __construct( array &$seen ) {
						$this->seen = &$seen;
					}

					/**
					 * Records the request.
					 *
					 * @param array $request Request.
					 * @return void
					 */
					public function run( array $request ): void {
						$this->seen[] = $request;
					}
				};
			}

			/**
			 * Does not exit in tests.
			 *
			 * @return void
			 */
			protected function finish(): void {}
		};
		$origin     = array( 'origin' => 'https://shop.example.test' );
		$token      = ( new SessionManager() )->start()['token'];
		Functions\when( 'wp_get_current_user' )->justReturn(
			(object) array(
				'ID'           => 0,
				'display_name' => '',
			)
		);

		$this->assertSame( 'chatlume_forbidden_origin', $controller->stream( new HeaderRequest( array( 'token' => $token ), array( 'origin' => 'https://evil.test' ) ) )->get_error_code() );
		$this->assertSame( 'chatlume_invalid_session', $controller->stream( new HeaderRequest( array( 'token' => 'bad' ), $origin ) )->get_error_code() );
		$this->assertSame( array(), $ran );

		$controller->stream(
			new HeaderRequest(
				array(
					'token'   => $token,
					'message' => 'Hello',
				),
				$origin
			)
		);
		$this->assertCount( 1, $ran );
		$this->assertSame( 'Hello', $ran[0]['message'] );

		$this->options[ Settings::OPTION ] = array( 'streaming' => array( 'enabled' => false ) );
		$disabled                          = $controller->stream( new HeaderRequest( array( 'token' => $token ), $origin ) );
		$this->assertSame( 'chatlume_streaming_disabled', $disabled->get_error_code() );
		$this->assertSame( 409, $disabled->get_error_data()['status'] );
		$this->assertCount( 1, $ran );
	}

	public function test_stop_route_flags_only_the_callers_stream(): void {
		$this->ext_object_cache = true;
		$controller             = new ChatController();
		$origin                 = array( 'origin' => 'https://shop.example.test' );
		$session                = ( new SessionManager() )->start();
		$sid                    = ( new SessionManager() )->verify( $session['token'] )['sid'];
		$stream                 = 'abcdef0123456789';

		$this->assertSame(
			'chatlume_invalid_session',
			$controller->stop(
				new HeaderRequest(
					array(
						'token'     => 'bad',
						'stream_id' => $stream,
					),
					$origin
				)
			)->get_error_code()
		);
		$this->assertSame(
			'chatlume_forbidden_origin',
			$controller->stop(
				new HeaderRequest(
					array(
						'token'     => $session['token'],
						'stream_id' => $stream,
					),
					array( 'origin' => 'https://evil.test' )
				)
			)->get_error_code()
		);
		$this->assertFalse( ( new StopSignal() )->requested( StopSignal::key( $sid, $stream ) ) );

		$response = $controller->stop(
			new HeaderRequest(
				array(
					'token'     => $session['token'],
					'stream_id' => $stream,
				),
				$origin
			)
		);

		$this->assertSame( array( 'stopping' => true ), $response->get_data() );
		$this->assertTrue( ( new StopSignal() )->requested( StopSignal::key( $sid, $stream ) ) );
		$this->assertFalse( ( new StopSignal() )->requested( StopSignal::key( 'someone-else', $stream ) ) );
	}
}
