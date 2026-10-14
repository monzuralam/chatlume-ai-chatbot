<?php
/**
 * Quick answer rows.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes `{prefix}chatlume_quick_answers` (Chatlume's own table;
 * all values prepared). The enabled list used for matching is cached in the
 * object cache and invalidated on every change.
 */
class QuickAnswerRepository {

	/**
	 * Object cache group (also the "last changed" key).
	 */
	private const CACHE_GROUP = 'chatlume_quick_answers';

	/**
	 * Paginated list for the admin.
	 *
	 * @param array<string, mixed> $args search, language, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $args ): array {
		global $wpdb;

		$table    = Schema::table( 'quick_answers' );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$search   = (string) ( $args['search'] ?? '' );
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$language = $args['language'] ?? null;
		// Optional filters as static SQL: an empty search or a 0 language flag disables that condition.
		$has_language = null === $language ? 0 : 1;
		$language     = (string) $language;

		// Admin listing of Chatlume's own table; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ( %s = '' OR question LIKE %s OR answer LIKE %s OR keywords LIKE %s ) AND ( %d = 0 OR language = %s )", $table, $search, $like, $like, $like, $has_language, $language ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT id, question, answer, keywords, language, enabled, hit_count, miss_count, created_at, updated_at FROM %i WHERE ( %s = '' OR question LIKE %s OR answer LIKE %s OR keywords LIKE %s ) AND ( %d = 0 OR language = %s ) ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $table, $search, $like, $like, $like, $has_language, $language, $per_page, $offset ), ARRAY_A );

		return array(
			'items' => array_map( array( $this, 'cast' ), is_array( $items ) ? $items : array() ),
			'total' => $total,
		);
	}

	/**
	 * One quick answer.
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; admin reads are infrequent.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, question, answer, keywords, language, enabled, hit_count, miss_count, created_at, updated_at FROM %i WHERE id = %d', Schema::table( 'quick_answers' ), $id ), ARRAY_A );

		return is_array( $row ) ? $this->cast( $row ) : null;
	}

	/**
	 * Creates a quick answer.
	 *
	 * @param array<string, mixed> $data question, answer, keywords, language, enabled.
	 * @return int New ID (0 on failure).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into Chatlume's own table.
		$inserted = $wpdb->insert(
			Schema::table( 'quick_answers' ),
			array(
				'question'   => (string) $data['question'],
				'answer'     => (string) $data['answer'],
				'keywords'   => (string) ( $data['keywords'] ?? '' ),
				'language'   => (string) ( $data['language'] ?? '' ),
				'enabled'    => empty( $data['enabled'] ) && array_key_exists( 'enabled', $data ) ? 0 : 1,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->changed();

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Updates fields of a quick answer.
	 *
	 * @param int                  $id   ID.
	 * @param array<string, mixed> $data Any of question, answer, keywords, language, enabled.
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$fields  = array();
		$formats = array();

		foreach ( array( 'question', 'answer', 'keywords', 'language' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$fields[ $field ] = (string) $data[ $field ];
				$formats[]        = '%s';
			}
		}
		if ( array_key_exists( 'enabled', $data ) ) {
			$fields['enabled'] = $data['enabled'] ? 1 : 0;
			$formats[]         = '%d';
		}

		$fields['updated_at'] = current_time( 'mysql', true );
		$formats[]            = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update on Chatlume's own table.
		$updated = $wpdb->update( Schema::table( 'quick_answers' ), $fields, array( 'id' => $id ), $formats, array( '%d' ) );

		$this->changed();

		return false !== $updated;
	}

	/**
	 * Deletes a quick answer.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete from Chatlume's own table.
		$deleted = $wpdb->delete( Schema::table( 'quick_answers' ), array( 'id' => $id ), array( '%d' ) );

		$this->changed();

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Enabled quick answers for a language (plus language-neutral ones), cached.
	 *
	 * @param string $language Language code ('' for all languages).
	 * @return array<int, array{id: int, question: string, answer: string, keywords: string}>
	 */
	public function enabled_for( string $language ): array {
		global $wpdb;

		$key    = 'enabled:' . md5( $language ) . ':' . wp_cache_get_last_changed( self::CACHE_GROUP );
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$table = Schema::table( 'quick_answers' );
		$base  = substr( $language, 0, 2 );

		// Matching set from Chatlume's own table; cached above until the next change. An empty language matches every row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, question, answer, keywords FROM %i WHERE enabled = 1 AND ( %s = '' OR language = '' OR language = %s OR language = %s ) ORDER BY id LIMIT 2000", $table, $language, $language, $base ), ARRAY_A );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'id'       => (int) $row['id'],
				'question' => (string) $row['question'],
				'answer'   => (string) $row['answer'],
				'keywords' => (string) $row['keywords'],
			);
		}

		wp_cache_set( $key, $items, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $items;
	}

	/**
	 * Counts a served answer.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public function record_hit( int $id ): void {
		$this->bump( $id, 'hit_count' );
	}

	/**
	 * Counts a visitor choosing "Ask AI" instead of the quick answer.
	 *
	 * @param int $id ID.
	 * @return void
	 */
	public function record_miss( int $id ): void {
		$this->bump( $id, 'miss_count' );
	}

	/**
	 * Increments a counter column.
	 *
	 * @param int    $id     ID.
	 * @param string $column hit_count or miss_count.
	 * @return void
	 */
	private function bump( int $id, string $column ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic counter on Chatlume's own table; $column is one of two fixed names.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %i + 1 WHERE id = %d', Schema::table( 'quick_answers' ), 'miss_count' === $column ? 'miss_count' : 'hit_count', 'miss_count' === $column ? 'miss_count' : 'hit_count', $id ) );
	}

	/**
	 * Invalidates cached matching lists.
	 *
	 * @return void
	 */
	private function changed(): void {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Casts a row for the API.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function cast( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'question'   => (string) $row['question'],
			'answer'     => (string) $row['answer'],
			'keywords'   => (string) $row['keywords'],
			'language'   => (string) $row['language'],
			'enabled'    => (bool) (int) $row['enabled'],
			'hit_count'  => (int) $row['hit_count'],
			'miss_count' => (int) $row['miss_count'],
			'created_at' => (string) $row['created_at'],
			'updated_at' => (string) $row['updated_at'],
		);
	}
}
