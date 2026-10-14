<?php
/**
 * API key storage.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use Chatlume\Security\Encryption;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores provider API keys encrypted in their own non-autoloaded option
 * (`chatlume_api_keys`), separate from settings, so settings can be read,
 * returned over REST, or exported without ever touching a key.
 *
 * A key defined as a constant in wp-config.php (for example
 * CHATLUME_OPENAI_API_KEY) always wins and is never stored.
 * Keys are never returned in full; only the last four characters are shown.
 */
final class ApiKeys {

	/**
	 * Option holding encrypted keys by provider ID.
	 */
	public const OPTION = 'chatlume_api_keys';

	/**
	 * Constant that overrides a provider's key, e.g. CHATLUME_OPENAI_API_KEY.
	 *
	 * @param string $provider_id Provider ID.
	 * @return string
	 */
	public static function constant_name( string $provider_id ): string {
		return 'CHATLUME_' . strtoupper( str_replace( '-', '_', $provider_id ) ) . '_API_KEY';
	}

	/**
	 * Resolves the usable key for a provider.
	 *
	 * @param string $provider_id Provider ID.
	 * @return array{key: string, source: string, error: bool} source: constant, settings, or none.
	 */
	public static function resolve( string $provider_id ): array {
		$constant = self::constant_name( $provider_id );

		if ( defined( $constant ) && is_string( constant( $constant ) ) && '' !== trim( constant( $constant ) ) ) {
			return array(
				'key'    => trim( constant( $constant ) ),
				'source' => 'constant',
				'error'  => false,
			);
		}

		$stored = self::stored()[ $provider_id ] ?? '';
		if ( '' === $stored ) {
			return array(
				'key'    => '',
				'source' => 'none',
				'error'  => false,
			);
		}

		$plain = ( new Encryption() )->decrypt( $stored );

		return array(
			'key'    => is_wp_error( $plain ) ? '' : $plain,
			'source' => 'settings',
			'error'  => is_wp_error( $plain ),
		);
	}

	/**
	 * Safe status for the admin: never includes the key itself.
	 *
	 * @param string $provider_id Provider ID.
	 * @return array{source: string, masked: string, readable: bool, constant: string}
	 */
	public static function status( string $provider_id ): array {
		$resolved = self::resolve( $provider_id );

		return array(
			'source'   => $resolved['source'],
			'masked'   => Encryption::mask( $resolved['key'] ),
			'readable' => ! $resolved['error'],
			'constant' => self::constant_name( $provider_id ),
		);
	}

	/**
	 * Encrypts and stores a key.
	 *
	 * @param string $provider_id Provider ID.
	 * @param string $key         Plain-text key.
	 * @return true|WP_Error
	 */
	public static function save( string $provider_id, string $key ) {
		$key = trim( $key );

		if ( '' === $key ) {
			return new WP_Error( 'chatlume_empty_key', __( 'Please enter an API key.', 'chatlume-ai-chatbot' ), array( 'status' => 400 ) );
		}

		$encryption = new Encryption();
		$encrypted  = $encryption->encrypt( $key );

		if ( is_wp_error( $encrypted ) ) {
			$encrypted->add_data( array( 'status' => 500 ) );
			return $encrypted;
		}

		$keys                 = self::stored();
		$keys[ $provider_id ] = $encrypted;
		update_option( self::OPTION, $keys, false );

		// After a salt change, saving a fresh key means stored keys are readable again;
		// keys that are still unreadable keep reporting it until re-entered.
		if ( Encryption::STATUS_KEY_CHANGED === $encryption->status() ) {
			$encryption->reset_fingerprint();
		}

		return true;
	}

	/**
	 * Removes a stored key.
	 *
	 * @param string $provider_id Provider ID.
	 * @return void
	 */
	public static function delete( string $provider_id ): void {
		$keys = self::stored();
		unset( $keys[ $provider_id ] );
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * Encrypted keys by provider ID.
	 *
	 * @return array<string, string>
	 */
	private static function stored(): array {
		$keys = get_option( self::OPTION, array() );

		return is_array( $keys ) ? array_filter( $keys, 'is_string' ) : array();
	}
}
