<?php
/**
 * Tests for cache purging and optimization-plugin exclusions.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Compat\CachePurger;
use Chatlume\Compat\OptimizationPlugins;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Which changes purge, how each caching plugin is called, and the
 * exclusions handed to optimization plugins.
 */
class CompatTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		CachePurger::reset();
		unset( $_SERVER['HTTP_CF_RAY'] );
	}

	protected function tear_down(): void {
		CachePurger::reset();
		unset( $_SERVER['HTTP_CF_RAY'] );
		parent::tear_down();
	}

	public function test_nothing_is_purged_without_caching_plugins(): void {
		// Runs first: later tests define the caching plugins' functions.
		Actions\expectDone( 'chatlume_page_cache_purged' )->once()->with( array() );

		$this->assertSame( array(), CachePurger::purge() );
		$this->assertArrayNotHasKey( CachePurger::LAST_OPTION, $this->options );
		$this->assertSame(
			array(
				'purged' => array(),
				'cdn'    => '',
			),
			CachePurger::report()
		);
	}

	public function test_each_caching_plugin_is_purged_once_per_request(): void {
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );
		Functions\expect( 'rocket_clean_domain' )->once();
		Functions\expect( 'w3tc_flush_all' )->once();
		Functions\expect( 'wp_cache_clear_cache' )->once()->with( 3 );
		Functions\expect( 'sg_cachepress_purge_cache' )->once();

		$purged = CachePurger::purge();
		$this->assertSame( array( 'WP Rocket', 'W3 Total Cache', 'WP Super Cache', 'SiteGround Optimizer' ), $purged );
		$this->assertSame( $purged, CachePurger::purge(), 'A second call in the same request does nothing.' );
		$this->assertSame( $purged, $this->options[ CachePurger::LAST_OPTION ]['plugins'] );
		$this->assertFalse( $this->autoload[ CachePurger::LAST_OPTION ] );
		$this->assertSame( $purged, CachePurger::report()['purged'] );
	}

	public function test_only_settings_printed_into_pages_trigger_a_purge(): void {
		$old = array(
			'display'  => array( 'enabled' => true ),
			'chatbot'  => array( 'bot_name' => 'Ava' ),
			'privacy'  => array( 'retention_days' => 90 ),
			'security' => array( 'rate_limit' => 20 ),
		);

		$this->assertFalse( CachePurger::page_settings_changed( $old, $old ) );
		$this->assertFalse( CachePurger::page_settings_changed( $old, array_merge( $old, array( 'privacy' => array( 'retention_days' => 30 ) ) ) ) );
		$this->assertTrue( CachePurger::page_settings_changed( $old, array_merge( $old, array( 'display' => array( 'enabled' => false ) ) ) ) );
		$this->assertTrue( CachePurger::page_settings_changed( $old, array_merge( $old, array( 'chatbot' => array( 'bot_name' => 'Max' ) ) ) ) );
		$this->assertTrue( CachePurger::page_settings_changed( $old, array_merge( $old, array( 'integrations' => array( 'analytics' => true ) ) ) ) );
		$this->assertTrue( CachePurger::page_settings_changed( false, $old ), 'First save.' );

		Actions\expectDone( 'chatlume_page_cache_purged' )->never();
		CachePurger::on_settings_change( $old, array_merge( $old, array( 'security' => array( 'rate_limit' => 5 ) ) ) );
	}

	public function test_a_cdn_is_reported_for_a_manual_purge(): void {
		$this->assertSame( '', CachePurger::cdn() );

		$_SERVER['HTTP_CF_RAY'] = '8a1b2c3d4e5f-DAC';
		$this->assertSame( 'Cloudflare', CachePurger::cdn() );

		unset( $_SERVER['HTTP_CF_RAY'] );
		$this->options[ Settings::OPTION ] = array( 'security' => array( 'trusted_proxy' => 'cloudflare' ) );
		$this->assertSame( 'Cloudflare', CachePurger::cdn() );

		Filters\expectApplied( 'chatlume_detected_cdn' )->once()->andReturn( 'Bunny CDN' );
		$this->assertSame( 'Bunny CDN', CachePurger::cdn() );
	}

	public function test_exclusion_filters_are_registered(): void {
		( new OptimizationPlugins() )->register();

		foreach ( array(
			'rocket_exclude_js',
			'rocket_excluded_inline_js_content',
			'rocket_delay_js_exclusions',
			'litespeed_optimize_js_excludes',
			'litespeed_optm_js_defer_exc',
			'litespeed_optm_gm_js_exc',
			'autoptimize_filter_js_exclude',
			'perfmatters_delay_js_exclusions',
			'perfmatters_defer_js_exclusions',
			'perfmatters_minify_js_exclusions',
			'sgo_js_minify_exclude',
			'sgo_javascript_combine_exclude',
			'sgo_js_async_exclude',
			'sgo_javascript_combine_excluded_inline_content',
			'wp_script_attributes',
			'wp_inline_script_attributes',
		) as $hook ) {
			$this->assertTrue( has_filter( $hook ), $hook );
		}
	}

	public function test_exclusion_lists(): void {
		$this->assertSame( 'chatlume-ai-chatbot/build/widget/', OptimizationPlugins::asset_marker() );

		$this->assertSame(
			array( 'jquery.js', 'chatlumeWidgetConfig', 'chatlume-ai-chatbot/build/widget/' ),
			OptimizationPlugins::markers( array( 'jquery.js', 'chatlumeWidgetConfig' ) )
		);
		$this->assertSame( array( 'chatlume-ai-chatbot/build/widget/', 'chatlumeWidgetConfig' ), OptimizationPlugins::markers( null ) );
		$this->assertSame( 'not-a-list', OptimizationPlugins::markers( 'not-a-list' ), 'Unexpected types are left alone.' );
		$this->assertSame( array( 'chatlumeWidgetConfig' ), OptimizationPlugins::inline_markers( array() ) );
		$this->assertSame( array( '/wp-content/plugins/chatlume-ai-chatbot/build/widget/(.*).js' ), OptimizationPlugins::rocket_files( array() ) );
		$this->assertSame( array( 'jquery', 'chatlume-widget' ), OptimizationPlugins::handles( array( 'jquery' ) ) );
		$this->assertSame( 'jquery.js, chatlume-ai-chatbot/build/widget/, chatlumeWidgetConfig', OptimizationPlugins::comma_markers( 'jquery.js, ' ) );
		$this->assertSame( 'chatlume-ai-chatbot/build/widget/, chatlumeWidgetConfig', OptimizationPlugins::comma_markers( '' ) );
	}

	public function test_only_the_widget_tags_get_opt_out_attributes(): void {
		$launcher = OptimizationPlugins::script_attributes(
			array(
				'src' => 'https://example.test/index.js',
				'id'  => 'chatlume-widget-js',
			)
		);
		$this->assertSame( '1', $launcher['data-no-optimize'] );
		$this->assertSame( '1', $launcher['data-no-defer'] );
		$this->assertSame( '1', $launcher['data-noptimize'] );
		$this->assertSame( 'false', $launcher['data-cfasync'] );

		$inline = OptimizationPlugins::inline_script_attributes( array( 'id' => 'chatlume-widget-js-before' ), 'window.chatlumeWidgetConfig = {};' );
		$this->assertSame( 'false', $inline['data-cfasync'] );

		$other = array( 'id' => 'jquery-core-js' );
		$this->assertSame( $other, OptimizationPlugins::script_attributes( $other ) );
		$this->assertSame( $other, OptimizationPlugins::inline_script_attributes( $other, '' ) );
	}
}
