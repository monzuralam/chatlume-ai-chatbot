<?php
/**
 * Daily data cleanup.
 *
 * @package Chatlume
 */

namespace Chatlume\Cron;

use Chatlume\Data\ConversationRepository;
use Chatlume\Security\RateLimiter;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Once a day: deletes conversations older than the retention period and
 * expired rate-limit rows. Work is done in batches so a single run stays short.
 */
class RetentionCleanup {

	/**
	 * Cron hook.
	 */
	public const HOOK = 'chatlume_daily_cleanup';

	/**
	 * Maximum batches per run (500 conversations each).
	 */
	private const MAX_BATCHES = 20;

	/**
	 * Hooks the job and makes sure it is scheduled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( self::class, 'schedule' ) );
	}

	/**
	 * Schedules the daily run if missing.
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Runs the cleanup.
	 *
	 * @return array{conversations: int, rate_limits: int}
	 */
	public function run(): array {
		$deleted = 0;
		$days    = (int) Settings::get( 'privacy.retention_days', 90 );

		if ( $days > 0 ) {
			$before        = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$conversations = new ConversationRepository();

			for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
				$count    = $conversations->delete_inactive_before( $before );
				$deleted += $count;
				if ( $count < 500 ) {
					break;
				}
			}
		}

		return array(
			'conversations' => $deleted,
			// Rate-limit windows are at most one day long.
			'rate_limits'   => RateLimiter::purge( time() - DAY_IN_SECONDS ),
		);
	}
}
