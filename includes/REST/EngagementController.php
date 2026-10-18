<?php
/**
 * REST: leads, handoff, and transcript emails from the widget.
 *
 * @package Chatlume
 */

namespace Chatlume\REST;

use Chatlume\Chat\BotText;
use Chatlume\Chat\ConversationState;
use Chatlume\Engagement\Handoff;
use Chatlume\Engagement\Leads;
use Chatlume\Plugin;
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
 * POST chatlume/v1/lead, /handoff, and /transcript. Public routes for
 * visitors, protected like /feedback: same-origin check, signed session
 * token, conversation ownership, and per-session limits (3 per hour for
 * anything that sends email or stores details).
 */
class EngagementController {

	/**
	 * Emails and lead submissions per session per hour.
	 */
	public const EMAIL_LIMIT = 3;

	/**
	 * Conversations.
	 *
	 * @var ConversationState
	 */
	private ConversationState $state;

	/**
	 * Constructor.
	 *
	 * @param ConversationState|null $state Conversations (tests).
	 */
	public function __construct( ?ConversationState $state = null ) {
		$this->state = $state ?? new ConversationState();
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$token = array(
			'type'              => 'string',
			'required'          => true,
			'maxLength'         => 1024,
			'sanitize_callback' => array( ChatController::class, 'sanitize_token' ),
			'validate_callback' => 'rest_validate_request_arg',
		);
		$page  = array(
			'type'              => 'string',
			'default'           => '',
			'maxLength'         => 2048,
			'sanitize_callback' => array( ChatController::class, 'sanitize_page_url' ),
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/lead',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'lead' ),
				// Public: protected by the session token, rate limits, and the same-origin check.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'           => $token,
					'conversation_id' => ChatController::conversation_arg(),
					'name'            => array(
						'type'              => 'string',
						'default'           => '',
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'email'           => array(
						'type'              => 'string',
						'required'          => true,
						'maxLength'         => 100,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'consent'         => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'page_url'        => $page,
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/handoff',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handoff' ),
				// Public: protected by the session token, conversation ownership, rate limits, and the same-origin check.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'           => $token,
					'conversation_id' => ChatController::conversation_arg() + array( 'required' => true ),
					'channel'         => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => Handoff::CHANNELS,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'page_url'        => $page,
				),
			)
		);

		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/transcript',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transcript' ),
				// Public: protected by the session token, conversation ownership, rate limits, and the same-origin check.
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'           => $token,
					'conversation_id' => ChatController::conversation_arg() + array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * POST /lead: the visitor's name and email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lead( WP_REST_Request $request ) {
		$session = $this->guard( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		// Details are also collected when "Email this conversation to the team" needs a reply address.
		$handoff = Handoff::config();
		if ( ! Settings::get( 'leads.enabled', false ) && ! ( $handoff['email_team'] ?? false ) ) {
			return new WP_Error( 'chatlume_disabled', __( 'This is not available right now.', 'chatlume-ai-chatbot' ), array( 'status' => 403 ) );
		}

		$email = (string) $request->get_param( 'email' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'chatlume_invalid_email', __( 'Please enter a valid email address.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}
		if ( '' !== trim( (string) Settings::get( 'leads.consent', '' ) ) && Settings::get( 'leads.enabled', false ) && ! $request->get_param( 'consent' ) ) {
			return new WP_Error( 'chatlume_consent', __( 'Please tick the box to continue.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		// Only valid submissions count, so a mistyped address costs nothing.
		$limited = self::limit( 'lead', $session['sid'], self::EMAIL_LIMIT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$conversation_id = (string) $request->get_param( 'conversation_id' );
		if ( '' !== $conversation_id && null === $this->state->find( $conversation_id, $session['sid'] ) ) {
			$conversation_id = '';
		}

		( new Leads( $this->state ) )->save(
			$session['sid'],
			$conversation_id,
			trim( (string) $request->get_param( 'name' ) ),
			$email,
			(string) $request->get_param( 'page_url' )
		);

		return self::no_store( array( 'saved' => true ) );
	}

	/**
	 * POST /handoff: the visitor wants a person.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handoff( WP_REST_Request $request ) {
		$channel = (string) $request->get_param( 'channel' );
		$email   = 'email_team' === $channel;
		$session = $this->guard( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$config    = Handoff::config();
		$available = null !== $config && (
			$email ? $config['email_team'] : in_array( $channel, array_column( $config['actions'], 'type' ), true )
		);
		if ( ! $available ) {
			return new WP_Error( 'chatlume_disabled', __( 'This is not available right now.', 'chatlume-ai-chatbot' ), array( 'status' => 403 ) );
		}

		$conversation_id = $this->owned( $request, $session['sid'] );
		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}

		$limited = self::limit( $email ? 'handoff' : 'handoff-link', $session['sid'], $email ? self::EMAIL_LIMIT : 20 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = ( new Handoff( $this->state ) )->request( $session['sid'], $conversation_id, $channel, (string) $request->get_param( 'page_url' ) );

		return true === $result ? self::no_store( array( 'sent' => $email ) ) : self::failure( $result );
	}

	/**
	 * POST /transcript: the conversation to the visitor's own email.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function transcript( WP_REST_Request $request ) {
		$session = $this->guard( $request );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$conversation_id = $this->owned( $request, $session['sid'] );
		if ( is_wp_error( $conversation_id ) ) {
			return $conversation_id;
		}

		$limited = self::limit( 'transcript', $session['sid'], self::EMAIL_LIMIT );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$result = ( new Handoff( $this->state ) )->email_visitor( $session['sid'], $conversation_id );

		return true === $result ? self::no_store( array( 'sent' => true ) ) : self::failure( $result );
	}

	/**
	 * Origin and session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{sid: string, iat: int, exp: int}|WP_Error
	 */
	private function guard( WP_REST_Request $request ) {
		$origin = ( new ChatController() )->check_origin( $request );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$session = ( new SessionManager() )->verify( (string) $request->get_param( 'token' ) );
		if ( null === $session ) {
			return new WP_Error( 'chatlume_invalid_session', __( 'Your chat session expired.', 'chatlume-ai-chatbot' ), array( 'status' => 401 ) );
		}

		return $session;
	}

	/**
	 * Counts a request against the session's hourly limit.
	 *
	 * @param string $bucket     Rate-limit bucket.
	 * @param string $session_id Session ID.
	 * @param int    $limit      Requests per hour.
	 * @return true|WP_Error
	 */
	private static function limit( string $bucket, string $session_id, int $limit ) {
		$limited = ( new RateLimiter() )->hit( $bucket . ':' . $session_id, $limit, HOUR_IN_SECONDS );
		if ( $limited['allowed'] ) {
			return true;
		}

		return new WP_Error(
			'chatlume_rate_limited',
			BotText::rate_limited(),
			array(
				'status'      => 429,
				'retry_after' => $limited['retry_after'],
			)
		);
	}

	/**
	 * The requested conversation, if it belongs to the session.
	 *
	 * @param WP_REST_Request $request    Request.
	 * @param string          $session_id Session ID.
	 * @return string|WP_Error
	 */
	private function owned( WP_REST_Request $request, string $session_id ) {
		$id = (string) $request->get_param( 'conversation_id' );
		if ( '' === $id || null === $this->state->find( $id, $session_id ) ) {
			return new WP_Error( 'chatlume_not_found', __( 'This conversation could not be found.', 'chatlume-ai-chatbot' ), array( 'status' => 404 ) );
		}

		return $id;
	}

	/**
	 * Visitor-safe error for a handoff or transcript that did not go out.
	 *
	 * @param string $reason no_lead, off, or mail.
	 * @return WP_Error
	 */
	private static function failure( string $reason ): WP_Error {
		switch ( $reason ) {
			case 'no_lead':
				return new WP_Error( 'chatlume_no_lead', __( 'Please add your email first.', 'chatlume-ai-chatbot' ), array( 'status' => 409 ) );
			case 'off':
				return new WP_Error( 'chatlume_disabled', __( 'This is not available right now.', 'chatlume-ai-chatbot' ), array( 'status' => 403 ) );
			default:
				return new WP_Error( 'chatlume_mail_failed', __( 'The email could not be sent. Please try again later.', 'chatlume-ai-chatbot' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * A response that is never cached.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return WP_REST_Response
	 */
	private static function no_store( array $data ): WP_REST_Response {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
