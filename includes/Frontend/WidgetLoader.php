<?php
/**
 * Front-end widget loading.
 *
 * @package Chatlume
 */

namespace Chatlume\Frontend;

use Chatlume\Appearance\TokenResolver;
use Chatlume\Plugin;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the tiny widget launcher on front-end pages.
 *
 * The launcher (vanilla TypeScript, deferred) renders the floating button
 * from the inline configuration and loads the chat app on first click.
 * Nothing printed here is visitor-specific (no nonces or tokens), so pages
 * stay fully cacheable; visitors get a signed session from POST /session
 * at runtime.
 */
class WidgetLoader {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'chatlume-widget';

	/**
	 * Whether the inline configuration was already added.
	 *
	 * @var bool
	 */
	private static bool $configured = false;

	/**
	 * Hooks the loader.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_floating' ) );
	}

	/**
	 * Loads the launcher on pages where the floating widget should appear.
	 *
	 * @return void
	 */
	public function maybe_enqueue_floating(): void {
		if ( self::should_display() ) {
			self::enqueue( true );
		}
	}

	/**
	 * Whether the floating widget belongs on the current page.
	 *
	 * @return bool
	 */
	public static function should_display(): bool {
		$display = (bool) Settings::get( 'display.enabled', true )
			&& ! is_admin()
			&& ! is_feed()
			&& ! is_embed()
			&& ! is_robots()
			&& ! wp_is_json_request()
			&& self::is_built()
			&& DisplayRules::matches_request();

		/**
		 * Filters whether the floating chat widget is shown on the current request.
		 *
		 * The shortcode and block render regardless of this filter.
		 *
		 * @param bool $display Whether to show the floating widget.
		 */
		return (bool) apply_filters( 'chatlume_should_display_widget', $display );
	}

	/**
	 * Whether the widget bundle exists.
	 *
	 * @return bool
	 */
	public static function is_built(): bool {
		return is_readable( CHATLUME_PATH . 'build/widget/index.asset.php' );
	}

	/**
	 * Enqueues the launcher with its inline configuration (once per page).
	 *
	 * @param bool $floating Whether the floating launcher is wanted (an inline chat on the page hides it anyway).
	 * @return void
	 */
	public static function enqueue( bool $floating ): void {
		if ( ! self::register_script() ) {
			return;
		}

		if ( ! self::$configured ) {
			self::configure( self::inline_config( $floating ) );
			return;
		}

		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Prints the page configuration (once) and enqueues the launcher. The
	 * first configuration wins, so a draft preview is not replaced by an
	 * inline chat rendered later on the page.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @return void
	 */
	public static function configure( array $config ): void {
		if ( self::$configured || ! self::register_script() ) {
			return;
		}

		self::$configured = true;
		wp_add_inline_script(
			self::HANDLE,
			'window.chatlumeWidgetConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Registers the launcher script (without configuration). The admin loads
	 * it this way for previews: with no page configuration it shows no
	 * floating launcher and only provides `window.Chatlume.mountPreview()`.
	 *
	 * @return bool Whether the widget is built.
	 */
	public static function register_script(): bool {
		if ( ! self::is_built() ) {
			return false;
		}

		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			$asset = require CHATLUME_PATH . 'build/widget/index.asset.php';

			wp_register_script(
				self::HANDLE,
				CHATLUME_URL . 'build/widget/index.js',
				array(),
				isset( $asset['version'] ) ? (string) $asset['version'] : CHATLUME_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		return true;
	}

	/**
	 * Configuration printed with the launcher. Contains no secrets and
	 * nothing specific to the visitor.
	 *
	 * @param bool $floating Whether the floating launcher is enabled.
	 * @return array<string, mixed>
	 */
	public static function inline_config( bool $floating ): array {
		/**
		 * Filters the configuration printed with the widget launcher.
		 *
		 * @param array<string, mixed> $config Configuration.
		 */
		return (array) apply_filters(
			'chatlume_widget_config',
			array(
				'rest'      => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
				'assets'    => CHATLUME_URL . 'build/widget/',
				'version'   => CHATLUME_VERSION,
				'floating'  => $floating,
				'preload'   => (bool) Settings::get( 'display.preload', false ),
				'display'   => DisplayRules::client_config(),
				'streaming' => array(
					'enabled'           => (bool) Settings::get( 'streaming.enabled', true ),
					'firstTokenTimeout' => (int) Settings::get( 'streaming.first_token_timeout', 20 ),
				),
				'theme'     => TokenResolver::config(),
				'strings'   => WidgetStrings::all(),
			)
		);
	}
}
