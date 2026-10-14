<?php
/**
 * Multisite support.
 *
 * @package Chatlume
 */

namespace Chatlume\Installer;

use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps per-site tables in sync across a network: installs on every site at
 * network activation, installs on new sites while network-active, and drops
 * a site's tables when the site is deleted.
 */
class Multisite {

	/**
	 * Sites processed per batch during network activation.
	 */
	public const BATCH_SIZE = 100;

	/**
	 * Installs the current site (defaults to Migrations::install_site()).
	 *
	 * @var callable
	 */
	private $installer;

	/**
	 * Constructor.
	 *
	 * @param callable|null $installer Per-site installer; injectable for tests.
	 */
	public function __construct( ?callable $installer = null ) {
		$this->installer = $installer ?? array( Migrations::class, 'install_site' );
	}

	/**
	 * Hooks network events.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		// Priority 200: after core has created the new site's own tables.
		add_action( 'wp_initialize_site', array( $this, 'on_initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( $this, 'add_tables_to_drop' ), 10, 2 );
	}

	/**
	 * Installs on every site in the network, in batches.
	 *
	 * @param callable|null $installer Per-site installer; defaults to Migrations::install_site().
	 * @return int Number of sites processed.
	 */
	public static function install_all_sites( ?callable $installer = null ): int {
		$installer = $installer ?? array( Migrations::class, 'install_site' );
		$offset    = 0;
		$processed = 0;

		do {
			$site_ids = get_sites(
				array(
					'fields'                 => 'ids',
					'number'                 => self::BATCH_SIZE,
					'offset'                 => $offset,
					'update_site_meta_cache' => false,
				)
			);

			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				call_user_func( $installer );
				restore_current_blog();
				++$processed;
			}

			$offset  += self::BATCH_SIZE;
			$has_more = count( $site_ids ) === self::BATCH_SIZE;
		} while ( $has_more );

		return $processed;
	}

	/**
	 * Installs on a newly created site when Chatlume is network-active.
	 *
	 * @param WP_Site $site New site.
	 * @return void
	 */
	public function on_initialize_site( WP_Site $site ): void {
		if ( ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		call_user_func( $this->installer );
		restore_current_blog();
	}

	/**
	 * Adds Chatlume's tables to the list core drops when a site is deleted.
	 *
	 * @param string[] $tables  Tables core will drop.
	 * @param int      $site_id Site being deleted.
	 * @return string[]
	 */
	public function add_tables_to_drop( $tables, $site_id ): array {
		global $wpdb;

		$tables = is_array( $tables ) ? $tables : array();

		return array_merge( $tables, Schema::tables_for_prefix( $wpdb->get_blog_prefix( (int) $site_id ) ) );
	}

	/**
	 * Whether Chatlume is network-activated.
	 *
	 * @return bool
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active_for_network( CHATLUME_BASENAME );
	}
}
