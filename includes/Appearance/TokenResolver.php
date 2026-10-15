<?php
/**
 * Theme to widget configuration.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a theme into what the widget needs on first paint:
 * flat CSS custom properties for light and dark mode, mode-independent
 * variables (sizes, radii, fonts, motion), and a few rendering options.
 *
 * The result is stored in the autoloaded `chatlume_widget_config` option
 * (kept small, about 3 KB) and printed with the launcher, so the widget
 * never flashes default styles. A version hash lets the widget detect
 * when its cached copy is stale.
 */
final class TokenResolver {

	/**
	 * Autoloaded option holding the resolved configuration.
	 */
	public const OPTION = 'chatlume_widget_config';

	/**
	 * Option holding the published theme (not autoloaded).
	 */
	public const THEME_OPTION = 'chatlume_theme';

	/**
	 * Font stacks with fallbacks covering scripts many fonts lack
	 * (Bengali, Arabic, CJK) so text never renders as boxes.
	 */
	private const FONT_STACKS = array(
		'inherit'        => 'inherit',
		'system'         => 'system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", "Helvetica Neue", Arial, sans-serif',
		'system-serif'   => 'ui-serif, Georgia, Cambria, "Noto Serif", "Noto Serif Bengali", "Noto Naskh Arabic", "Noto Serif CJK SC", "Times New Roman", serif',
		'system-rounded' => 'ui-rounded, "SF Pro Rounded", "Nunito", system-ui, "Noto Sans", "Noto Sans Bengali", "Noto Sans Arabic", "Noto Sans CJK SC", sans-serif',
		'mono'           => 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
	);

	/**
	 * Shadow presets.
	 */
	private const SHADOWS = array(
		'none'   => 'none',
		'soft'   => '0 4px 16px rgba(15, 20, 40, 0.08)',
		'medium' => '0 12px 32px rgba(15, 20, 40, 0.14), 0 2px 6px rgba(15, 20, 40, 0.08)',
		'strong' => '0 24px 64px rgba(15, 20, 40, 0.24), 0 4px 12px rgba(15, 20, 40, 0.12)',
	);

	/**
	 * Base spacing per density (px).
	 */
	private const DENSITY = array(
		'compact'     => 8,
		'comfortable' => 12,
		'spacious'    => 16,
	);

	/**
	 * Animation duration per speed (ms).
	 */
	private const SPEED = array(
		'fast'   => 140,
		'normal' => 200,
		'slow'   => 320,
	);

	/**
	 * Launcher sizes (px).
	 */
	private const LAUNCHER_SIZE = array(
		's' => 52,
		'm' => 58,
		'l' => 66,
	);

	/**
	 * The published theme (defaults when none is saved).
	 *
	 * @return array<string, mixed>
	 */
	public static function published_theme(): array {
		return ThemeSchema::sanitize( get_option( self::THEME_OPTION, array() ) );
	}

	/**
	 * Saves a theme as published and rebuilds the widget configuration.
	 *
	 * @param array<string, mixed> $theme Theme.
	 * @return array<string, mixed> The stored configuration.
	 */
	public static function publish( array $theme ): array {
		$theme = ThemeSchema::sanitize( $theme );
		update_option( self::THEME_OPTION, $theme, false );

		return self::rebuild();
	}

	/**
	 * Rebuilds `chatlume_widget_config` from the published theme.
	 *
	 * @return array<string, mixed>
	 */
	public static function rebuild(): array {
		$config = self::resolve( self::published_theme() );

		// Autoloaded on purpose: the launcher needs it on every front-end page.
		update_option( self::OPTION, $config, true );

		return $config;
	}

	/**
	 * The stored configuration, rebuilt if missing or outdated.
	 *
	 * @return array<string, mixed>
	 */
	public static function config(): array {
		$config = get_option( self::OPTION, array() );

		if ( ! is_array( $config ) || ( $config['schema'] ?? 0 ) !== ThemeSchema::VERSION || empty( $config['v'] ) ) {
			return self::rebuild();
		}

		return $config;
	}

	/**
	 * Resolves a theme into the widget configuration.
	 *
	 * @param array<string, mixed> $theme Sanitized theme.
	 * @return array<string, mixed>
	 */
	public static function resolve( array $theme ): array {
		$light = array();
		$dark  = array();

		foreach ( ThemeSchema::COLOR_TOKENS as $token ) {
			$name           = '--chatlume-' . self::kebab( $token );
			$light[ $name ] = $theme['colors']['light'][ $token ];
			$dark[ $name ]  = $theme['colors']['dark'][ $token ];
		}

		$angle = (int) $theme['header']['gradientAngle'];
		foreach ( array(
			'light' => &$light,
			'dark'  => &$dark,
		) as $mode => &$tokens ) {
			$colors = $theme['colors'][ $mode ];
			// The header background is one value whatever the header style, so styles switch without re-rendering.
			$tokens['--chatlume-header-fill'] = 'gradient' === $theme['header']['style']
				? sprintf( 'linear-gradient(%ddeg, %s, %s)', $angle, $colors['headerBackground'], $colors['headerBackgroundEnd'] )
				: ( 'minimal' === $theme['header']['style'] ? $colors['background'] : $colors['headerBackground'] );
			$tokens['--chatlume-header-ink']  = 'minimal' === $theme['header']['style'] ? $colors['botText'] : $colors['headerText'];
		}
		unset( $tokens );

		$type    = $theme['typography'];
		$body    = self::FONT_STACKS[ $type['bodyFont'] ] ?? 'inherit';
		$heading = 'same' === $type['headingFont'] ? $body : ( self::FONT_STACKS[ $type['headingFont'] ] ?? $body );
		$layout  = $theme['layout'];
		$radii   = $theme['shape']['radii'];

		$vars = array(
			'--chatlume-font'            => $body,
			'--chatlume-font-heading'    => $heading,
			'--chatlume-font-size'       => $type['baseSize'] . 'px',
			'--chatlume-line-height'     => (string) $type['lineHeight'],
			'--chatlume-title-weight'    => (string) $type['titleWeight'],
			'--chatlume-message-weight'  => (string) $type['messageWeight'],
			'--chatlume-letter-spacing'  => $type['letterSpacing'] . 'em',
			'--chatlume-width'           => $layout['width'] . 'px',
			'--chatlume-height'          => $layout['height'] . 'px',
			'--chatlume-offset-x'        => $layout['offsetX'] . 'px',
			'--chatlume-offset-y'        => $layout['offsetY'] . 'px',
			'--chatlume-mobile-offset-x' => $layout['mobileOffsetX'] . 'px',
			'--chatlume-mobile-offset-y' => $layout['mobileOffsetY'] . 'px',
			'--chatlume-z'               => (string) $layout['zIndex'],
			'--chatlume-radius-window'   => $radii['window'] . 'px',
			'--chatlume-radius-bubble'   => $radii['bubble'] . 'px',
			'--chatlume-radius-button'   => $radii['button'] . 'px',
			'--chatlume-radius-input'    => $radii['input'] . 'px',
			'--chatlume-radius-card'     => $radii['card'] . 'px',
			'--chatlume-shadow'          => self::SHADOWS[ $theme['shape']['shadow'] ],
			'--chatlume-space'           => self::DENSITY[ $theme['shape']['density'] ] . 'px',
			'--chatlume-duration'        => self::SPEED[ $theme['motion']['speed'] ] . 'ms',
			'--chatlume-launcher-size'   => self::LAUNCHER_SIZE[ $theme['launcher']['size'] ] . 'px',
		);

		$ui = array(
			'mode'             => $theme['mode'],
			'style'            => $layout['style'],
			'position'         => $layout['position'],
			'mobileFullscreen' => $layout['mobileFullscreen'],
			'fullscreenBelow'  => $layout['fullscreenBelow'],
			'header'           => $theme['header']['style'],
			'messages'         => $theme['messages'],
			'background'       => $theme['background'],
			'composer'         => $theme['composer'],
			'launcher'         => $theme['launcher'],
			'motion'           => $theme['motion']['open'],
			'brand'            => $theme['brand'],
		);

		$config = array(
			'schema' => ThemeSchema::VERSION,
			'light'  => $light,
			'dark'   => $dark,
			'vars'   => $vars,
			'ui'     => $ui,
		);

		// Changes whenever anything visible changes; the widget compares it with its cached copy.
		$config['v'] = substr( md5( (string) wp_json_encode( $config ) ), 0, 12 );

		return $config;
	}

	/**
	 * camelCase → kebab-case.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function kebab( string $name ): string {
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '-$0', $name ) );
	}
}
