<?php
/**
 * Widget theme schema, defaults, and validation.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every appearance setting lives in one versioned `theme` object. This class
 * defines its shape, the default theme, and sanitize(), which clamps every
 * value to a safe range so no setting can break the widget layout. The
 * admin's Zod schema (src/shared/theme) mirrors these rules.
 */
final class ThemeSchema {

	/**
	 * Current theme format version.
	 */
	public const VERSION = 1;

	/**
	 * Semantic color tokens, each defined for light and dark mode.
	 */
	public const COLOR_TOKENS = array(
		'primary',
		'onPrimary',
		'accent',
		'background',
		'surface',
		'headerBackground',
		'headerBackgroundEnd',
		'headerText',
		'botBubble',
		'botText',
		'userBubble',
		'userText',
		'link',
		'border',
		'inputBackground',
		'inputText',
		'launcherBackground',
		'launcherIcon',
		'sourceCard',
		'muted',
		'error',
		'success',
	);

	/**
	 * Built-in launcher icons.
	 */
	public const LAUNCHER_ICONS = array( 'chat', 'chat-dots', 'message', 'sparkle', 'help', 'support', 'headset', 'bot', 'smile', 'wave', 'lightbulb', 'heart' );

	/**
	 * Enumerated options: path => allowed values (first is the default).
	 */
	private const ENUMS = array(
		'mode'                => array( 'light', 'dark', 'auto' ),
		'header.style'        => array( 'solid', 'gradient', 'minimal' ),
		'layout.style'        => array( 'floating', 'panel', 'modal' ),
		'layout.position'     => array( 'right', 'left' ),
		'shape.preset'        => array( 'soft', 'sharp', 'rounded', 'pill' ),
		'shape.shadow'        => array( 'medium', 'none', 'soft', 'strong' ),
		'shape.density'       => array( 'comfortable', 'compact', 'spacious' ),
		'messages.style'      => array( 'bubbles', 'flat', 'outlined' ),
		'messages.timestamps' => array( 'hover', 'always', 'never' ),
		'background.type'     => array( 'solid', 'pattern', 'gradient' ),
		'background.pattern'  => array( 'dots', 'grid', 'waves', 'diagonal' ),
		'composer.style'      => array( 'rounded', 'bar' ),
		'composer.sendButton' => array( 'icon', 'text' ),
		'launcher.type'       => array( 'icon', 'avatar', 'image' ),
		'launcher.icon'       => self::LAUNCHER_ICONS,
		'launcher.shape'      => array( 'circle', 'square', 'pill' ),
		'launcher.size'       => array( 'm', 's', 'l' ),
		'launcher.attention'  => array( 'none', 'pulse', 'bounce', 'badge' ),
		'motion.open'         => array( 'scale', 'slide', 'none' ),
		'motion.speed'        => array( 'normal', 'fast', 'slow' ),
		'brand.avatarType'    => array( 'initials', 'image', 'illustration' ),
		'brand.illustration'  => array( 'bubbles', 'robot', 'spark', 'leaf', 'wave', 'planet' ),
	);

	/**
	 * Numeric options: path => [minimum, maximum, integer?].
	 */
	private const RANGES = array(
		'header.gradientAngle'     => array( 0, 360, true ),
		'typography.baseSize'      => array( 13, 18, true ),
		'typography.lineHeight'    => array( 1.2, 1.9, false ),
		'typography.titleWeight'   => array( 400, 800, true ),
		'typography.messageWeight' => array( 300, 600, true ),
		'typography.letterSpacing' => array( -0.05, 0.1, false ),
		'layout.offsetX'           => array( 0, 200, true ),
		'layout.offsetY'           => array( 0, 200, true ),
		'layout.mobileOffsetX'     => array( 0, 100, true ),
		'layout.mobileOffsetY'     => array( 0, 100, true ),
		'layout.zIndex'            => array( 1, 2147483647, true ),
		'layout.width'             => array( 300, 520, true ),
		'layout.height'            => array( 400, 820, true ),
		'layout.fullscreenBelow'   => array( 320, 1024, true ),
		'shape.radii.window'       => array( 0, 32, true ),
		'shape.radii.bubble'       => array( 0, 28, true ),
		'shape.radii.button'       => array( 0, 999, true ),
		'shape.radii.input'        => array( 0, 999, true ),
		'shape.radii.card'         => array( 0, 24, true ),
	);

	/**
	 * Free-text options: path => maximum length.
	 */
	private const TEXTS = array(
		'launcher.label'       => 40,
		'launcher.teaser'      => 140,
		'brand.headerTitle'    => 60,
		'brand.headerSubtitle' => 80,
		'brand.statusText'     => 40,
	);

	/**
	 * Image URL options (Media Library URLs).
	 */
	private const URLS = array( 'launcher.image', 'brand.avatarUrl', 'brand.logoUrl' );

	/**
	 * Boolean options.
	 */
	private const BOOLEANS = array(
		'layout.mobileFullscreen',
		'messages.avatar',
		'messages.animate',
		'background.glass',
		'brand.showLogo',
		'brand.showAvatar',
		'brand.showStatus',
		'brand.poweredBy',
	);

	/**
	 * The default theme: a calm indigo palette that passes WCAG AA in light
	 * and dark mode.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'    => self::VERSION,
			'mode'       => 'light',
			'colors'     => array(
				'light' => array(
					'primary'             => '#4f46e5',
					'onPrimary'           => '#ffffff',
					'accent'              => '#7c3aed',
					'background'          => '#ffffff',
					'surface'             => '#f6f7f9',
					'headerBackground'    => '#4f46e5',
					'headerBackgroundEnd' => '#7c3aed',
					'headerText'          => '#ffffff',
					'botBubble'           => '#f1f2f6',
					'botText'             => '#1f2330',
					'userBubble'          => '#4f46e5',
					'userText'            => '#ffffff',
					'link'                => '#4338ca',
					'border'              => '#e4e6eb',
					'inputBackground'     => '#ffffff',
					'inputText'           => '#1f2330',
					'launcherBackground'  => '#4f46e5',
					'launcherIcon'        => '#ffffff',
					'sourceCard'          => '#ffffff',
					'muted'               => '#5f6576',
					'error'               => '#c62828',
					'success'             => '#1b7f4b',
				),
				'dark'  => array(
					'primary'             => '#8b8cf8',
					'onPrimary'           => '#12122a',
					'accent'              => '#a78bfa',
					'background'          => '#16171d',
					'surface'             => '#1d1f27',
					'headerBackground'    => '#2a2b4a',
					'headerBackgroundEnd' => '#3b2d5c',
					'headerText'          => '#f4f4f8',
					'botBubble'           => '#262833',
					'botText'             => '#eceef3',
					'userBubble'          => '#5b5ef0',
					'userText'            => '#ffffff',
					'link'                => '#a5b4fc',
					'border'              => '#2e313d',
					'inputBackground'     => '#1d1f27',
					'inputText'           => '#eceef3',
					'launcherBackground'  => '#5b5ef0',
					'launcherIcon'        => '#ffffff',
					'sourceCard'          => '#1d1f27',
					'muted'               => '#a3a8b8',
					'error'               => '#ff8a80',
					'success'             => '#6fd49b',
				),
			),
			'header'     => array(
				'style'         => 'solid',
				'gradientAngle' => 135,
			),
			'typography' => array(
				'bodyFont'      => 'inherit',
				'headingFont'   => 'same',
				'baseSize'      => 15,
				'lineHeight'    => 1.5,
				'titleWeight'   => 600,
				'messageWeight' => 400,
				'letterSpacing' => 0.0,
			),
			'layout'     => array(
				'style'            => 'floating',
				'position'         => 'right',
				'offsetX'          => 20,
				'offsetY'          => 20,
				'mobileOffsetX'    => 12,
				'mobileOffsetY'    => 12,
				'zIndex'           => 99999,
				'width'            => 380,
				'height'           => 600,
				'mobileFullscreen' => true,
				'fullscreenBelow'  => 480,
			),
			'shape'      => array(
				'preset'  => 'soft',
				'radii'   => array(
					'window' => 16,
					'bubble' => 16,
					'button' => 999,
					'input'  => 999,
					'card'   => 12,
				),
				'shadow'  => 'medium',
				'density' => 'comfortable',
			),
			'messages'   => array(
				'style'      => 'bubbles',
				'avatar'     => true,
				'timestamps' => 'hover',
				'animate'    => true,
			),
			'background' => array(
				'type'    => 'solid',
				'pattern' => 'dots',
				'glass'   => false,
			),
			'composer'   => array(
				'style'      => 'rounded',
				'sendButton' => 'icon',
			),
			'launcher'   => array(
				'type'      => 'icon',
				'icon'      => 'chat',
				'image'     => '',
				'shape'     => 'circle',
				'size'      => 'm',
				'label'     => '',
				'attention' => 'none',
				'teaser'    => '',
			),
			'motion'     => array(
				'open'  => 'scale',
				'speed' => 'normal',
			),
			'brand'      => array(
				'avatarType'     => 'initials',
				'avatarUrl'      => '',
				'illustration'   => 'bubbles',
				'logoUrl'        => '',
				'showLogo'       => false,
				'headerTitle'    => '',
				'headerSubtitle' => '',
				'statusText'     => '',
				'showAvatar'     => true,
				'showStatus'     => true,
				'poweredBy'      => false,
			),
		);
	}

	/**
	 * Validates and clamps a (possibly partial or outdated) theme. Unknown
	 * keys are dropped; missing or invalid values fall back to defaults.
	 *
	 * @param mixed $theme Raw theme.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $theme ): array {
		$theme = is_array( $theme ) ? self::migrate( $theme ) : array();
		$clean = self::defaults();

		foreach ( array( 'light', 'dark' ) as $mode ) {
			foreach ( self::COLOR_TOKENS as $token ) {
				$value = self::get( $theme, "colors.{$mode}.{$token}" );
				$color = is_string( $value ) ? self::color( $value ) : '';
				if ( '' !== $color ) {
					$clean['colors'][ $mode ][ $token ] = $color;
				}
			}
		}

		foreach ( self::ENUMS as $path => $allowed ) {
			$value = self::get( $theme, $path );
			if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
				self::set( $clean, $path, $value );
			}
		}

		foreach ( self::RANGES as $path => $range ) {
			$value = self::get( $theme, $path );
			if ( is_numeric( $value ) ) {
				$number = max( $range[0], min( $range[1], (float) $value ) );
				self::set( $clean, $path, $range[2] ? (int) round( $number ) : round( $number, 3 ) );
			}
		}

		foreach ( self::TEXTS as $path => $max ) {
			$value = self::get( $theme, $path );
			if ( is_string( $value ) ) {
				self::set( $clean, $path, mb_substr( sanitize_text_field( $value ), 0, $max ) );
			}
		}

		foreach ( self::URLS as $path ) {
			$value = self::get( $theme, $path );
			if ( is_string( $value ) ) {
				self::set( $clean, $path, esc_url_raw( $value, array( 'http', 'https' ) ) );
			}
		}

		foreach ( self::BOOLEANS as $path ) {
			$value = self::get( $theme, $path );
			if ( null !== $value ) {
				self::set( $clean, $path, rest_sanitize_boolean( $value ) );
			}
		}

		// Fonts are keys from FontCatalog ("inherit", "system", "bundled:inter", "custom:…").
		$body = self::get( $theme, 'typography.bodyFont' );
		if ( is_string( $body ) && preg_match( FontCatalog::KEY_PATTERN, $body ) ) {
			$clean['typography']['bodyFont'] = $body;
		}
		$heading = self::get( $theme, 'typography.headingFont' );
		if ( is_string( $heading ) && ( 'same' === $heading || preg_match( FontCatalog::KEY_PATTERN, $heading ) ) ) {
			$clean['typography']['headingFont'] = $heading;
		}

		$clean['version'] = self::VERSION;

		return $clean;
	}

	/**
	 * Upgrades older theme formats step by step. Version 1 is the first.
	 *
	 * @param array<string, mixed> $theme Theme.
	 * @return array<string, mixed>
	 */
	public static function migrate( array $theme ): array {
		$theme['version'] = self::VERSION;

		return $theme;
	}

	/**
	 * Normalizes a hex color (#rgb, #rrggbb, or #rrggbbaa), or ''.
	 *
	 * @param string $value Color.
	 * @return string Lowercase hex color.
	 */
	public static function color( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $short ) ) {
			return '#' . $short[1][0] . $short[1][0] . $short[1][1] . $short[1][1] . $short[1][2] . $short[1][2];
		}

		return preg_match( '/^#([0-9a-f]{6}|[0-9a-f]{8})$/', $value ) ? $value : '';
	}

	/**
	 * Reads a dotted path.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $path Path.
	 * @return mixed
	 */
	private static function get( array $data, string $path ) {
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! is_array( $data ) || ! array_key_exists( $key, $data ) ) {
				return null;
			}
			$data = $data[ $key ];
		}

		return $data;
	}

	/**
	 * Writes a dotted path.
	 *
	 * @param array<string, mixed> $data  Data.
	 * @param string               $path  Path.
	 * @param mixed                $value Value.
	 * @return void
	 */
	private static function set( array &$data, string $path, $value ): void {
		$ref = &$data;
		foreach ( explode( '.', $path ) as $key ) {
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = $ref[ $key ] ?? array();
			}
			$ref = &$ref[ $key ];
		}
		$ref = $value;
	}
}
