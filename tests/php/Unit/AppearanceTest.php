<?php
/**
 * Tests for the theme schema and token resolver.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Appearance\ThemeSchema;
use Chatlume\Appearance\TokenResolver;
use Chatlume\Tests\TestCase;

/**
 * Theme validation, clamping, migration, and token output.
 */
class AppearanceTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				unset( $protocols );
				return preg_match( '#^https?://#', (string) $url ) ? (string) $url : '';
			}
		);
	}

	public function test_defaults_are_valid_and_complete(): void {
		$defaults = ThemeSchema::defaults();

		$this->assertSame( ThemeSchema::VERSION, $defaults['version'] );
		foreach ( array( 'light', 'dark' ) as $mode ) {
			foreach ( ThemeSchema::COLOR_TOKENS as $token ) {
				$this->assertMatchesRegularExpression( '/^#[0-9a-f]{6}$/', $defaults['colors'][ $mode ][ $token ], "$mode.$token" );
			}
		}
		$this->assertSame( $defaults, ThemeSchema::sanitize( $defaults ) );
	}

	public function test_sanitize_clamps_numbers_and_rejects_unknown_values(): void {
		$theme    = ThemeSchema::sanitize(
			array(
				'mode'       => 'neon',
				'layout'     => array(
					'style'    => 'modal',
					'width'    => 99999,
					'height'   => -5,
					'position' => 'top',
				),
				'typography' => array(
					'baseSize'   => 40,
					'lineHeight' => '1.333333',
					'bodyFont'   => 'Comic Sans; } body { display:none',
				),
				'launcher'   => array(
					'icon'   => 'nope',
					'teaser' => str_repeat( 'a', 500 ),
					'label'  => '<b>Chat</b>',
				),
				'brand'      => array(
					'avatarUrl' => 'javascript:alert(1)',
					'logoUrl'   => 'https://example.test/logo.png',
					'poweredBy' => 'true',
				),
				'unknown'    => array( 'x' => 1 ),
			)
		);
		$defaults = ThemeSchema::defaults();

		$this->assertSame( 'light', $theme['mode'] );
		$this->assertSame( 'modal', $theme['layout']['style'] );
		$this->assertSame( $defaults['layout']['position'], $theme['layout']['position'] );
		$this->assertLessThan( 99999, $theme['layout']['width'] );
		$this->assertGreaterThan( 0, $theme['layout']['height'] );
		$this->assertSame( 18, $theme['typography']['baseSize'] );
		$this->assertSame( 1.333, $theme['typography']['lineHeight'] );
		$this->assertSame( $defaults['typography']['bodyFont'], $theme['typography']['bodyFont'] );
		$this->assertSame( 'chat', $theme['launcher']['icon'] );
		$this->assertSame( 140, mb_strlen( $theme['launcher']['teaser'] ) );
		$this->assertSame( 'Chat', $theme['launcher']['label'] );
		$this->assertSame( '', $theme['brand']['avatarUrl'] );
		$this->assertSame( 'https://example.test/logo.png', $theme['brand']['logoUrl'] );
		$this->assertTrue( $theme['brand']['poweredBy'] );
		$this->assertArrayNotHasKey( 'unknown', $theme );
	}

	public function test_colors_are_normalized_or_dropped(): void {
		$this->assertSame( '#aabbcc', ThemeSchema::color( '#ABC' ) );
		$this->assertSame( '#11223344', ThemeSchema::color( ' #11223344 ' ) );
		$this->assertSame( '', ThemeSchema::color( 'red' ) );
		$this->assertSame( '', ThemeSchema::color( '#12345' ) );
		$this->assertSame( '', ThemeSchema::color( '#fff;background:url(x)' ) );

		$theme = ThemeSchema::sanitize(
			array(
				'colors' => array(
					'light' => array(
						'primary' => '#F00',
						'accent'  => 'expression(alert(1))',
					),
				),
			)
		);
		$this->assertSame( '#ff0000', $theme['colors']['light']['primary'] );
		$this->assertSame( ThemeSchema::defaults()['colors']['light']['accent'], $theme['colors']['light']['accent'] );
	}

	public function test_non_array_input_and_old_versions_become_current(): void {
		$this->assertSame( ThemeSchema::defaults(), ThemeSchema::sanitize( 'garbage' ) );
		$this->assertSame( ThemeSchema::VERSION, ThemeSchema::migrate( array( 'version' => 0 ) )['version'] );
	}

	public function test_resolve_outputs_token_sets_and_options(): void {
		$theme                            = ThemeSchema::defaults();
		$theme['header']['style']         = 'gradient';
		$theme['header']['gradientAngle'] = 90;
		$config                           = TokenResolver::resolve( $theme );

		$this->assertSame( ThemeSchema::VERSION, $config['schema'] );
		$this->assertSame( '#4f46e5', $config['light']['--chatlume-primary'] );
		$this->assertArrayHasKey( '--chatlume-header-background-end', $config['dark'] );
		$this->assertStringStartsWith( 'linear-gradient(90deg, ', $config['light']['--chatlume-header-fill'] );
		$this->assertSame( '15px', $config['vars']['--chatlume-font-size'] );
		$this->assertSame( 'floating', $config['ui']['style'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $config['v'] );

		foreach ( array_merge( array_keys( $config['light'] ), array_keys( $config['vars'] ) ) as $name ) {
			$this->assertMatchesRegularExpression( '/^--chatlume-[a-z0-9-]+$/', $name );
		}

		// Every visible change gives a new version hash.
		$theme['colors']['light']['primary'] = '#000000';
		$this->assertNotSame( $config['v'], TokenResolver::resolve( $theme )['v'] );
	}

	public function test_minimal_header_uses_the_background(): void {
		$theme                    = ThemeSchema::defaults();
		$theme['header']['style'] = 'minimal';
		$config                   = TokenResolver::resolve( $theme );

		$this->assertSame( $theme['colors']['light']['background'], $config['light']['--chatlume-header-fill'] );
		$this->assertSame( $theme['colors']['light']['botText'], $config['light']['--chatlume-header-ink'] );
	}

	public function test_config_is_built_once_and_autoloaded(): void {
		$config = TokenResolver::config();

		$this->assertSame( $config, $this->options[ TokenResolver::OPTION ] );
		$this->assertTrue( $this->autoload[ TokenResolver::OPTION ] ?? true );
		$this->assertLessThan( 6 * 1024, strlen( (string) wp_json_encode( $config ) ), 'Widget config stays under 6 KB.' );

		// Stale stored configuration (old schema) is rebuilt.
		$this->options[ TokenResolver::OPTION ] = array( 'schema' => 0 );
		$this->assertSame( $config['v'], TokenResolver::config()['v'] );
	}

	public function test_publish_sanitizes_and_rebuilds(): void {
		$config = TokenResolver::publish(
			array(
				'mode'   => 'dark',
				'layout' => array( 'position' => 'left' ),
			)
		);

		$this->assertSame( 'dark', $this->options[ TokenResolver::THEME_OPTION ]['mode'] );
		$this->assertSame( 'left', $config['ui']['position'] );
		$this->assertSame( $config, TokenResolver::config() );
	}
}
