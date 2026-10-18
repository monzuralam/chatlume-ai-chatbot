<?php
/**
 * Optimization-plugin compatibility.
 *
 * @package Chatlume
 */

namespace Chatlume\Compat;

use Chatlume\Frontend\WidgetLoader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the widget launcher and its page configuration out of JavaScript
 * combine, minify, defer, and delay features, so the chat button appears
 * right away and the configuration always runs before the launcher.
 *
 * Each plugin's own documented exclusion filter is used (WP Rocket,
 * LiteSpeed Cache, Autoptimize, Perfmatters, and SiteGround Optimizer). A
 * filter only runs when its plugin applies it, so registering them is
 * harmless when that plugin is not installed. The script tags also carry
 * the opt-out attributes that LiteSpeed Cache, Autoptimize, Cloudflare
 * Rocket Loader, and similar tools recognize, which covers plugins without
 * a filter. Without any exclusion the widget still works: the launcher
 * finds its lazy chunks through the address in its configuration.
 */
class OptimizationPlugins {

	/**
	 * Text found only in the inline page configuration.
	 */
	public const INLINE_MARKER = 'chatlumeWidgetConfig';

	/**
	 * Hooks the exclusions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_script_attributes', array( self::class, 'script_attributes' ) );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'inline_script_attributes' ), 10, 2 );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Other plugins' documented hooks.
		// WP Rocket: minify/combine (regex fragments of file paths), inline combine, and delay JavaScript.
		add_filter( 'rocket_exclude_js', array( self::class, 'rocket_files' ) );
		add_filter( 'rocket_excluded_inline_js_content', array( self::class, 'inline_markers' ) );
		add_filter( 'rocket_delay_js_exclusions', array( self::class, 'markers' ) );
		// LiteSpeed Cache: combine/minify, defer and delay, and guest mode.
		add_filter( 'litespeed_optimize_js_excludes', array( self::class, 'markers' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( self::class, 'markers' ) );
		add_filter( 'litespeed_optm_gm_js_exc', array( self::class, 'markers' ) );
		// Autoptimize: a comma-separated list, and files it must not minify again.
		add_filter( 'autoptimize_filter_js_exclude', array( self::class, 'comma_markers' ) );
		add_filter( 'autoptimize_filter_js_consider_minified', array( self::class, 'asset_markers' ) );
		// Perfmatters: delay, defer, and minify.
		add_filter( 'perfmatters_delay_js_exclusions', array( self::class, 'markers' ) );
		add_filter( 'perfmatters_defer_js_exclusions', array( self::class, 'markers' ) );
		add_filter( 'perfmatters_minify_js_exclusions', array( self::class, 'markers' ) );
		// SiteGround Optimizer: script handles, and inline content for combining.
		add_filter( 'sgo_js_minify_exclude', array( self::class, 'handles' ) );
		add_filter( 'sgo_javascript_combine_exclude', array( self::class, 'handles' ) );
		add_filter( 'sgo_js_async_exclude', array( self::class, 'handles' ) );
		add_filter( 'sgo_javascript_combine_excluded_inline_content', array( self::class, 'inline_markers' ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * The part of the widget's script addresses unique to Chatlume, e.g.
	 * "chatlume-ai-chatbot/build/widget/".
	 *
	 * @return string
	 */
	public static function asset_marker(): string {
		return dirname( CHATLUME_BASENAME ) . '/build/widget/';
	}

	/**
	 * Adds the asset and inline markers to a list.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function markers( $exclusions ) {
		return self::add( $exclusions, array( self::asset_marker(), self::INLINE_MARKER ) );
	}

	/**
	 * Adds the asset marker to a list (Autoptimize passes false for none).
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function asset_markers( $exclusions ) {
		return self::add( false === $exclusions ? array() : $exclusions, array( self::asset_marker() ) );
	}

	/**
	 * Adds the inline marker to a list.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function inline_markers( $exclusions ) {
		return self::add( $exclusions, array( self::INLINE_MARKER ) );
	}

	/**
	 * Adds the widget's file paths, as a regex fragment, to WP Rocket's list.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function rocket_files( $exclusions ) {
		$path = (string) wp_parse_url( CHATLUME_URL . 'build/widget/', PHP_URL_PATH );

		return self::add( $exclusions, array( $path . '(.*).js' ) );
	}

	/**
	 * Adds the launcher's handle to a list of handles.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function handles( $exclusions ) {
		return self::add( $exclusions, array( WidgetLoader::HANDLE ) );
	}

	/**
	 * Adds the markers to a comma-separated list.
	 *
	 * @param mixed $exclusions Existing exclusions.
	 * @return mixed
	 */
	public static function comma_markers( $exclusions ) {
		if ( ! is_string( $exclusions ) ) {
			return $exclusions;
		}

		$items = array_filter( array_map( 'trim', explode( ',', $exclusions ) ), 'strlen' );

		return implode( ', ', self::add( $items, array( self::asset_marker(), self::INLINE_MARKER ) ) );
	}

	/**
	 * Opt-out attributes for the launcher script tag.
	 *
	 * @param mixed $attributes Attributes.
	 * @return mixed
	 */
	public static function script_attributes( $attributes ) {
		if ( is_array( $attributes ) && WidgetLoader::HANDLE . '-js' === ( $attributes['id'] ?? '' ) ) {
			$attributes += self::opt_out();
		}

		return $attributes;
	}

	/**
	 * Opt-out attributes for the inline configuration before the launcher.
	 *
	 * @param mixed $attributes Attributes.
	 * @param mixed $data       Script content.
	 * @return mixed
	 */
	public static function inline_script_attributes( $attributes, $data = '' ) {
		unset( $data );
		if ( is_array( $attributes ) && WidgetLoader::HANDLE . '-js-before' === ( $attributes['id'] ?? '' ) ) {
			$attributes += self::opt_out();
		}

		return $attributes;
	}

	/**
	 * Attributes that ask optimizers to leave a script alone:
	 * LiteSpeed Cache (data-no-optimize, data-no-defer), Autoptimize
	 * (data-noptimize), and Cloudflare Rocket Loader (data-cfasync).
	 *
	 * @return array<string, string>
	 */
	private static function opt_out(): array {
		return array(
			'data-no-optimize' => '1',
			'data-no-defer'    => '1',
			'data-noptimize'   => '1',
			'data-cfasync'     => 'false',
		);
	}

	/**
	 * Appends items to an exclusion list without duplicates. Lists that are
	 * not arrays (an unexpected type from another plugin) are left alone.
	 *
	 * @param mixed    $exclusions Existing list.
	 * @param string[] $items Items.
	 * @return mixed
	 */
	private static function add( $exclusions, array $items ) {
		if ( null === $exclusions || '' === $exclusions ) {
			$exclusions = array();
		}
		if ( ! is_array( $exclusions ) ) {
			return $exclusions;
		}

		foreach ( $items as $item ) {
			if ( ! in_array( $item, $exclusions, true ) ) {
				$exclusions[] = $item;
			}
		}

		return $exclusions;
	}
}
