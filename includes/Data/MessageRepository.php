<?php
/**
 * Message rows.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `{prefix}chatlume_messages` (Chatlume's own table; all
 * values prepared).
 */
class MessageRepository {

	/**
	 * Adds a message.
	 *
	 * @param int                  $conversation_id Conversation ID.
	 * @param array<string, mixed> $data            role, content, source, input_tokens, output_tokens,
	 *                                              cost, latency_ms, sources, error_type, meta.
	 * @return int New ID (0 on failure).
	 */
	public function add( int $conversation_id, array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into Chatlume's own table.
		$inserted = $wpdb->insert(
			Schema::table( 'messages' ),
			array(
				'conversation_id' => $conversation_id,
				'role'            => (string) ( $data['role'] ?? 'user' ),
				'content'         => (string) ( $data['content'] ?? '' ),
				'source'          => (string) ( $data['source'] ?? '' ),
				'input_tokens'    => (int) ( $data['input_tokens'] ?? 0 ),
				'output_tokens'   => (int) ( $data['output_tokens'] ?? 0 ),
				'cost'            => (float) ( $data['cost'] ?? 0 ),
				'latency_ms'      => (int) ( $data['latency_ms'] ?? 0 ),
				'sources'         => (string) wp_json_encode( (array) ( $data['sources'] ?? array() ) ),
				'error_type'      => (string) ( $data['error_type'] ?? '' ),
				'meta'            => (string) wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The most recent user/assistant messages of a conversation, oldest first.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit           Number of messages.
	 * @return array<int, array{role: string, content: string}>
	 */
	public function recent( int $conversation_id, int $limit ): array {
		global $wpdb;

		if ( $limit <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; history changes with every message.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM %i WHERE conversation_id = %d AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT %d",
				Schema::table( 'messages' ),
				$conversation_id,
				$limit
			),
			ARRAY_A
		);

		return array_reverse(
			array_map(
				static function ( array $row ): array {
					return array(
						'role'    => (string) $row['role'],
						'content' => (string) $row['content'],
					);
				},
				is_array( $rows ) ? $rows : array()
			)
		);
	}

	/**
	 * One message of a conversation.
	 *
	 * @param int $message_id      Message ID.
	 * @param int $conversation_id Conversation ID (the message must belong to it).
	 * @return array{role: string, content: string}|null
	 */
	public function find( int $message_id, int $conversation_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; read once per webhook.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT role, content FROM %i WHERE id = %d AND conversation_id = %d', Schema::table( 'messages' ), $message_id, $conversation_id ), ARRAY_A );

		return is_array( $row )
			? array(
				'role'    => (string) $row['role'],
				'content' => (string) $row['content'],
			)
			: null;
	}

	/**
	 * The whole conversation for a transcript, oldest first.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $limit           Most messages.
	 * @return array<int, array{role: string, content: string}>
	 */
	public function transcript( int $conversation_id, int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; read once per email.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM %i WHERE conversation_id = %d AND role IN ('user', 'assistant') ORDER BY id ASC LIMIT %d",
				Schema::table( 'messages' ),
				$conversation_id,
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				return array(
					'role'    => (string) $row['role'],
					'content' => (string) $row['content'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Stores visitor feedback on an assistant message of a conversation.
	 *
	 * @param int $message_id      Message ID.
	 * @param int $conversation_id Conversation ID (the message must belong to it).
	 * @param int $feedback        1, -1, or 0 to clear.
	 * @return bool Whether a message was updated.
	 */
	public function set_feedback( int $message_id, int $conversation_id, int $feedback ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on Chatlume's own table.
		$updated = $wpdb->update(
			Schema::table( 'messages' ),
			array( 'feedback' => 0 === $feedback ? null : $feedback ),
			array(
				'id'              => $message_id,
				'conversation_id' => $conversation_id,
				'role'            => 'assistant',
			),
			array( '%d' ),
			array( '%d', '%d', '%s' )
		);

		return false !== $updated && $updated > 0;
	}
}
