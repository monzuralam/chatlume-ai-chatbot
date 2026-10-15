<?php
/**
 * Optional debug log of chat pipeline events.
 *
 * @package Chatlume
 */

namespace Chatlume\Support;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When "Debug logging" is on, records a short summary of each chat event
 * (reply source, provider and model, tokens, latency, error codes) so the
 * admin can see why the bot behaved as it did.
 *
 * Never recorded: message text, visitor details, IP addresses, or API keys.
 * Entries live in one capped, non-autoloaded option; Chatlume never writes
 * log files.
 */
final class DebugLog {

	/**
	 * Option name.
	 */
	public const OPTION = 'chatlume_debug_log';

	/**
	 * Maximum entries kept.
	 */
	public const LIMIT = 100;

	/**
	 * Fields an entry may carry (anything else is dropped).
	 */
	private const FIELDS = array( 'source', 'provider', 'model', 'input_tokens', 'output_tokens', 'cost', 'latency_ms', 'error_type', 'interrupted', 'streamed', 'code', 'status' );

	/**
	 * Whether debug logging is on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'debug.enabled', false );
	}

	/**
	 * Records an event when logging is on.
	 *
	 * @param string               $event Event name (reply, rejected).
	 * @param array<string, mixed> $data  Summary fields.
	 * @return void
	 */
	public static function record( string $event, array $data ): void {
		if ( ! self::enabled() ) {
			return;
		}

		$entry = array(
			'time'  => time(),
			'event' => sanitize_key( $event ),
		);

		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				continue;
			}
			$value = $data[ $field ];
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$entry[ $field ] = $value;
			} elseif ( is_string( $value ) && '' !== $value ) {
				$entry[ $field ] = mb_substr( sanitize_text_field( $value ), 0, 200 );
			}
		}

		$entries = self::all();
		array_unshift( $entries, $entry );

		update_option( self::OPTION, array_slice( $entries, 0, self::LIMIT ), false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Removes all entries.
	 *
	 * @return void
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
