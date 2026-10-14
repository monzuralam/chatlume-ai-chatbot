<?php
/**
 * REST: public chat routes.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Chat\BotText;
use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Data\ConversationRepository;
use Chatlume\Data\MessageRepository;
use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Plugin;
use Chatlume\Security\IpResolver;
use Chatlume\Security\RateLimiter;
use Chatlume\Security\SessionManager;
use Chatlume\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visitor routes under chatlume/v1:
 * - POST /session  Creates or renews a visitor session (signed token) and returns fresh bot settings.
 * - POST /chat     Answers a message (non-streaming).
 * - POST /feedback Thumbs up/down on a reply.
 *
 * These routes are public by design (`__return_true` permission callbacks):
 * access is enforced by a signed session token, rate limits per hashed IP
 * and per session, a same-origin check, a honeypot field, and budget caps.
 * No nonce is used, so pages stay fully cacheable.
 */
class ChatController {

	/**
	 * Allowed language code pattern (e.g. "en", "en-US", "zh_Hans_CN").
	 */
	public const LANGUAGE_PATTERN = '^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,3}$';

	/**
	 * Conversation ID pattern (database ID or temporary ID).
	 */
	public const CONVERSATION_PATTERN = '^(\d{1,20}|t_[a-f0-9]{24})?$';

	/**
	 * Sessions a single visitor may start per 10 minutes.
	 */
	private const SESSION_LIMIT = 30;

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$token = array(
			'type'              => 'string',
			'maxLength'         => 1024,
			'sanitize_callback' => array( self::class, 'sanitize_token' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'session' ),
				// Public: visitors start sessions here. Protected by the same-origin check and a per-IP rate limit.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'    => $token + array( 'default' => '' ),
					'language' => self::language_arg(),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat' ),
				// Public: protected by the signed session token, rate limits, same-origin check, honeypot, and budget caps.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'              => $token + array( 'required' => true ),
					'message'            => array(
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 1,
						'maxLength'         => 8000,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'conversation_id'    => self::conversation_arg(),
					'page_url'           => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 2048,
						'sanitize_callback' => array( self::class, 'sanitize_page_url' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'page_title'         => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 300,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'language'           => self::language_arg(),
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
					// Honeypot: a field hidden from people; bots that fill it are rejected.
					'website'            => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 500,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/feedback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'feedback' ),
				// Public: protected by the signed session token, conversation ownership, rate limits, and the same-origin check.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'           => $token + array( 'required' => true ),
					'conversation_id' => self::conversation_arg() + array( 'required' => true ),
					'message_id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 0,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'rating'          => array(
						'type'              => 'integer',
						'required'          => true,
						'enum'              => array( -1, 0, 1 ),
						'sanitize_callback' => array( self::class, 'sanitize_rating' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * POST /session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function session( WP_REST_Request $request ) {
		$origin = $this->check_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$visitor = IpResolver::visitor_hash();
		$limited = ( new RateLimiter() )->hit( 'session-start:' . $visitor, self::SESSION_LIMIT, 10 * MINUTE_IN_SECONDS );
		if ( ! $limited['allowed'] ) {
			return self::error( new ChatException( 'rate_limited', BotText::rate_limited(), 429, $limited['retry_after'] ) );
		}

		$session = ( new SessionManager() )->start( (string) $request->get_param( 'token' ) );

		return self::no_store(
			new WP_REST_Response(
				array(
					'token'   => $session['token'],
					'expires' => $session['expires'],
					'config'  => $this->behavior_config( (string) $request->get_param( 'language' ) ),
				)
			)
		);
	}

	/**
	 * POST /chat.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat( WP_REST_Request $request ) {
		$origin = $this->check_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return new WP_Error( 'chatlume_invalid_request', BotText::error(), array( 'status' => 400 ) );
		}

		$session = ( new SessionManager() )->verify( (string) $request->get_param( 'token' ) );
		if ( null === $session ) {
			return self::error( new ChatException( 'invalid_session', __( 'Your chat session expired. Please send your message again.', 'chatlume-ai-chatbot' ), 401 ) );
		}

		$user = wp_get_current_user();

		try {
			$result = ( new ChatService() )->handle(
				array(
					'session_id'         => $session['sid'],
					'message'            => (string) $request->get_param( 'message' ),
					'conversation_id'    => (string) $request->get_param( 'conversation_id' ),
					'page_url'           => (string) $request->get_param( 'page_url' ),
					'page_title'         => (string) $request->get_param( 'page_title' ),
					'language'           => self::language( (string) $request->get_param( 'language' ) ),
					'user_id'            => (int) $user->ID,
					'user_name'          => $user->ID ? (string) $user->display_name : '',
					'visitor_hash'       => IpResolver::visitor_hash(),
					'skip_quick_answers' => (bool) $request->get_param( 'skip_quick_answers' ),
					'quick_answer_id'    => (int) $request->get_param( 'quick_answer_id' ),
				)
			);
		} catch ( ChatException $exception ) {
			return self::error( $exception );
		}

		return self::no_store(
			new WP_REST_Response(
				array(
					'conversation_id' => $result['conversation_id'],
					'message_id'      => $result['message_id'],
					'reply'           => $result['reply'],
				)
			)
		);
	}

	/**
	 * POST /feedback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function feedback( WP_REST_Request $request ) {
		$origin = $this->check_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$session = ( new SessionManager() )->verify( (string) $request->get_param( 'token' ) );
		if ( null === $session ) {
			return self::error( new ChatException( 'invalid_session', __( 'Your chat session expired.', 'chatlume-ai-chatbot' ), 401 ) );
		}

		$limited = ( new RateLimiter() )->hit( 'feedback:' . $session['sid'], 60, 10 * MINUTE_IN_SECONDS );
		if ( ! $limited['allowed'] ) {
			return self::error( new ChatException( 'rate_limited', BotText::rate_limited(), 429, $limited['retry_after'] ) );
		}

		$conversation_id = (string) $request->get_param( 'conversation_id' );
		$rating          = (int) $request->get_param( 'rating' );
		$saved           = false;

		// Unstored (temporary) conversations have nothing to rate; that is not an error for the visitor.
		if ( ctype_digit( $conversation_id ) ) {
			$conversations = new ConversationRepository();
			if ( null === $conversations->find_for_session( (int) $conversation_id, $session['sid'] ) ) {
				return new WP_Error( 'chatlume_not_found', __( 'This conversation could not be found.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
			}

			$saved = ( new MessageRepository() )->set_feedback( (int) $request->get_param( 'message_id' ), (int) $conversation_id, $rating );
			if ( $saved && 0 !== $rating ) {
				$conversations->set_rating( (int) $conversation_id, $rating );
			}
		}

		return self::no_store( new WP_REST_Response( array( 'saved' => $saved ) ) );
	}

	/**
	 * Settings the widget needs, always fresh (never cached in HTML).
	 *
	 * @param string $language Page language.
	 * @return array<string, mixed>
	 */
	private function behavior_config( string $language ): array {
		$starters = BotText::starters();

		if ( Settings::get( 'quick_answers.enabled', true ) && Settings::get( 'quick_answers.show_as_starters', false ) ) {
			foreach ( ( new QuickAnswerRepository() )->enabled_for( self::language( $language ) ) as $quick_answer ) {
				if ( count( $starters ) >= 4 ) {
					break;
				}
				if ( ! in_array( $quick_answer['question'], $starters, true ) ) {
					$starters[] = $quick_answer['question'];
				}
			}
		}

		return array(
			'bot_name'            => BotText::bot_name(),
			'welcome_message'     => BotText::welcome(),
			'starter_questions'   => array_slice( $starters, 0, 4 ),
			'max_message_length'  => (int) Settings::get( 'chatbot.max_message_length', 1000 ),
			'store_conversations' => (bool) Settings::get( 'privacy.store_conversations', true ),
		);
	}

	/**
	 * Same-origin check: the request must come from a page of this site
	 * (Origin header, or Referer when a browser omits Origin).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_origin( WP_REST_Request $request ) {
		$source = (string) $request->get_header( 'origin' );
		if ( '' === $source || 'null' === $source ) {
			$source = (string) $request->get_header( 'referer' );
		}

		$host = strtolower( (string) wp_parse_url( $source, PHP_URL_HOST ) );
		$port = wp_parse_url( $source, PHP_URL_PORT );

		foreach ( self::allowed_origins() as $allowed ) {
			if ( '' !== $host && strtolower( (string) wp_parse_url( $allowed, PHP_URL_HOST ) ) === $host && wp_parse_url( $allowed, PHP_URL_PORT ) === $port ) {
				return true;
			}
		}

		return new WP_Error( 'chatlume_forbidden_origin', __( 'This chat can only be used on this website.', 'chatlume-ai-chatbot' ), array( 'status' => 403 ) );
	}

	/**
	 * Origins allowed to use the public routes.
	 *
	 * @return string[]
	 */
	private static function allowed_origins(): array {
		/**
		 * Filters the origins allowed to call Chatlume's public chat routes.
		 *
		 * @param string[] $origins Origins (scheme, host, and optional port).
		 */
		$origins = apply_filters( 'chatlume_allowed_origins', array( home_url(), site_url() ) );

		return is_array( $origins ) ? array_filter( $origins, 'is_string' ) : array( home_url() );
	}

	/**
	 * Rating as an integer. (PHP's intval() cannot be a REST sanitize callback:
	 * WordPress passes three arguments and intval() accepts at most two.)
	 *
	 * @param mixed $value Raw rating.
	 * @return int
	 */
	public static function sanitize_rating( $value ): int {
		return (int) $value;
	}

	/**
	 * Keeps a token's characters (hex and the separator dot).
	 *
	 * @param mixed $value Raw token.
	 * @return string
	 */
	public static function sanitize_token( $value ): string {
		return (string) preg_replace( '/[^a-f0-9.]/', '', strtolower( (string) $value ) );
	}

	/**
	 * Accepts page URLs of this site only (used in the prompt and the log).
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	public static function sanitize_page_url( $value ): string {
		$url  = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		return '' !== $host && strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) === $host ? $url : '';
	}

	/**
	 * Normalized language code, or the site language.
	 *
	 * @param string $language Requested language.
	 * @return string
	 */
	public static function language( string $language ): string {
		$language = str_replace( '_', '-', trim( $language ) );

		return '' !== $language && preg_match( '/' . self::LANGUAGE_PATTERN . '/', $language ) ? $language : str_replace( '_', '-', get_locale() );
	}

	/**
	 * Language argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function language_arg(): array {
		return array(
			'type'              => 'string',
			'default'           => '',
			'maxLength'         => 20,
			'pattern'           => '^$|' . self::LANGUAGE_PATTERN,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Conversation ID argument definition.
	 *
	 * @return array<string, mixed>
	 */
	private static function conversation_arg(): array {
		return array(
			'type'              => 'string',
			'default'           => '',
			'maxLength'         => 30,
			'pattern'           => self::CONVERSATION_PATTERN,
			'sanitize_callback' => 'sanitize_text_field',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * REST error from a visitor-safe exception.
	 *
	 * @param ChatException $exception Exception.
	 * @return WP_Error
	 */
	private static function error( ChatException $exception ): WP_Error {
		return new WP_Error(
			'chatlume_' . $exception->error_code(),
			$exception->visitor_message(),
			array(
				'status'      => $exception->status(),
				'retry_after' => $exception->retry_after(),
			)
		);
	}

	/**
	 * Marks a response as never cacheable (it is per visitor).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private static function no_store( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
