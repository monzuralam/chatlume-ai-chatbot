<?php
/**
 * Tests for widget fonts.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Appearance\FontCatalog;
use Chatlume\Appearance\FontUploads;
use Chatlume\Appearance\ThemeSchema;
use Chatlume\Appearance\TokenResolver;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Font choices, stacks with script fallbacks, faces sent to the widget, and
 * upload checks.
 */
class FontCatalogTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	public function test_bundled_fonts_come_from_the_manifest(): void {
		$options = FontCatalog::options();

		foreach ( array( 'inter', 'dm-sans', 'manrope', 'plus-jakarta-sans', 'lora', 'noto-sans-bengali', 'noto-sans-arabic' ) as $slug ) {
			$this->assertArrayHasKey( 'bundled:' . $slug, $options );
			foreach ( $options[ 'bundled:' . $slug ]['faces'] as $face ) {
				$this->assertFileExists( CHATLUME_PATH . substr( $face['src'], strlen( CHATLUME_URL ) ), 'Every bundled face ships with the plugin.' );
			}
			$this->assertFileExists( CHATLUME_PATH . 'assets/fonts/' . $slug . '/OFL.txt', 'Each font keeps its license.' );
		}

		$inter = $options['bundled:inter'];
		$this->assertSame( 'Chatlume Inter', $inter['faces'][0]['family'], 'Bundled families never clash with site fonts.' );
		$this->assertStringStartsWith( '"Chatlume Inter", system-ui', $inter['stack'] );
		$this->assertStringContainsString( '"Noto Sans Bengali"', $inter['stack'], 'Scripts the font lacks fall back to system fonts.' );
		$this->assertContains( 'latin', $inter['scripts'] );
		$this->assertContains( 'bengali', $options['bundled:noto-sans-bengali']['scripts'] );
		$this->assertStringContainsString( 'serif', $options['bundled:lora']['stack'] );
		$this->assertNull( $options['system']['scripts'] );
	}

	public function test_unknown_or_removed_fonts_use_the_system_stack(): void {
		$this->assertSame( FontCatalog::SYSTEM_STACKS['system'], FontCatalog::resolve( 'custom:abcdefabcdef' )['stack'] );
		$this->assertSame( array(), FontCatalog::resolve( 'theme:gone' )['faces'] );
		$this->assertSame( 'inherit', FontCatalog::resolve( 'inherit' )['stack'] );
	}

	public function test_theme_keeps_valid_font_keys_only(): void {
		$theme = ThemeSchema::sanitize(
			array(
				'typography' => array(
					'bodyFont'    => 'bundled:lora',
					'headingFont' => 'custom:0123456789ab',
				),
			)
		);
		$this->assertSame( 'bundled:lora', $theme['typography']['bodyFont'] );
		$this->assertSame( 'custom:0123456789ab', $theme['typography']['headingFont'] );

		$bad = ThemeSchema::sanitize(
			array(
				'typography' => array(
					'bodyFont'    => 'url(evil)',
					'headingFont' => 'Comic Sans',
				),
			)
		);
		$this->assertSame( 'inherit', $bad['typography']['bodyFont'] );
		$this->assertSame( 'same', $bad['typography']['headingFont'] );
	}

	public function test_only_fonts_in_use_reach_the_widget(): void {
		$theme                              = ThemeSchema::defaults();
		$theme['typography']['bodyFont']    = 'bundled:dm-sans';
		$theme['typography']['headingFont'] = 'same';

		$config = TokenResolver::resolve( $theme );
		$this->assertStringStartsWith( '"Chatlume DM Sans"', $config['vars']['--chatlume-font'] );
		$this->assertSame( $config['vars']['--chatlume-font'], $config['vars']['--chatlume-font-heading'] );
		$this->assertNotEmpty( $config['fonts'] );
		$this->assertSame( array( 'Chatlume DM Sans' ), array_values( array_unique( array_column( $config['fonts'], 'family' ) ) ) );

		$this->assertSame( array(), TokenResolver::resolve( ThemeSchema::defaults() )['fonts'], 'Inherited fonts load nothing.' );
	}

	public function test_uploaded_fonts_are_choices(): void {
		$this->options[ FontCatalog::CUSTOM_OPTION ] = array(
			array(
				'id'     => 'abcdef012345',
				'name'   => 'Brand Sans',
				'file'   => 'brand-sans-abcdef012345.woff2',
				'url'    => 'https://example.test/wp-content/uploads/chatlume-fonts/brand-sans-abcdef012345.woff2',
				'weight' => '100 900',
				'style'  => 'normal',
			),
			array( 'id' => '../../evil' ),
		);
		FontCatalog::flush();

		$option = FontCatalog::options()['custom:abcdef012345'];
		$this->assertSame( 'Brand Sans', $option['label'] );
		$this->assertSame( '100 900', $option['faces'][0]['weight'] );
		$this->assertCount( 1, FontCatalog::uploaded(), 'Malformed entries are ignored.' );
	}

	public function test_uploads_must_be_real_small_woff2_files(): void {
		$file = array(
			'name'  => 'brand.woff2',
			'size'  => 1000,
			'error' => UPLOAD_ERR_OK,
		);

		$this->assertTrue( FontUploads::check( $file, 'wOF2' . str_repeat( 'x', 100 ) ) );
		$this->assertInstanceOf( WP_Error::class, FontUploads::check( array( 'name' => 'brand.ttf' ) + $file, 'wOF2' ) );
		$this->assertInstanceOf( WP_Error::class, FontUploads::check( array( 'name' => 'shell.php.woff2' ) + $file, '<?php' ), 'The signature must match.' );
		$this->assertInstanceOf( WP_Error::class, FontUploads::check( array( 'size' => FontUploads::MAX_BYTES + 1 ) + $file, 'wOF2' ) );
		$this->assertInstanceOf( WP_Error::class, FontUploads::check( array( 'error' => UPLOAD_ERR_PARTIAL ) + $file, 'wOF2' ) );
	}

	public function test_site_script_follows_the_site_language(): void {
		Functions\when( 'get_locale' )->justReturn( 'bn_BD' );
		$this->assertSame( 'bengali', FontCatalog::site_script() );
		Functions\when( 'get_locale' )->justReturn( 'fr_FR' );
		$this->assertSame( 'latin', FontCatalog::site_script() );
	}
}
