<?php

/**
 * Plugin Name:       Chatlume – AI Chatbot
 * Description:       Add a fast, brandable AI chatbot to your site using the AI provider of your choice, with streaming replies, quick answers, and cost controls.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            monzuralam
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       chatlume-ai-chatbot
 *
 * @package Chatlume
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CHATLUME_VERSION', '0.1.0' );
define( 'CHATLUME_FILE', __FILE__ );
define( 'CHATLUME_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHATLUME_URL', plugin_dir_url( __FILE__ ) );
define( 'CHATLUME_BASENAME', plugin_basename( __FILE__ ) );
define( 'CHATLUME_MIN_PHP', '8.0' );

if ( version_compare( PHP_VERSION, CHATLUME_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'chatlume_php_version_notice' );
	return;
}

if ( ! is_readable( CHATLUME_PATH . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'chatlume_missing_autoloader_notice' );
	return;
}

require_once CHATLUME_PATH . 'vendor/autoload.php';

if ( is_readable( CHATLUME_PATH . 'vendor-prefixed/autoload.php' ) ) {
	require_once CHATLUME_PATH . 'vendor-prefixed/autoload.php';
}

register_activation_hook( __FILE__, array( \Chatlume\Installer\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Chatlume\Installer\Activation::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \Chatlume\Plugin::instance(), 'boot' ) );

/**
 * Admin notice shown when the server runs an unsupported PHP version.
 *
 * @return void
 */
function chatlume_php_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version. */
				__( 'Chatlume – AI Chatbot requires PHP %1$s or newer. Your server runs PHP %2$s. Please ask your host to upgrade PHP.', 'chatlume-ai-chatbot' ),
				CHATLUME_MIN_PHP,
				PHP_VERSION
			)
		)
	);
}

/**
 * Admin notice shown when the Composer autoloader is missing (a development
 * checkout where `composer install` has not been run).
 *
 * @return void
 */
function chatlume_missing_autoloader_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Chatlume – AI Chatbot is missing required files. Please reinstall the plugin from WordPress.org, or run "composer install" in the plugin folder if you are working from source.', 'chatlume-ai-chatbot' )
	);
}
