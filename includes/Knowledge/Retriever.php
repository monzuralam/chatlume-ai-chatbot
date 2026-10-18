<?php
/**
 * Two-stage knowledge retrieval.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Installer\Schema;
use Chatlume\Settings\Settings;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the passages that answer a visitor's question.
 *
 * Stage 1 picks up to 100 candidates by keywords: MySQL FULLTEXT search, or
 * PHP n-gram scoring for scripts without spaces, servers without FULLTEXT,
 * and questions FULLTEXT cannot match. Stage 2 reranks only those candidates
 * by meaning when embeddings are on and nearly all candidates have vectors
 * from the current model, so vectors are never all loaded and models are
 * never mixed. The best passages go to the prompt (through the
 * `chatlume_retrieved_context` filter) inside delimiters.
 */
class Retriever {

	/**
	 * Candidates kept from stage 1.
	 */
	public const CANDIDATES = 100;

	/**
	 * Rows read for n-gram scoring.
	 */
	private const NGRAM_ROWS = 300;

	/**
	 * Query terms used to pre-select rows for n-gram scoring.
	 */
	private const NGRAM_TERMS = 8;

	/**
	 * Weight of meaning in the final score (the rest is keywords).
	 */
	private const SEMANTIC_WEIGHT = 0.7;

	/**
	 * Share of candidates that must have current vectors for reranking.
	 */
	private const VECTOR_COVERAGE = 0.8;

	/**
	 * Passages scoring below this share of the best one are left out.
	 */
	private const RELATIVE_CUTOFF = 0.4;

	/**
	 * Hooks retrieval into the chat pipeline (early, so other filters can
	 * adjust the result).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'chatlume_retrieved_context', array( $this, 'filter' ), 5, 3 );
	}

	/**
	 * Adds retrieved passages for a message.
	 *
	 * @param mixed                $chunks  Chunks so far.
	 * @param string               $message Visitor message.
	 * @param array<string, mixed> $context Request context.
	 * @return array<int, array<string, mixed>>
	 */
	public function filter( $chunks, $message, $context = array() ): array {
		$chunks = is_array( $chunks ) ? $chunks : array();

		return array_merge( $chunks, $this->retrieve( (string) $message, (string) ( is_array( $context ) ? ( $context['language'] ?? '' ) : '' ) ) );
	}

	/**
	 * The best passages for a question.
	 *
	 * @param string   $question Question.
	 * @param string   $language Page language ("en-US", "bn").
	 * @param int|null $limit    Passages (default: the top_k setting).
	 * @return array<int, array{content: string, title: string, url: string, score: float, source_type: string, source_id: string, match: string}>
	 */
	public function retrieve( string $question, string $language = '', ?int $limit = null ): array {
		$question = trim( $question );
		$tokens   = Text::tokens( $question );
		if ( array() === $tokens ) {
			return array();
		}

		$limit    = max( 1, min( 10, $limit ?? (int) Settings::get( 'knowledge.top_k', 4 ) ) );
		$language = Language::multilingual() ? Language::primary( $language ) : '';

		$match      = 'keyword';
		$candidates = array();
		if ( ! NgramScorer::is_unspaced( $question ) && 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' ) ) {
			$candidates = $this->fulltext( $tokens, $language );
		}
		if ( array() === $candidates ) {
			$candidates = $this->ngram( $tokens, $language );
			$match      = 'ngram';
		}
		if ( array() === $candidates ) {
			return array();
		}

		$scored = $this->rerank( $question, $candidates );
		if ( null !== $scored ) {
			$candidates = $scored;
			$match     .= '_semantic';
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		$best    = (float) $candidates[0]['score'];
		$results = array();
		$seen    = array();
		foreach ( $candidates as $row ) {
			if ( count( $results ) >= $limit || $row['score'] < $best * self::RELATIVE_CUTOFF ) {
				break;
			}
			$hash = md5( (string) $row['content'] );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$results[]     = array(
				'content'     => (string) $row['content'],
				'title'       => (string) $row['title'],
				'url'         => (string) $row['url'],
				'score'       => round( (float) $row['score'], 4 ),
				'source_type' => (string) $row['source_type'],
				'source_id'   => (string) $row['source_id'],
				'match'       => $match,
			);
		}

		return $results;
	}

	/**
	 * Stage 1 with MySQL FULLTEXT; scores are scaled to 0–1.
	 *
	 * @param string[] $tokens   Query tokens.
	 * @param string   $language Language filter ('' for none).
	 * @return array<int, array<string, mixed>>
	 */
	private function fulltext( array $tokens, string $language ): array {
		global $wpdb;

		// Boolean mode with stem prefixes: "refunds" also finds "refund" (FULLTEXT does not stem).
		$query = implode( ' ', array_map( static fn( string $token ): string => NgramScorer::stem( $token ) . '*', $tokens ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Search over Chatlume's own table; every value is prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, source_type, source_id, title, url, content, embedding, embedding_model, MATCH(title, content) AGAINST (%s IN BOOLEAN MODE) AS relevance FROM %i WHERE MATCH(title, content) AGAINST (%s IN BOOLEAN MODE) AND ( %s = '' OR language IN ('', %s) ) ORDER BY relevance DESC LIMIT %d", $query, Schema::table( 'knowledge' ), $query, $language, $language, self::CANDIDATES ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( array() === $rows ) {
			return array();
		}

		$max = max( array_map( static fn( $row ) => (float) $row['relevance'], $rows ) );
		foreach ( $rows as &$row ) {
			$row['score'] = $max > 0 ? (float) $row['relevance'] / $max : 0.0;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Stage 1 with n-gram scoring in PHP, on rows that contain at least one
	 * of the question's most distinctive terms.
	 *
	 * @param string[] $tokens   Query tokens.
	 * @param string   $language Language filter ('' for none).
	 * @return array<int, array<string, mixed>>
	 */
	private function ngram( array $tokens, string $language ): array {
		global $wpdb;

		// Longer terms are rarer and narrow the rows best.
		$terms = $tokens;
		usort(
			$terms,
			static function ( string $a, string $b ): int {
				return mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' );
			}
		);
		$terms = array_slice( $terms, 0, self::NGRAM_TERMS );

		$values = array( Schema::table( 'knowledge' ), $language, $language );
		foreach ( $terms as $term ) {
			$pattern  = '%' . $wpdb->esc_like( NgramScorer::stem( $term ) ) . '%';
			$values[] = $pattern;
			$values[] = $pattern;
		}
		$values[] = self::NGRAM_ROWS;
		$where    = implode( ' OR ', array_fill( 0, count( $terms ), 'content LIKE %s OR title LIKE %s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The OR list holds only placeholders; every value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_type, source_id, title, url, content, embedding, embedding_model FROM %i WHERE ( %s = '' OR language IN ('', %s) ) AND ( $where ) LIMIT %d",
				$values
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows = is_array( $rows ) ? $rows : array();
		if ( array() === $rows ) {
			return array();
		}

		$docs = array();
		foreach ( $rows as $index => $row ) {
			$docs[ (string) $index ] = $row['title'] . "\n" . $row['content'];
		}

		$candidates = array();
		foreach ( array_slice( NgramScorer::rank( $tokens, $docs ), 0, self::CANDIDATES, true ) as $index => $score ) {
			$row          = $rows[ (int) $index ];
			$row['score'] = $score;
			$candidates[] = $row;
		}

		return $candidates;
	}

	/**
	 * Stage 2: blends meaning with the keyword score. Null when reranking
	 * cannot run (embeddings off, too few current vectors, or no question
	 * vector), so keyword order stands.
	 *
	 * @param string                           $question   Question.
	 * @param array<int, array<string, mixed>> $candidates Candidates.
	 * @return array<int, array<string, mixed>>|null
	 */
	private function rerank( string $question, array $candidates ): ?array {
		$model = Embeddings::model_key();
		if ( null === $model ) {
			return null;
		}

		$vectors = array();
		foreach ( $candidates as $index => $row ) {
			if ( $model === (string) $row['embedding_model'] && is_string( $row['embedding'] ) && '' !== $row['embedding'] ) {
				$vectors[ $index ] = Embeddings::unpack( $row['embedding'] );
			}
		}
		if ( count( $vectors ) < count( $candidates ) * self::VECTOR_COVERAGE ) {
			return null;
		}

		$query = Embeddings::query_vector( $question );
		if ( null === $query ) {
			return null;
		}

		foreach ( $candidates as $index => &$row ) {
			$similarity   = isset( $vectors[ $index ] ) ? max( 0.0, Embeddings::cosine( $query, $vectors[ $index ] ) ) : 0.0;
			$row['score'] = self::SEMANTIC_WEIGHT * $similarity + ( 1 - self::SEMANTIC_WEIGHT ) * (float) $row['score'];
		}
		unset( $row );

		return $candidates;
	}
}
