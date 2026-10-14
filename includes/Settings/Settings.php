<?php
/**
 * Settings storage.
 *
 * @package Chatlume
 */

namespace Chatlume\Settings;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the single `chatlume_settings` option (not autoloaded).
 *
 * The stored array always carries `schema_version`; Migrations upgrades
 * older stored data before it is read.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'chatlume_settings';

	/**
	 * Current settings schema version.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Every setting, with defaults filled in for missing keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION, array() );

		return self::merge_known( SettingsSchema::all_defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting by dotted path, e.g. 'privacy.delete_data_on_uninstall'.
	 *
	 * @param string $path     Dotted path.
	 * @param mixed  $fallback Value returned when the path does not exist.
	 * @return mixed
	 */
	public static function get( string $path, $fallback = null ) {
		$value = self::get_all();

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $fallback;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Validates, sanitizes, and deep-merges a partial update, then saves it.
	 *
	 * @param array<string, mixed> $partial Partial settings keyed by section.
	 * @return array<string, mixed>|WP_Error All settings after the update, or a validation error.
	 */
	public static function update( array $partial ) {
		$schema = array(
			'type'       => 'object',
			'properties' => SettingsSchema::definition(),
		);

		$valid = SettingsSchema::validate( $partial, $schema, 'settings' );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$merged = self::merge_known( self::get_all(), SettingsSchema::sanitize( $partial, $schema ) );

		self::save( $merged );

		return self::get_all();
	}

	/**
	 * Writes settings, stamping the schema version. Not autoloaded.
	 *
	 * @param array<string, mixed> $settings Settings without schema_version.
	 * @return void
	 */
	public static function save( array $settings ): void {
		unset( $settings['schema_version'] );
		$settings['schema_version'] = self::SCHEMA_VERSION;

		update_option( self::OPTION, $settings, false );
	}

	/**
	 * Creates the option with defaults if it does not exist yet.
	 *
	 * @return void
	 */
	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			$settings                   = SettingsSchema::all_defaults();
			$settings['schema_version'] = self::SCHEMA_VERSION;
			add_option( self::OPTION, $settings, '', false );
		}
	}

	/**
	 * Recursively overlays $values onto $base, keeping only keys that exist in
	 * $base. List arrays are replaced, not merged.
	 *
	 * @param array<string, mixed> $base   Base values (defines the allowed keys).
	 * @param array<string, mixed> $values Values to overlay.
	 * @return array<string, mixed>
	 */
	public static function merge_known( array $base, array $values ): array {
		foreach ( $base as $key => $default ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}

			$is_assoc = is_array( $default ) && array() !== $default && array_keys( $default ) !== range( 0, count( $default ) - 1 );

			if ( $is_assoc && is_array( $values[ $key ] ) ) {
				$base[ $key ] = self::merge_known( $default, $values[ $key ] );
			} elseif ( is_array( $default ) === is_array( $values[ $key ] ) ) {
				$base[ $key ] = $values[ $key ];
			}
		}

		return $base;
	}
}
