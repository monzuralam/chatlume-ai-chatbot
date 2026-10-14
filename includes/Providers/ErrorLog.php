<?php
/**
 * Recent provider errors.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the last 50 provider failures in a non-autoloaded option so admins
 * can see what went wrong and why. Messages are already redacted by
 * ProviderError; nothing is written to files.
 */
final class ErrorLog {

	/**
	 * Option name.
	 */
	public const OPTION = 'chatlume_provider_errors';

	/**
	 * Maximum entries kept.
	 */
	public const LIMIT = 50;

	/**
	 * Records a failure.
	 *
	 * @param ProviderError $error Error.
	 * @param string        $model Model ID.
	 * @return void
	 */
	public static function record( ProviderError $error, string $model = '' ): void {
		$entries = self::all();

		array_unshift(
			$entries,
			array(
				'time'     => time(),
				'provider' => $error->provider(),
				'model'    => mb_substr( $model, 0, 200 ),
				'type'     => $error->type(),
				'status'   => $error->status(),
				'detail'   => $error->detail(),
			)
		);

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
