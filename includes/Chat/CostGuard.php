<?php
/**
 * Budgets and per-conversation limits.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks daily and monthly AI usage against the admin's budgets, applies
 * the per-conversation message cap, and emails the admin at 80% and 100%
 * of a budget (once per budget period).
 *
 * Usage is kept in one small non-autoloaded option. Days and months follow
 * the site's timezone. Under heavy concurrency counts can lag by a request;
 * budgets are estimates, so this is acceptable.
 */
class CostGuard {

	/**
	 * Option holding current usage.
	 */
	public const OPTION = 'chatlume_usage';

	/**
	 * Alert thresholds (percent of the budget).
	 */
	private const THRESHOLDS = array( 80, 100 );

	/**
	 * Whether a conversation reached its message cap.
	 *
	 * @param int $user_messages Visitor messages already in the conversation.
	 * @return bool
	 */
	public function conversation_capped( int $user_messages ): bool {
		$cap = (int) Settings::get( 'costs.conversation_message_cap', 50 );

		return $cap > 0 && $user_messages >= $cap;
	}

	/**
	 * The budget period that is used up, or '' when AI use is allowed.
	 *
	 * @return string day, month, or ''.
	 */
	public function exhausted_period(): string {
		$usage = $this->usage();

		foreach ( array( 'day', 'month' ) as $period ) {
			$budget = $this->budget( $period );
			if ( $budget > 0 && $this->spent( $usage, $period ) >= $budget ) {
				return $period;
			}
		}

		return '';
	}

	/**
	 * What to do when a budget is used up: site_search, offline, or fallback_model.
	 *
	 * @return string
	 */
	public function cap_behavior(): string {
		$behavior = (string) Settings::get( 'costs.cap_behavior', 'site_search' );

		return in_array( $behavior, array( 'site_search', 'offline', 'fallback_model' ), true ) ? $behavior : 'site_search';
	}

	/**
	 * Adds usage from one reply and sends alerts that became due.
	 *
	 * @param int        $tokens Input plus output tokens.
	 * @param float|null $cost   Estimated cost in USD (null when unknown).
	 * @return void
	 */
	public function record( int $tokens, ?float $cost ): void {
		$usage = $this->usage();

		$usage['day_tokens']   += max( 0, $tokens );
		$usage['month_tokens'] += max( 0, $tokens );
		$usage['day_cost']     += max( 0.0, (float) $cost );
		$usage['month_cost']   += max( 0.0, (float) $cost );

		foreach ( array( 'day', 'month' ) as $period ) {
			$budget = $this->budget( $period );
			if ( $budget <= 0 ) {
				continue;
			}

			$percent = 100 * $this->spent( $usage, $period ) / $budget;

			foreach ( self::THRESHOLDS as $threshold ) {
				if ( $percent >= $threshold && ! in_array( $threshold, $usage['alerts'][ $period ], true ) ) {
					$usage['alerts'][ $period ][] = $threshold;
					$this->alert( $period, $threshold, $usage );

					if ( 100 === $threshold ) {
						/**
						 * Fires when a daily or monthly budget is used up.
						 *
						 * @param string               $period day or month.
						 * @param array<string, mixed> $usage  Current usage.
						 */
						do_action( 'chatlume_budget_reached', $period, $usage );
					}
				}
			}
		}

		update_option( self::OPTION, $usage, false );
	}

	/**
	 * Current usage, reset at the start of each day and month.
	 *
	 * @return array{day: string, month: string, day_tokens: int, month_tokens: int, day_cost: float, month_cost: float, alerts: array{day: int[], month: int[]}}
	 */
	public function usage(): array {
		$day   = wp_date( 'Y-m-d' );
		$month = wp_date( 'Y-m' );
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$usage = array(
			'day'          => $day,
			'month'        => $month,
			'day_tokens'   => 0,
			'month_tokens' => 0,
			'day_cost'     => 0.0,
			'month_cost'   => 0.0,
			'alerts'       => array(
				'day'   => array(),
				'month' => array(),
			),
		);

		if ( ( $saved['month'] ?? '' ) === $month ) {
			$usage['month_tokens']    = (int) ( $saved['month_tokens'] ?? 0 );
			$usage['month_cost']      = (float) ( $saved['month_cost'] ?? 0 );
			$usage['alerts']['month'] = array_map( 'intval', (array) ( $saved['alerts']['month'] ?? array() ) );
		}

		if ( ( $saved['day'] ?? '' ) === $day ) {
			$usage['day_tokens']    = (int) ( $saved['day_tokens'] ?? 0 );
			$usage['day_cost']      = (float) ( $saved['day_cost'] ?? 0 );
			$usage['alerts']['day'] = array_map( 'intval', (array) ( $saved['alerts']['day'] ?? array() ) );
		}

		return $usage;
	}

	/**
	 * Budget for a period in the configured unit (0 = none).
	 *
	 * @param string $period day or month.
	 * @return float
	 */
	private function budget( string $period ): float {
		return (float) Settings::get( 'costs.' . ( 'day' === $period ? 'daily_budget' : 'monthly_budget' ), 0 );
	}

	/**
	 * Amount used in a period in the configured unit.
	 *
	 * @param array<string, mixed> $usage  Usage.
	 * @param string               $period day or month.
	 * @return float
	 */
	private function spent( array $usage, string $period ): float {
		$unit = 'tokens' === Settings::get( 'costs.budget_unit', 'currency' ) ? 'tokens' : 'cost';

		return (float) $usage[ $period . '_' . $unit ];
	}

	/**
	 * Emails the admin about a budget threshold.
	 *
	 * @param string               $period    day or month.
	 * @param int                  $threshold 80 or 100.
	 * @param array<string, mixed> $usage     Usage.
	 * @return void
	 */
	private function alert( string $period, int $threshold, array $usage ): void {
		$to = trim( (string) Settings::get( 'costs.alert_email', '' ) );
		$to = '' !== $to ? $to : (string) get_option( 'admin_email' );

		if ( ! is_email( $to ) ) {
			return;
		}

		$site   = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$tokens = 'tokens' === Settings::get( 'costs.budget_unit', 'currency' );
		$spent  = $tokens
			? number_format_i18n( (float) $usage[ $period . '_tokens' ] ) . ' ' . __( 'tokens', 'chatlume-ai-chatbot' )
			: '$' . number_format_i18n( (float) $usage[ $period . '_cost' ], 2 );
		$budget = $tokens
			? number_format_i18n( $this->budget( $period ) ) . ' ' . __( 'tokens', 'chatlume-ai-chatbot' )
			: '$' . number_format_i18n( $this->budget( $period ), 2 );

		$period_label = 'day' === $period ? __( 'daily', 'chatlume-ai-chatbot' ) : __( 'monthly', 'chatlume-ai-chatbot' );

		$subject = 100 === $threshold
			/* translators: 1: Site name, 2: "daily" or "monthly". */
			? sprintf( __( '[%1$s] Chatlume reached its %2$s AI budget', 'chatlume-ai-chatbot' ), $site, $period_label )
			/* translators: 1: Site name, 2: "daily" or "monthly". */
			: sprintf( __( '[%1$s] Chatlume used 80%% of its %2$s AI budget', 'chatlume-ai-chatbot' ), $site, $period_label );

		$behavior = array(
			'site_search'    => __( 'The chatbot now answers only with quick answers and site search until the budget resets.', 'chatlume-ai-chatbot' ),
			'offline'        => __( 'The chatbot now shows your offline message until the budget resets.', 'chatlume-ai-chatbot' ),
			'fallback_model' => __( 'The chatbot now uses your fallback model until the budget resets.', 'chatlume-ai-chatbot' ),
		);

		$lines = array(
			/* translators: 1: Amount used, 2: Budget, 3: "daily" or "monthly". */
			sprintf( __( 'Chatlume has used %1$s of its %3$s budget of %2$s.', 'chatlume-ai-chatbot' ), $spent, $budget, $period_label ),
			100 === $threshold ? $behavior[ $this->cap_behavior() ] : __( 'Nothing has changed yet. The budget rule applies once it is fully used.', 'chatlume-ai-chatbot' ),
			/* translators: %s: Admin URL. */
			sprintf( __( 'You can raise the budget in Chatlume: %s', 'chatlume-ai-chatbot' ), admin_url( 'admin.php?page=chatlume' ) ),
		);

		wp_mail( $to, $subject, implode( "\n\n", $lines ) );
	}
}
