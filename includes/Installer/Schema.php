<?php
/**
 * Custom database tables.
 *
 * @package Chatlume
 */

namespace Chatlume\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines and creates Chatlume's custom tables for the current site.
 */
final class Schema {

	/**
	 * Table names without the `{prefix}chatlume_` part.
	 */
	public const TABLES = array(
		'conversations',
		'messages',
		'knowledge',
		'quick_answers',
		'rate_limits',
	);

	/**
	 * Option recording whether the FULLTEXT index on the knowledge table exists.
	 */
	public const FULLTEXT_OPTION = 'chatlume_fulltext_available';

	/**
	 * Name of the FULLTEXT index on the knowledge table.
	 */
	public const FULLTEXT_INDEX = 'search_text';

	/**
	 * Full table name for the current site.
	 *
	 * @param string $name Short table name, e.g. 'messages'.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'chatlume_' . $name;
	}

	/**
	 * Full table names for a given table prefix (used for other sites in a network).
	 *
	 * @param string $prefix Table prefix, e.g. 'wp_3_'.
	 * @return string[]
	 */
	public static function tables_for_prefix( string $prefix ): array {
		return array_map(
			static function ( string $name ) use ( $prefix ): string {
				return $prefix . 'chatlume_' . $name;
			},
			self::TABLES
		);
	}

	/**
	 * CREATE TABLE statements in the exact format dbDelta() expects
	 * (one column per line, two spaces after PRIMARY KEY, KEY instead of INDEX).
	 *
	 * The FULLTEXT index is not part of these statements: on database servers
	 * without InnoDB FULLTEXT support it would make the whole CREATE fail.
	 * It is added separately by add_fulltext_index().
	 *
	 * @param string $prefix          Table prefix.
	 * @param string $charset_collate Charset and collation clause.
	 * @return string[] Keyed by short table name.
	 */
	public static function statements( string $prefix, string $charset_collate ): array {
		$p = $prefix . 'chatlume_';

		return array(
			'conversations' => "CREATE TABLE {$p}conversations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id varchar(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_hash char(64) NOT NULL DEFAULT '',
  page_url text NULL,
  language varchar(20) NOT NULL DEFAULT '',
  provider varchar(64) NOT NULL DEFAULT '',
  model varchar(191) NOT NULL DEFAULT '',
  lead_name varchar(191) NOT NULL DEFAULT '',
  lead_email varchar(191) NOT NULL DEFAULT '',
  rating tinyint(4) NULL DEFAULT NULL,
  message_count int(10) unsigned NOT NULL DEFAULT 0,
  total_cost decimal(14,6) NOT NULL DEFAULT 0,
  is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
  meta longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY user_id (user_id),
  KEY visitor_hash (visitor_hash),
  KEY language (language),
  KEY lead_email (lead_email),
  KEY is_test_created (is_test,created_at),
  KEY updated_at (updated_at)
) {$charset_collate};",

			'messages'      => "CREATE TABLE {$p}messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  role varchar(20) NOT NULL DEFAULT '',
  content longtext NOT NULL,
  source varchar(20) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cost decimal(14,6) NOT NULL DEFAULT 0,
  latency_ms int(10) unsigned NOT NULL DEFAULT 0,
  feedback tinyint(4) NULL DEFAULT NULL,
  sources longtext NULL,
  error_type varchar(40) NOT NULL DEFAULT '',
  meta longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY source_created (source,created_at),
  KEY created_at (created_at),
  KEY feedback (feedback)
) {$charset_collate};",

			'knowledge'     => "CREATE TABLE {$p}knowledge (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_type varchar(32) NOT NULL DEFAULT '',
  source_id varchar(191) NOT NULL DEFAULT '',
  title text NULL,
  url text NULL,
  language varchar(20) NOT NULL DEFAULT '',
  chunk_index int(10) unsigned NOT NULL DEFAULT 0,
  content longtext NOT NULL,
  embedding longblob NULL,
  embedding_model varchar(191) NOT NULL DEFAULT '',
  embedding_dims smallint(5) unsigned NOT NULL DEFAULT 0,
  is_stale tinyint(1) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source (source_type,source_id),
  KEY language (language),
  KEY is_stale (is_stale),
  KEY embedding_model (embedding_model),
  KEY updated_at (updated_at)
) {$charset_collate};",

			'quick_answers' => "CREATE TABLE {$p}quick_answers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  question text NOT NULL,
  answer longtext NOT NULL,
  keywords text NULL,
  language varchar(20) NOT NULL DEFAULT '',
  enabled tinyint(1) unsigned NOT NULL DEFAULT 1,
  hit_count int(10) unsigned NOT NULL DEFAULT 0,
  miss_count int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY language_enabled (language,enabled)
) {$charset_collate};",

			'rate_limits'   => "CREATE TABLE {$p}rate_limits (
  bucket_key varchar(191) NOT NULL,
  window_start int(10) unsigned NOT NULL,
  count int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (bucket_key,window_start),
  KEY window_start (window_start)
) {$charset_collate};",
		);
	}

	/**
	 * Creates or updates all tables for the current site.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( array_values( self::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) ) );

		self::add_fulltext_index();
	}

	/**
	 * Adds the FULLTEXT index used for keyword retrieval, if the server supports it,
	 * and records the result. Without it, retrieval falls back to n-gram scoring.
	 *
	 * @return bool Whether the index exists.
	 */
	public static function add_fulltext_index(): bool {
		global $wpdb;

		$table = self::table( 'knowledge' );

		if ( ! self::has_fulltext_index() ) {
			$suppress = $wpdb->suppress_errors( true );
			// Schema change on Chatlume's own table; dbDelta cannot add it conditionally.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY %i (title,content)', $table, self::FULLTEXT_INDEX ) );
			$wpdb->suppress_errors( $suppress );
		}

		$available = self::has_fulltext_index();
		update_option( self::FULLTEXT_OPTION, $available ? 'yes' : 'no', false );

		return $available;
	}

	/**
	 * Whether the knowledge table has the FULLTEXT index.
	 *
	 * @return bool
	 */
	public static function has_fulltext_index(): bool {
		global $wpdb;

		// Reads index metadata of Chatlume's own table; no core API exists for this.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', self::table( 'knowledge' ), self::FULLTEXT_INDEX ) );

		return null !== $row;
	}

	/**
	 * Whether every table exists for the current site.
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {
		global $wpdb;

		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			// Existence check of Chatlume's own table during install/upgrade.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drops every Chatlume table for the given prefix. Used by uninstall only.
	 *
	 * @param string $prefix Table prefix.
	 * @return void
	 */
	public static function drop_tables( string $prefix ): void {
		global $wpdb;

		foreach ( self::tables_for_prefix( $prefix ) as $table ) {
			// Uninstall removes Chatlume's own tables; no core API exists for this.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
