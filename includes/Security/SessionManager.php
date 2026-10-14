<?php
/**
 * Signed visitor sessions.
 *
 * @package Chatlume
 */

namespace Chatlume\Security;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and verifies stateless session tokens for visitors.
 *
 * A token is `hex(payload).hex(hmac)` where the payload holds
 * the session ID, issue time, and expiry, signed with a key derived from the
 * WordPress salts. Tokens are fetched at runtime from POST /session and are
 * never printed into (possibly cached) HTML.
 */
class SessionManager {

	/**
	 * Token format version.
	 */
	private const VERSION = 1;

	/**
	 * Issues a new session, or renews a still-valid one (keeping its ID).
	 *
	 * @param string $existing Token the visitor already holds, if any.
	 * @return array{token: string, session_id: string, expires: int, renewed: bool}
	 */
	public function start( string $existing = '' ): array {
		$current = '' !== $existing ? $this->verify( $existing ) : null;
		$id      = null !== $current ? $current['sid'] : bin2hex( random_bytes( 16 ) );
		$now     = time();
		$expires = $now + max( 1, (int) Settings::get( 'security.session_hours', 24 ) ) * HOUR_IN_SECONDS;

		return array(
			'token'      => $this->sign(
				array(
					'v'   => self::VERSION,
					'sid' => $id,
					'iat' => $now,
					'exp' => $expires,
				)
			),
			'session_id' => $id,
			'expires'    => $expires,
			'renewed'    => null !== $current,
		);
	}

	/**
	 * Verifies a token.
	 *
	 * @param string $token Token.
	 * @return array{sid: string, iat: int, exp: int}|null Payload, or null when invalid or expired.
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || strlen( $token ) > 1024 ) {
			return null;
		}

		list( $encoded, $signature ) = $parts;

		if ( ! hash_equals( $this->signature( $encoded ), $signature ) ) {
			return null;
		}

		$payload = json_decode( (string) self::decode( $encoded ), true );

		if (
			! is_array( $payload )
			|| self::VERSION !== ( $payload['v'] ?? null )
			|| ! is_string( $payload['sid'] ?? null )
			|| ! preg_match( '/^[a-f0-9]{32}$/', $payload['sid'] )
			|| (int) ( $payload['exp'] ?? 0 ) < time()
		) {
			return null;
		}

		return array(
			'sid' => $payload['sid'],
			'iat' => (int) $payload['iat'],
			'exp' => (int) $payload['exp'],
		);
	}

	/**
	 * Builds a signed token.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	private function sign( array $payload ): string {
		$encoded = self::encode( (string) wp_json_encode( $payload ) );

		return $encoded . '.' . $this->signature( $encoded );
	}

	/**
	 * HMAC signature of an encoded payload.
	 *
	 * @param string $encoded Encoded payload.
	 * @return string
	 */
	private function signature( string $encoded ): string {
		return self::encode( hash_hmac( 'sha256', $encoded, 'chatlume-session|' . wp_salt( 'auth' ), true ) );
	}

	/**
	 * Hex-encodes token parts (URL-safe and plain ASCII).
	 *
	 * @param string $data Binary data.
	 * @return string
	 */
	private static function encode( string $data ): string {
		return bin2hex( $data );
	}

	/**
	 * Decodes a hex token part.
	 *
	 * @param string $data Hex data.
	 * @return string|false
	 */
	private static function decode( string $data ) {
		if ( '' === $data || 0 !== strlen( $data ) % 2 || ! ctype_xdigit( $data ) ) {
			return false;
		}
		return hex2bin( $data );
	}
}
