<?php
/**
 * Tests for the admin Playground endpoint.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\REST\PlaygroundController;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * Admin-only access, and every Playground chat is a test chat as a logged-out
 * visitor, with a trace and without visitor rate limits.
 */
class PlaygroundTest extends TestCase {

	/**
	 * Requests the fake pipeline received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $received = array();

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Acme' );
	}

	/**
	 * Controller whose pipeline records the request, or throws.
	 *
	 * @param ChatException|null $error Exception to throw.
	 * @return PlaygroundController
	 */
	private function controller( ?ChatException $error = null ): PlaygroundController {
		$service = new class( $this, $error ) extends ChatService {
			/**
			 * Test case.
			 *
			 * @var PlaygroundTest
			 */
			private PlaygroundTest $test;

			/**
			 * Exception to throw.
			 *
			 * @var ChatException|null
			 */
			private ?ChatException $error;

			/**
			 * Constructor.
			 *
			 * @param PlaygroundTest     $test  Test case.
			 * @param ChatException|null $error Exception.
			 */
			public function __construct( PlaygroundTest $test, ?ChatException $error ) {
				$this->test  = $test;
				$this->error = $error;
			}

			/**
			 * Records the request.
			 *
			 * @param array $request Request.
			 * @param array $stream  Stream.
			 * @return array
			 * @throws ChatException When told to.
			 */
			public function handle( array $request, array $stream = array() ): array {
				unset( $stream );
				if ( null !== $this->error ) {
					throw $this->error;
				}
				$this->test->received[] = $request;
				return array(
					'conversation_id' => '12',
					'message_id'      => 3,
					'reply'           => array( 'text' => 'Hi' ),
					'details'         => array( 'trace' => array( 'route' => 'ai' ) ),
				);
			}
		};

		return new class( $service ) extends PlaygroundController {
			/**
			 * Pipeline.
			 *
			 * @var ChatService
			 */
			private ChatService $fake;

			/**
			 * Constructor.
			 *
			 * @param ChatService $fake Pipeline.
			 */
			public function __construct( ChatService $fake ) {
				$this->fake = $fake;
			}

			/**
			 * The fake pipeline.
			 *
			 * @return ChatService
			 */
			protected function service(): ChatService {
				return $this->fake;
			}
		};
	}

	public function test_route_is_admin_only(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new PlaygroundController() )->permissions_check() );

		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertTrue( ( new PlaygroundController() )->permissions_check() );
	}

	public function test_chats_are_tests_as_a_visitor_with_a_trace(): void {
		$response = $this->controller()->chat(
			new WP_REST_Request(
				array(
					'message'            => 'Hello',
					'conversation_id'    => '12',
					'language'           => 'de_DE',
					'skip_quick_answers' => true,
					'quick_answer_id'    => 4,
				)
			)
		);

		$this->assertSame( 'ai', $response->get_data()['details']['trace']['route'] );
		$sent = $this->received[0];
		$this->assertSame( 'admin-playground-5', $sent['session_id'] );
		$this->assertSame( 'Hello', $sent['message'] );
		$this->assertSame( '12', $sent['conversation_id'] );
		$this->assertSame( 'de-DE', $sent['language'] );
		$this->assertSame( 0, $sent['user_id'], 'Tests run as a logged-out visitor.' );
		$this->assertTrue( $sent['is_test'] );
		$this->assertTrue( $sent['skip_rate_limit'] );
		$this->assertTrue( $sent['debug'] );
		$this->assertTrue( $sent['skip_quick_answers'] );
		$this->assertSame( 4, $sent['quick_answer_id'] );
	}

	public function test_pipeline_errors_become_rest_errors(): void {
		$result = $this->controller( new ChatException( 'conversation_limit', 'Limit reached.', 403 ) )->chat( new WP_REST_Request( array( 'message' => 'Hello' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'chatlume_conversation_limit', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
