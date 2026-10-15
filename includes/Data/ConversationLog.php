<?php
/**
 * Admin reads and deletes of logged conversations.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Conversations page: filtered listing, transcripts, deletion, export.
 *
 * Test conversations (from the admin's own testing) are never listed.
 * Queries are fixed SQL on Chatlume's own tables in which every filter is a
 * prepared value: an unused filter compares against its "any" value.
 */
class ConversationLog {

	/**
	 * Reply sources that can be filtered.
	 */
	public const SOURCES = array( 'ai', 'quick_answer', 'cache', 'fallback_model', 'site_search', 'guardrail', 'offline' );

	/**
	 * Largest page size.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Most conversations in one export.
	 */
	public const EXPORT_LIMIT = 2000;

	/**
	 * Date bounds used when a date filter is empty (strict SQL modes reject
	 * comparing a DATETIME with an empty string).
	 */
	private const EARLIEST = '1970-01-01 00:00:00';
	private const LATEST   = '9999-12-31 00:00:00';

	/**
	 * Validated filters with "any" defaults.
	 *
	 * @param array<string, mixed> $raw search, date_from, date_to (site-local Y-m-d), rating, source, lead, language.
	 * @return array{search: string, like: string, from: string, to: string, rating: string, source: string, lead: string, language: string}
	 */
	public static function filters( array $raw ): array {
		$search = mb_substr( trim( (string) ( $raw['search'] ?? '' ) ), 0, 200 );
		$rating = (string) ( $raw['rating'] ?? 'any' );
		$source = (string) ( $raw['source'] ?? '' );
		$lead   = (string) ( $raw['lead'] ?? 'any' );

		return array(
			'search'   => $search,
			'like'     => '' === $search ? '' : '%' . self::esc_like( $search ) . '%',
			'from'     => self::day_start( (string) ( $raw['date_from'] ?? '' ), 0, self::EARLIEST ),
			'to'       => self::day_start( (string) ( $raw['date_to'] ?? '' ), 1, self::LATEST ),
			'rating'   => in_array( $rating, array( 'any', 'up', 'down', 'none' ), true ) ? $rating : 'any',
			'source'   => in_array( $source, self::SOURCES, true ) ? $source : '',
			'lead'     => in_array( $lead, array( 'any', 'yes', 'no' ), true ) ? $lead : 'any',
			'language' => (string) preg_replace( '/[^A-Za-z0-9_-]/', '', mb_substr( (string) ( $raw['language'] ?? '' ), 0, 20 ) ),
		);
	}

	/**
	 * Start of a site-local day (plus some days) in UTC.
	 *
	 * @param string $date      Y-m-d in the site's timezone.
	 * @param int    $plus_days Days to add.
	 * @param string $fallback  Value when no valid date is given.
	 * @return string UTC datetime.
	 */
	private static function day_start( string $date, int $plus_days, string $fallback ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $fallback;
		}
		$day = date_create_immutable( $date . ' 00:00:00', wp_timezone() );
		if ( false === $day ) {
			return $fallback;
		}
		if ( $plus_days ) {
			$day = $day->modify( '+' . $plus_days . ' day' );
		}

		return $day->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Escapes LIKE wildcards.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function esc_like( string $text ): string {
		global $wpdb;

		return $wpdb->esc_like( $text );
	}

	/**
	 * Prepared values for the shared WHERE clause, in order.
	 *
	 * @param array<string, string> $filters Filters from filters().
	 * @return array<int, mixed>
	 */
	private static function where_values( array $filters ): array {
		return array(
			$filters['search'],
			$filters['like'],
			$filters['like'],
			$filters['like'],
			$filters['from'],
			$filters['to'],
			$filters['rating'],
			$filters['rating'],
			$filters['rating'],
			$filters['rating'],
			$filters['source'],
			$filters['source'],
			$filters['lead'],
			$filters['lead'],
			$filters['lead'],
			$filters['language'],
			$filters['language'],
		);
	}

	/**
	 * One page of conversations, newest activity first.
	 *
	 * @param array<string, mixed> $raw      Raw filters.
	 * @param int                  $page     Page (1-based).
	 * @param int                  $per_page Items per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $raw, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$filters       = self::filters( $raw );
		$per_page      = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$offset        = ( max( 1, $page ) - 1 ) * $per_page;
		$conversations = Schema::table( 'conversations' );
		$messages      = Schema::table( 'messages' );

		// Admin listing of Chatlume's own tables; every filter value is prepared. The values are
		// passed as one list built by query_values(), which the sniff cannot count (a unit test checks
		// that placeholders and values match).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i c WHERE c.is_test = 0 AND ( %s = '' OR c.lead_name LIKE %s OR c.lead_email LIKE %s OR EXISTS ( SELECT 1 FROM %i ms WHERE ms.conversation_id = c.id AND ms.content LIKE %s ) ) AND c.created_at >= %s AND c.created_at < %s AND ( %s = 'any' OR ( %s = 'up' AND c.rating = 1 ) OR ( %s = 'down' AND c.rating = -1 ) OR ( %s = 'none' AND c.rating IS NULL ) ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i mf WHERE mf.conversation_id = c.id AND mf.source = %s ) ) AND ( %s = 'any' OR ( %s = 'yes' AND c.lead_email <> '' ) OR ( %s = 'no' AND c.lead_email = '' ) ) AND ( %s = '' OR c.language = %s )", self::query_values( $conversations, $messages, $filters ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.id, c.user_id, c.page_url, c.language, c.provider, c.model, c.lead_name, c.lead_email, c.rating, c.message_count, c.total_cost, c.created_at, c.updated_at, ( SELECT LEFT( mq.content, 160 ) FROM %i mq WHERE mq.conversation_id = c.id AND mq.role = 'user' ORDER BY mq.id LIMIT 1 ) AS first_question, ( SELECT GROUP_CONCAT( DISTINCT mr.source ) FROM %i mr WHERE mr.conversation_id = c.id AND mr.role = 'assistant' ) AS sources FROM %i c WHERE c.is_test = 0 AND ( %s = '' OR c.lead_name LIKE %s OR c.lead_email LIKE %s OR EXISTS ( SELECT 1 FROM %i ms WHERE ms.conversation_id = c.id AND ms.content LIKE %s ) ) AND c.created_at >= %s AND c.created_at < %s AND ( %s = 'any' OR ( %s = 'up' AND c.rating = 1 ) OR ( %s = 'down' AND c.rating = -1 ) OR ( %s = 'none' AND c.rating IS NULL ) ) AND ( %s = '' OR EXISTS ( SELECT 1 FROM %i mf WHERE mf.conversation_id = c.id AND mf.source = %s ) ) AND ( %s = 'any' OR ( %s = 'yes' AND c.lead_email <> '' ) OR ( %s = 'no' AND c.lead_email = '' ) ) AND ( %s = '' OR c.language = %s ) ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d", array_merge( array( $messages, $messages ), self::query_values( $conversations, $messages, $filters ), array( $per_page, $offset ) ) ), ARRAY_A );

		return array(
			'items' => array_map( array( self::class, 'summary' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Values for the shared FROM/WHERE part (tables included).
	 *
	 * @param string                $conversations Conversations table.
	 * @param string                $messages      Messages table.
	 * @param array<string, string> $filters       Filters.
	 * @return array<int, mixed>
	 */
	private static function query_values( string $conversations, string $messages, array $filters ): array {
		$values = self::where_values( $filters );

		// Table names go where the SQL has %i: before the search subquery and the source subquery.
		array_splice( $values, 3, 0, array( $messages ) );
		array_splice( $values, 12, 0, array( $messages ) );

		return array_merge( array( $conversations ), $values );
	}

	/**
	 * Languages that appear in the log (for the filter).
	 *
	 * @return string[]
	 */
	public function languages(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct values of Chatlume's own table for a filter.
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT language FROM %i WHERE is_test = 0 AND language <> '' ORDER BY language LIMIT 100", Schema::table( 'conversations' ) ) );

		return array_values( array_map( 'strval', is_array( $values ) ? $values : array() ) );
	}

	/**
	 * A conversation with all its messages, or null (test conversations included).
	 *
	 * @param int $id Conversation ID.
	 * @return array{conversation: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
	 */
	public function transcript( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chatlume's own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, user_id, page_url, language, provider, model, lead_name, lead_email, rating, message_count, total_cost, is_test, created_at, updated_at FROM %i WHERE id = %d', Schema::table( 'conversations' ), $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'conversation' => self::summary( $row ),
			'messages'     => $this->messages( array( $id ) )[ $id ] ?? array(),
		);
	}

	/**
	 * Messages of several conversations, grouped by conversation ID.
	 *
	 * @param int[] $ids Conversation IDs.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	public function messages( array $ids ): array {
		global $wpdb;

		$ids = self::positive_ids( $ids );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() list is only %d placeholders; all values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, conversation_id, role, content, source, input_tokens, output_tokens, cost, latency_ms, feedback, sources, error_type, meta, created_at FROM %i WHERE conversation_id IN ($placeholders) ORDER BY conversation_id, id", array_merge( array( Schema::table( 'messages' ) ), $ids ) ), ARRAY_A );
		// phpcs:enable

		$grouped = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$sources = json_decode( (string) $row['sources'], true );
			$meta    = json_decode( (string) $row['meta'], true );

			$grouped[ (int) $row['conversation_id'] ][] = array(
				'id'            => (int) $row['id'],
				'role'          => (string) $row['role'],
				'content'       => (string) $row['content'],
				'source'        => (string) $row['source'],
				'input_tokens'  => (int) $row['input_tokens'],
				'output_tokens' => (int) $row['output_tokens'],
				'cost'          => (float) $row['cost'],
				'latency_ms'    => (int) $row['latency_ms'],
				'feedback'      => null === $row['feedback'] ? null : (int) $row['feedback'],
				'sources'       => is_array( $sources ) ? array_values( $sources ) : array(),
				'error_type'    => (string) $row['error_type'],
				'interrupted'   => is_array( $meta ) ? (string) ( $meta['interrupted'] ?? '' ) : '',
				'created_at'    => self::iso( (string) $row['created_at'] ),
			);
		}

		return $grouped;
	}

	/**
	 * Conversations and their messages for an export (newest first).
	 *
	 * @param array<string, mixed> $raw Raw filters.
	 * @return array{conversations: array<int, array<string, mixed>>, truncated: bool}
	 */
	public function export( array $raw ): array {
		$found    = $this->search( $raw, 1, self::MAX_PER_PAGE );
		$items    = $found['items'];
		$page     = 1;
		$per_page = self::MAX_PER_PAGE;
		$wanted   = min( $found['total'], self::EXPORT_LIMIT );
		$fetched  = count( $items );

		while ( $fetched < $wanted ) {
			++$page;
			$more = $this->search( $raw, $page, $per_page )['items'];
			if ( array() === $more ) {
				break;
			}
			$items    = array_merge( $items, $more );
			$fetched += count( $more );
		}
		$items = array_slice( $items, 0, self::EXPORT_LIMIT );

		$messages = $this->messages( array_column( $items, 'id' ) );
		foreach ( $items as &$item ) {
			$item['messages'] = $messages[ $item['id'] ] ?? array();
		}
		unset( $item );

		return array(
			'conversations' => $items,
			'truncated'     => $found['total'] > self::EXPORT_LIMIT,
		);
	}

	/**
	 * Deletes conversations and their messages.
	 *
	 * @param int[] $ids Conversation IDs.
	 * @return int Conversations deleted.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = self::positive_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() list is only %d placeholders; all values are prepared.
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE conversation_id IN ($placeholders)", array_merge( array( Schema::table( 'messages' ) ), $ids ) ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ($placeholders)", array_merge( array( Schema::table( 'conversations' ) ), $ids ) ) );
		// phpcs:enable

		return (int) $deleted;
	}

	/**
	 * Unique positive integer IDs.
	 *
	 * @param array<int, mixed> $ids Raw IDs.
	 * @return int[]
	 */
	private static function positive_ids( array $ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);
	}

	/**
	 * A conversation row for the admin (no session or visitor hash).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function summary( array $row ): array {
		$sources = array_values( array_filter( explode( ',', (string) ( $row['sources'] ?? '' ) ) ) );

		$summary = array(
			'id'             => (int) $row['id'],
			'logged_in'      => (int) ( $row['user_id'] ?? 0 ) > 0,
			'page_url'       => (string) ( $row['page_url'] ?? '' ),
			'language'       => (string) ( $row['language'] ?? '' ),
			'provider'       => (string) ( $row['provider'] ?? '' ),
			'model'          => (string) ( $row['model'] ?? '' ),
			'lead_name'      => (string) ( $row['lead_name'] ?? '' ),
			'lead_email'     => (string) ( $row['lead_email'] ?? '' ),
			'rating'         => null === ( $row['rating'] ?? null ) ? null : (int) $row['rating'],
			'message_count'  => (int) ( $row['message_count'] ?? 0 ),
			'total_cost'     => (float) ( $row['total_cost'] ?? 0 ),
			'created_at'     => self::iso( (string) ( $row['created_at'] ?? '' ) ),
			'updated_at'     => self::iso( (string) ( $row['updated_at'] ?? '' ) ),
			'first_question' => (string) ( $row['first_question'] ?? '' ),
			'sources'        => $sources,
		);

		if ( isset( $row['is_test'] ) ) {
			$summary['is_test'] = (bool) $row['is_test'];
		}

		return $summary;
	}

	/**
	 * A UTC database datetime as ISO 8601.
	 *
	 * @param string $datetime Y-m-d H:i:s (UTC).
	 * @return string
	 */
	private static function iso( string $datetime ): string {
		return '' === $datetime ? '' : str_replace( ' ', 'T', $datetime ) . 'Z';
	}
}
