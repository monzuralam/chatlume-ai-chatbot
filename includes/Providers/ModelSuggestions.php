<?php
/**
 * Recommended-model badges from family patterns.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks and orders models in a provider's live list.
 *
 * The live list is always the source of truth: data/model-suggestions.json
 * (shipped with each release, never fetched remotely) holds only family
 * patterns, so it never names a model that has to exist and never goes
 * stale in a harmful way. Free models (zero price in the live list, e.g.
 * OpenRouter) are flagged by the provider when it fetches the list.
 */
final class ModelSuggestions {

	/**
	 * Parsed suggestion file.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $data = null;

	/**
	 * Patterns for a provider and model kind.
	 *
	 * @param string $provider Provider ID.
	 * @param string $kind     chat or embedding.
	 * @return string[]
	 */
	public static function patterns( string $provider, string $kind ): array {
		$patterns = self::data()['providers'][ $provider ][ $kind ] ?? array();

		return array_values( array_filter( is_array( $patterns ) ? $patterns : array(), 'is_string' ) );
	}

	/**
	 * Adds `recommended` and orders the list: recommended models first (by
	 * pattern rank, newest first within a rank), then free models, then the
	 * rest alphabetically.
	 *
	 * @param string                           $provider Provider ID.
	 * @param array<int, array<string, mixed>> $models   Normalized models.
	 * @return array<int, array<string, mixed>>
	 */
	public static function apply( string $provider, array $models ): array {
		$ranked = array();

		foreach ( $models as $model ) {
			$rank                 = self::rank( $provider, (string) ( $model['kind'] ?? 'chat' ), (string) $model['id'] );
			$model['recommended'] = null !== $rank;
			$model['free']        = ! empty( $model['free'] );
			$ranked[]             = array( $rank ?? PHP_INT_MAX, $model );
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				if ( $a[0] !== $b[0] ) {
					return $a[0] <=> $b[0];
				}
				if ( $a[1]['free'] !== $b[1]['free'] ) {
					return $a[1]['free'] ? -1 : 1;
				}
				if ( PHP_INT_MAX !== $a[0] ) {
					// Same family: newest first (by creation time, then by version-aware ID).
					$created = (int) ( $b[1]['created'] ?? 0 ) <=> (int) ( $a[1]['created'] ?? 0 );
					return 0 !== $created ? $created : strnatcasecmp( (string) $b[1]['id'], (string) $a[1]['id'] );
				}
				return strnatcasecmp( (string) $a[1]['id'], (string) $b[1]['id'] );
			}
		);

		return array_map(
			static function ( array $item ): array {
				return $item[1];
			},
			$ranked
		);
	}

	/**
	 * Index of the first matching pattern, or null.
	 *
	 * @param string $provider Provider ID.
	 * @param string $kind     Model kind.
	 * @param string $model_id Model ID.
	 * @return int|null
	 */
	public static function rank( string $provider, string $kind, string $model_id ): ?int {
		foreach ( self::patterns( $provider, $kind ) as $index => $pattern ) {
			$regex = '~' . str_replace( '~', '\\~', $pattern ) . '~i';
			// A malformed pattern in the data file must never break the model list.
			if ( @preg_match( $regex, $model_id ) === 1 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $index;
			}
		}

		return null;
	}

	/**
	 * Replaces the suggestion data (tests only).
	 *
	 * @param array<string, mixed>|null $data Data, or null to reload from disk.
	 * @return void
	 */
	public static function set_data( ?array $data ): void {
		self::$data = $data;
	}

	/**
	 * Loads data/model-suggestions.json once per request.
	 *
	 * @return array<string, mixed>
	 */
	private static function data(): array {
		if ( null === self::$data ) {
			$file = CHATLUME_PATH . 'data/model-suggestions.json';
			// Local, read-only plugin data file; no WP_Filesystem needed for reading.
			$json       = is_readable( $file ) ? file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$decoded    = json_decode( (string) $json, true );
			self::$data = is_array( $decoded ) ? $decoded : array();
		}

		return self::$data;
	}
}
