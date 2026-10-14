<?php
/**
 * Tests for Multisite.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Installer\Multisite;
use Chatlume\Tests\TestCase;
use WP_Site;

/**
 * Network activation, new sites, and site deletion.
 */
class MultisiteTest extends TestCase {

	/**
	 * Site IDs switched to, in order.
	 *
	 * @var int[]
	 */
	private array $switched = array();

	protected function set_up(): void {
		parent::set_up();

		$this->switched = array();
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->switched[] = $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->justReturn( true );
	}

	public function test_install_all_sites_processes_every_batch(): void {
		Functions\when( 'get_sites' )->alias(
			static function ( array $args ) {
				$total = 103;
				$ids   = range( 1, $total );
				return array_slice( $ids, $args['offset'], $args['number'] );
			}
		);

		$installs = 0;
		$count    = Multisite::install_all_sites(
			static function () use ( &$installs ) {
				++$installs;
			}
		);

		$this->assertSame( 103, $count );
		$this->assertSame( 103, $installs );
		$this->assertSame( range( 1, 103 ), $this->switched );
	}

	public function test_new_site_is_installed_only_when_network_active(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		$active = false;
		Functions\when( 'is_plugin_active_for_network' )->alias(
			static function ( $plugin ) use ( &$active ) {
				return CHATLUME_BASENAME === $plugin && $active;
			}
		);

		$installs  = 0;
		$multisite = new Multisite(
			static function () use ( &$installs ) {
				++$installs;
			}
		);

		$multisite->on_initialize_site( new WP_Site( 5 ) );
		$this->assertSame( 0, $installs );
		$this->assertSame( array(), $this->switched );

		$active = true;
		$multisite->on_initialize_site( new WP_Site( 5 ) );
		$this->assertSame( 1, $installs );
		$this->assertSame( array( 5 ), $this->switched );
	}

	public function test_deleted_site_tables_are_dropped(): void {
		global $wpdb;

		$wpdb = new class() {
			/**
			 * Table prefix for a site.
			 *
			 * @param int $site_id Site ID.
			 * @return string
			 */
			public function get_blog_prefix( $site_id ) {
				return 'wp_' . $site_id . '_';
			}
		};

		$tables = ( new Multisite() )->add_tables_to_drop( array( 'wp_9_posts' ), 9 );

		$this->assertSame( 'wp_9_posts', $tables[0] );
		$this->assertContains( 'wp_9_chatlume_conversations', $tables );
		$this->assertContains( 'wp_9_chatlume_rate_limits', $tables );
		$this->assertCount( 6, $tables );

		$wpdb = null;
	}

	public function test_hooks_are_registered_on_multisite_only(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'add_action' )->never();
		( new Multisite() )->register();

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'add_action' )->once()->with( 'wp_initialize_site', \Mockery::type( 'array' ), 200 );
		Functions\expect( 'add_filter' )->once()->with( 'wpmu_drop_tables', \Mockery::type( 'array' ), 10, 2 );
		( new Multisite() )->register();

		$this->assertTrue( true );
	}
}
