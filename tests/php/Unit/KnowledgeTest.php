<?php
/**
 * Tests for the knowledge index: chunking, languages, eligibility, storage,
 * and background rebuilds.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Cron\IndexQueue;
use Chatlume\Data\KnowledgeRepository;
use Chatlume\Knowledge\Chunker;
use Chatlume\Knowledge\Indexer;
use Chatlume\Knowledge\Language;
use Chatlume\REST\KnowledgeController;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;
use WP_Error;
use WP_Post;
use WP_REST_Request;

/**
 * Knowledge index.
 */
class KnowledgeTest extends TestCase {

	/**
	 * Fake database with an in-memory knowledge table and posts.
	 *
	 * @var object
	 */
	private object $db;

	/**
	 * Posts by ID.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $posts = array();

	/**
	 * Scheduled single events: [timestamp, hook].
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private array $scheduled = array();

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
			 * Posts table.
			 *
			 * @var string
			 */
			public $posts = 'wp_posts';

			/**
			 * Knowledge rows.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = array();

			/**
			 * Post IDs that match the candidate query.
			 *
			 * @var int[]
			 */
			public array $post_ids = array();

			/**
			 * Prepared queries: [sql, values].
			 *
			 * @var array<int, array{0: string, 1: array}>
			 */
			public array $prepared = array();

			/**
			 * Checks that placeholders and values match.
			 *
			 * @param string $query   Query.
			 * @param mixed  ...$args Values (or one array of values).
			 * @return string
			 * @throws \RuntimeException On a mismatch.
			 */
			public function prepare( $query, ...$args ) {
				if ( 1 === count( $args ) && is_array( $args[0] ) ) {
					$args = $args[0];
				}
				$placeholders = preg_match_all( '/%[sdfi]/', $query );
				if ( count( $args ) !== $placeholders ) {
					// Test double: the message is asserted on, never printed.
					throw new \RuntimeException( "Expected $placeholders values, got " . count( $args ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				$this->prepared[] = array( $query, $args );
				return $query;
			}

			/**
			 * Last prepared values.
			 *
			 * @return array
			 */
			private function args(): array {
				return end( $this->prepared )[1];
			}

			/**
			 * Escapes LIKE wildcards like core.
			 *
			 * @param string $text Text.
			 * @return string
			 */
			public function esc_like( $text ) {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * Rows of one source.
			 *
			 * @param string $query Query.
			 * @return array
			 */
			public function get_results( $query ) {
				$args = $this->args();
				if ( false !== strpos( $query, 'SELECT chunk_index' ) ) {
					return array_values(
						array_filter(
							$this->rows,
							static function ( $row ) use ( $args ) {
								return $row['source_type'] === $args[1] && $row['source_id'] === $args[2];
							}
						)
					);
				}
				return array();
			}

			/**
			 * Inserts a row.
			 *
			 * @param string $table Table.
			 * @param array  $data  Row.
			 * @return int
			 */
			public function insert( $table, $data ) {
				unset( $table );
				$this->rows[] = $data;
				return 1;
			}

			/**
			 * Deletes rows of a source, or rows whose post is no longer a
			 * candidate (the end-of-rebuild purge).
			 *
			 * @param string $query Query.
			 * @return int
			 */
			public function query( $query ) {
				$args     = $this->args();
				$before   = count( $this->rows );
				$unlisted = false !== strpos( $query, 'LEFT JOIN' );
				$type     = $unlisted ? end( $args ) : $args[1];
				$post_ids = array_map( 'strval', $this->post_ids );

				$this->rows = array_values(
					array_filter(
						$this->rows,
						static function ( $row ) use ( $args, $unlisted, $type, $post_ids ) {
							if ( $row['source_type'] !== $type ) {
								return true;
							}
							return $unlisted ? in_array( $row['source_id'], $post_ids, true ) : $row['source_id'] !== $args[2];
						}
					)
				);

				return $before - count( $this->rows );
			}

			/**
			 * Candidate post IDs after an ID, in order, limited.
			 *
			 * @return array
			 */
			public function get_col() {
				$args  = $this->args();
				$limit = (int) array_pop( $args );
				$after = (int) array_pop( $args );
				$ids   = array_values(
					array_filter(
						$this->post_ids,
						static function ( $id ) use ( $after ) {
							return $id > $after;
						}
					)
				);
				return array_map( 'strval', array_slice( $ids, 0, $limit ) );
			}

			/**
			 * Candidate count.
			 *
			 * @return string
			 */
			public function get_var() {
				return (string) count( $this->post_ids );
			}

			/**
			 * Totals.
			 *
			 * @return array
			 */
			public function get_row() {
				return array(
					'chunks'     => (string) count( $this->rows ),
					'sources'    => '1',
					'bytes'      => '100',
					'embedded'   => null,
					'stale'      => null,
					'updated_at' => '2026-10-18 10:00:00',
				);
			}
		};
		$wpdb     = $this->db;

		$this->posts     = array();
		$this->scheduled = array();

		Functions\when( 'post_type_exists' )->alias(
			static function ( $type ) {
				return in_array( $type, array( 'post', 'page', 'product' ), true );
			}
		);
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_the_title' )->alias(
			static function ( $post ) {
				return $post->post_title;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				return 'https://example.test/?p=' . $post->ID;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->posts[ (int) $id ] ?? null;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[] = array( (int) $timestamp, (string) $hook );
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			function ( $hook ) {
				$this->scheduled = array_values(
					array_filter(
						$this->scheduled,
						static function ( $event ) use ( $hook ) {
							return $event[1] !== $hook;
						}
					)
				);
				return 0;
			}
		);
	}

	protected function tear_down(): void {
		global $wpdb;
		$wpdb = null;
		parent::tear_down();
	}

	/**
	 * Stores knowledge settings.
	 *
	 * @param array<string, mixed> $knowledge Knowledge section.
	 * @return void
	 */
	private function knowledge( array $knowledge ): void {
		$this->options['chatlume_settings'] = array( 'knowledge' => $knowledge );
	}

	/**
	 * A published post.
	 *
	 * @param int                  $id     ID.
	 * @param array<string, mixed> $fields Fields.
	 * @return WP_Post
	 */
	private function post( int $id, array $fields = array() ): WP_Post {
		$post = new WP_Post(
			array_merge(
				array(
					'ID'           => $id,
					'post_type'    => 'page',
					'post_title'   => 'Page ' . $id,
					'post_content' => '<p>Opening hours are 9 to 5.</p>',
				),
				$fields
			)
		);

		$this->posts[ $id ] = $post;

		return $post;
	}

	/**
	 * Source IDs in the fake table.
	 *
	 * @param string $type Source type.
	 * @return string[]
	 */
	private function indexed( string $type = Indexer::TYPE_POST ): array {
		$ids = array();
		foreach ( $this->db->rows as $row ) {
			if ( $row['source_type'] === $type ) {
				$ids[] = $row['source_id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	public function test_clean_removes_markup_shortcodes_and_scripts(): void {
		$text = Chunker::clean( '<h2>Delivery</h2><p>We ship &amp; deliver.</p>[gallery ids="1,2"]<script>alert(1)</script><p>Free&nbsp;returns.</p>[/old_tag]' );

		$this->assertSame( "Delivery\n\nWe ship & deliver.\n\nFree returns.", $text );
	}

	public function test_short_text_is_one_chunk(): void {
		$this->assertSame( array( 'Hello there.' ), Chunker::split( '  Hello there. ' ) );
		$this->assertSame( array(), Chunker::split( '   ' ) );
	}

	public function test_long_text_splits_with_overlap_and_keeps_every_word(): void {
		$sentences = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$sentences[] = "Sentence number $i tells something useful about the shop.";
		}
		$text   = implode( ' ', $sentences );
		$chunks = Chunker::split( $text );

		$this->assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( Chunker::SIZE + Chunker::OVERLAP, mb_strlen( $chunk ) );
		}
		// The next chunk starts with the end of the previous one.
		$this->assertStringContainsString( substr( $chunks[1], 0, 30 ), $chunks[0] );
		foreach ( array( 'number 1 ', 'number 30 ', 'number 60 ' ) as $needle ) {
			$this->assertStringContainsString( $needle, implode( ' ', $chunks ) );
		}
	}

	public function test_multibyte_text_is_split_without_breaking_characters(): void {
		$bengali = str_repeat( 'আমাদের দোকানে সব পণ্য পাওয়া যায়। ', 60 );
		$chinese = str_repeat( '我们的商店全天营业。', 200 );

		foreach ( array( $bengali, $chinese ) as $text ) {
			$chunks = Chunker::split( $text );
			$this->assertGreaterThan( 1, count( $chunks ) );
			foreach ( $chunks as $chunk ) {
				$this->assertTrue( mb_check_encoding( $chunk, 'UTF-8' ) );
				$this->assertLessThanOrEqual( Chunker::SIZE + Chunker::OVERLAP, mb_strlen( $chunk ) );
			}
		}
	}

	public function test_primary_language_codes(): void {
		$this->assertSame( 'en', Language::primary( 'en_US' ) );
		$this->assertSame( 'pt', Language::primary( 'pt-BR' ) );
		$this->assertSame( 'bn', Language::primary( 'bn' ) );
		$this->assertSame( '', Language::primary( '' ) );
		$this->assertSame( '', Language::primary( '12_AB' ) );
		$this->assertFalse( Language::multilingual() );
		$this->assertSame( 'en', Language::of_post( 5 ) );
	}

	public function test_eligibility_rules(): void {
		$this->knowledge(
			array(
				'post_types'  => array( 'page', 'post', 'missing_type' ),
				'include_ids' => array( 40 ),
				'exclude_ids' => array( 3 ),
			)
		);

		$this->assertSame( array( 'page', 'post' ), Indexer::post_types() );
		$this->assertTrue( Indexer::eligible( $this->post( 1 ) ) );
		$this->assertFalse( Indexer::eligible( $this->post( 2, array( 'post_status' => 'draft' ) ) ) );
		$this->assertFalse( Indexer::eligible( $this->post( 3 ) ) );
		$this->assertFalse( Indexer::eligible( $this->post( 4, array( 'post_password' => 'secret' ) ) ) );
		$this->assertFalse( Indexer::eligible( $this->post( 5, array( 'post_type' => 'product' ) ) ) );
		$this->assertTrue( Indexer::eligible( $this->post( 40, array( 'post_type' => 'product' ) ) ) );
	}

	public function test_saving_indexes_and_unpublishing_removes(): void {
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );
		$indexer = new Indexer();
		$post    = $this->post( 7 );

		$indexer->on_save( 7, $post );
		$this->assertSame( array( '7' ), $this->indexed() );
		$row = $this->db->rows[0];
		$this->assertSame( 'Page 7', $row['title'] );
		$this->assertSame( 'https://example.test/?p=7', $row['url'] );
		$this->assertSame( 'en', $row['language'] );
		$this->assertSame( "Page 7\n\nOpening hours are 9 to 5.", $row['content'] );

		$post->post_status = 'draft';
		$indexer->on_save( 7, $post );
		$this->assertSame( array(), $this->indexed() );

		$post->post_status = 'publish';
		$indexer->on_save( 7, $post );
		$indexer->on_remove( 7 );
		$this->assertSame( array(), $this->indexed() );
	}

	public function test_unchosen_content_is_skipped_without_queries(): void {
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );

		( new Indexer() )->on_save( 9, $this->post( 9, array( 'post_type' => 'product' ) ) );

		$this->assertSame( array(), $this->db->prepared );
	}

	public function test_sources_filter_can_change_or_drop_a_source(): void {
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, $post = null ) {
				if ( 'chatlume_knowledge_sources' !== $hook ) {
					return $value;
				}
				return 8 === $post->ID ? null : array_merge( $value, array( 'text' => 'Custom text.' ) );
			}
		);
		$indexer = new Indexer();

		$this->assertSame( 1, $indexer->index_post( $this->post( 1 ) ) );
		$this->assertSame( 0, $indexer->index_post( $this->post( 8 ) ) );
		$this->assertSame( array( '1' ), $this->indexed() );
		$this->assertSame( 'Custom text.', $this->db->rows[0]['content'] );
	}

	public function test_business_info_is_indexed_and_removed_when_empty(): void {
		$this->knowledge( array( 'business_info' => 'We are open every day.' ) );
		$indexer = new Indexer();

		$this->assertSame( 1, $indexer->index_business_info() );
		$this->assertSame( array( 'info' ), $this->indexed( Indexer::TYPE_BUSINESS ) );
		$this->assertSame( 'About this business', $this->db->rows[0]['title'] );

		$this->knowledge( array( 'business_info' => '' ) );
		$this->assertSame( 0, $indexer->index_business_info() );
		$this->assertSame( array(), $this->indexed( Indexer::TYPE_BUSINESS ) );
	}

	public function test_unchanged_chunks_keep_their_embedding(): void {
		$repository = new KnowledgeRepository();
		$repository->replace( 'post', '1', 'T', '', 'en', array( 'First part.', 'Second part.' ) );
		$this->db->rows[0]['embedding']       = 'vector-1';
		$this->db->rows[0]['embedding_model'] = 'model-a';
		$this->db->rows[0]['embedding_dims']  = 3;
		$this->db->rows[1]['embedding']       = 'vector-2';
		$this->db->rows[1]['is_stale']        = 0;

		$repository->replace( 'post', '1', 'T', '', 'en', array( 'First part.', 'Changed part.' ) );

		$this->assertCount( 2, $this->db->rows );
		$this->assertSame( 'vector-1', $this->db->rows[0]['embedding'] );
		$this->assertSame( 'model-a', $this->db->rows[0]['embedding_model'] );
		$this->assertSame( 3, $this->db->rows[0]['embedding_dims'] );
		$this->assertNull( $this->db->rows[1]['embedding'] );
	}

	public function test_stats_are_typed(): void {
		$stats = ( new KnowledgeRepository() )->stats();

		$this->assertSame( 0, $stats['embedded'] );
		$this->assertSame( 0, $stats['stale'] );
		$this->assertSame( '2026-10-18T10:00:00Z', $stats['updated_at'] );
	}

	public function test_rebuild_runs_in_batches_and_purges_what_is_gone(): void {
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );
		$ids = range( 1, IndexQueue::BATCH + 3 );
		foreach ( $ids as $id ) {
			$this->post( $id );
		}
		$this->db->post_ids = $ids;
		// Indexed earlier, no longer eligible.
		$this->db->rows[] = array(
			'source_type' => 'post',
			'source_id'   => '999',
			'updated_at'  => '2000-01-01 00:00:00',
		);

		$queue = new IndexQueue();
		$state = $queue->start();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( count( $ids ), $state['total'] );
		$this->assertSame( IndexQueue::HOOK, $this->scheduled[0][1] );

		$state = $queue->run_batch();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( IndexQueue::BATCH, $state['done'] );
		$this->assertCount( 2, $this->scheduled );

		$state = $queue->run_batch();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( count( $ids ), $state['done'] );
		$this->assertNotContains( '999', $this->indexed() );
		$this->assertCount( count( $ids ), $this->indexed() );

		// Finished: further runs change nothing.
		$this->assertSame( $state, $queue->run_batch() );
	}

	public function test_late_cron_is_caught_up_by_status_checks(): void {
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );
		$this->post( 1 );
		$this->db->post_ids = array( 1 );
		$queue              = new IndexQueue();
		$queue->start();

		$this->assertSame( 'running', $queue->maybe_run()['status'] );

		$this->options[ IndexQueue::OPTION ]['next_run'] = time() - 600;
		$this->assertSame( 'done', $queue->maybe_run()['status'] );
	}

	public function test_first_index_starts_once(): void {
		$queue = new IndexQueue();
		$queue->maybe_start_first();
		$this->assertSame( 'running', IndexQueue::state()['status'] );

		$this->options[ IndexQueue::OPTION ]['status'] = 'done';
		$queue->maybe_start_first();
		$this->assertSame( 'done', IndexQueue::state()['status'] );
	}

	public function test_settings_changes_reindex_only_what_changed(): void {
		$this->post( 5 );
		$this->post( 6, array( 'post_type' => 'product' ) );
		$queue = new IndexQueue();
		$old   = array(
			'knowledge' => array(
				'post_types'    => array( 'page' ),
				'include_ids'   => array(),
				'exclude_ids'   => array(),
				'business_info' => '',
			),
		);

		// Excluding one page removes just that page.
		$this->knowledge( array( 'post_types' => array( 'page' ) ) );
		( new Indexer() )->index_post( $this->posts[5] );
		$new                                = $old;
		$new['knowledge']['exclude_ids']    = array( 5 );
		$this->options['chatlume_settings'] = $new;
		$queue->on_settings( $old, $new );
		$this->assertSame( array(), $this->indexed() );
		$this->assertArrayNotHasKey( IndexQueue::OPTION, $this->options );

		// Including one product indexes just that product.
		$new                                = $old;
		$new['knowledge']['include_ids']    = array( 6 );
		$this->options['chatlume_settings'] = $new;
		$queue->on_settings( $old, $new );
		$this->assertSame( array( '6' ), $this->indexed() );

		// New business information indexes only that text.
		$new                                = $old;
		$new['knowledge']['business_info']  = 'Family run since 1990.';
		$this->options['chatlume_settings'] = $new;
		$queue->on_settings( $old, $new );
		$this->assertSame( array( 'info' ), $this->indexed( Indexer::TYPE_BUSINESS ) );
		$this->assertArrayNotHasKey( IndexQueue::OPTION, $this->options );

		// Other post types rebuild everything; the order does not matter.
		$new                            = $old;
		$new['knowledge']['post_types'] = array( 'page' );
		$queue->on_settings( $old, $new );
		$this->assertArrayNotHasKey( IndexQueue::OPTION, $this->options );
		$new['knowledge']['post_types'] = array( 'post', 'page' );
		$queue->on_settings( $old, $new );
		$this->assertSame( 'running', IndexQueue::state()['status'] );
	}

	public function test_controller_requires_a_manager(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );

		$result = ( new KnowledgeController() )->permissions_check();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_controller_reports_status_and_sources(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		$this->options['chatlume_fulltext_available'] = 'yes';
		$controller                                   = new KnowledgeController();

		$status = $controller->reindex()->get_data();
		$this->assertSame( 'running', $status['queue']['status'] );
		$this->assertTrue( $status['fulltext'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $status['queue']['started'] );
		$this->assertSame( '', $status['queue']['finished'] );

		$response = $controller->get_sources(
			new WP_REST_Request(
				array(
					'type'     => 'post',
					'search'   => 'hours',
					'page'     => 2,
					'per_page' => 10,
				)
			)
		);

		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( '0', $response->get_headers()['X-WP-Total'] );
		$last = end( $this->db->prepared );
		$this->assertSame( array( 'wp_chatlume_knowledge', 'post', 'post', '%hours%' ), $last[1] );
	}

	public function test_knowledge_settings_are_sanitized(): void {
		$result = Settings::update(
			array(
				'knowledge' => array(
					'post_types'    => array( 'Page', 'product' ),
					'include_ids'   => array( 3, '4' ),
					'business_info' => "Open <b>daily</b>\nfrom 9.",
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'page', 'product' ), $result['knowledge']['post_types'] );
		$this->assertSame( array( 3, 4 ), $result['knowledge']['include_ids'] );
		$this->assertSame( array(), $result['knowledge']['exclude_ids'] );
		$this->assertSame( "Open daily\nfrom 9.", $result['knowledge']['business_info'] );

		// Out-of-range IDs are clamped, never stored negative.
		$result = Settings::update( array( 'knowledge' => array( 'include_ids' => array( -1 ) ) ) );
		$this->assertSame( array( 0 ), $result['knowledge']['include_ids'] );
		$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'knowledge' => array( 'include_ids' => array_fill( 0, 501, 1 ) ) ) ) );
	}
}
