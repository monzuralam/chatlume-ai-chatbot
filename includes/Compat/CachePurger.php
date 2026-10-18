<?php
/**
 * Page cache purging.
 *
 * @package Chatlume
 */

namespace Chatlume\Compat;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears the page cache of popular caching plugins when something printed
 * into pages changes: a newly published widget look, or settings the
 * launcher's page configuration is built from. Each plugin is called
 * through its own public API, and only when that API exists. Visitor data
 * never needs this: it is loaded at runtime from the REST API.
 */
class CachePurger {

	/**
	 * Settings sections that end up in the page (launcher configuration,
	 * display rules, and widget texts).
	 */
	public const PAGE_SECTIONS = array( 'display', 'streaming', 'integrations', 'chatbot' );

	/**
	 * Option with the last purge: {at, plugins}.
	 */
	public const LAST_OPTION = 'chatlume_cache_purge';

	/**
	 * Plugins purged in this request (null: none yet), so one request
	 * clears each cache only once.
	 *
	 * @var string[]|null
	 */
	private static ?array $purged = null;

	/**
	 * Hooks the purge triggers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'chatlume_appearance_published', array( self::class, 'purge' ) );
		// Labels and translated texts are printed into pages too.
		add_action( 'chatlume_widget_texts_saved', array( self::class, 'purge' ) );
		add_action( 'update_option_' . Settings::OPTION, array( self::class, 'on_settings_change' ), 10, 2 );
	}

	/**
	 * Purges when a section printed into pages changed.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public static function on_settings_change( $old_value, $value ): void {
		if ( self::page_settings_changed( $old_value, $value ) ) {
			self::purge();
		}
	}

	/**
	 * Whether a section printed into pages differs.
	 *
	 * @param mixed $old_value Old settings.
	 * @param mixed $value     New settings.
	 * @return bool
	 */
	public static function page_settings_changed( $old_value, $value ): bool {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$value     = is_array( $value ) ? $value : array();

		foreach ( self::PAGE_SECTIONS as $section ) {
			if ( ( $old_value[ $section ] ?? null ) !== ( $value[ $section ] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clears the page cache of every active caching plugin (once per request).
	 *
	 * @return string[] Names of the plugins whose cache was cleared.
	 */
	public static function purge(): array {
		if ( null !== self::$purged ) {
			return self::$purged;
		}

		$purged = array();

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'WP Rocket';
		}
		if ( defined( 'LSCWP_V' ) ) {
			// LiteSpeed Cache's public API action.
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Another plugin's hook.
			$purged[] = 'LiteSpeed Cache';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$purged[] = 'W3 Total Cache';
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache( get_current_blog_id() );
			$purged[] = 'WP Super Cache';
		}
		if ( class_exists( 'WpFastestCache', false ) ) {
			// WP Fastest Cache's public API action.
			do_action( 'wpfc_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Another plugin's hook.
			$purged[] = 'WP Fastest Cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'SiteGround Optimizer';
		}

		/**
		 * Fires after Chatlume cleared the page caches it knows, so hosts and
		 * other caching plugins can clear theirs.
		 *
		 * @param string[] $purged Names of the plugins whose cache was cleared.
		 */
		do_action( 'chatlume_page_cache_purged', $purged );

		if ( array() !== $purged ) {
			update_option(
				self::LAST_OPTION,
				array(
					'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'plugins' => $purged,
				),
				false
			);
		}

		self::$purged = $purged;

		return $purged;
	}

	/**
	 * What happened to caches in this request, for the admin.
	 *
	 * @return array{purged: string[], cdn: string}
	 */
	public static function report(): array {
		return array(
			'purged' => self::$purged ?? array(),
			'cdn'    => self::cdn(),
		);
	}

	/**
	 * A CDN that may cache pages and that Chatlume cannot purge ('' if none
	 * is detected).
	 *
	 * @return string
	 */
	public static function cdn(): string {
		$cdn = '';
		if (
			defined( 'CLOUDFLARE_PLUGIN_DIR' )
			|| isset( $_SERVER['HTTP_CF_RAY'] )
			|| 'cloudflare' === Settings::get( 'security.trusted_proxy', 'none' )
		) {
			$cdn = 'Cloudflare';
		}

		/**
		 * Filters the name of the CDN detected in front of the site.
		 *
		 * @param string $cdn CDN name, or '' for none.
		 */
		return (string) apply_filters( 'chatlume_detected_cdn', $cdn );
	}

	/**
	 * Forgets this request's purge (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$purged = null;
	}
}
