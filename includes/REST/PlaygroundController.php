<?php
/**
 * REST: the admin Playground ("Test your bot").
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST chatlume/v1/playground/chat: an admin chats with the real bot as a
 * logged-out visitor would (so caching and personalization behave alike). Replies
 * come with a trace of how they were produced (route, quick answer, cache,
 * knowledge chunks, final prompt). These chats are stored as tests, so they
 * never appear in Conversations or statistics, and visitor rate limits do not
 * apply. Keys are never part of the trace.
 */
class PlaygroundController {

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/playground/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'message'            => array(
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'maxLength'         => 4000,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'conversation_id'    => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 30,
						'pattern'           => ChatController::CONVERSATION_PATTERN,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'language'           => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 20,
						'pattern'           => '^$|' . ChatController::LANGUAGE_PATTERN,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'skip_quick_answers' => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'quick_answer_id'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
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
			__( 'Sorry, you are not allowed to test the chatbot.', 'chatlume-ai-chatbot' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * POST /playground/chat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat( WP_REST_Request $request ) {
		try {
			$result = $this->service()->handle(
				array(
					'session_id'         => self::session_id( get_current_user_id() ),
					'message'            => (string) $request->get_param( 'message' ),
					'conversation_id'    => (string) $request->get_param( 'conversation_id' ),
					'page_url'           => home_url( '/' ),
					'page_title'         => (string) get_bloginfo( 'name' ),
					'language'           => ChatController::language( (string) $request->get_param( 'language' ) ),
					'user_id'            => 0,
					'user_name'          => '',
					'skip_quick_answers' => (bool) $request->get_param( 'skip_quick_answers' ),
					'quick_answer_id'    => (int) $request->get_param( 'quick_answer_id' ),
					'is_test'            => true,
					'skip_rate_limit'    => true,
					'debug'              => true,
				)
			);
		} catch ( ChatException $exception ) {
			return new WP_Error(
				'chatlume_' . $exception->error_code(),
				$exception->visitor_message(),
				array( 'status' => $exception->status() )
			);
		}

		$response = new WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Session of an admin's Playground chats.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function session_id( int $user_id ): string {
		return 'admin-playground-' . $user_id;
	}

	/**
	 * The chat pipeline (replaceable in tests).
	 *
	 * @return ChatService
	 */
	protected function service(): ChatService {
		return new ChatService();
	}
}
