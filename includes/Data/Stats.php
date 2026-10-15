<?php
/**
 * Dashboard statistics.
 *
 * @package Chatlume
 */

namespace Chatlume\Data;

use Chatlume\Installer\Schema;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Totals, daily series, savings, and unanswered questions for the
 * Dashboard. Test conversations are never counted. Days follow the site's
 * timezone.
 */
class Stats {

	/**
	 * Reply sources that cost nothing.
	 */
	private const FREE_SOURCES = array( 'quick_answer', 'cache' );

	/**
	 * Reply sources produced by an AI model.
	 */
	private const AI_SOURCES = array( 'ai', 'fallback_model' );

	/**
	 * Unanswered questions shown.
	 */
	private const UNANSWERED_LIMIT = 10;

	/**
	 * Statistics for the last N days (1 = today).
	 *
	 * @param int $days 1, 7, or 30.
	 * @return array<string, mixed>
	 */
	public function summary( int $days ): array {
		$days   = in_array( $days, array( 1, 7, 30 ), true ) ? $days : 7;
		$start  = self::period_start( $days );
		$offset = self::utc_offset();

		$totals = $this->totals( $start );

		return array(
			'days'       => $days,
			'totals'     => $totals,
			'series'     => $this->series( $start, $days, $offset ),
			'unanswered' => $this->unanswered( $start ),
		);
	}

	/**
	 * UTC start of the period (local midnight, N-1 days ago).
	 *
	 * @param int $days Days.
	 * @return string Y-m-d H:i:s.
	 */
	public static function period_start( int $days ): string {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );

		return $today->modify( '-' . ( $days - 1 ) . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Current offset of the site timezone from UTC, in seconds.
	 *
	 * @return int
	 */
	private static function utc_offset(): int {
		return wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	/**
	 * Period totals and estimated savings.
	 *
	 * @param string $start UTC start.
	 * @return array<string, mixed>
	 */
	private function totals( string $start ): array {
		global $wpdb;

		$conversations = Schema::table( 'conversations' );
		$messages      = Schema::table( 'messages' );

		// Dashboard totals from Chatlume's own tables; values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_test = 0 AND created_at >= %s', $conversations, $start ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT m.role, m.source, COUNT(*) AS replies, SUM( m.input_tokens + m.output_tokens ) AS tokens, SUM( m.cost ) AS cost FROM %i m INNER JOIN %i c ON c.id = m.conversation_id WHERE c.is_test = 0 AND m.created_at >= %s GROUP BY m.role, m.source', $messages, $conversations, $start ), ARRAY_A );

		$questions = 0;
		$tokens    = 0;
		$cost      = 0.0;
		$by_source = array();
		$ai_cost   = 0.0;
		$ai_count  = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$count   = (int) $row['replies'];
			$tokens += (int) $row['tokens'];
			$cost   += (float) $row['cost'];

			if ( 'user' === $row['role'] ) {
				$questions += $count;
				continue;
			}

			$source               = (string) $row['source'];
			$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + $count;

			if ( in_array( $source, self::AI_SOURCES, true ) ) {
				$ai_cost  += (float) $row['cost'];
				$ai_count += $count;
			}
		}

		$free    = 0;
		$average = $ai_count > 0 ? $ai_cost / $ai_count : $this->average_ai_cost();
		foreach ( self::FREE_SOURCES as $source ) {
			$free += $by_source[ $source ] ?? 0;
		}

		return array(
			'conversations'     => $conversation_count,
			'questions'         => $questions,
			'tokens'            => $tokens,
			'cost'              => round( $cost, 6 ),
			'replies_by_source' => $by_source,
			'free_answers'      => $free,
			'average_ai_cost'   => round( $average, 6 ),
			'saved'             => round( $free * $average, 6 ),
		);
	}

	/**
	 * Average cost of an AI answer over all time (used when the period has
	 * no AI answers to compare against).
	 *
	 * @return float
	 */
	private function average_ai_cost(): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard figure from Chatlume's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS replies, SUM( cost ) AS cost FROM %i WHERE role = 'assistant' AND source IN ( %s, %s )", Schema::table( 'messages' ), self::AI_SOURCES[0], self::AI_SOURCES[1] ), ARRAY_A );

		$replies = is_array( $row ) ? (int) $row['replies'] : 0;

		return $replies > 0 ? (float) $row['cost'] / $replies : 0.0;
	}

	/**
	 * One entry per day: conversations, questions, cost.
	 *
	 * @param string $start  UTC start.
	 * @param int    $days   Days.
	 * @param int    $offset Site timezone offset (seconds).
	 * @return array<int, array{date: string, conversations: int, questions: int, cost: float}>
	 */
	private function series( string $start, int $days, int $offset ): array {
		global $wpdb;

		$conversations = Schema::table( 'conversations' );
		$messages      = Schema::table( 'messages' );

		// Daily counts in the site's timezone; values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$conversation_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE( DATE_ADD( created_at, INTERVAL %d SECOND ) ) AS day, COUNT(*) AS total FROM %i WHERE is_test = 0 AND created_at >= %s GROUP BY day', $offset, $conversations, $start ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$message_rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE( DATE_ADD( m.created_at, INTERVAL %d SECOND ) ) AS day, SUM( m.role = 'user' ) AS questions, SUM( m.cost ) AS cost FROM %i m INNER JOIN %i c ON c.id = m.conversation_id WHERE c.is_test = 0 AND m.created_at >= %s GROUP BY day", $offset, $messages, $conversations, $start ), ARRAY_A );

		$series = array();
		$day    = new \DateTimeImmutable( 'today', wp_timezone() );
		for ( $index = $days - 1; $index >= 0; $index-- ) {
			$date            = $day->modify( '-' . $index . ' days' )->format( 'Y-m-d' );
			$series[ $date ] = array(
				'date'          => $date,
				'conversations' => 0,
				'questions'     => 0,
				'cost'          => 0.0,
			);
		}

		foreach ( is_array( $conversation_rows ) ? $conversation_rows : array() as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ]['conversations'] = (int) $row['total'];
			}
		}
		foreach ( is_array( $message_rows ) ? $message_rows : array() as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ]['questions'] = (int) $row['questions'];
				$series[ $row['day'] ]['cost']      = round( (float) $row['cost'], 6 );
			}
		}

		return array_values( $series );
	}

	/**
	 * Questions the bot could not answer well: answered by site search, rated
	 * not helpful, with no knowledge match, or where "Ask AI" overruled a
	 * quick answer. Grouped by question, most frequent first.
	 *
	 * @param string $start UTC start.
	 * @return array<int, array{question: string, count: int, reasons: string[], last_seen: string, language: string}>
	 */
	private function unanswered( string $start ): array {
		global $wpdb;

		$conversations = Schema::table( 'conversations' );
		$messages      = Schema::table( 'messages' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard list from Chatlume's own tables; values are prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT q.content AS question, a.source, a.feedback, a.meta, a.created_at, c.language FROM %i a INNER JOIN %i c ON c.id = a.conversation_id INNER JOIN %i q ON q.id = ( SELECT MAX( q2.id ) FROM %i q2 WHERE q2.conversation_id = a.conversation_id AND q2.role = 'user' AND q2.id < a.id ) WHERE c.is_test = 0 AND a.role = 'assistant' AND a.created_at >= %s AND ( a.source = 'site_search' OR a.feedback = -1 OR a.meta LIKE %s OR a.meta LIKE %s ) ORDER BY a.id DESC LIMIT 300", $messages, $conversations, $messages, $messages, $start, '%' . $wpdb->esc_like( '"no_knowledge_match":true' ) . '%', '%' . $wpdb->esc_like( '"ask_ai_miss"' ) . '%' ), ARRAY_A );

		$groups = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$question = trim( (string) $row['question'] );
			$key      = Text::normalize( $question );
			if ( '' === $key ) {
				continue;
			}

			$meta    = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $meta ) ? $meta : array();
			$reasons = array();
			if ( 'site_search' === $row['source'] ) {
				$reasons[] = 'site_search';
			}
			if ( null !== $row['feedback'] && -1 === (int) $row['feedback'] ) {
				$reasons[] = 'not_helpful';
			}
			if ( ! empty( $meta['no_knowledge_match'] ) ) {
				$reasons[] = 'no_knowledge';
			}
			if ( ! empty( $meta['ask_ai_miss'] ) ) {
				$reasons[] = 'ask_ai';
			}

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'question'  => mb_substr( $question, 0, 300 ),
					'count'     => 0,
					'reasons'   => array(),
					'last_seen' => str_replace( ' ', 'T', (string) $row['created_at'] ) . 'Z',
					'language'  => (string) $row['language'],
				);
			}
			++$groups[ $key ]['count'];
			$groups[ $key ]['reasons'] = array_values( array_unique( array_merge( $groups[ $key ]['reasons'], $reasons ) ) );
		}

		$list = array_values( $groups );
		usort(
			$list,
			static function ( array $a, array $b ): int {
				$by_count = $b['count'] <=> $a['count'];

				return 0 !== $by_count ? $by_count : strcmp( $b['last_seen'], $a['last_seen'] );
			}
		);

		return array_slice( $list, 0, self::UNANSWERED_LIMIT );
	}
}
