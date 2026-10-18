<?php
/**
 * Widget texts per language.
 *
 * @package Chatlume
 */

namespace Chatlume\Frontend;

use Chatlume\Appearance\TokenResolver;
use Chatlume\Compat\Multilingual;
use Chatlume\Settings\Settings;
use Chatlume\Settings\SettingsSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every visitor-facing text, editable per language.
 *
 * Two kinds of text are stored per language code:
 * - "labels": the widget's interface labels (placeholder, lead form, …).
 *   An empty label uses WordPress's translation for that language.
 * - "content": translations of the admin's own texts, keyed by where the
 *   main-language text lives (e.g. "chatbot.welcome_message" or
 *   "theme.brand.headerTitle"). An empty translation uses the main
 *   language's text, and an empty main text uses the translated default.
 *
 * The language is the page's on the front end and the one the widget sends
 * with REST requests (see use_language()).
 */
final class WidgetTexts {

	/**
	 * Option with the texts (not autoloaded):
	 * code => {labels: {key: text}, content: {path: text}}.
	 */
	public const OPTION = 'chatlume_widget_texts';

	/**
	 * Longest interface label.
	 */
	public const LABEL_MAX = 200;

	/**
	 * Labels that are not edited here: the error and offline messages are
	 * the Chatbot page's texts, and the character counter is a number format.
	 */
	public const FIXED_LABELS = array( 'error', 'offline', 'charCount' );

	/**
	 * Translatable admin texts with a fixed path: path => [format, max length].
	 */
	private const CONTENT = array(
		'chatbot.bot_name'            => array( 'text', 60 ),
		'chatbot.welcome_message'     => array( 'rich', 1000 ),
		'chatbot.refusal_message'     => array( 'textarea', 500 ),
		'chatbot.offline_message'     => array( 'textarea', 500 ),
		'chatbot.error_message'       => array( 'textarea', 500 ),
		'chatbot.site_search_message' => array( 'textarea', 500 ),
		'display.triggers.message'    => array( 'text', 200 ),
		'leads.consent'               => array( 'rich', 300 ),
		'theme.launcher.label'        => array( 'text', 40 ),
		'theme.launcher.teaser'       => array( 'text', 140 ),
		'theme.brand.headerTitle'     => array( 'text', 60 ),
		'theme.brand.headerSubtitle'  => array( 'text', 80 ),
		'theme.brand.statusText'      => array( 'text', 40 ),
	);

	/**
	 * Language of this request (null: the page's).
	 *
	 * @var string|null
	 */
	private static ?string $language = null;

	/**
	 * Uses a language for the rest of the request: its stored texts, and its
	 * locale for the built-in translated texts.
	 *
	 * @param string $code Language code.
	 * @return void
	 */
	public static function use_language( string $code ): void {
		$code           = Multilingual::known( $code ) ? $code : Multilingual::main();
		self::$language = $code;

		$locale = Multilingual::locale( $code );
		if ( Multilingual::active() && '' !== $locale && function_exists( 'switch_to_locale' ) && determine_locale() !== $locale ) {
			switch_to_locale( $locale );
		}
	}

	/**
	 * Language of this request.
	 *
	 * @return string
	 */
	public static function language(): string {
		return self::$language ?? Multilingual::current();
	}

	/**
	 * Forgets the request language (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$language = null;
	}

	/**
	 * Interface labels in the request language.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = WidgetStrings::all();
		foreach ( self::stored( self::language() )['labels'] as $key => $text ) {
			if ( isset( $labels[ $key ] ) && '' !== $text && ! in_array( $key, self::FIXED_LABELS, true ) ) {
				$labels[ $key ] = $text;
			}
		}

		return $labels;
	}

	/**
	 * An admin text in the request language.
	 *
	 * @param string $path Where the main-language text lives.
	 * @param string $main Main-language text ('' when the default is used).
	 * @return string The translation, or the main-language text.
	 */
	public static function translate( string $path, string $main ): string {
		// Only texts the admin wrote are translated; defaults are translated by WordPress.
		if ( '' === trim( $main ) ) {
			return $main;
		}

		$code = self::language();
		if ( Multilingual::main() === $code ) {
			return $main;
		}

		$text = self::stored( $code )['content'][ $path ] ?? '';

		return '' !== $text ? $text : $main;
	}

	/**
	 * The widget theme with its texts in the request language. The version
	 * changes with the language, so cached copies never mix languages.
	 *
	 * @param array<string, mixed> $config Theme configuration.
	 * @return array<string, mixed>
	 */
	public static function theme( array $config ): array {
		$code = self::language();
		if ( Multilingual::main() === $code || ! isset( $config['ui'] ) || ! is_array( $config['ui'] ) ) {
			return $config;
		}

		foreach ( array( 'launcher.label', 'launcher.teaser', 'brand.headerTitle', 'brand.headerSubtitle', 'brand.statusText' ) as $path ) {
			list( $group, $key ) = explode( '.', $path );
			if ( isset( $config['ui'][ $group ][ $key ] ) && is_string( $config['ui'][ $group ][ $key ] ) ) {
				$config['ui'][ $group ][ $key ] = self::translate( 'theme.' . $path, $config['ui'][ $group ][ $key ] );
			}
		}
		// Still a short hex version, as the session route expects.
		$config['v'] = substr( md5( (string) ( $config['v'] ?? '' ) . '|' . $code ), 0, 12 );

		return $config;
	}

	/**
	 * Admin texts that can be translated, with their main-language text, in
	 * the order the page shows them.
	 *
	 * @return array<int, array{path: string, group: string, format: string, max: int, main: string}>
	 */
	public static function sources(): array {
		$sources = array();
		$add     = static function ( string $path, string $group, string $format, int $max, string $main ) use ( &$sources ): void {
			if ( '' !== trim( $main ) ) {
				$sources[] = compact( 'path', 'group', 'format', 'max', 'main' );
			}
		};

		$theme = TokenResolver::published_theme();
		foreach ( self::CONTENT as $path => $meta ) {
			if ( 0 === strpos( $path, 'theme.' ) ) {
				list( , $group, $key ) = explode( '.', $path );
				$main                  = (string) ( $theme[ $group ][ $key ] ?? '' );
				$section               = 'appearance';
			} else {
				$main    = (string) Settings::get( $path, '' );
				$section = strtok( $path, '.' );
			}
			$add( $path, (string) $section, $meta[0], $meta[1], $main );

			// Starter questions and quick actions follow the welcome message.
			if ( 'chatbot.welcome_message' === $path ) {
				foreach ( array_values( (array) Settings::get( 'chatbot.starter_questions', array() ) ) as $index => $question ) {
					$add( 'chatbot.starter_questions.' . $index, 'chatbot', 'text', 120, is_string( $question ) ? $question : '' );
				}
				foreach ( array_values( (array) Settings::get( 'chatbot.quick_actions', array() ) ) as $index => $action ) {
					$add( 'chatbot.quick_actions.' . $index . '.label', 'chatbot', 'text', 40, (string) ( $action['label'] ?? '' ) );
					if ( 'message' === ( $action['type'] ?? '' ) ) {
						$add( 'chatbot.quick_actions.' . $index . '.value', 'chatbot', 'text', 500, (string) ( $action['value'] ?? '' ) );
					}
				}
			}
		}

		return $sources;
	}

	/**
	 * Stored texts of a language.
	 *
	 * @param string $code Language code.
	 * @return array{labels: array<string, string>, content: array<string, string>}
	 */
	public static function stored( string $code ): array {
		$all  = get_option( self::OPTION, array() );
		$item = is_array( $all ) && isset( $all[ $code ] ) && is_array( $all[ $code ] ) ? $all[ $code ] : array();

		return array(
			'labels'  => self::strings( $item['labels'] ?? array() ),
			'content' => self::strings( $item['content'] ?? array() ),
		);
	}

	/**
	 * Replaces a language's texts. Unknown keys are dropped, every text is
	 * sanitized like the setting it belongs to, and empty texts are removed.
	 *
	 * @param string $code    Language code (one of the site's languages).
	 * @param mixed  $labels  Interface labels: key => text.
	 * @param mixed  $content Admin texts: path => text.
	 * @return array{labels: array<string, string>, content: array<string, string>} What was saved.
	 */
	public static function save( string $code, $labels, $content ): array {
		$known_labels = WidgetStrings::all();
		$clean        = array(
			'labels'  => array(),
			'content' => array(),
		);

		foreach ( is_array( $labels ) ? $labels : array() as $key => $text ) {
			if ( is_string( $key ) && isset( $known_labels[ $key ] ) && ! in_array( $key, self::FIXED_LABELS, true ) && is_scalar( $text ) ) {
				$text = SettingsSchema::sanitize_string(
					(string) $text,
					array(
						'format'    => 'text',
						'maxLength' => self::LABEL_MAX,
					)
				);
				if ( '' !== trim( $text ) ) {
					$clean['labels'][ $key ] = $text;
				}
			}
		}

		// Translations are only kept in other languages than the main one.
		if ( Multilingual::main() !== $code ) {
			$formats = array();
			foreach ( self::sources() as $source ) {
				$formats[ $source['path'] ] = array( $source['format'], $source['max'] );
			}
			foreach ( is_array( $content ) ? $content : array() as $path => $text ) {
				if ( is_string( $path ) && isset( $formats[ $path ] ) && is_scalar( $text ) ) {
					$text = SettingsSchema::sanitize_string(
						(string) $text,
						array(
							'format'    => $formats[ $path ][0],
							'maxLength' => $formats[ $path ][1],
						)
					);
					if ( '' !== trim( $text ) ) {
						$clean['content'][ $path ] = $text;
					}
				}
			}
		}

		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( array() === $clean['labels'] && array() === $clean['content'] ) {
			unset( $all[ $code ] );
		} else {
			$all[ $code ] = $clean;
		}
		update_option( self::OPTION, $all, false );

		/**
		 * Fires after the widget texts of a language change.
		 *
		 * @param string $code Language code.
		 */
		do_action( 'chatlume_widget_texts_saved', $code );

		return $clean;
	}

	/**
	 * Only string values with string keys.
	 *
	 * @param mixed $items Items.
	 * @return array<string, string>
	 */
	private static function strings( $items ): array {
		$strings = array();
		foreach ( is_array( $items ) ? $items : array() as $key => $text ) {
			if ( is_string( $key ) && is_string( $text ) ) {
				$strings[ $key ] = $text;
			}
		}

		return $strings;
	}
}
