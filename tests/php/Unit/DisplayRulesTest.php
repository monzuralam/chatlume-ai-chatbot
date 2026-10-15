<?php
/**
 * Tests for display rules and business hours.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Frontend\DisplayRules;
use Chatlume\REST\DisplayController;
use Chatlume\Settings\Settings;
use Chatlume\Settings\SettingsSchema;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Page, address, visitor, and hours rules, their settings, and the admin route.
 */
class DisplayRulesTest extends TestCase {

	/**
	 * Request context.
	 *
	 * @param int    $id   Object ID.
	 * @param string $type Post type.
	 * @param string $path Path.
	 * @return array{object_id: int, post_type: string, path: string}
	 */
	private static function page( int $id = 0, string $type = '', string $path = '/' ): array {
		return array(
			'object_id' => $id,
			'post_type' => $type,
			'path'      => $path,
		);
	}

	/**
	 * Hours where every day is closed except the given ones.
	 *
	 * @param array<string, array{0: string, 1: string}> $open Open days: start, end.
	 * @return array<string, array<string, mixed>>
	 */
	private static function hours( array $open ): array {
		$days = array();
		foreach ( DisplayRules::DAYS as $day ) {
			$days[ $day ] = array(
				'open'  => isset( $open[ $day ] ),
				'start' => $open[ $day ][0] ?? '09:00',
				'end'   => $open[ $day ][1] ?? '17:00',
			);
		}
		return $days;
	}

	/**
	 * A moment in UTC.
	 *
	 * @param string $time Y-m-d H:i (2026-10-12 is a Monday).
	 * @return \DateTimeImmutable
	 */
	private static function moment( string $time ): \DateTimeImmutable {
		return new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) );
	}

	public function test_page_modes(): void {
		$rules = array(
			'pages_mode'   => 'include',
			'page_ids'     => array( 7 ),
			'post_types'   => array( 'product' ),
			'url_patterns' => array( '/help/*' ),
		);

		$this->assertTrue( DisplayRules::page_matches( $rules, self::page( 7, 'page' ) ) );
		$this->assertTrue( DisplayRules::page_matches( $rules, self::page( 9, 'product' ) ), 'Single product.' );
		$this->assertTrue( DisplayRules::page_matches( $rules, self::page( 0, 'product', '/shop' ) ), 'Product archive.' );
		$this->assertTrue( DisplayRules::page_matches( $rules, self::page( 0, '', '/help/faq' ) ) );
		$this->assertFalse( DisplayRules::page_matches( $rules, self::page( 3, 'post', '/blog/x' ) ) );

		$rules['pages_mode'] = 'exclude';
		$this->assertFalse( DisplayRules::page_matches( $rules, self::page( 7, 'page' ) ) );
		$this->assertTrue( DisplayRules::page_matches( $rules, self::page( 3, 'post' ) ) );

		$this->assertTrue( DisplayRules::page_matches( array( 'pages_mode' => 'all' ), self::page() ) );
	}

	public function test_address_patterns(): void {
		$this->assertTrue( DisplayRules::path_matches( '/shop', array( '/shop/*' ) ), '"/shop/*" includes /shop itself.' );
		$this->assertTrue( DisplayRules::path_matches( '/shop/mugs/blue', array( '/shop/*' ) ) );
		$this->assertFalse( DisplayRules::path_matches( '/shopping', array( '/shop/*' ) ) );
		$this->assertTrue( DisplayRules::path_matches( '/cart/checkout', array( '*checkout*' ) ) );
		$this->assertTrue( DisplayRules::path_matches( '/contact', array( '/Contact' ) ), 'Case does not matter.' );
		$this->assertFalse( DisplayRules::path_matches( '/contact-us', array( '/contact' ) ), 'No wildcard means an exact address.' );
		$this->assertTrue( DisplayRules::path_matches( '/', array( '/' ) ) );
		$this->assertFalse( DisplayRules::path_matches( '/a.b', array( '/a*c' ) ), 'Regex characters are literal.' );
	}

	public function test_patterns_are_normalized(): void {
		$this->assertSame( '/shop/*', DisplayRules::normalize_pattern( ' shop/** ' ) );
		$this->assertSame( '/pricing', DisplayRules::normalize_pattern( 'https://example.test/Pricing/?ref=1' ) );
		$this->assertSame( '/', DisplayRules::normalize_pattern( 'https://example.test' ) );
		$this->assertSame( '/a/b', DisplayRules::normalize_pattern( '//a//b/' ) );
		$this->assertSame( '*checkout*', DisplayRules::normalize_pattern( '*checkout*' ) );
		$this->assertSame( '/café', DisplayRules::normalize_pattern( '/Café' ) );
		$this->assertSame( '/xy', DisplayRules::normalize_pattern( '/x<y>' ) );
		$this->assertSame( '', DisplayRules::normalize_pattern( '   ' ) );
	}

	public function test_current_path_is_relative_to_a_subdirectory_install(): void {
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.test/blog/' );

		$_SERVER['REQUEST_URI'] = '/blog/Shop/Mugs/?color=blue';
		$this->assertSame( '/shop/mugs', DisplayRules::current_path() );

		$_SERVER['REQUEST_URI'] = '/blog';
		$this->assertSame( '/', DisplayRules::current_path() );

		unset( $_SERVER['REQUEST_URI'] );
	}

	public function test_visitor_rules(): void {
		$this->assertTrue( DisplayRules::visitor_matches( array( 'visitors' => 'all' ), false, array() ) );
		$this->assertTrue( DisplayRules::visitor_matches( array( 'visitors' => 'logged_out' ), false, array() ) );
		$this->assertFalse( DisplayRules::visitor_matches( array( 'visitors' => 'logged_out' ), true, array( 'subscriber' ) ) );
		$this->assertTrue( DisplayRules::visitor_matches( array( 'visitors' => 'logged_in' ), true, array( 'subscriber' ) ), 'No roles chosen: any role.' );
		$this->assertFalse( DisplayRules::visitor_matches( array( 'visitors' => 'logged_in' ), false, array() ) );

		$customers = array(
			'visitors' => 'logged_in',
			'roles'    => array( 'customer' ),
		);
		$this->assertTrue( DisplayRules::visitor_matches( $customers, true, array( 'customer' ) ) );
		$this->assertFalse( DisplayRules::visitor_matches( $customers, true, array( 'subscriber' ) ) );
	}

	public function test_business_hours(): void {
		$weekdays = self::hours(
			array(
				'mon' => array( '09:00', '17:00' ),
				'tue' => array( '09:00', '17:00' ),
			)
		);
		$this->assertTrue( DisplayRules::is_open( $weekdays, self::moment( '2026-10-12 09:00' ) ), 'Opens on the minute.' );
		$this->assertTrue( DisplayRules::is_open( $weekdays, self::moment( '2026-10-12 16:59' ) ) );
		$this->assertFalse( DisplayRules::is_open( $weekdays, self::moment( '2026-10-12 17:00' ) ), 'Closes on the minute.' );
		$this->assertFalse( DisplayRules::is_open( $weekdays, self::moment( '2026-10-12 08:59' ) ) );
		$this->assertFalse( DisplayRules::is_open( $weekdays, self::moment( '2026-10-14 12:00' ) ), 'Wednesday is closed.' );

		$late = self::hours( array( 'fri' => array( '22:00', '02:00' ) ) );
		$this->assertTrue( DisplayRules::is_open( $late, self::moment( '2026-10-16 23:30' ) ), 'Friday night.' );
		$this->assertTrue( DisplayRules::is_open( $late, self::moment( '2026-10-17 01:59' ) ), 'Runs into Saturday.' );
		$this->assertFalse( DisplayRules::is_open( $late, self::moment( '2026-10-17 02:00' ) ) );
		$this->assertFalse( DisplayRules::is_open( $late, self::moment( '2026-10-16 01:00' ) ), 'Thursday was closed.' );

		$all_day = self::hours( array( 'sun' => array( '00:00', '00:00' ) ) );
		$this->assertTrue( DisplayRules::is_open( $all_day, self::moment( '2026-10-18 23:59' ) ) );
		$this->assertFalse( DisplayRules::is_open( $all_day, self::moment( '2026-10-19 00:30' ) ), 'All day does not carry into Monday.' );

		$this->assertFalse( DisplayRules::is_open( array(), self::moment( '2026-10-12 12:00' ) ) );
	}

	public function test_hours_follow_the_site_timezone(): void {
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'Asia/Dhaka' ) );
		Functions\when( 'wp_timezone_string' )->justReturn( 'Asia/Dhaka' );

		$this->options[ Settings::OPTION ] = array(
			'display' => array(
				'devices' => 'mobile',
				'hours'   => array(
					'enabled'  => true,
					'behavior' => 'hide',
					'days'     => self::hours( array() ),
				),
			),
		);
		$this->assertTrue( DisplayRules::is_closed_now(), 'Every day closed.' );

		$config = DisplayRules::client_config();
		$this->assertSame( 'mobile', $config['devices'] );
		$this->assertSame( 'hide', $config['hours']['behavior'] );
		$this->assertSame( 'Asia/Dhaka', $config['hours']['timezone'] );
		$this->assertTrue( $config['hours']['enabled'] );

		$this->options[ Settings::OPTION ]['display']['hours']['enabled'] = false;
		$this->assertFalse( DisplayRules::is_closed_now(), 'Hours off: never closed.' );
	}

	public function test_settings_validate_times_and_addresses(): void {
		$schema = SettingsSchema::definition()['display'];

		$this->assertTrue(
			SettingsSchema::validate(
				array(
					'url_patterns' => array( '/shop/*' ),
					'hours'        => array( 'days' => array( 'mon' => array( 'start' => '23:59' ) ) ),
				),
				$schema,
				'display'
			)
		);
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'hours' => array( 'days' => array( 'mon' => array( 'start' => '24:00' ) ) ) ), $schema, 'display' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'hours' => array( 'days' => array( 'mon' => array( 'end' => '9:00' ) ) ) ), $schema, 'display' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'url_patterns' => array( '<>' ) ), $schema, 'display' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'pages_mode' => 'some' ), $schema, 'display' ) );

		$clean = SettingsSchema::sanitize(
			array(
				'page_ids'     => array( '12', 'x' ),
				'url_patterns' => array( 'https://example.test/Help/' ),
				'hours'        => array( 'days' => array( 'mon' => array( 'start' => 'noon' ) ) ),
			),
			$schema
		);
		$this->assertSame( array( 12, 0 ), $clean['page_ids'] );
		$this->assertSame( array( '/help' ), $clean['url_patterns'] );
		$this->assertSame( '09:00', $clean['hours']['days']['mon']['start'], 'An invalid time falls back to the default.' );
	}

	public function test_display_route_is_admin_only(): void {
		Functions\when( 'rest_authorization_required_code' )->justReturn( 401 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertInstanceOf( WP_Error::class, ( new DisplayController() )->permissions_check() );
	}
}
