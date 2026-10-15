<?php
/**
 * The site's own branding.
 *
 * @package Chatlume
 */

namespace Chatlume\Appearance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What "Match my website" starts from: the site icon and custom logo, the
 * theme.json color palette, and the theme's fonts. Classic themes without
 * theme.json are sampled in the browser instead (computed styles of the
 * home page).
 */
final class SiteBrand {

	/**
	 * Branding of the active site and theme.
	 *
	 * @return array{site_name: string, site_icon: string, logo: string, palette: array<int, array{slug: string, name: string, color: string}>, fonts: string[], block_theme: bool}
	 */
	public static function detect(): array {
		$logo_id = (int) get_theme_mod( 'custom_logo', 0 );
		$logo    = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';

		$fonts = array();
		foreach ( FontCatalog::options() as $key => $option ) {
			if ( 'theme' === $option['group'] ) {
				$fonts[] = $key;
			}
		}

		return array(
			'site_name'   => (string) get_bloginfo( 'name' ),
			'site_icon'   => has_site_icon() ? (string) get_site_icon_url( 192 ) : '',
			'logo'        => is_string( $logo ) ? esc_url_raw( $logo ) : '',
			'palette'     => self::palette(),
			'fonts'       => $fonts,
			'block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		);
	}

	/**
	 * The theme's color palette (hex colors only).
	 *
	 * @return array<int, array{slug: string, name: string, color: string}>
	 */
	public static function palette(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		$colors  = array();
		foreach ( (array) ( $palette['theme'] ?? array() ) as $entry ) {
			$color = is_array( $entry ) ? ThemeSchema::color( (string) ( $entry['color'] ?? '' ) ) : '';
			if ( '' !== $color ) {
				$colors[] = array(
					'slug'  => sanitize_key( (string) ( $entry['slug'] ?? '' ) ),
					'name'  => sanitize_text_field( (string) ( $entry['name'] ?? '' ) ),
					'color' => substr( $color, 0, 7 ),
				);
			}
		}

		return $colors;
	}
}
