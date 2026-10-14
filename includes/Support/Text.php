<?php
/**
 * Multibyte-safe text normalization and tokenization.
 *
 * @package Chatlume
 */

namespace Chatlume\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by quick-answer matching, the response cache, and site search.
 * Works for any script: words are split on Unicode letters and numbers, and
 * scripts written without spaces (Chinese, Japanese, Thai, Lao, Khmer,
 * Burmese) are split into overlapping character pairs instead.
 */
final class Text {

	/**
	 * Scripts written without spaces between words.
	 */
	private const UNSPACED = '\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}';

	/**
	 * Very common English words that carry no meaning for matching.
	 * Other languages keep all words (their scores are simply less smoothed).
	 */
	private const STOPWORDS = array(
		'a',
		'an',
		'the',
		'and',
		'or',
		'but',
		'is',
		'are',
		'was',
		'were',
		'be',
		'been',
		'am',
		'do',
		'does',
		'did',
		'have',
		'has',
		'had',
		'i',
		'me',
		'my',
		'we',
		'our',
		'you',
		'your',
		'it',
		'its',
		'this',
		'that',
		'these',
		'those',
		'to',
		'of',
		'in',
		'on',
		'at',
		'for',
		'with',
		'by',
		'from',
		'about',
		'can',
		'could',
		'would',
		'should',
		'will',
		'please',
		'what',
		'how',
		'when',
		'where',
		'which',
		'who',
		'why',
		'there',
		'any',
		'some',
		'so',
		'if',
		'as',
		'into',
		'than',
		'then',
		'just',
		'tell',
		'know',
		'want',
		'need',
		'hi',
		'hello',
	);

	/**
	 * Lowercased text with punctuation removed and whitespace collapsed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_KC );
			$text       = false !== $normalized ? $normalized : $text;
		}

		$text = mb_strtolower( wp_strip_all_tags( $text ), 'UTF-8' );
		$text = (string) preg_replace( '/[^\p{L}\p{N}\p{M}]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Distinct meaningful tokens: words, plus character pairs for unspaced scripts.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$tokens = array();

		foreach ( explode( ' ', self::normalize( $text ) ) as $word ) {
			if ( '' === $word ) {
				continue;
			}

			if ( preg_match( '/[' . self::UNSPACED . ']/u', $word ) ) {
				foreach ( self::bigrams( $word ) as $pair ) {
					$tokens[ $pair ] = true;
				}
				continue;
			}

			if ( ( mb_strlen( $word, 'UTF-8' ) > 1 || ctype_digit( $word ) ) && ! in_array( $word, self::STOPWORDS, true ) ) {
				$tokens[ $word ] = true;
			}
		}

		// Numeric keys become integers in PHP arrays; tokens are always strings.
		return array_map( 'strval', array_keys( $tokens ) );
	}

	/**
	 * Overlapping character pairs (a single character yields itself).
	 *
	 * @param string $word Word.
	 * @return string[]
	 */
	public static function bigrams( string $word ): array {
		$chars = preg_split( '//u', $word, -1, PREG_SPLIT_NO_EMPTY );
		$chars = is_array( $chars ) ? $chars : array();

		if ( count( $chars ) < 2 ) {
			return $chars;
		}

		$pairs = array();
		for ( $i = 0, $n = count( $chars ) - 1; $i < $n; $i++ ) {
			$pairs[] = $chars[ $i ] . $chars[ $i + 1 ];
		}

		return $pairs;
	}

	/**
	 * Whether $needle appears in $haystack as whole words (both normalized).
	 * Unspaced scripts match as plain substrings.
	 *
	 * @param string $haystack Normalized text.
	 * @param string $needle   Normalized phrase.
	 * @return bool
	 */
	public static function contains_phrase( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}

		if ( preg_match( '/[' . self::UNSPACED . ']/u', $needle ) ) {
			return false !== mb_strpos( $haystack, $needle, 0, 'UTF-8' );
		}

		return false !== mb_strpos( ' ' . $haystack . ' ', ' ' . $needle . ' ', 0, 'UTF-8' );
	}
}
