<?php
/**
 * System status for the admin.
 *
 * @package Chatlume
 */

namespace Chatlume\Admin;

use Chatlume\Installer\Migrations;
use Chatlume\Installer\Schema;
use Chatlume\Security\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects environment and installation status shown in the admin.
 */
class HealthCheck {

	/**
	 * Snapshot of the installation and detected core features.
	 *
	 * Core features are detected by function/class existence so older
	 * WordPress versions never hit undefined functions.
	 *
	 * @return array<string, mixed>
	 */
	public static function snapshot(): array {
		return array(
			'wpVersion'         => (string) get_bloginfo( 'version' ),
			'phpVersion'        => PHP_VERSION,
			'dbVersion'         => (int) get_option( Migrations::DB_VERSION_OPTION, 0 ),
			'dbVersionExpected' => Migrations::DB_VERSION,
			'fulltext'          => 'yes' === get_option( Schema::FULLTEXT_OPTION, 'no' ),
			'encryption'        => ( new Encryption() )->status(),
			'isMultisite'       => is_multisite(),
			'features'          => array(
				'abilitiesApi'   => function_exists( 'wp_register_ability' ),
				'aiClient'       => function_exists( 'wp_ai_client_prompt' ),
				'connectors'     => function_exists( 'wp_get_connectors' ),
				'fontLibrary'    => class_exists( 'WP_Font_Library' ),
				'commandPalette' => wp_script_is( 'wp-commands', 'registered' ),
			),
		);
	}
}
