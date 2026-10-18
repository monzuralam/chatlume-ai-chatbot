<?php
/**
 * WPML and Polylang languages.
 *
 * @package Chatlume
 */

namespace Chatlume\Compat;

use Chatlume\Knowledge\Language;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's languages as Polylang or WPML define them: each language's
 * code (Polylang slug or WPML language code), locale, and name, the main
 * language, and the language of the current page. Without a multilingual
 * plugin the site has one language. REST requests carry the page language
 * from the widget (the page's lang attribute), which is mapped back to a
 * language code here.
 */
final class Multilingual {

	/**
	 * Languages for this request (null until read).
	 *
	 * @var array<int, array{code: string, locale: string, name: string}>|null
	 */
	private static ?array $languages = null;

	/**
	 * The active multilingual plugin.
	 *
	 * @return string 'polylang', 'wpml', or ''.
	 */
	public static function plugin(): string {
		// WPML first: Polylang never defines WPML's constant (it only mirrors some of its functions).
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'wpml';
		}
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_default_language' ) ) {
			return 'polylang';
		}

		return '';
	}

	/**
	 * Whether the site has more than one language.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return count( self::languages() ) > 1;
	}

	/**
	 * The site's languages, the main language first.
	 *
	 * @return array<int, array{code: string, locale: string, name: string}>
	 */
	public static function languages(): array {
		if ( null !== self::$languages ) {
			return self::$languages;
		}

		$languages = array();
		$main      = '';

		if ( 'polylang' === self::plugin() ) {
			$codes   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
			$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
			foreach ( array_values( $codes ) as $index => $code ) {
				$languages[] = self::language( $code, $locales[ $index ] ?? '', $names[ $index ] ?? '' );
			}
			$main = (string) pll_default_language( 'slug' );
		} elseif ( 'wpml' === self::plugin() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter.
			$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			foreach ( is_array( $list ) ? $list : array() as $item ) {
				if ( is_array( $item ) ) {
					$languages[] = self::language( $item['language_code'] ?? $item['code'] ?? '', $item['default_locale'] ?? '', $item['native_name'] ?? '' );
				}
			}
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter.
			$main = (string) apply_filters( 'wpml_default_language', null );
		}

		$languages = array_values(
			array_filter(
				$languages,
				static function ( array $language ): bool {
					return '' !== $language['code'];
				}
			)
		);

		if ( array() === $languages ) {
			$locale    = (string) get_locale();
			$languages = array( self::language( Language::primary( $locale ), $locale, '' ) );
			$main      = $languages[0]['code'];
		}

		// Main language first.
		usort(
			$languages,
			static function ( array $a, array $b ) use ( $main ): int {
				return (int) ( $b['code'] === $main ) - (int) ( $a['code'] === $main );
			}
		);

		self::$languages = $languages;

		return $languages;
	}

	/**
	 * Code of the main language.
	 *
	 * @return string
	 */
	public static function main(): string {
		return self::languages()[0]['code'];
	}

	/**
	 * Language of the page being rendered (the main language outside the front end).
	 *
	 * @return string
	 */
	public static function current(): string {
		$current = '';
		if ( 'polylang' === self::plugin() && function_exists( 'pll_current_language' ) ) {
			$current = (string) pll_current_language( 'slug' );
		} elseif ( 'wpml' === self::plugin() ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API filter.
			$current = (string) apply_filters( 'wpml_current_language', null );
		}

		return self::known( $current ) ? $current : self::main();
	}

	/**
	 * Language code for a page language tag sent by the widget ("bn-BD",
	 * "pt-br", "en"): an exact locale or code match first, then the same
	 * primary language, otherwise the main language.
	 *
	 * @param string $tag Language tag.
	 * @return string
	 */
	public static function from_tag( string $tag ): string {
		$tag = strtolower( str_replace( '_', '-', trim( $tag ) ) );
		if ( '' === $tag ) {
			return self::main();
		}

		foreach ( self::languages() as $language ) {
			if ( strtolower( str_replace( '_', '-', $language['locale'] ) ) === $tag || strtolower( $language['code'] ) === $tag ) {
				return $language['code'];
			}
		}

		$primary = Language::primary( $tag );
		foreach ( self::languages() as $language ) {
			if ( '' !== $primary && ( Language::primary( $language['locale'] ) === $primary || Language::primary( $language['code'] ) === $primary ) ) {
				return $language['code'];
			}
		}

		return self::main();
	}

	/**
	 * Whether a code is one of the site's languages.
	 *
	 * @param string $code Code.
	 * @return bool
	 */
	public static function known( string $code ): bool {
		return '' !== $code && in_array( $code, array_column( self::languages(), 'code' ), true );
	}

	/**
	 * Locale of a language ('' when unknown).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function locale( string $code ): string {
		foreach ( self::languages() as $language ) {
			if ( $language['code'] === $code ) {
				return $language['locale'];
			}
		}

		return '';
	}

	/**
	 * Forgets the languages read in this request (tests, language changes).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$languages = null;
	}

	/**
	 * One language entry.
	 *
	 * @param mixed $code   Code.
	 * @param mixed $locale Locale.
	 * @param mixed $name   Name.
	 * @return array{code: string, locale: string, name: string}
	 */
	private static function language( $code, $locale, $name ): array {
		$code   = is_string( $code ) ? strtolower( trim( $code ) ) : '';
		$code   = preg_match( '/^[a-z0-9_-]{1,20}$/', $code ) ? $code : '';
		$locale = is_string( $locale ) ? trim( $locale ) : '';
		$name   = is_string( $name ) && '' !== trim( $name ) ? wp_strip_all_tags( $name ) : ( '' !== $locale ? $locale : $code );

		return array(
			'code'   => $code,
			'locale' => $locale,
			'name'   => $name,
		);
	}
}
