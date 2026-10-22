<?php
/**
 * Tests for draft previews on the site, saved presets, and site branding.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Appearance\AppearanceStore;
use Chatlume\Appearance\PreviewMode;
use Chatlume\Appearance\SiteBrand;
use Chatlume\Appearance\ThemeSchema;
use Chatlume\Tests\TestCase;

/**
 * Signed, expiring, user-bound preview tokens; only the requesting admin sees
 * a draft; presets; theme palette.
 */
class PreviewModeTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		PreviewMode::reset();
	}

	protected function tear_down(): void {
		unset( $_GET[ PreviewMode::PARAM ] );
		PreviewMode::reset();
		parent::tear_down();
	}

	public function test_tokens_are_signed_bound_and_expire(): void {
		$now   = 1700000000;
		$token = PreviewMode::token( 7, 'frame', $now )['token'];

		$this->assertSame(
			array(
				'user' => 7,
				'mode' => 'frame',
			),
			PreviewMode::verify( $token, $now + 60 )
		);
		$this->assertMatchesRegularExpression( '/^[a-f0-9]+\.[a-f0-9]{64}$/', $token, 'Hex only, no base64.' );
		$this->assertNull( PreviewMode::verify( $token, $now + PreviewMode::TTL + 1 ), 'Expires after 30 minutes.' );

		list( $payload, $signature ) = explode( '.', $token );
		$forged                      = bin2hex( '1:frame:' . ( $now + 999999 ) ) . '.' . $signature;
		$this->assertNull( PreviewMode::verify( $forged, $now ), 'A changed payload fails the signature.' );
		$this->assertNull( PreviewMode::verify( $payload . '.' . str_repeat( '0', 64 ), $now ) );
		$this->assertNull( PreviewMode::verify( 'not-a-token', $now ) );
		$this->assertNull( PreviewMode::verify( bin2hex( '7:evil:' . ( $now + 60 ) ) . '.' . hash_hmac( 'sha256', 'chatlume-preview|7:evil:' . ( $now + 60 ), 'test-salt' ), $now ), 'Unknown modes are refused.' );
	}

	public function test_only_the_admin_who_asked_gets_the_preview(): void {
		$_GET[ PreviewMode::PARAM ] = PreviewMode::token( 7, 'site' )['token'];

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->assertSame( 'site', PreviewMode::current() );

		PreviewMode::reset();
		Functions\when( 'get_current_user_id' )->justReturn( 8 );
		$this->assertSame( '', PreviewMode::current(), 'Another user cannot reuse the address.' );

		PreviewMode::reset();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$this->assertSame( '', PreviewMode::current(), 'Visitors who are not logged in never see drafts.' );

		PreviewMode::reset();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( false );
		$this->assertSame( '', PreviewMode::current(), 'The admin must still be allowed to manage Chatlume.' );
	}

	public function test_the_draft_notice_is_printed_by_the_server(): void {
		ob_start();
		PreviewMode::print_badge();
		$html = (string) ob_get_clean();

		$this->assertSame( '<div class="chatlume-preview-badge" role="status">Draft preview: only you can see this. Replies are samples.</div>', $html );
	}

	public function test_presets_are_saved_sanitized_and_limited(): void {
		$theme                               = ThemeSchema::defaults();
		$theme['colors']['light']['primary'] = 'not a color';

		$preset = AppearanceStore::save_preset( 'Brand <b>look</b>', $theme );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{12}$/', $preset['id'] );
		$this->assertSame( '#4f46e5', $preset['theme']['colors']['light']['primary'] );
		$this->assertCount( 1, AppearanceStore::presets() );

		for ( $index = 1; $index < AppearanceStore::MAX_PRESETS; $index++ ) {
			AppearanceStore::save_preset( 'P' . $index, ThemeSchema::defaults() );
		}
		$this->assertNull( AppearanceStore::save_preset( 'One too many', ThemeSchema::defaults() ) );

		$this->assertTrue( AppearanceStore::delete_preset( $preset['id'] ) );
		$this->assertFalse( AppearanceStore::delete_preset( $preset['id'] ) );
		$this->assertCount( AppearanceStore::MAX_PRESETS - 1, AppearanceStore::presets() );
	}

	public function test_palette_keeps_hex_colors_only(): void {
		Functions\when( 'wp_get_global_settings' )->justReturn(
			array(
				'theme' => array(
					array(
						'slug'  => 'primary',
						'name'  => 'Primary',
						'color' => '#1E40AF',
					),
					array(
						'slug'  => 'gradient',
						'name'  => 'Gradient',
						'color' => 'var(--wp--preset--color--x)',
					),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'slug'  => 'primary',
					'name'  => 'Primary',
					'color' => '#1e40af',
				),
			),
			SiteBrand::palette()
		);
	}
}
