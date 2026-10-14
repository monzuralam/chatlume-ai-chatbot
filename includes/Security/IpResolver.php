<?php
/**
 * Visitor IP resolution and hashing.
 *
 * @package Chatlume
 */

namespace Chatlume\Security;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the visitor's IP address safely and turns it into a salted hash.
 *
 * REMOTE_ADDR is used unless a trusted proxy is configured. Forwarding
 * headers are read only when the request really comes from that proxy, so
 * visitors cannot spoof their address to dodge rate limits. Raw IPs are
 * never stored; only hash() output is.
 */
class IpResolver {

	/**
	 * Cloudflare's published ranges (https://www.cloudflare.com/ips/), checked
	 * when this release was built. Filterable with `chatlume_cloudflare_ip_ranges`.
	 */
	private const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * The visitor's IP address, or '' when none can be determined.
	 *
	 * @param array<string, mixed>|null $server Server variables (defaults to $_SERVER).
	 * @return string
	 */
	public static function resolve( ?array $server = null ): string {
		$server = $server ?? $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value read below is unslashed and validated with filter_var().

		$remote = self::valid_ip( $server['REMOTE_ADDR'] ?? '' );
		if ( '' === $remote ) {
			return '';
		}

		$mode = (string) Settings::get( 'security.trusted_proxy', 'none' );

		if ( 'cloudflare' === $mode && self::in_ranges( $remote, self::cloudflare_ranges() ) ) {
			$forwarded = self::valid_ip( $server['HTTP_CF_CONNECTING_IP'] ?? '' );
			return '' !== $forwarded ? $forwarded : $remote;
		}

		if ( 'custom' === $mode ) {
			$proxies = array_filter( (array) Settings::get( 'security.trusted_proxy_ips', array() ), 'is_string' );
			if ( self::in_ranges( $remote, $proxies ) ) {
				return self::from_forwarded_for( (string) ( $server['HTTP_X_FORWARDED_FOR'] ?? '' ), $proxies, $remote );
			}
		}

		return $remote;
	}

	/**
	 * Salted, non-reversible hash of an IP (64 hex characters).
	 *
	 * @param string $ip IP address.
	 * @return string '' when the IP is empty.
	 */
	public static function hash( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		return hash_hmac( 'sha256', 'chatlume-ip|' . $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Hash of the current visitor's IP.
	 *
	 * @return string
	 */
	public static function visitor_hash(): string {
		return self::hash( self::resolve() );
	}

	/**
	 * Whether a string is an IP address or CIDR range.
	 *
	 * @param string $range Value.
	 * @return bool
	 */
	public static function is_valid_range( string $range ): bool {
		$parts = explode( '/', $range );
		if ( count( $parts ) > 2 || '' === self::valid_ip( $parts[0] ) ) {
			return false;
		}
		if ( 1 === count( $parts ) ) {
			return true;
		}

		$max = false !== strpos( $parts[0], ':' ) ? 128 : 32;

		return ctype_digit( $parts[1] ) && (int) $parts[1] <= $max;
	}

	/**
	 * Whether an IP is inside any of the ranges.
	 *
	 * @param string   $ip     IP address.
	 * @param string[] $ranges IPs or CIDR ranges.
	 * @return bool
	 */
	public static function in_ranges( string $ip, array $ranges ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid input returns false, which is handled.
		if ( false === $packed ) {
			return false;
		}

		foreach ( $ranges as $range ) {
			$range = trim( (string) $range );
			if ( ! self::is_valid_range( $range ) ) {
				continue;
			}

			list( $subnet, $bits ) = array_pad( explode( '/', $range ), 2, null );
			$subnet_packed         = inet_pton( $subnet );

			if ( strlen( $subnet_packed ) !== strlen( $packed ) ) {
				continue; // IPv4 vs IPv6.
			}

			$bits = null === $bits ? strlen( $packed ) * 8 : (int) $bits;
			if ( self::prefix_matches( $packed, $subnet_packed, $bits ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The client IP from X-Forwarded-For: the right-most address that is not
	 * one of our trusted proxies (addresses further left can be forged).
	 *
	 * @param string   $header   Header value.
	 * @param string[] $proxies  Trusted proxy ranges.
	 * @param string   $fallback Value when no usable address exists.
	 * @return string
	 */
	private static function from_forwarded_for( string $header, array $proxies, string $fallback ): string {
		$addresses = array_reverse( array_map( 'trim', explode( ',', $header ) ) );

		foreach ( $addresses as $address ) {
			$ip = self::valid_ip( $address );
			if ( '' === $ip ) {
				break;
			}
			if ( ! self::in_ranges( $ip, $proxies ) ) {
				return $ip;
			}
		}

		return $fallback;
	}

	/**
	 * Cloudflare ranges (filterable, for when Cloudflare publishes new ones).
	 *
	 * @return string[]
	 */
	private static function cloudflare_ranges(): array {
		/**
		 * Filters the IP ranges treated as Cloudflare proxies.
		 *
		 * @param string[] $ranges CIDR ranges.
		 */
		$ranges = apply_filters( 'chatlume_cloudflare_ip_ranges', self::CLOUDFLARE_RANGES );

		return is_array( $ranges ) ? array_filter( $ranges, 'is_string' ) : self::CLOUDFLARE_RANGES;
	}

	/**
	 * Validates an IP address from a header.
	 *
	 * @param mixed $value Raw value.
	 * @return string IP or ''.
	 */
	private static function valid_ip( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$ip = filter_var( trim( wp_unslash( $value ) ), FILTER_VALIDATE_IP );

		return false === $ip ? '' : $ip;
	}

	/**
	 * Compares the first $bits bits of two packed addresses.
	 *
	 * @param string $a    Packed address.
	 * @param string $b    Packed address.
	 * @param int    $bits Prefix length.
	 * @return bool
	 */
	private static function prefix_matches( string $a, string $b, int $bits ): bool {
		$bytes = intdiv( $bits, 8 );

		if ( 0 !== strncmp( $a, $b, $bytes ) ) {
			return false;
		}

		$remainder = $bits % 8;
		if ( 0 === $remainder ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $remainder ) ) & 0xFF;

		return ( ord( $a[ $bytes ] ) & $mask ) === ( ord( $b[ $bytes ] ) & $mask );
	}
}
