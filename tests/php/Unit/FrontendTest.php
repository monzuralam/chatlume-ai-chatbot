<?php
/**
 * Tests for the front-end widget loader and the inline embed.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Chatlume\Frontend\Shortcode;
use Chatlume\Frontend\WidgetLoader;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Display rules, the launcher script and its configuration, the shortcode.
 */
class FrontendTest extends TestCase {

	/**
	 * Registered scripts: handle => [src, deps, version, args].
	 *
	 * @var array<string, array>
	 */
	private array $scripts = array();

	/**
	 * Inline scripts: [handle, data, position].
	 *
	 * @var array<int, array>
	 */
	private array $inline = array();

	/**
	 * Enqueued handles.
	 *
	 * @var string[]
	 */
	private array $enqueued = array();

	protected function set_up(): void {
		parent::set_up();

		if ( ! WidgetLoader::is_built() ) {
			$this->markTestSkipped( 'Run `npm run build` first: the loader needs the built widget.' );
		}

		$this->scripts  = array();
		$this->inline   = array();
		$this->enqueued = array();

		$configured = new \ReflectionProperty( WidgetLoader::class, 'configured' );
		$configured->setAccessible( true );
		$configured->setValue( null, false );

		foreach ( array( 'is_admin', 'is_feed', 'is_embed', 'is_robots', 'wp_is_json_request' ) as $function ) {
			Functions\when( $function )->justReturn( false );
		}
		Functions\when( 'rest_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.test/wp-json/' . $path;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps, $version, $args ) {
				$this->scripts[ $handle ] = array( $src, $deps, $version, $args );
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $data, $position ) {
				$this->inline[] = array( $handle, $data, $position );
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->enqueued[] = $handle;
			}
		);
		Functions\when( 'shortcode_atts' )->alias(
			static function ( $pairs, $atts ) {
				return array_merge( $pairs, array_intersect_key( (array) $atts, $pairs ) );
			}
		);
	}

	public function test_widget_shows_site_wide_by_default(): void {
		$this->assertTrue( WidgetLoader::should_display() );
	}

	public function test_display_rules(): void {
		$this->options[ Settings::OPTION ] = array( 'display' => array( 'enabled' => false ) );
		$this->assertFalse( WidgetLoader::should_display(), 'Turned off in settings.' );

		$this->options[ Settings::OPTION ] = array();
		Functions\when( 'is_feed' )->justReturn( true );
		$this->assertFalse( WidgetLoader::should_display(), 'Never in feeds.' );

		Functions\when( 'is_feed' )->justReturn( false );
		Filters\expectApplied( 'chatlume_should_display_widget' )->once()->with( true )->andReturn( false );
		$this->assertFalse( WidgetLoader::should_display(), 'Filterable.' );
	}

	public function test_launcher_is_deferred_with_its_configuration_once(): void {
		WidgetLoader::enqueue( true );
		WidgetLoader::enqueue( false );

		$script = $this->scripts[ WidgetLoader::HANDLE ];
		$this->assertStringEndsWith( 'build/widget/index.js', $script[0] );
		$this->assertSame( array(), $script[1], 'No WordPress script dependencies.' );
		$this->assertSame(
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			),
			$script[3]
		);

		$this->assertCount( 1, $this->inline, 'Configuration is printed once per page.' );
		$this->assertSame( 'before', $this->inline[0][2] );
		$this->assertStringStartsWith( 'window.chatlumeWidgetConfig = {', $this->inline[0][1] );
		$this->assertSame( array( WidgetLoader::HANDLE, WidgetLoader::HANDLE ), $this->enqueued );
	}

	public function test_inline_configuration_has_no_secrets_or_visitor_data(): void {
		$this->options['chatlume_api_keys'] = array( 'openai' => 'v1:secret-ciphertext' );

		$config = WidgetLoader::inline_config( true );

		$this->assertSame(
			array( 'rest', 'assets', 'version', 'floating', 'preload', 'streaming', 'theme', 'strings' ),
			array_keys( $config )
		);
		$this->assertSame( 'https://example.test/wp-json/chatlume/v1/', $config['rest'] );
		$this->assertTrue( $config['floating'] );
		$this->assertSame( 20, $config['streaming']['firstTokenTimeout'] );
		$this->assertArrayHasKey( 'stop', $config['strings'] );

		$json = (string) wp_json_encode( $config );
		$this->assertStringNotContainsString( 'secret', $json );
		$this->assertStringNotContainsString( '"token":', $json );
		$this->assertStringNotContainsString( 'nonce', strtolower( $json ) );
	}

	public function test_shortcode_renders_an_inline_chat(): void {
		$html = ( new Shortcode() )->shortcode( array( 'height' => '480' ) );

		$this->assertStringContainsString( '<chatlume-widget data-inline="1" style="display:block;height:480px;max-width:100%"></chatlume-widget>', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'class="chatlume-inline"', $html );
		$this->assertSame( array( WidgetLoader::HANDLE ), $this->enqueued );
	}

	public function test_shortcode_height_is_clamped(): void {
		$this->assertStringContainsString( 'height:320px', Shortcode::render( 10 ) );
		$this->assertStringContainsString( 'height:1200px', Shortcode::render( 99999 ) );
		$this->assertStringContainsString( 'height:600px', ( new Shortcode() )->shortcode( '' ) );
		$this->assertStringContainsString( 'height:320px', ( new Shortcode() )->shortcode( array( 'height' => '"><script>' ) ) );
	}

	public function test_inline_chat_works_where_the_floating_widget_is_off(): void {
		$this->options[ Settings::OPTION ] = array( 'display' => array( 'enabled' => false ) );

		$this->assertStringContainsString( '<chatlume-widget', Shortcode::render( 600 ) );
		$this->assertStringContainsString( '"floating":false', $this->inline[0][1] );
	}
}
