<?php
/**
 * Tests for Dashboard statistics.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Data\Stats;
use Chatlume\REST\StatsController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Totals, savings, daily series, unanswered questions, and the checklist.
 */
class StatsTest extends TestCase {

	/**
	 * Fake database.
	 *
	 * @var object
	 */
	private object $db;

	protected function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->db = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Results by query keyword.
			 *
			 * @var array<string, mixed>
			 */
			public array $answers = array();

			/**
			 * Prepared queries.
			 *
			 * @var array<int, array{0: string, 1: array}>
			 */
			public array $prepared = array();

			/**
			 * Checks placeholders and returns the query.
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return string
			 * @throws \RuntimeException On a mismatch.
			 */
			public function prepare( $query, ...$args ) {
				if ( preg_match_all( '/%[sdfi]/', $query ) !== count( $args ) ) {
					throw new \RuntimeException( 'Placeholder mismatch' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double.
				}
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			/**
			 * Escapes LIKE wildcards.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Answer for a query.
			 *
			 * @param string $query Query.
			 * @param mixed  $fallback Fallback.
			 * @return mixed
			 */
			private function answer( $query, $fallback ) {
				foreach ( $this->answers as $needle => $value ) {
					if ( false !== strpos( $query, $needle ) ) {
						return $value;
					}
				}
				return $fallback;
			}

			/**
			 * Single value.
			 *
			 * @param string $query Query.
			 * @return mixed
			 */
			public function get_var( $query ) {
				return $this->answer( $query, '0' );
			}

			/**
			 * Rows.
			 *
			 * @param string $query Query.
			 * @return array
			 */
			public function get_results( $query ) {
				return $this->answer( $query, array() );
			}

			/**
			 * Row.
			 *
			 * @param string $query Query.
			 * @return array|null
			 */
			public function get_row( $query ) {
				return $this->answer( $query, null );
			}
		};
		$wpdb     = $this->db;

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Asia/Dhaka' ) );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		parent::tear_down();
	}

	public function test_totals_and_estimated_savings(): void {
		$this->db->answers = array(
			'SELECT COUNT(*) FROM %i WHERE is_test' => '3',
			'GROUP BY m.role, m.source'             => array(
				array(
					'role'    => 'user',
					'source'  => '',
					'replies' => '6',
					'tokens'  => '0',
					'cost'    => '0',
				),
				array(
					'role'    => 'assistant',
					'source'  => 'ai',
					'replies' => '2',
					'tokens'  => '300',
					'cost'    => '0.004',
				),
				array(
					'role'    => 'assistant',
					'source'  => 'quick_answer',
					'replies' => '3',
					'tokens'  => '0',
					'cost'    => '0',
				),
				array(
					'role'    => 'assistant',
					'source'  => 'cache',
					'replies' => '1',
					'tokens'  => '0',
					'cost'    => '0',
				),
			),
		);

		$totals = ( new Stats() )->summary( 7 )['totals'];

		$this->assertSame( 3, $totals['conversations'] );
		$this->assertSame( 6, $totals['questions'] );
		$this->assertSame( 300, $totals['tokens'] );
		$this->assertSame( 4, $totals['free_answers'] );
		$this->assertSame( 0.002, $totals['average_ai_cost'] );
		$this->assertSame( 0.008, $totals['saved'], 'Four free answers at the average AI answer cost.' );
		$this->assertSame(
			array(
				'ai'           => 2,
				'quick_answer' => 3,
				'cache'        => 1,
			),
			$totals['replies_by_source']
		);
	}

	public function test_savings_use_the_all_time_average_when_the_period_had_no_ai_answers(): void {
		$this->db->answers = array(
			'GROUP BY m.role, m.source' => array(
				array(
					'role'    => 'assistant',
					'source'  => 'cache',
					'replies' => '5',
					'tokens'  => '0',
					'cost'    => '0',
				),
			),
			'source IN ( %s, %s )'      => array(
				'replies' => '10',
				'cost'    => '0.05',
			),
		);

		$this->assertSame( 0.025, ( new Stats() )->summary( 30 )['totals']['saved'] );
	}

	public function test_series_has_one_entry_per_local_day(): void {
		$today             = ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'Asia/Dhaka' ) ) )->format( 'Y-m-d' );
		$this->db->answers = array(
			'COUNT(*) AS total'         => array(
				array(
					'day'   => $today,
					'total' => '2',
				),
			),
			'AS questions, SUM( m.cost' => array(
				array(
					'day'       => $today,
					'questions' => '5',
					'cost'      => '0.01',
				),
				array(
					'day'       => '1999-01-01',
					'questions' => '9',
					'cost'      => '1',
				),
			),
		);

		$series = ( new Stats() )->summary( 7 )['series'];

		$this->assertCount( 7, $series );
		$this->assertSame( $today, $series[6]['date'] );
		$this->assertSame( 2, $series[6]['conversations'] );
		$this->assertSame( 5, $series[6]['questions'] );
		$this->assertSame( 0, $series[0]['questions'] );

		$offsets = array_column( array_filter( $this->db->prepared, static fn( $query ) => false !== strpos( $query[0], 'INTERVAL %d SECOND' ) ), 1 );
		$this->assertSame( 6 * 3600, $offsets[0][0], 'Days follow the site timezone (UTC+6).' );
	}

	public function test_unsupported_periods_fall_back_to_seven_days(): void {
		$this->assertSame( 7, ( new Stats() )->summary( 99 )['days'] );
		$this->assertSame(
			( new \DateTimeImmutable( 'today', new \DateTimeZone( 'Asia/Dhaka' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			Stats::period_start( 1 ),
			'Today starts at local midnight.'
		);
	}

	public function test_unanswered_questions_are_grouped_with_their_reasons(): void {
		$this->db->answers = array(
			'INNER JOIN %i q' => array(
				array(
					'question'   => 'Do you ship to Canada?',
					'source'     => 'site_search',
					'feedback'   => null,
					'meta'       => '{}',
					'created_at' => '2026-10-15 09:00:00',
					'language'   => 'en',
				),
				array(
					'question'   => 'do you ship to canada',
					'source'     => 'ai',
					'feedback'   => '-1',
					'meta'       => '{"no_knowledge_match":true}',
					'created_at' => '2026-10-15 08:00:00',
					'language'   => 'en',
				),
				array(
					'question'   => 'Refund policy?',
					'source'     => 'ai',
					'feedback'   => null,
					'meta'       => '{"ask_ai_miss":4}',
					'created_at' => '2026-10-15 10:00:00',
					'language'   => 'en',
				),
			),
		);

		$unanswered = ( new Stats() )->summary( 7 )['unanswered'];

		$this->assertCount( 2, $unanswered );
		$this->assertSame( 'Do you ship to Canada?', $unanswered[0]['question'] );
		$this->assertSame( 2, $unanswered[0]['count'] );
		$this->assertSame( array( 'site_search', 'not_helpful', 'no_knowledge' ), $unanswered[0]['reasons'] );
		$this->assertSame( array( 'ask_ai' ), $unanswered[1]['reasons'] );
	}

	public function test_checklist_reflects_the_setup(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		$this->db->answers                 = array( 'SELECT COUNT(*) FROM %i WHERE' => '0' );
		$this->options[ Settings::OPTION ] = array(
			'chatbot' => array( 'bot_name' => 'Ava' ),
			'display' => array( 'enabled' => false ),
		);

		$steps = array_column( StatsController::checklist( true ), 'done', 'id' );

		$this->assertFalse( $steps['provider'] );
		$this->assertTrue( $steps['personalize'] );
		$this->assertFalse( $steps['widget'] );
		$this->assertFalse( $steps['quick_answers'] );
		$this->assertTrue( $steps['first_chat'] );
	}

	public function test_route_is_admin_only(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new StatsController() )->permissions_check() );
	}
}
