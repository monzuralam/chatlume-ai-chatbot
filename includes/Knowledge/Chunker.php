<?php
/**
 * Text cleaning and chunking.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns content into plain-text chunks of about 800 characters that overlap
 * a little, so a fact split across a boundary still appears whole in one
 * chunk. Chunks break at paragraphs, then sentences, then words, and never
 * inside a multibyte character.
 */
final class Chunker {

	/**
	 * Target chunk length in characters.
	 */
	public const SIZE = 800;

	/**
	 * Characters repeated from the end of the previous chunk.
	 */
	public const OVERLAP = 120;

	/**
	 * Plain text from post content or HTML: shortcodes, scripts, styles, and
	 * tags removed, entities decoded, paragraphs kept as blank lines.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function clean( string $content ): string {
		$text = function_exists( 'strip_shortcodes' ) ? strip_shortcodes( $content ) : $content;
		// Leftover shortcode tags from plugins that are no longer active.
		$text = (string) preg_replace( '/\[\/?[a-zA-Z][\w-]*(?:\s[^\]]*)?\]/u', ' ', $text );
		$text = (string) preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $text );
		// Block-level tags become paragraph breaks before the tags are removed.
		$text = (string) preg_replace( '#</?(p|div|h[1-6]|li|ul|ol|tr|table|section|article|blockquote|pre|figure|figcaption|header|footer)\b[^>]*>|<br\s*/?>#i', "\n\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0" ), array( "\n", "\n", ' ' ), $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/u", "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Splits text into overlapping chunks.
	 *
	 * @param string $text    Plain text.
	 * @param int    $size    Target length.
	 * @param int    $overlap Overlap length.
	 * @return string[]
	 */
	public static function split( string $text, int $size = self::SIZE, int $overlap = self::OVERLAP ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		if ( mb_strlen( $text, 'UTF-8' ) <= $size ) {
			return array( $text );
		}

		$chunks  = array();
		$current = '';
		foreach ( self::pieces( $text, $size ) as $piece ) {
			$candidate = '' === $current ? $piece : $current . self::joiner( $current ) . $piece;
			if ( mb_strlen( $candidate, 'UTF-8' ) <= $size || '' === $current ) {
				$current = $candidate;
				continue;
			}
			$chunks[] = trim( $current );
			$tail     = self::tail( $current, $overlap );
			$current  = '' !== $tail ? $tail . ' ' . $piece : $piece;
		}
		if ( '' !== trim( $current ) ) {
			$chunks[] = trim( $current );
		}

		return $chunks;
	}

	/**
	 * Paragraphs, with long ones split into sentences and long sentences
	 * into word runs, so no piece is longer than the chunk size.
	 *
	 * @param string $text Text.
	 * @param int    $size Chunk size.
	 * @return string[]
	 */
	private static function pieces( string $text, int $size ): array {
		$pieces = array();
		foreach ( preg_split( "/\n\s*\n/u", $text ) as $paragraph ) {
			$paragraph = trim( (string) $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			if ( mb_strlen( $paragraph, 'UTF-8' ) <= $size ) {
				$pieces[] = $paragraph;
				continue;
			}
			// Sentence ends in Latin, Bengali (।), Arabic (؟ ۔), and CJK (。！？).
			$sentences = preg_split( '/(?<=[.!?।؟۔。！？])\s+|(?<=[。！？])/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY );
			foreach ( is_array( $sentences ) ? $sentences : array( $paragraph ) as $sentence ) {
				$sentence = trim( $sentence );
				while ( mb_strlen( $sentence, 'UTF-8' ) > $size ) {
					$cut      = self::word_cut( $sentence, $size );
					$pieces[] = trim( mb_substr( $sentence, 0, $cut, 'UTF-8' ) );
					$sentence = trim( mb_substr( $sentence, $cut, null, 'UTF-8' ) );
				}
				if ( '' !== $sentence ) {
					$pieces[] = $sentence;
				}
			}
		}

		return $pieces;
	}

	/**
	 * Where to cut a long run: the last space before the limit, or the limit
	 * itself (scripts without spaces).
	 *
	 * @param string $text Text.
	 * @param int    $size Limit.
	 * @return int Characters to keep.
	 */
	private static function word_cut( string $text, int $size ): int {
		$head  = mb_substr( $text, 0, $size, 'UTF-8' );
		$space = mb_strrpos( $head, ' ', 0, 'UTF-8' );

		return false !== $space && $space > $size / 2 ? $space : $size;
	}

	/**
	 * The end of a chunk to repeat at the start of the next, from a word
	 * boundary.
	 *
	 * @param string $text    Chunk.
	 * @param int    $overlap Length.
	 * @return string
	 */
	private static function tail( string $text, int $overlap ): string {
		if ( $overlap <= 0 ) {
			return '';
		}
		$tail  = mb_substr( $text, -$overlap, null, 'UTF-8' );
		$space = mb_strpos( $tail, ' ', 0, 'UTF-8' );

		return trim( false !== $space && $space < $overlap / 2 ? mb_substr( $tail, $space, null, 'UTF-8' ) : $tail );
	}

	/**
	 * Paragraph break between pieces that were separate paragraphs, a space
	 * otherwise (keeps the text readable for the AI).
	 *
	 * @param string $current Text so far.
	 * @return string
	 */
	private static function joiner( string $current ): string {
		return preg_match( '/[.!?।؟۔。！？:]$/u', $current ) ? "\n\n" : ' ';
	}
}
