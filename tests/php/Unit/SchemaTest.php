<?php
/**
 * Tests for Schema.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Installer\Schema;
use Chatlume\Tests\TestCase;

/**
 * Table definitions in dbDelta format.
 */
class SchemaTest extends TestCase {

	/**
	 * Statements for the default prefix.
	 *
	 * @var array<string, string>
	 */
	private array $statements;

	protected function set_up(): void {
		parent::set_up();
		$this->statements = Schema::statements( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' );
	}

	public function test_defines_every_table_with_prefix(): void {
		$this->assertSame( Schema::TABLES, array_keys( $this->statements ) );

		foreach ( $this->statements as $name => $sql ) {
			$this->assertStringStartsWith( "CREATE TABLE wp_chatlume_{$name} (", $sql );
			$this->assertStringEndsWith( 'COLLATE utf8mb4_unicode_520_ci;', $sql );
		}
	}

	public function test_uses_dbdelta_formatting(): void {
		foreach ( $this->statements as $sql ) {
			// dbDelta requires exactly two spaces after PRIMARY KEY and KEY (not INDEX).
			$this->assertMatchesRegularExpression( '/PRIMARY KEY  \(/', $sql );
			$this->assertDoesNotMatchRegularExpression( '/\bINDEX\b/', $sql );
			$this->assertStringNotContainsString( '`', $sql );
		}
	}

	public function test_fulltext_index_is_added_separately(): void {
		$this->assertStringNotContainsString( 'FULLTEXT', implode( "\n", $this->statements ) );
	}

	public function test_required_columns_and_indexes(): void {
		$expectations = array(
			'conversations' => array( 'session_id varchar(64)', 'visitor_hash char(64)', 'is_test tinyint(1)', 'meta longtext', 'KEY is_test_created (is_test,created_at)', 'KEY language (language)' ),
			'messages'      => array( 'conversation_id bigint(20) unsigned NOT NULL', "source varchar(20) NOT NULL DEFAULT ''", 'meta longtext', 'KEY conversation_id (conversation_id)', 'KEY source_created (source,created_at)' ),
			'knowledge'     => array( 'embedding longblob NULL', 'embedding_model varchar(191)', 'embedding_dims smallint(5)', 'KEY is_stale (is_stale)', 'KEY source (source_type,source_id)' ),
			'quick_answers' => array( 'hit_count int(10)', 'miss_count int(10)', 'KEY language_enabled (language,enabled)' ),
			'rate_limits'   => array( 'PRIMARY KEY  (bucket_key,window_start)', 'KEY window_start (window_start)' ),
		);

		foreach ( $expectations as $table => $fragments ) {
			foreach ( $fragments as $fragment ) {
				$this->assertStringContainsString( $fragment, $this->statements[ $table ], "{$table}: {$fragment}" );
			}
		}
	}

	public function test_tables_for_prefix(): void {
		$this->assertSame(
			array(
				'wp_3_chatlume_conversations',
				'wp_3_chatlume_messages',
				'wp_3_chatlume_knowledge',
				'wp_3_chatlume_quick_answers',
				'wp_3_chatlume_rate_limits',
			),
			Schema::tables_for_prefix( 'wp_3_' )
		);
	}
}
