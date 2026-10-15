<?php
/**
 * Main plugin class: wires every component to WordPress.
 *
 * @package Chatlume
 */

namespace Chatlume;

use Chatlume\Admin\AdminPage;
use Chatlume\Admin\Assets;
use Chatlume\Cron\RetentionCleanup;
use Chatlume\Frontend\Shortcode;
use Chatlume\Frontend\WidgetLoader;
use Chatlume\Installer\Activation;
use Chatlume\Installer\Migrations;
use Chatlume\Installer\Multisite;
use Chatlume\REST\ChatbotController;
use Chatlume\REST\ChatController;
use Chatlume\REST\ConversationsController;
use Chatlume\REST\HealthController;
use Chatlume\REST\ProvidersController;
use Chatlume\REST\QuickAnswersController;
use Chatlume\REST\SettingsController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * REST API namespace.
	 */
	public const REST_NAMESPACE = 'chatlume/v1';

	/**
	 * Admin page slug (admin.php?page=chatlume).
	 */
	public const ADMIN_SLUG = 'chatlume';

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Returns the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers all hooks. Runs on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new Migrations() )->register();
		( new Multisite() )->register();
		( new Activation() )->register();
		( new RetentionCleanup() )->register();
		( new Shortcode() )->register();

		if ( ! is_admin() ) {
			( new WidgetLoader() )->register();
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Registered on every request: REST requests (which save it) are not is_admin().
		add_action( 'init', array( AdminPage::class, 'register_user_meta' ) );

		if ( is_admin() ) {
			$admin_page = new AdminPage();
			$admin_page->register();
			( new Assets( $admin_page ) )->register();
		}
	}

	/**
	 * Registers REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new SettingsController() )->register_routes();
		( new ProvidersController() )->register_routes();
		( new ChatController() )->register_routes();
		( new QuickAnswersController() )->register_routes();
		( new HealthController() )->register_routes();
		( new ChatbotController() )->register_routes();
		( new ConversationsController() )->register_routes();
	}

	/**
	 * Capability required to manage Chatlume.
	 *
	 * Filterable with `chatlume_capability` so site owners can delegate access.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability required to manage Chatlume.
		 *
		 * @param string $capability Capability name. Default 'manage_options'.
		 */
		$capability = apply_filters( 'chatlume_capability', 'manage_options' );

		return is_string( $capability ) && '' !== $capability ? $capability : 'manage_options';
	}

	/**
	 * Whether the current user can manage Chatlume.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::capability() );
	}
}
