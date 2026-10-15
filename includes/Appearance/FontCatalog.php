<?php
/**
 * Fonts the widget can use.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every font choice for the Customizer, each with a ready CSS stack and
 * the faces the widget must register:
 *
 * - basic: inherit from the site, or a system stack;
 * - bundled: open-license variable fonts in assets/fonts (manifest.json);
 * - theme: font families from the active theme's theme.json;
 * - library: fonts installed with the WordPress Font Library;
 * - custom: woff2 files uploaded in the Customizer.
 *
 * Every stack ends with fallbacks covering scripts many fonts lack
 * (Bengali, Arabic, CJK), so text never renders as boxes. Keys are stored in
 * the theme; a key whose font has gone falls back to the system stack.
 */
final class FontCatalog {

	/**
	 * Uploaded fonts (non-autoloaded option).
	 */
	public const CUSTOM_OPTION = 'chatlume_custom_fonts';

	/**
	 * Folder in uploads for uploaded fonts.
	 */
	public const UPLOAD_DIR = 'chatlume-fonts';

	/**
	 * Valid font keys.
	 */
	public const KEY_PATTERN = '/^(inherit|system|system-serif|system-rounded|mono|bundled:[a-z0-9-]{1,40}|theme:[a-z0-9-]{1,80}|library:\d{1,20}|custom:[a-f0-9]{12})$/';

	/**
	 * System stacks with script fallbacks.
	 */
	public const SYSTEM_STACKS = array(
		'inherit'        => 'inherit',
		'system'         => 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", "Helvetica Neue", Arial, sans-serif',
		'system-serif'   => 'ui-serif, Georgia, Cambria, "Noto Serif", "Noto Serif Bengali", "Noto Naskh Arabic", "Noto Serif CJK SC", "Times New Roman", serif',
		'system-rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", system-ui, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", sans-serif',
		'mono'           => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
	);

	/**
	 * Scripts by language code (for the "script support" note).
	 */
	private const SCRIPTS = array(
		'bn' => 'bengali',
		'as' => 'bengali',
		'ar' => 'arabic',
		'fa' => 'arabic',
		'ur' => 'arabic',
		'ps' => 'arabic',
		'ru' => 'cyrillic',
		'uk' => 'cyrillic',
		'bg' => 'cyrillic',
		'sr' => 'cyrillic',
		'mk' => 'cyrillic',
		'kk' => 'cyrillic',
		'el' => 'greek',
		'vi' => 'vietnamese',
		'hi' => 'devanagari',
		'mr' => 'devanagari',
		'ne' => 'devanagari',
		'zh' => 'cjk',
		'ja' => 'cjk',
		'ko' => 'cjk',
		'th' => 'thai',
		'he' => 'hebrew',
	);

	/**
	 * Options cached per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $options = null;

	/**
	 * Every font choice, keyed by font key.
	 *
	 * @return array<string, array{key: string, label: string, group: string, stack: string, faces: array<int, array<string, string>>, scripts: string[]|null}>
	 */
	public static function options(): array {
		if ( null === self::$options ) {
			self::$options = array_merge( self::basic(), self::bundled(), self::theme(), self::library(), self::custom() );
		}

		return self::$options;
	}

	/**
	 * Forgets cached options (after an upload or deletion).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$options = null;
	}

	/**
	 * Stack and faces for a key; unknown keys use the system stack.
	 *
	 * @param string $key Font key.
	 * @return array{stack: string, faces: array<int, array<string, string>>}
	 */
	public static function resolve( string $key ): array {
		$options = self::options();
		$option  = $options[ $key ] ?? $options['system'];

		return array(
			'stack' => $option['stack'],
			'faces' => $option['faces'],
		);
	}

	/**
	 * Script of the site language, e.g. "bengali", or "latin".
	 *
	 * @return string
	 */
	public static function site_script(): string {
		$language = strtolower( substr( (string) get_locale(), 0, 2 ) );

		return self::SCRIPTS[ $language ] ?? 'latin';
	}

	/**
	 * Inherit and the system stacks.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function basic(): array {
		$labels = array(
			'inherit'        => __( 'Same as my site', 'chatlume-ai-chatbot' ),
			'system'         => __( 'System sans-serif', 'chatlume-ai-chatbot' ),
			'system-serif'   => __( 'System serif', 'chatlume-ai-chatbot' ),
			'system-rounded' => __( 'System rounded', 'chatlume-ai-chatbot' ),
			'mono'           => __( 'Monospace', 'chatlume-ai-chatbot' ),
		);

		$options = array();
		foreach ( self::SYSTEM_STACKS as $key => $stack ) {
			$options[ $key ] = self::option( $key, $labels[ $key ], 'basic', $stack, array(), null );
		}

		return $options;
	}

	/**
	 * Bundled open-license fonts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function bundled(): array {
		$file = CHATLUME_PATH . 'assets/fonts/manifest.json';
		// Local, read-only plugin data file; no WP_Filesystem needed for reading.
		$json     = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$manifest = json_decode( (string) $json, true );

		$options = array();
		foreach ( is_array( $manifest ) ? $manifest : array() as $font ) {
			if ( ! is_array( $font ) || empty( $font['slug'] ) || empty( $font['faces'] ) ) {
				continue;
			}
			// A namespaced family never clashes with a font of the same name on the site.
			$family = 'Chatlume ' . $font['name'];
			$faces  = array();
			foreach ( (array) $font['faces'] as $face ) {
				$faces[] = array(
					'family'       => $family,
					'src'          => CHATLUME_URL . 'assets/fonts/' . $face['file'],
					'weight'       => (string) $face['weight'],
					'style'        => 'normal',
					'unicodeRange' => (string) ( $face['unicodeRange'] ?? '' ),
				);
			}
			$fallback = 'serif' === ( $font['category'] ?? '' ) ? 'system-serif' : 'system';
			$key      = 'bundled:' . $font['slug'];

			$options[ $key ] = self::option( $key, (string) $font['name'], 'bundled', '"' . $family . '", ' . self::SYSTEM_STACKS[ $fallback ], $faces, array_values( (array) $font['scripts'] ) );
		}

		return $options;
	}

	/**
	 * Font families of the active theme (theme.json).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function theme(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$faces    = self::theme_faces();
		$options  = array();

		foreach ( (array) ( $families['theme'] ?? array() ) as $family ) {
			if ( ! is_array( $family ) || empty( $family['slug'] ) || empty( $family['fontFamily'] ) ) {
				continue;
			}
			$slug  = sanitize_key( (string) $family['slug'] );
			$name  = trim( explode( ',', (string) $family['fontFamily'] )[0], " \"'" );
			$stack = self::css_stack( (string) $family['fontFamily'] );
			if ( '' === $slug || '' === $stack ) {
				continue;
			}
			$key             = 'theme:' . $slug;
			$options[ $key ] = self::option( $key, (string) ( $family['name'] ?? $name ), 'theme', $stack . ', ' . self::SYSTEM_STACKS['system'], $faces[ strtolower( $name ) ] ?? array(), null );
		}

		return $options;
	}

	/**
	 * Faces of theme fonts with resolved URLs, by lowercase family name.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	private static function theme_faces(): array {
		if ( ! class_exists( '\WP_Font_Face_Resolver' ) ) {
			return array();
		}

		$faces = array();
		foreach ( \WP_Font_Face_Resolver::get_fonts_from_theme_json() as $family ) {
			foreach ( (array) $family as $face ) {
				$src = self::woff2_url( (array) ( $face['src'] ?? array() ) );
				if ( '' === $src || empty( $face['font-family'] ) ) {
					continue;
				}
				$name                           = (string) $face['font-family'];
				$faces[ strtolower( $name ) ][] = array(
					'family'       => $name,
					'src'          => $src,
					'weight'       => (string) ( $face['font-weight'] ?? '400' ),
					'style'        => (string) ( $face['font-style'] ?? 'normal' ),
					'unicodeRange' => (string) ( $face['unicode-range'] ?? '' ),
				);
			}
		}

		return $faces;
	}

	/**
	 * Fonts installed with the WordPress Font Library.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function library(): array {
		if ( ! post_type_exists( 'wp_font_family' ) ) {
			return array();
		}

		$options = array();
		foreach ( get_posts(
			array(
				'post_type'      => 'wp_font_family',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		) as $post ) {
			$settings = json_decode( (string) $post->post_content, true );
			$family   = is_array( $settings ) ? (string) ( $settings['fontFamily'] ?? '' ) : '';
			$name     = trim( explode( ',', $family )[0], " \"'" );
			$stack    = self::css_stack( $family );
			if ( '' === $name || '' === $stack ) {
				continue;
			}

			$faces = array();
			foreach ( get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => 'wp_font_face',
					'post_status' => 'publish',
					'numberposts' => 50,
				)
			) as $child ) {
				$face = json_decode( (string) $child->post_content, true );
				$src  = is_array( $face ) ? self::woff2_url( (array) ( $face['src'] ?? array() ) ) : '';
				if ( '' !== $src ) {
					$faces[] = array(
						'family'       => $name,
						'src'          => $src,
						'weight'       => (string) ( $face['fontWeight'] ?? '400' ),
						'style'        => (string) ( $face['fontStyle'] ?? 'normal' ),
						'unicodeRange' => (string) ( $face['unicodeRange'] ?? '' ),
					);
				}
			}

			$key             = 'library:' . $post->ID;
			$options[ $key ] = self::option( $key, (string) ( $settings['name'] ?? $name ), 'library', $stack . ', ' . self::SYSTEM_STACKS['system'], $faces, null );
		}

		return $options;
	}

	/**
	 * Uploaded woff2 fonts.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function custom(): array {
		$options = array();
		foreach ( self::uploaded() as $font ) {
			$family          = 'Chatlume Custom ' . $font['id'];
			$key             = 'custom:' . $font['id'];
			$options[ $key ] = self::option(
				$key,
				$font['name'],
				'custom',
				'"' . $family . '", ' . self::SYSTEM_STACKS['system'],
				array(
					array(
						'family'       => $family,
						'src'          => $font['url'],
						'weight'       => $font['weight'],
						'style'        => $font['style'],
						'unicodeRange' => '',
					),
				),
				null
			);
		}

		return $options;
	}

	/**
	 * Stored uploads.
	 *
	 * @return array<int, array{id: string, name: string, file: string, url: string, weight: string, style: string}>
	 */
	public static function uploaded(): array {
		$stored = get_option( self::CUSTOM_OPTION, array() );
		$fonts  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $font ) {
			if ( is_array( $font ) && isset( $font['id'], $font['url'] ) && preg_match( '/^[a-f0-9]{12}$/', (string) $font['id'] ) ) {
				$fonts[] = array(
					'id'     => (string) $font['id'],
					'name'   => (string) ( $font['name'] ?? '' ),
					'file'   => (string) ( $font['file'] ?? '' ),
					'url'    => esc_url_raw( (string) $font['url'] ),
					'weight' => (string) ( $font['weight'] ?? '400' ),
					'style'  => 'italic' === ( $font['style'] ?? '' ) ? 'italic' : 'normal',
				);
			}
		}

		return $fonts;
	}

	/**
	 * The first woff2 URL of a font face source list.
	 *
	 * @param array<int, mixed> $sources Sources.
	 * @return string
	 */
	private static function woff2_url( array $sources ): string {
		foreach ( $sources as $source ) {
			$url = is_string( $source ) ? esc_url_raw( $source, array( 'http', 'https' ) ) : '';
			if ( '' !== $url && str_ends_with( strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '.woff2' ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * A CSS font-family list with only safe characters.
	 *
	 * @param string $family CSS font-family value.
	 * @return string
	 */
	private static function css_stack( string $family ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N} ,"\'._-]/u', '', $family ) );
	}

	/**
	 * One option.
	 *
	 * @param string                           $key     Key.
	 * @param string                           $label   Label.
	 * @param string                           $group   Group.
	 * @param string                           $stack   CSS stack.
	 * @param array<int, array<string,string>> $faces   Faces.
	 * @param string[]|null                    $scripts Supported scripts, when known.
	 * @return array<string, mixed>
	 */
	private static function option( string $key, string $label, string $group, string $stack, array $faces, ?array $scripts ): array {
		return array(
			'key'     => $key,
			'label'   => $label,
			'group'   => $group,
			'stack'   => $stack,
			'faces'   => $faces,
			'scripts' => $scripts,
		);
	}
}
