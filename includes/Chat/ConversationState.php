<?php
/**
 * Server-side conversation state.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Data\ConversationRepository;
use Chatlume\Data\MessageRepository;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The server is the source of truth for history: visitors send only their
 * new message, and the history is loaded here.
 *
 * With conversation storage on, conversations and messages are rows in the
 * database (IDs are numeric strings). With it off, the conversation lives
 * only in a transient that expires after 30 idle minutes (IDs start with
 * "t_"), and nothing is written to the tables.
 */
class ConversationState {

	/**
	 * Idle lifetime of unstored conversations.
	 */
	public const TRANSIENT_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Conversations.
	 *
	 * @var ConversationRepository
	 */
	private ConversationRepository $conversations;

	/**
	 * Messages.
	 *
	 * @var MessageRepository
	 */
	private MessageRepository $messages;

	/**
	 * Whether conversations are stored in the database.
	 *
	 * @var bool
	 */
	private bool $store;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository|null $conversations Conversations.
	 * @param MessageRepository|null      $messages      Messages.
	 * @param bool|null                   $store         Storage override (defaults to the setting).
	 */
	public function __construct( ?ConversationRepository $conversations = null, ?MessageRepository $messages = null, ?bool $store = null ) {
		$this->conversations = $conversations ?? new ConversationRepository();
		$this->messages      = $messages ?? new MessageRepository();
		$this->store         = $store ?? (bool) Settings::get( 'privacy.store_conversations', true );
	}

	/**
	 * Whether conversations are stored in the database.
	 *
	 * @return bool
	 */
	public function is_stored(): bool {
		return $this->store;
	}

	/**
	 * Starts a conversation.
	 *
	 * @param string               $session_id Session ID.
	 * @param array<string, mixed> $context    user_id, visitor_hash, page_url, language, is_test.
	 * @return string Conversation ID ('' on failure).
	 */
	public function start( string $session_id, array $context ): string {
		if ( $this->store ) {
			$id = $this->conversations->create( array( 'session_id' => $session_id ) + $context );
			return $id > 0 ? (string) $id : '';
		}

		$id = 't_' . bin2hex( random_bytes( 12 ) );
		set_transient(
			self::transient_key( $id ),
			array(
				'session'  => $session_id,
				'messages' => array(),
				'count'    => 0,
			),
			self::TRANSIENT_TTL
		);

		return $id;
	}

	/**
	 * Loads a conversation that belongs to the session.
	 *
	 * @param string $id         Conversation ID.
	 * @param string $session_id Session ID.
	 * @return array{id: string, user_messages: int}|null Null when missing, expired, or owned by another session.
	 */
	public function find( string $id, string $session_id ): ?array {
		if ( ctype_digit( $id ) && $this->store ) {
			$row = $this->conversations->find_for_session( (int) $id, $session_id );
			// message_count counts user and assistant messages; a turn is two of them.
			return null === $row ? null : array(
				'id'            => $id,
				'user_messages' => (int) ceil( (int) $row['message_count'] / 2 ),
			);
		}

		if ( preg_match( '/^t_[a-f0-9]{24}$/', $id ) ) {
			$data = get_transient( self::transient_key( $id ) );
			if ( is_array( $data ) && hash_equals( (string) ( $data['session'] ?? '' ), $session_id ) ) {
				return array(
					'id'            => $id,
					'user_messages' => (int) ( $data['count'] ?? 0 ),
				);
			}
		}

		return null;
	}

	/**
	 * The latest messages, oldest first.
	 *
	 * @param string $id    Conversation ID.
	 * @param int    $limit Number of messages.
	 * @return array<int, array{role: string, content: string}>
	 */
	public function history( string $id, int $limit ): array {
		if ( $limit <= 0 ) {
			return array();
		}

		if ( ctype_digit( $id ) ) {
			return $this->messages->recent( (int) $id, $limit );
		}

		$data = get_transient( self::transient_key( $id ) );

		return is_array( $data ) ? array_slice( (array) $data['messages'], -$limit ) : array();
	}

	/**
	 * Records a visitor message and the reply.
	 *
	 * @param string               $id       Conversation ID.
	 * @param string               $question Visitor message.
	 * @param array<string, mixed> $reply    content, source, input_tokens, output_tokens, cost, latency_ms,
	 *                                       sources, error_type, meta, provider, model.
	 * @return int ID of the stored reply (0 when not stored in the database).
	 */
	public function record_turn( string $id, string $question, array $reply ): int {
		if ( ctype_digit( $id ) ) {
			$conversation = (int) $id;
			$this->messages->add(
				$conversation,
				array(
					'role'    => 'user',
					'content' => $question,
				)
			);
			$message_id = $this->messages->add( $conversation, array( 'role' => 'assistant' ) + $reply );
			$this->conversations->record_activity(
				$conversation,
				2,
				(float) ( $reply['cost'] ?? 0 ),
				(string) ( $reply['provider'] ?? '' ),
				(string) ( $reply['model'] ?? '' )
			);
			return $message_id;
		}

		$key  = self::transient_key( $id );
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return 0;
		}

		$data['messages'][] = array(
			'role'    => 'user',
			'content' => $question,
		);
		$data['messages'][] = array(
			'role'    => 'assistant',
			'content' => (string) ( $reply['content'] ?? '' ),
		);
		// Keep the transient small: the prompt never needs more than the history window.
		$data['messages'] = array_slice( $data['messages'], -60 );
		$data['count']    = (int) ( $data['count'] ?? 0 ) + 1;

		// Writing again restarts the 30-minute idle timer.
		set_transient( $key, $data, self::TRANSIENT_TTL );

		return 0;
	}

	/**
	 * Transient key for an unstored conversation.
	 *
	 * @param string $id Conversation ID.
	 * @return string
	 */
	private static function transient_key( string $id ): string {
		return 'chatlume_conv_' . substr( $id, 2 );
	}
}
