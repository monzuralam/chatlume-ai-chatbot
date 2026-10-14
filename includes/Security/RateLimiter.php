<?php
/**
 * Fixed-window rate limiting.
 *
 * @package Chatlume
 */

namespace Chatlume\Security;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts hits per bucket (for example a hashed IP or a session) in fixed
 * time windows.
 *
 * Storage: the persistent object cache when the site has one (atomic
 * wp_cache_incr), otherwise one row per bucket and window in the
 * `chatlume_rate_limits` table, updated with a single atomic upsert. Rate
 * limits are never stored as transients in wp_options.
 */
class RateLimiter {

	/**
	 * Object cache group.
	 */
	private const CACHE_GROUP = 'chatlume_rate';

	/**
	 * Records one hit and reports whether it is within the limit.
	 *
	 * @param string $bucket  Bucket key, e.g. "ip:<hash>" or "session:<id>".
	 * @param int    $limit   Allowed hits per window.
	 * @param int    $window  Window length in seconds.
	 * @return array{allowed: bool, remaining: int, retry_after: int}
	 */
	public function hit( string $bucket, int $limit, int $window ): array {
		/**
		 * Filters a rate limit before it is applied.
		 *
		 * @param array{limit: int, window: int} $rule   Hits allowed per window (seconds).
		 * @param string                         $bucket Bucket key (type prefix and hashed identifier).
		 */
		$rule   = apply_filters(
			'chatlume_rate_limit',
			array(
				'limit'  => $limit,
				'window' => $window,
			),
			$bucket
		);
		$limit  = max( 1, (int) ( $rule['limit'] ?? $limit ) );
		$window = max( 1, (int) ( $rule['window'] ?? $window ) );

		$now          = time();
		$window_start = $now - ( $now % $window );
		$count        = $this->increment( substr( $bucket, 0, 191 ), $window_start, $window );

		return array(
			'allowed'     => $count <= $limit,
			'remaining'   => max( 0, $limit - $count ),
			'retry_after' => $count <= $limit ? 0 : $window_start + $window - $now,
		);
	}

	/**
	 * Removes expired rows (run by the daily cleanup).
	 *
	 * @param int $older_than Delete windows that started before this timestamp.
	 * @return int Rows removed.
	 */
	public static function purge( int $older_than ): int {
		global $wpdb;

		// Housekeeping on Chatlume's own table; no core API exists for it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE window_start < %d', Schema::table( 'rate_limits' ), $older_than ) );
	}

	/**
	 * Increments and returns the count for a bucket window.
	 *
	 * @param string $bucket       Bucket key.
	 * @param int    $window_start Window start timestamp.
	 * @param int    $window       Window length in seconds.
	 * @return int
	 */
	private function increment( string $bucket, int $window_start, int $window ): int {
		if ( wp_using_ext_object_cache() ) {
			$key = md5( $bucket ) . ':' . $window_start;
			// add() is a no-op when the key exists, so concurrent first hits stay correct.
			wp_cache_add( $key, 0, self::CACHE_GROUP, $window + 60 );
			$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
			if ( false !== $count ) {
				return (int) $count;
			}
		}

		global $wpdb;
		$table = Schema::table( 'rate_limits' );

		// Atomic upsert on Chatlume's own table: one query per hit, safe under concurrency.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (bucket_key, window_start, count) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE count = count + 1', $table, $bucket, $window_start ) );

		// Reads back the counter just written; caching would return stale counts.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT count FROM %i WHERE bucket_key = %s AND window_start = %d', $table, $bucket, $window_start ) );
	}
}
