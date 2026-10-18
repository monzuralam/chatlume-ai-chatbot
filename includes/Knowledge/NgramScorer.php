<?php
/**
 * Keyword relevance computed in PHP.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ranks passages against a question without the database's full-text index:
 * for scripts written without spaces (Chinese, Japanese, Thai), on servers
 * without FULLTEXT support, and when full-text search finds nothing. Words
 * match by their stem at the start of a word (so "returns" finds "returned"); unspaced
 * scripts match as overlapping character pairs. Rare terms count more than
 * common ones (BM25-style weighting with saturation).
 */
final class NgramScorer {

	/**
	 * Term-frequency saturation (higher counts add less and less).
	 */
	private const K1 = 1.2;

	/**
	 * Scripts written without spaces between words.
	 */
	private const UNSPACED = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

	/**
	 * Whether text uses a script written without spaces.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_unspaced( string $text ): bool {
		return (bool) preg_match( self::UNSPACED, $text );
	}

	/**
	 * A word's stem for prefix matching, so "refunds" finds "refund" and
	 * "delivery" finds "delivered": long words lose up to two final letters.
	 * Short words, numbers, and unspaced scripts are kept whole.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	public static function stem( string $token ): string {
		$length = mb_strlen( $token, 'UTF-8' );
		if ( $length < 5 || ctype_digit( $token ) || self::is_unspaced( $token ) ) {
			return $token;
		}

		return mb_substr( $token, 0, $length >= 6 ? $length - 2 : $length - 1, 'UTF-8' );
	}

	/**
	 * Scores documents against query tokens.
	 *
	 * @param string[]              $tokens Query tokens from Text::tokens().
	 * @param array<string, string> $docs   Document text by key.
	 * @return array<string, float> Score from 0 to 1 by key, for documents that match at all.
	 */
	public static function rank( array $tokens, array $docs ): array {
		$tokens = array_values( array_unique( array_filter( array_map( 'strval', $tokens ) ) ) );
		if ( array() === $tokens || array() === $docs ) {
			return array();
		}

		$counts = array();
		$df     = array_fill_keys( $tokens, 0 );
		foreach ( $docs as $key => $text ) {
			$normalized = Text::normalize( (string) $text );
			foreach ( $tokens as $token ) {
				$count = self::count( $token, $normalized );
				if ( $count > 0 ) {
					$counts[ $key ][ $token ] = $count;
					++$df[ $token ];
				}
			}
		}

		$total = count( $docs );
		$idf   = array();
		foreach ( $df as $token => $frequency ) {
			$idf[ $token ] = log( 1 + ( $total - $frequency + 0.5 ) / ( $frequency + 0.5 ) );
		}
		$max = array_sum( $idf );
		if ( $max <= 0 ) {
			return array();
		}

		$scores = array();
		foreach ( $counts as $key => $found ) {
			$sum = 0.0;
			foreach ( $found as $token => $count ) {
				$sum += $idf[ $token ] * ( $count / ( $count + self::K1 ) );
			}
			// A passage with every term once scores about half; repeats push it toward 1.
			$scores[ (string) $key ] = min( 1.0, $sum / $max / ( 1 / ( 1 + self::K1 ) ) / 2 );
		}
		arsort( $scores );

		return $scores;
	}

	/**
	 * Occurrences of a token: at a word start, or anywhere for unspaced scripts.
	 *
	 * @param string $token      Token.
	 * @param string $normalized Normalized text.
	 * @return int
	 */
	private static function count( string $token, string $normalized ): int {
		if ( self::is_unspaced( $token ) ) {
			return substr_count( $normalized, $token );
		}

		return (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( self::stem( $token ), '/' ) . '/u', $normalized );
	}
}
