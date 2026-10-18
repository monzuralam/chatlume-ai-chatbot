<?php
/**
 * Full (re)builds of the knowledge index in the background.
 *
 * @package Chatlume
 */

namespace Chatlume\Cron;

use Chatlume\Installer\Schema;
use Chatlume\Knowledge\Indexer;
use Chatlume\Knowledge\ProductSync;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indexes every eligible post in WP-Cron batches, so large sites never time
 * out, then removes what is no longer eligible. A rebuild starts on a fresh
 * site, when the knowledge sources change, and on "Re-index all". When
 * WP-Cron runs late, the admin's progress checks run the next batch.
 */
class IndexQueue {

	/**
	 * State option (not autoloaded).
	 */
	public const OPTION = 'chatlume_index_state';

	/**
	 * Cron hook for one batch.
	 */
	public const HOOK = 'chatlume_index_batch';

	/**
	 * Posts per batch.
	 */
	public const BATCH = 25;

	/**
	 * Seconds after which a due batch is considered stuck.
	 */
	private const LATE = 60;

	/**
	 * Indexer.
	 *
	 * @var Indexer
	 */
	private Indexer $indexer;

	/**
	 * Constructor.
	 *
	 * @param Indexer|null $indexer Indexer (tests).
	 */
	public function __construct( ?Indexer $indexer = null ) {
		$this->indexer = $indexer ?? new Indexer();
	}

	/**
	 * Hooks the queue.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run_batch' ) );
		// Admin only: front-end requests never pay for the check.
		add_action( 'admin_init', array( $this, 'maybe_start_first' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings' ), 10, 2 );
	}

	/**
	 * A fresh site builds its first index once. The index is also rebuilt
	 * when the post types it covers change outside the knowledge settings:
	 * WooCommerce activated or deactivated, a custom post type removed, or
	 * an update that adds a source.
	 *
	 * @return void
	 */
	public function maybe_start_first(): void {
		$stored = get_option( self::OPTION, false );
		if ( false === $stored || ( is_array( $stored ) && self::candidate_types() !== self::state()['types'] ) ) {
			$this->start();
		}
	}

	/**
	 * Knowledge sources changed. New post types rebuild everything; items
	 * included or excluded one by one, and the business information, are
	 * re-indexed on their own.
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 * @return void
	 */
	public function on_settings( $old_value, $value ): void {
		$before = is_array( $old_value ) && is_array( $old_value['knowledge'] ?? null ) ? $old_value['knowledge'] : array();
		$after  = is_array( $value ) && is_array( $value['knowledge'] ?? null ) ? $value['knowledge'] : array();

		$types_before = array_values( (array) ( $before['post_types'] ?? array() ) );
		$types_after  = array_values( (array) ( $after['post_types'] ?? array() ) );
		sort( $types_before );
		sort( $types_after );
		if ( $types_before !== $types_after || ( $before['products'] ?? true ) !== ( $after['products'] ?? true ) ) {
			$this->start();
			return;
		}

		$changed = array_merge(
			self::changed_ids( $before['include_ids'] ?? array(), $after['include_ids'] ?? array() ),
			self::changed_ids( $before['exclude_ids'] ?? array(), $after['exclude_ids'] ?? array() )
		);
		foreach ( array_unique( $changed ) as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$this->indexer->index_post( $post );
			} else {
				$this->indexer->on_remove( $id );
			}
		}

		if ( (string) ( $before['business_info'] ?? '' ) !== (string) ( $after['business_info'] ?? '' ) ) {
			$this->indexer->index_business_info();
		}
	}

	/**
	 * IDs added to or removed from a list.
	 *
	 * @param mixed $before Previous list.
	 * @param mixed $after  New list.
	 * @return int[]
	 */
	private static function changed_ids( $before, $after ): array {
		$before = array_map( 'intval', (array) $before );
		$after  = array_map( 'intval', (array) $after );

		return array_values( array_filter( array_merge( array_diff( $before, $after ), array_diff( $after, $before ) ) ) );
	}

	/**
	 * Starts a full rebuild (restarts one that is running).
	 *
	 * @return array<string, mixed> State.
	 */
	public function start(): array {
		$this->indexer->index_business_info();

		$state = array(
			'status'   => 'running',
			'total'    => $this->count(),
			'done'     => 0,
			'after_id' => 0,
			'started'  => current_time( 'mysql', true ),
			'finished' => '',
			'next_run' => time(),
			'types'    => self::candidate_types(),
		);
		update_option( self::OPTION, $state, false );

		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time(), self::HOOK );

		return $state;
	}

	/**
	 * Indexes the next batch; finishes when nothing is left.
	 *
	 * @return array<string, mixed> State.
	 */
	public function run_batch(): array {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$ids = $this->next_ids( (int) $state['after_id'] );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$this->indexer->index_post( $post );
			}
			$state['after_id'] = $id;
			++$state['done'];
		}

		if ( count( $ids ) < self::BATCH ) {
			// Sources whose post is gone, unpublished, or no longer chosen.
			$included = self::included_ids();
			self::purge_unlisted( Indexer::TYPE_POST, Indexer::post_types(), $included );
			self::purge_unlisted( ProductSync::TYPE, ProductSync::enabled() ? array( ProductSync::TYPE ) : array(), $included );
			$state['status']   = 'done';
			$state['done']     = max( (int) $state['done'], (int) $state['total'] );
			$state['finished'] = current_time( 'mysql', true );
			$state['next_run'] = 0;
		} else {
			$state['next_run'] = time() + 5;
			wp_schedule_single_event( $state['next_run'], self::HOOK );
		}

		update_option( self::OPTION, $state, false );

		return $state;
	}

	/**
	 * Runs a batch when WP-Cron is late (called from the admin's progress
	 * checks).
	 *
	 * @return array<string, mixed> State.
	 */
	public function maybe_run(): array {
		$state = self::state();
		if ( 'running' === $state['status'] && (int) $state['next_run'] + self::LATE < time() ) {
			return $this->run_batch();
		}

		return $state;
	}

	/**
	 * Current state.
	 *
	 * @return array{status: string, total: int, done: int, after_id: int, started: string, finished: string, next_run: int, types: string[]}
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		return array(
			// Post types the last rebuild covered.
			'types'    => array_values( array_map( 'strval', (array) ( $state['types'] ?? array() ) ) ),
			'status'   => in_array( $state['status'] ?? '', array( 'running', 'done' ), true ) ? (string) $state['status'] : 'idle',
			'total'    => (int) ( $state['total'] ?? 0 ),
			'done'     => (int) ( $state['done'] ?? 0 ),
			'after_id' => (int) ( $state['after_id'] ?? 0 ),
			'started'  => (string) ( $state['started'] ?? '' ),
			'finished' => (string) ( $state['finished'] ?? '' ),
			'next_run' => (int) ( $state['next_run'] ?? 0 ),
		);
	}

	/**
	 * Post types rebuilt in full: the chosen ones, plus WooCommerce products
	 * when they are indexed.
	 *
	 * @return string[]
	 */
	private static function candidate_types(): array {
		$types = Indexer::post_types();
		if ( ProductSync::enabled() ) {
			$types[] = ProductSync::TYPE;
		}

		return $types;
	}

	/**
	 * Eligible candidates: published posts of the chosen types or chosen one
	 * by one (the indexer checks each post again).
	 *
	 * @param int $after_id Continue after this ID.
	 * @return int[]
	 */
	private function next_ids( int $after_id ): array {
		global $wpdb;

		$types    = self::candidate_types();
		$included = self::included_ids();
		if ( array() === $types && array() === $included ) {
			return array();
		}

		// Placeholders so the IN() lists are never empty.
		$types    = array() === $types ? array( '' ) : $types;
		$included = array() === $included ? array( 0 ) : $included;
		$type_in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$id_in    = implode( ',', array_fill( 0, count( $included ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() lists are only placeholders; all values are prepared. Read in ID order so batches resume reliably.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_status = 'publish' AND post_password = '' AND ( post_type IN ($type_in) OR ID IN ($id_in) ) AND ID > %d ORDER BY ID ASC LIMIT %d",
				array_merge( array( $wpdb->posts ), $types, $included, array( $after_id, self::BATCH ) )
			)
		);
		// phpcs:enable

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Candidates in total (for the progress bar).
	 *
	 * @return int
	 */
	private function count(): int {
		global $wpdb;

		$types    = self::candidate_types();
		$included = self::included_ids();
		$types    = array() === $types ? array( '' ) : $types;
		$included = array() === $included ? array( 0 ) : $included;
		$type_in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$id_in    = implode( ',', array_fill( 0, count( $included ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() lists are only placeholders; all values are prepared.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_password = '' AND ( post_type IN ($type_in) OR ID IN ($id_in) )",
				array_merge( array( $wpdb->posts ), $types, $included )
			)
		);
		// phpcs:enable

		return (int) $total;
	}

	/**
	 * Items chosen one by one.
	 *
	 * @return int[]
	 */
	private static function included_ids(): array {
		return array_values( array_filter( array_map( 'intval', (array) Settings::get( 'knowledge.include_ids', array() ) ) ) );
	}

	/**
	 * Removes indexed sources of a type whose post is no longer a candidate
	 * (deleted, unpublished, password-protected, or of a type no longer
	 * chosen). Compares with the posts table, not with times, so sources
	 * saved while the rebuild ran are never lost or kept by mistake.
	 *
	 * @param string   $source_type Source type.
	 * @param string[] $types       Post types still indexed.
	 * @param int[]    $included    Items chosen one by one.
	 * @return void
	 */
	private static function purge_unlisted( string $source_type, array $types, array $included ): void {
		global $wpdb;

		// Placeholders so the IN() lists are never empty.
		$types    = array() === $types ? array( '' ) : $types;
		$included = array() === $included ? array( 0 ) : $included;
		$type_in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$id_in    = implode( ',', array_fill( 0, count( $included ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The IN() lists are only placeholders; all values are prepared.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE k FROM %i k LEFT JOIN %i p ON p.ID = k.source_id AND p.post_status = 'publish' AND p.post_password = '' AND ( p.post_type IN ($type_in) OR p.ID IN ($id_in) ) WHERE k.source_type = %s AND p.ID IS NULL",
				array_merge( array( Schema::table( 'knowledge' ), $wpdb->posts ), $types, $included, array( $source_type ) )
			)
		);
		// phpcs:enable
	}
}
