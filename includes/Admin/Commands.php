<?php
/**
 * Chatlume commands in WordPress's Command Palette.
 *
 * @package Chatlume
 */

namespace Chatlume\Admin;

use Chatlume\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "Chatlume: Conversations", "Chatlume: Test your bot", "Chatlume:
 * Customize widget", and the other Chatlume screens to the core Command
 * Palette (Ctrl/Cmd+K). The palette is on every admin screen from WordPress
 * 6.9 and in the block editors before that; the small script loads only
 * there, and only for users who can manage Chatlume.
 */
class Commands {

	/**
	 * Script handle.
	 */
	public const HANDLE = 'chatlume-commands';

	/**
	 * Hooks the script.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Every admin screen, when core shows the palette there (6.9+).
	 *
	 * @return void
	 */
	public function enqueue_admin(): void {
		if ( function_exists( 'wp_enqueue_command_palette_assets' ) ) {
			$this->enqueue();
		}
	}

	/**
	 * Enqueues the commands once.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$asset_file = CHATLUME_PATH . 'build/commands/index.asset.php';
		if ( ! Plugin::current_user_can_manage() || wp_script_is( self::HANDLE, 'enqueued' ) || ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		wp_register_script(
			self::HANDLE,
			CHATLUME_URL . 'build/commands/index.js',
			isset( $asset['dependencies'] ) ? (array) $asset['dependencies'] : array(),
			isset( $asset['version'] ) ? (string) $asset['version'] : CHATLUME_VERSION,
			array( 'in_footer' => true )
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.chatlumeCommands = ' . wp_json_encode( array( 'adminUrl' => admin_url( 'admin.php?page=' . Plugin::ADMIN_SLUG ) ) ) . ';',
			'before'
		);
		wp_set_script_translations( self::HANDLE, 'chatlume-ai-chatbot', CHATLUME_PATH . 'languages' );
		wp_enqueue_script( self::HANDLE );
	}
}
