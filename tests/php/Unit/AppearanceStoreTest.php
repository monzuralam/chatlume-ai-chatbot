<?php
/**
 * Tests for the Customizer's draft, publish, and versions.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Appearance\AppearanceStore;
use Chatlume\Appearance\ThemeSchema;
use Chatlume\Appearance\TokenResolver;
use Chatlume\REST\AppearanceController;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Drafts stay private until published; ten versions are kept; the admin's
 * resolver matches this one.
 */
class AppearanceStoreTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 3 );
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'display_name' => 'Rafi' ) );
	}

	/**
	 * A theme with another primary color.
	 *
	 * @param string $color Color.
	 * @return array<string, mixed>
	 */
	private static function theme( string $color ): array {
		$theme                               = ThemeSchema::defaults();
		$theme['colors']['light']['primary'] = $color;
		return $theme;
	}

	public function test_drafts_are_private_until_published(): void {
		$this->assertFalse( AppearanceStore::has_changes() );

		AppearanceStore::save_draft( self::theme( '#123456' ) );
		$this->assertTrue( AppearanceStore::has_changes() );
		$this->assertSame( '#123456', AppearanceStore::draft()['colors']['light']['primary'] );
		$this->assertSame( '#4f46e5', TokenResolver::published_theme()['colors']['light']['primary'], 'Visitors still see the published theme.' );

		AppearanceStore::publish();
		$this->assertFalse( AppearanceStore::has_changes() );
		$this->assertSame( '#123456', TokenResolver::config()['light']['--chatlume-primary'] );
		$this->assertArrayNotHasKey( AppearanceStore::DRAFT_OPTION, $this->options );
	}

	public function test_a_draft_equal_to_the_published_theme_is_not_a_change(): void {
		AppearanceStore::save_draft( self::theme( '#123456' ) );
		AppearanceStore::save_draft( ThemeSchema::defaults() );

		$this->assertFalse( AppearanceStore::has_changes() );
		$this->assertArrayNotHasKey( AppearanceStore::DRAFT_OPTION, $this->options );
	}

	public function test_discard_returns_to_the_published_theme(): void {
		AppearanceStore::save_draft( self::theme( '#123456' ) );
		AppearanceStore::discard();

		$this->assertSame( '#4f46e5', AppearanceStore::draft()['colors']['light']['primary'] );
	}

	public function test_the_last_ten_versions_are_kept_and_restorable(): void {
		for ( $index = 1; $index <= 12; $index++ ) {
			AppearanceStore::save_draft( self::theme( sprintf( '#0000%02d', $index ) ) );
			AppearanceStore::publish();
		}

		$versions = AppearanceStore::versions();
		$this->assertCount( 10, $versions );
		$this->assertTrue( $versions[0]['current'] );
		$this->assertFalse( $versions[1]['current'] );
		$this->assertSame( 'Rafi', $versions[0]['author'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $versions[0]['published_at'] );
		$this->assertArrayNotHasKey( 'theme', $versions[0], 'The list carries no themes.' );

		$oldest = end( $versions );
		$draft  = AppearanceStore::restore( $oldest['id'] );
		$this->assertSame( '#000003', $draft['colors']['light']['primary'], 'Versions 1 and 2 were dropped.' );
		$this->assertTrue( AppearanceStore::has_changes(), 'A restored version waits in the draft.' );
		$this->assertSame( '#000012', TokenResolver::published_theme()['colors']['light']['primary'] );

		$this->assertNull( AppearanceStore::restore( 'missing' ) );
	}

	public function test_drafts_are_sanitized(): void {
		$draft = AppearanceStore::save_draft(
			array(
				'colors' => array( 'light' => array( 'primary' => 'javascript:alert(1)' ) ),
				'layout' => array( 'width' => 9000 ),
				'extra'  => 'dropped',
			)
		);

		$this->assertSame( '#4f46e5', $draft['colors']['light']['primary'] );
		$this->assertSame( 520, $draft['layout']['width'] );
		$this->assertArrayNotHasKey( 'extra', $draft );
	}

	public function test_admin_resolver_parity_fixtures_match_php(): void {
		$root     = dirname( __DIR__, 2 ) . '/fixtures/';
		$resolved = TokenResolver::resolve( ThemeSchema::defaults() );
		unset( $resolved['v'] );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixtures.
		$this->assertSame( json_decode( (string) file_get_contents( $root . 'theme-defaults.json' ), true ), json_decode( (string) wp_json_encode( ThemeSchema::defaults() ), true ) );
		$this->assertSame( json_decode( (string) file_get_contents( $root . 'theme-resolved.json' ), true ), json_decode( (string) wp_json_encode( $resolved ), true ) );
		// phpcs:enable
	}

	public function test_routes_are_admin_only_and_limit_theme_size(): void {
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertInstanceOf( WP_Error::class, ( new AppearanceController() )->permissions_check() );

		$this->assertTrue( AppearanceController::validate_theme( ThemeSchema::defaults() ) );
		$this->assertInstanceOf( WP_Error::class, AppearanceController::validate_theme( 'theme' ) );
		$this->assertInstanceOf( WP_Error::class, AppearanceController::validate_theme( array( 'x' => str_repeat( 'a', 40000 ) ) ) );
	}
}
