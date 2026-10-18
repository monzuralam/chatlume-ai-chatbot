<?php
/**
 * Knowledge chunks storage.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes {prefix}chatlume_knowledge. A source (a post, the
 * business info, a document, a product) is stored as ordered chunks.
 */
class KnowledgeRepository {

	/**
	 * Replaces a source's chunks. A chunk whose text did not change keeps its
	 * embedding, so editing a page re-embeds only what changed.
	 *
	 * @param string   $type     Source type (post, business, document, product).
	 * @param string   $id       Source ID.
	 * @param string   $title    Title.
	 * @param string   $url      Address (may be empty).
	 * @param string   $language Language code ('' for all languages).
	 * @param string[] $chunks   Chunks in order.
	 * @return int Chunks stored.
	 */
	public function replace( string $type, string $id, string $title, string $url, string $language, array $chunks ): int {
		global $wpdb;

		$table = Schema::table( 'knowledge' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table; read right before rewriting.
		$old = $wpdb->get_results( $wpdb->prepare( 'SELECT chunk_index, content, embedding, embedding_model, embedding_dims, is_stale FROM %i WHERE source_type = %s AND source_id = %s', $table, $type, $id ), ARRAY_A );

		$kept = array();
		foreach ( is_array( $old ) ? $old : array() as $row ) {
			if ( null !== $row['embedding'] && '' !== $row['embedding'] && ! (int) $row['is_stale'] ) {
				$kept[ md5( (string) $row['content'] ) ] = $row;
			}
		}

		$this->delete( $type, $id );

		$now    = current_time( 'mysql', true );
		$stored = 0;
		foreach ( array_values( $chunks ) as $index => $content ) {
			$content = trim( (string) $content );
			if ( '' === $content ) {
				continue;
			}
			$previous = $kept[ md5( $content ) ] ?? null;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert into Chatlume's own table.
			$wpdb->insert(
				$table,
				array(
					'source_type'     => $type,
					'source_id'       => $id,
					'title'           => $title,
					'url'             => $url,
					'language'        => $language,
					'chunk_index'     => $index,
					'content'         => $content,
					'embedding'       => $previous['embedding'] ?? null,
					'embedding_model' => (string) ( $previous['embedding_model'] ?? '' ),
					'embedding_dims'  => (int) ( $previous['embedding_dims'] ?? 0 ),
					'is_stale'        => 0,
					'updated_at'      => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
			++$stored;
		}

		return $stored;
	}

	/**
	 * Removes a source.
	 *
	 * @param string $type Source type.
	 * @param string $id   Source ID.
	 * @return void
	 */
	public function delete( string $type, string $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_type = %s AND source_id = %s', Schema::table( 'knowledge' ), $type, $id ) );
	}

	/**
	 * Index size and contents.
	 *
	 * @return array{chunks: int, sources: int, bytes: int, embedded: int, stale: int, types: array<string, int>, languages: array<string, int>, updated_at: string}
	 */
	public function stats(): array {
		global $wpdb;

		$table = Schema::table( 'knowledge' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over Chatlume's own table.
		$totals = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS chunks, COUNT(DISTINCT source_type, source_id) AS sources, COALESCE(SUM(LENGTH(content)), 0) + COALESCE(SUM(LENGTH(embedding)), 0) AS bytes, SUM(embedding IS NOT NULL AND embedding <> '' AND is_stale = 0) AS embedded, SUM(is_stale) AS stale, MAX(updated_at) AS updated_at FROM %i", $table ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over Chatlume's own table.
		$types = $wpdb->get_results( $wpdb->prepare( 'SELECT source_type, COUNT(DISTINCT source_id) AS total FROM %i GROUP BY source_type', $table ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate over Chatlume's own table.
		$languages = $wpdb->get_results( $wpdb->prepare( 'SELECT language, COUNT(*) AS total FROM %i GROUP BY language', $table ), ARRAY_A );

		$by_type = array();
		foreach ( is_array( $types ) ? $types : array() as $row ) {
			$by_type[ (string) $row['source_type'] ] = (int) $row['total'];
		}
		$by_language = array();
		foreach ( is_array( $languages ) ? $languages : array() as $row ) {
			$by_language[ (string) $row['language'] ] = (int) $row['total'];
		}

		return array(
			'chunks'     => (int) ( $totals['chunks'] ?? 0 ),
			'sources'    => (int) ( $totals['sources'] ?? 0 ),
			'bytes'      => (int) ( $totals['bytes'] ?? 0 ),
			'embedded'   => (int) ( $totals['embedded'] ?? 0 ),
			'stale'      => (int) ( $totals['stale'] ?? 0 ),
			'types'      => $by_type,
			'languages'  => $by_language,
			'updated_at' => empty( $totals['updated_at'] ) ? '' : str_replace( ' ', 'T', (string) $totals['updated_at'] ) . 'Z',
		);
	}

	/**
	 * Indexed sources, newest first.
	 *
	 * @param array{type?: string, search?: string, page?: int, per_page?: int} $args Filters.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function sources( array $args = array() ): array {
		global $wpdb;

		$table    = Schema::table( 'knowledge' );
		$type     = (string) ( $args['type'] ?? '' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$like     = '' === $search ? '%' : '%' . $wpdb->esc_like( $search ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list over Chatlume's own table; values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_type, source_id, MAX(title) AS title, MAX(url) AS url, MAX(language) AS language, COUNT(*) AS chunks, MAX(updated_at) AS updated_at FROM %i WHERE ( %s = '' OR source_type = %s ) AND title LIKE %s GROUP BY source_type, source_id ORDER BY updated_at DESC, source_id DESC LIMIT %d OFFSET %d", $table, $type, $type, $like, $per_page, $offset ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin list over Chatlume's own table; values are prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT source_type, source_id) FROM %i WHERE ( %s = '' OR source_type = %s ) AND title LIKE %s", $table, $type, $type, $like ) );

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[] = array(
				'type'       => (string) $row['source_type'],
				'id'         => (string) $row['source_id'],
				'title'      => (string) $row['title'],
				'url'        => (string) $row['url'],
				'language'   => (string) $row['language'],
				'chunks'     => (int) $row['chunks'],
				'updated_at' => str_replace( ' ', 'T', (string) $row['updated_at'] ) . 'Z',
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
