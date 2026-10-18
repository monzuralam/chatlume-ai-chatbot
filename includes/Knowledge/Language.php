<?php
/**
 * Content languages.
 *
 * @package Chatlume
 */

namespace Chatlume\Knowledge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The language of indexed content, as a primary language code ("en", "bn",
 * "zh"), so knowledge can be filtered by the visitor's language. On
 * multilingual sites the language comes from WPML or Polylang; otherwise
 * every item has the site's language.
 */
final class Language {

	/**
	 * Primary language code of a locale or language tag.
	 *
	 * @param string $locale "en_US", "pt-BR", "bn".
	 * @return string "en", "pt", "bn", or ''.
	 */
	public static function primary( string $locale ): string {
		$code = strtolower( (string) preg_replace( '/[_-].*$/', '', trim( $locale ) ) );

		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : '';
	}

	/**
	 * Whether a multilingual plugin is active.
	 *
	 * @return bool
	 */
	public static function multilingual(): bool {
		return function_exists( 'pll_get_post_language' ) || defined( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * Language of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string Primary language code.
	 */
	public static function of_post( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$language = pll_get_post_language( $post_id, 'slug' );
			if ( is_string( $language ) && '' !== $language ) {
				return self::primary( $language );
			}
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter.
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return self::primary( (string) $details['language_code'] );
			}
		}

		return self::site();
	}

	/**
	 * Language of the site.
	 *
	 * @return string
	 */
	public static function site(): string {
		return self::primary( (string) get_locale() );
	}
}
