<?php
/**
 * Concurrent stream limiter.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caps how many streaming replies run at once, so a small host keeps PHP
 * workers free for normal page views. Visitors over the cap get a normal
 * (non-streaming) reply instead.
 *
 * Slots live in one non-autoloaded option, each with an expiry slightly
 * after the maximum stream duration, so a slot held by a request that died
 * (fatal error, killed worker) frees itself.
 */
class StreamLimiter {

	/**
	 * Option holding active slots (slot ID => expiry timestamp).
	 */
	public const OPTION = 'chatlume_stream_slots';

	/**
	 * Seconds a slot outlives the stream's own time limit.
	 */
	private const GRACE = 15;

	/**
	 * Takes a slot when one is free.
	 *
	 * @param int $duration Maximum stream duration in seconds.
	 * @return string Slot ID, or '' when every slot is taken.
	 */
	public function acquire( int $duration ): string {
		$slots = $this->active();
		$max   = (int) Settings::get( 'streaming.max_concurrent', 3 );

		if ( count( $slots ) >= max( 1, $max ) ) {
			return '';
		}

		$slot           = wp_generate_password( 12, false );
		$slots[ $slot ] = time() + max( 1, $duration ) + self::GRACE;
		$this->save( $slots );

		return $slot;
	}

	/**
	 * Frees a slot. Safe to call more than once.
	 *
	 * @param string $slot Slot ID.
	 * @return void
	 */
	public function release( string $slot ): void {
		if ( '' === $slot ) {
			return;
		}

		$slots = $this->active();
		if ( isset( $slots[ $slot ] ) ) {
			unset( $slots[ $slot ] );
			$this->save( $slots );
		}
	}

	/**
	 * Number of streams running now.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->active() );
	}

	/**
	 * Unexpired slots.
	 *
	 * @return array<string, int>
	 */
	private function active(): array {
		$stored = get_option( self::OPTION, array() );
		$now    = time();
		$slots  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $slot => $expires ) {
			if ( is_string( $slot ) && (int) $expires > $now ) {
				$slots[ $slot ] = (int) $expires;
			}
		}

		return $slots;
	}

	/**
	 * Stores slots (never autoloaded).
	 *
	 * @param array<string, int> $slots Slots.
	 * @return void
	 */
	private function save( array $slots ): void {
		update_option( self::OPTION, $slots, false );
	}
}
