<?php
/**
 * Tests for IpResolver.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Security\IpResolver;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Trusted proxies, spoofing resistance, CIDR matching, and hashing.
 */
class IpResolverTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	/**
	 * Configures the trusted proxy setting.
	 *
	 * @param string   $mode Mode.
	 * @param string[] $ips  Custom proxy ranges.
	 * @return void
	 */
	private function proxy( string $mode, array $ips = array() ): void {
		$this->options[ Settings::OPTION ] = array(
			'security' => array(
				'trusted_proxy'     => $mode,
				'trusted_proxy_ips' => $ips,
			),
		);
	}

	public function test_uses_remote_addr_and_ignores_headers_by_default(): void {
		$this->assertSame(
			'198.51.100.7',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'           => '198.51.100.7',
					'HTTP_X_FORWARDED_FOR'  => '1.2.3.4',
					'HTTP_CF_CONNECTING_IP' => '5.6.7.8',
				)
			)
		);
		$this->assertSame( '', IpResolver::resolve( array( 'REMOTE_ADDR' => 'not-an-ip' ) ) );
		$this->assertSame( '', IpResolver::resolve( array() ) );
	}

	public function test_cloudflare_header_trusted_only_from_cloudflare(): void {
		$this->proxy( 'cloudflare' );

		$this->assertSame(
			'203.0.113.9',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'           => '172.70.1.1',
					'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
				)
			),
			'Request from a Cloudflare edge.'
		);
		$this->assertSame(
			'198.51.100.7',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'           => '198.51.100.7',
					'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
				)
			),
			'Spoofed header from a non-Cloudflare client is ignored.'
		);
		$this->assertSame(
			'2001:db8::1',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'           => '2606:4700::1',
					'HTTP_CF_CONNECTING_IP' => '2001:db8::1',
				)
			),
			'IPv6 edge.'
		);
	}

	public function test_custom_proxy_takes_rightmost_untrusted_forwarded_address(): void {
		$this->proxy( 'custom', array( '10.0.0.0/8' ) );

		$this->assertSame(
			'203.0.113.50',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'          => '10.0.0.2',
					'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.50, 10.0.0.9',
				)
			),
			'Left-most (forgeable) entries are ignored.'
		);
		$this->assertSame(
			'198.51.100.7',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'          => '198.51.100.7',
					'HTTP_X_FORWARDED_FOR' => '6.6.6.6',
				)
			),
			'Not from the proxy: header ignored.'
		);
		$this->assertSame(
			'10.0.0.2',
			IpResolver::resolve(
				array(
					'REMOTE_ADDR'          => '10.0.0.2',
					'HTTP_X_FORWARDED_FOR' => 'garbage',
				)
			)
		);
	}

	public function test_ranges(): void {
		$this->assertTrue( IpResolver::is_valid_range( '203.0.113.0/24' ) );
		$this->assertTrue( IpResolver::is_valid_range( '2001:db8::/32' ) );
		$this->assertTrue( IpResolver::is_valid_range( '203.0.113.5' ) );
		$this->assertFalse( IpResolver::is_valid_range( '203.0.113.0/33' ) );
		$this->assertFalse( IpResolver::is_valid_range( 'example.com' ) );
		$this->assertFalse( IpResolver::is_valid_range( '1.2.3.4/8/9' ) );

		$this->assertTrue( IpResolver::in_ranges( '203.0.113.200', array( '203.0.113.0/25', '203.0.113.128/25' ) ) );
		$this->assertTrue( IpResolver::in_ranges( '104.31.255.255', array( '104.16.0.0/12' ) ) );
		$this->assertFalse( IpResolver::in_ranges( '104.32.0.0', array( '104.16.0.0/12' ) ) );
		$this->assertFalse( IpResolver::in_ranges( '203.0.113.5', array( '2001:db8::/32' ) ), 'IPv4 never matches IPv6.' );
		$this->assertTrue( IpResolver::in_ranges( '203.0.113.5', array( '203.0.113.5' ) ) );
	}

	public function test_hash_is_salted_and_stable(): void {
		$hash = IpResolver::hash( '203.0.113.5' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertSame( $hash, IpResolver::hash( '203.0.113.5' ) );
		$this->assertNotSame( $hash, IpResolver::hash( '203.0.113.6' ) );
		$this->assertStringNotContainsString( '203', $hash );
		$this->assertSame( '', IpResolver::hash( '' ) );
	}
}
