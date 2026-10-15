<?php
/**
 * Stop requests for running streams.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Installer\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a visitor stop a reply that is still streaming.
 *
 * Many servers never tell PHP that the visitor closed the connection (some
 * proxies and Windows setups keep it open), so the widget also sends an
 * explicit stop request ("Stop generating", or a beacon when the tab
 * closes). The running stream checks for it and aborts the upstream request.
 *
 * Keys are derived from the visitor's session and the stream ID, so only
 * the session that started a stream can stop it. Flags live in the object
 * cache when a persistent one is available, otherwise in Chatlume's
 * rate-limit table (purged daily by the cleanup cron).
 */
class StopSignal {

	/**
	 * Object cache group.
	 */
	private const CACHE_GROUP = 'chatlume_stop';

	/**
	 * Seconds a stop request stays valid.
	 */
	private const TTL = 600;

	/**
	 * Stream ID format.
	 */
	public const ID_PATTERN = '^[a-f0-9]{16}$';

	/**
	 * A new stream ID.
	 *
	 * @return string
	 */
	public static function new_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * The flag key for a stream of a session.
	 *
	 * @param string $session_id Session ID.
	 * @param string $stream_id  Stream ID.
	 * @return string
	 */
	public static function key( string $session_id, string $stream_id ): string {
		return 'stop:' . substr( hash_hmac( 'sha256', $stream_id, $session_id ), 0, 40 );
	}

	/**
	 * Records a stop request.
	 *
	 * @param string $key Flag key.
	 * @return void
	 */
	public function request( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, 1, self::CACHE_GROUP, self::TTL );
			return;
		}

		global $wpdb;

		// Chatlume's own table; the flag must be visible to another, already running request.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (bucket_key, window_start, count) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE count = 1', Schema::table( 'rate_limits' ), $key, time() ) );
	}

	/**
	 * Whether a stop was requested (always read fresh, never from a local copy).
	 *
	 * @param string $key Flag key.
	 * @return bool
	 */
	public function requested( string $key ): bool {
		if ( wp_using_ext_object_cache() ) {
			return (bool) wp_cache_get( $key, self::CACHE_GROUP, true );
		}

		global $wpdb;

		// Polled by a running stream: a cached value would never change.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT count FROM %i WHERE bucket_key = %s AND window_start > %d LIMIT 1', Schema::table( 'rate_limits' ), $key, time() - self::TTL ) );
	}

	/**
	 * Removes a flag once its stream ended.
	 *
	 * @param string $key Flag key.
	 * @return void
	 */
	public function clear( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			return;
		}

		global $wpdb;

		// Chatlume's own table; no core API exists for it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE bucket_key = %s', Schema::table( 'rate_limits' ), $key ) );
	}
}
