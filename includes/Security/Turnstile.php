<?php
/**
 * Cloudflare Turnstile.
 *
 * @package Chatlume
 */

namespace Chatlume\Security;

use Chatlume\Providers\ApiKeys;
use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional bot check before a chat session starts. Off by default: only
 * when the admin switches it on and enters their own Cloudflare keys does
 * the widget load Cloudflare's script (when the chat opens). The secret
 * key is stored encrypted with the API keys (or set with the
 * CHATLUME_TURNSTILE_API_KEY constant) and never reaches the browser.
 */
final class Turnstile {

	/**
	 * Key storage ID of the secret key.
	 */
	public const KEY_ID = 'turnstile';

	/**
	 * Cloudflare's verification endpoint.
	 */
	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A server-side API call to an opt-in service disclosed in the readme, not an offloaded asset.

	/**
	 * Whether sessions need a Turnstile check.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return (bool) Settings::get( 'integrations.turnstile', false )
			&& '' !== self::site_key()
			&& '' !== ApiKeys::resolve( self::KEY_ID )['key'];
	}

	/**
	 * The public site key ('' when Turnstile is off).
	 *
	 * @return string
	 */
	public static function site_key(): string {
		return trim( (string) Settings::get( 'integrations.turnstile_site_key', '' ) );
	}

	/**
	 * Checks a visitor's Turnstile token with Cloudflare.
	 *
	 * @param string $token Token from the widget.
	 * @param string $ip    Visitor IP (sent to Cloudflare, never stored).
	 * @return bool Whether the visitor may start a session. When Cloudflare
	 *              cannot be reached, the chat stays available (rate limits
	 *              still apply); an explicit "no" from Cloudflare refuses.
	 */
	public static function verify( string $token, string $ip = '' ): bool {
		$token = trim( $token );
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}

		$body = array(
			'secret'   => ApiKeys::resolve( self::KEY_ID )['key'],
			'response' => $token,
		);
		if ( '' !== $ip ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => 5,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}

		$result = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $result ) && ! empty( $result['success'] );
	}
}
