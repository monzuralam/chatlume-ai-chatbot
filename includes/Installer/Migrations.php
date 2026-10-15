<?php
/**
 * Database and settings upgrades.
 *
 * @package Chatlume
 */

namespace Chatlume\Installer;

use Chatlume\Appearance\TokenResolver;
use Chatlume\Security\Encryption;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and upgrades the current site's tables and settings.
 *
 * Two independent versions are tracked:
 * - `chatlume_db_version`: table structure (dbDelta is idempotent, so any
 *   bump simply re-runs it).
 * - `schema_version` inside `chatlume_settings`: settings shape, upgraded
 *   step by step through settings_steps().
 */
class Migrations {

	/**
	 * Current database structure version.
	 */
	public const DB_VERSION = 2;

	/**
	 * Option storing the installed database version for the current site.
	 */
	public const DB_VERSION_OPTION = 'chatlume_db_version';

	/**
	 * Hooks the upgrade check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_upgrade' ), 1 );
	}

	/**
	 * Runs install/upgrade routines when the stored versions are behind.
	 *
	 * Only runs in wp-admin, WP-Cron, and WP-CLI, so visitors' page loads never
	 * trigger schema changes. The version check itself is one autoloaded option.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$is_cli = defined( 'WP_CLI' ) && WP_CLI;

		if ( ! is_admin() && ! wp_doing_cron() && ! $is_cli ) {
			return;
		}

		if ( self::needs_upgrade() ) {
			self::install_site();
		}
	}

	/**
	 * Whether the current site's tables or settings are out of date.
	 *
	 * @return bool
	 */
	public static function needs_upgrade(): bool {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) < self::DB_VERSION ) {
			return true;
		}

		$settings = get_option( Settings::OPTION, false );

		return ! is_array( $settings ) || (int) ( $settings['schema_version'] ?? 0 ) < Settings::SCHEMA_VERSION;
	}

	/**
	 * Installs or upgrades everything for the current site. Idempotent.
	 *
	 * @return void
	 */
	public static function install_site(): void {
		Schema::create_tables();

		$stored = get_option( Settings::OPTION, false );
		if ( is_array( $stored ) ) {
			Settings::save( ( new static() )->migrate_settings( $stored ) );
		} else {
			Settings::ensure_defaults();
		}

		( new Encryption() )->ensure_fingerprint();

		// Version 2: the widget's resolved design tokens (autoloaded for first paint).
		TokenResolver::rebuild();

		// Autoloaded on purpose: it is read on every admin request by needs_upgrade().
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Upgrades stored settings to the current schema version, one step at a time.
	 *
	 * @param array<string, mixed> $stored Stored settings, including schema_version.
	 * @return array<string, mixed> Migrated settings (schema_version is stamped on save).
	 */
	public function migrate_settings( array $stored ): array {
		$version = (int) ( $stored['schema_version'] ?? 0 );

		foreach ( $this->settings_steps() as $target => $step ) {
			if ( $version < $target && $target <= Settings::SCHEMA_VERSION ) {
				$stored  = $step( $stored );
				$version = $target;
			}
		}

		unset( $stored['schema_version'] );

		return $stored;
	}

	/**
	 * Settings migration steps keyed by the schema version they produce,
	 * in ascending order. Each step receives and returns the settings array.
	 *
	 * Version 1 is the initial shape, so its step only guarantees the
	 * sections exist; later versions append steps here.
	 *
	 * @return array<int, callable(array<string, mixed>): array<string, mixed>>
	 */
	protected function settings_steps(): array {
		return array(
			1 => static function ( array $settings ): array {
				foreach ( array( 'setup', 'privacy' ) as $section ) {
					if ( ! isset( $settings[ $section ] ) || ! is_array( $settings[ $section ] ) ) {
						$settings[ $section ] = array();
					}
				}
				return $settings;
			},
		);
	}
}
