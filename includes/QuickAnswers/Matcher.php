<?php
/**
 * Quick answer matching.
 *
 * @package Chatlume
 */

namespace Chatlume\QuickAnswers;

use Chatlume\Data\QuickAnswerRepository;
use Chatlume\Settings\Settings;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the admin-defined answer that clearly matches a visitor message,
 * so it can be served instantly without calling the AI.
 *
 * Scoring (0–1), multibyte-safe:
 * - identical normalized question: 1.0
 * - token overlap (Dice coefficient) between message and stored question
 * - a stored question fully contained in a short message: at least 0.85
 * - each admin keyword phrase found in the message: at least 0.8 + 0.1 per keyword
 * The default threshold (0.8) is deliberately high: when unsure, the AI answers.
 */
class Matcher {

	/**
	 * Quick answers.
	 *
	 * @var QuickAnswerRepository
	 */
	private QuickAnswerRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param QuickAnswerRepository|null $repository Repository.
	 */
	public function __construct( ?QuickAnswerRepository $repository = null ) {
		$this->repository = $repository ?? new QuickAnswerRepository();
	}

	/**
	 * Best match above the threshold, or null.
	 *
	 * @param string               $message  Visitor message.
	 * @param string               $language Page language ('' for any).
	 * @param array<string, mixed> $context  Request context (passed to the filter).
	 * @return array{id: int, question: string, answer: string, score: float}|null
	 */
	public function match( string $message, string $language, array $context = array() ): ?array {
		$threshold = (float) Settings::get( 'quick_answers.threshold', 0.8 );
		$best      = null;

		foreach ( $this->repository->enabled_for( $language ) as $candidate ) {
			$score = self::score( $message, $candidate['question'], $candidate['keywords'] );
			if ( $score >= $threshold && ( null === $best || $score > $best['score'] ) ) {
				$best = array(
					'id'       => $candidate['id'],
					'question' => $candidate['question'],
					'answer'   => $candidate['answer'],
					'score'    => round( $score, 3 ),
				);
			}
		}

		/**
		 * Filters the quick answer chosen for a message (null for none).
		 *
		 * @param array|null           $best    Match: id, question, answer, score.
		 * @param string               $message Visitor message.
		 * @param array<string, mixed> $context Request context.
		 */
		$filtered = apply_filters( 'chatlume_quick_answer_match', $best, $message, $context );

		return is_array( $filtered ) && isset( $filtered['answer'] ) ? $filtered : null;
	}

	/**
	 * Similarity between a message and a stored question with keywords.
	 *
	 * @param string $message  Visitor message.
	 * @param string $question Stored question.
	 * @param string $keywords Comma-separated keyword phrases.
	 * @return float 0–1.
	 */
	public static function score( string $message, string $question, string $keywords = '' ): float {
		$normalized_message = Text::normalize( $message );
		if ( '' === $normalized_message ) {
			return 0.0;
		}

		if ( Text::normalize( $question ) === $normalized_message ) {
			return 1.0;
		}

		$message_tokens  = Text::tokens( $message );
		$question_tokens = Text::tokens( $question );
		$score           = 0.0;

		if ( array() !== $message_tokens && array() !== $question_tokens ) {
			$shared = count( array_intersect( $message_tokens, $question_tokens ) );
			$score  = 2 * $shared / ( count( $message_tokens ) + count( $question_tokens ) );

			// "opening hours?" vs "what are your opening hours please": the whole stored question is present
			// and makes up most of the message.
			if ( count( $question_tokens ) >= 2 && count( $question_tokens ) === $shared && $shared / count( $message_tokens ) >= 0.6 ) {
				$score = max( $score, 0.85 );
			}
		}

		$hits = 0;
		foreach ( explode( ',', $keywords ) as $keyword ) {
			if ( Text::contains_phrase( $normalized_message, Text::normalize( $keyword ) ) ) {
				++$hits;
			}
		}
		if ( $hits > 0 ) {
			$score = max( $score, min( 1.0, 0.8 + 0.1 * $hits ) );
		}

		return min( 1.0, $score );
	}
}
