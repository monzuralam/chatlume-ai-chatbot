<?php
/**
 * Cache of AI answers to common first questions.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Settings\Settings;
use Chatlume\Support\Text;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves a previous AI answer, with no API call, when the same question is
 * asked again.
 *
 * Safety rules: only the first question of a conversation, only for
 * logged-out visitors, never when the prompt uses {user_name}; the key
 * includes the normalized question, page language, a hash of the fully
 * resolved system prompt, the answering provider and model, and the
 * knowledge-base version (so re-indexing invalidates old answers).
 */
class ResponseCache {

	/**
	 * Object cache group.
	 */
	private const GROUP = 'chatlume_responses';

	/**
	 * Option bumped when the knowledge base changes.
	 */
	public const KNOWLEDGE_VERSION_OPTION = 'chatlume_knowledge_version';

	/**
	 * Option bumped by "Clear cached answers": part of every key, so all
	 * earlier entries stop matching at once (they expire on their own).
	 */
	public const GENERATION_OPTION = 'chatlume_cache_generation';

	/**
	 * Whether a request may use the cache.
	 *
	 * @param bool $first_turn Whether this is the conversation's first question.
	 * @param int  $user_id    Logged-in user ID (0 for visitors).
	 * @return bool
	 */
	public static function eligible( bool $first_turn, int $user_id ): bool {
		return (bool) Settings::get( 'cache.enabled', true )
			&& $first_turn
			&& 0 === $user_id
			&& ! PromptBuilder::uses_user_name();
	}

	/**
	 * Cache key.
	 *
	 * @param string $message       Visitor message.
	 * @param string $language      Page language.
	 * @param string $system_prompt Fully resolved system prompt.
	 * @param string $target        Provider and model that would answer.
	 * @return string
	 */
	public static function key( string $message, string $language, string $system_prompt, string $target ): string {
		return 'chatlume_rc_' . md5(
			implode(
				'|',
				array(
					Text::normalize( $message ),
					strtolower( $language ),
					hash( 'sha256', $system_prompt ),
					$target,
					(string) get_option( self::KNOWLEDGE_VERSION_OPTION, '0' ),
					(string) get_option( self::GENERATION_OPTION, '0' ),
				)
			)
		);
	}

	/**
	 * Cached answer, or null.
	 *
	 * @param string $key Key.
	 * @return array{text: string, sources: array}|null
	 */
	public function get( string $key ): ?array {
		$value = wp_using_ext_object_cache() ? wp_cache_get( $key, self::GROUP ) : get_transient( $key );

		return is_array( $value ) && isset( $value['text'] ) && is_string( $value['text'] ) ? $value : null;
	}

	/**
	 * Stores an answer for the configured time.
	 *
	 * @param string               $key     Key.
	 * @param string               $text    Answer.
	 * @param array<int, mixed>    $sources Source links.
	 * @return void
	 */
	public function set( string $key, string $text, array $sources ): void {
		$ttl   = max( 1, (int) Settings::get( 'cache.ttl_hours', 24 ) ) * HOUR_IN_SECONDS;
		$value = array(
			'text'    => $text,
			'sources' => $sources,
		);

		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
		} else {
			set_transient( $key, $value, $ttl );
		}
	}

	/**
	 * Invalidates every cached answer.
	 *
	 * @return void
	 */
	public static function flush(): void {
		update_option( self::GENERATION_OPTION, (string) ( (int) get_option( self::GENERATION_OPTION, '0' ) + 1 ), false );
	}
}
