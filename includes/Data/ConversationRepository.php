<?php
/**
 * Conversation rows.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `{prefix}chatlume_conversations`.
 *
 * Direct queries are used because these are Chatlume's own tables (no core
 * API covers them); every dynamic value goes through $wpdb->prepare() or
 * the $wpdb insert/update helpers.
 */
class ConversationRepository {

	/**
	 * Creates a conversation.
	 *
	 * @param array<string, mixed> $data session_id, user_id, visitor_hash, page_url, language, is_test, meta.
	 * @return int New ID (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into Chatlume's own table.
		$inserted = $wpdb->insert(
			Schema::table( 'conversations' ),
			array(
				'session_id'   => (string) ( $data['session_id'] ?? '' ),
				'user_id'      => (int) ( $data['user_id'] ?? 0 ),
				'visitor_hash' => (string) ( $data['visitor_hash'] ?? '' ),
				'page_url'     => (string) ( $data['page_url'] ?? '' ),
				'language'     => (string) ( $data['language'] ?? '' ),
				'is_test'      => empty( $data['is_test'] ) ? 0 : 1,
				'meta'         => (string) wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * A conversation by ID.
	 *
	 * @param int $id Conversation ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; rows change on every message.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::table( 'conversations' ), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * A conversation by ID, only if it belongs to the session.
	 *
	 * @param int    $id         Conversation ID.
	 * @param string $session_id Session ID.
	 * @return array<string, mixed>|null
	 */
	public function find_for_session( int $id, string $session_id ): ?array {
		$row = $this->find( $id );

		return null !== $row && hash_equals( (string) $row['session_id'], $session_id ) ? $row : null;
	}

	/**
	 * Updates totals after a reply.
	 *
	 * @param int    $id       Conversation ID.
	 * @param int    $messages Messages added.
	 * @param float  $cost     Cost added (USD).
	 * @param string $provider Provider ID ('' keeps the current value).
	 * @param string $model    Model ID ('' keeps the current value).
	 * @return void
	 */
	public function record_activity( int $id, int $messages, float $cost, string $provider = '', string $model = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter update on Chatlume's own table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET message_count = message_count + %d, total_cost = total_cost + %f, provider = IF(%s = \'\', provider, %s), model = IF(%s = \'\', model, %s), updated_at = %s WHERE id = %d',
				Schema::table( 'conversations' ),
				$messages,
				$cost,
				$provider,
				$provider,
				$model,
				$model,
				current_time( 'mysql', true ),
				$id
			)
		);
	}

	/**
	 * Stores the visitor's latest rating (1 or -1).
	 *
	 * @param int $id     Conversation ID.
	 * @param int $rating Rating.
	 * @return void
	 */
	public function set_rating( int $id, int $rating ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on Chatlume's own table.
		$wpdb->update( Schema::table( 'conversations' ), array( 'rating' => $rating ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Stores the visitor's name and email.
	 *
	 * @param int    $id    Conversation ID.
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return void
	 */
	public function set_lead( int $id, string $name, string $email ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on Chatlume's own table.
		$wpdb->update(
			Schema::table( 'conversations' ),
			array(
				'lead_name'  => $name,
				'lead_email' => $email,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Adds keys to a conversation's meta.
	 *
	 * @param int                  $id   Conversation ID.
	 * @param array<string, mixed> $meta Keys to set.
	 * @return void
	 */
	public function merge_meta( int $id, array $meta ): void {
		global $wpdb;

		$row     = $this->find( $id );
		$current = null !== $row ? json_decode( (string) ( $row['meta'] ?? '' ), true ) : array();
		$current = is_array( $current ) ? $current : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on Chatlume's own table.
		$wpdb->update( Schema::table( 'conversations' ), array( 'meta' => (string) wp_json_encode( array_merge( $current, $meta ) ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Deletes conversations (and their messages) last active before a date.
	 *
	 * @param string $before UTC datetime (Y-m-d H:i:s).
	 * @param int    $limit  Maximum conversations per call (keeps each run short).
	 * @return int Conversations deleted.
	 */
	public function delete_inactive_before( string $before, int $limit = 500 ): int {
		global $wpdb;

		$conversations = Schema::table( 'conversations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention cleanup on Chatlume's own table.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE updated_at < %s ORDER BY id LIMIT %d', $conversations, $before, $limit ) ) );

		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() list is only %d placeholders, all values are prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE conversation_id IN ($placeholders)", array_merge( array( Schema::table( 'messages' ) ), $ids ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", array_merge( array( $conversations ), $ids ) ) );
		// phpcs:enable

		return count( $ids );
	}
}
