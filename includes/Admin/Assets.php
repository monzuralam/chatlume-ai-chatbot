<?php
/**
 * Admin app assets.
 *
 * @package Chatlume
 */

namespace Chatlume\Admin;

use Chatlume\Frontend\WidgetLoader;
use Chatlume\Plugin;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the admin React app on the Chatlume screen only.
 */
class Assets {

	/**
	 * Script and style handle.
	 */
	public const HANDLE = 'chatlume-admin';

	/**
	 * Admin page.
	 *
	 * @var AdminPage
	 */
	private AdminPage $page;

	/**
	 * Constructor.
	 *
	 * @param AdminPage $page Admin page (provides the screen hook suffix).
	 */
	public function __construct( AdminPage $page ) {
		$this->page = $page;
	}

	/**
	 * Hooks enqueueing.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Path of the generated asset manifest.
	 *
	 * @return string
	 */
	private static function asset_file(): string {
		return CHATLUME_PATH . 'build/admin/index.asset.php';
	}

	/**
	 * Whether the admin bundle has been built.
	 *
	 * @return bool
	 */
	public static function is_built(): bool {
		return is_readable( self::asset_file() );
	}

	/**
	 * Enqueues the app on the Chatlume screen.
	 *
	 * @param string $hook_suffix Current admin screen hook suffix.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->page->get_hook_suffix() || $hook_suffix !== $this->page->get_hook_suffix() || ! self::is_built() ) {
			return;
		}

		$asset = require self::asset_file();
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : CHATLUME_VERSION;

		wp_register_script(
			self::HANDLE,
			CHATLUME_URL . 'build/admin/index.js',
			$deps,
			$ver,
			array( 'in_footer' => true )
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.chatlume = window.chatlume || {}; window.chatlume.bootstrap = ' . wp_json_encode( $this->bootstrap_data() ) . ';',
			'before'
		);

		wp_set_script_translations( self::HANDLE, 'chatlume-ai-chatbot', CHATLUME_PATH . 'languages' );
		wp_enqueue_script( self::HANDLE );

		// The widget itself, for live previews (no floating launcher in the admin).
		if ( WidgetLoader::register_script() ) {
			wp_enqueue_script( WidgetLoader::HANDLE );
		}

		// The Media Library, for the avatar, logo, and launcher images in the Customizer.
		if ( current_user_can( 'upload_files' ) ) {
			wp_enqueue_media();
		}

		if ( is_readable( CHATLUME_PATH . 'build/admin/index.css' ) ) {
			wp_enqueue_style( self::HANDLE, CHATLUME_URL . 'build/admin/index.css', array(), $ver );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Data the app needs at boot. Contains no secrets; the REST nonce is
	 * provided by core's wp-api-fetch middleware.
	 *
	 * @return array<string, mixed>
	 */
	private function bootstrap_data(): array {
		$user = wp_get_current_user();

		return array(
			'version'        => CHATLUME_VERSION,
			'restNamespace'  => Plugin::REST_NAMESPACE,
			'assetsUrl'      => CHATLUME_URL . 'build/admin/',
			'adminUrl'       => admin_url( 'admin.php?page=' . Plugin::ADMIN_SLUG ),
			'siteUrl'        => home_url( '/' ),
			// For the suggested privacy notice ('' when the site has no published privacy policy page).
			'privacyUrl'     => (string) get_privacy_policy_url(),
			// The setup wizard opens once, until it is finished or skipped.
			'setupCompleted' => (bool) Settings::get( 'setup.completed', false ),
			// For the live preview's AI replies, which run inside the widget (not apiFetch).
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'docsUrl'        => 'https://wordpress.org/plugins/chatlume-ai-chatbot/',
			'colorMode'      => AdminPage::get_color_mode( (int) $user->ID ),
			'user'           => array(
				'id'          => (int) $user->ID,
				'displayName' => $user->display_name,
			),
			'isRtl'          => is_rtl(),
			'system'         => HealthCheck::snapshot(),
			// Widget configuration for previews (same data visitors' pages get).
			'widget'         => WidgetLoader::is_built() ? WidgetLoader::inline_config( false ) : null,
		);
	}
}
