<?php
/**
 * Encryption of secrets at rest.
 *
 * @package Chatlume
 */

namespace Chatlume\Security;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts secrets (API keys) with libsodium's secretbox (XSalsa20-Poly1305).
 *
 * The key is derived from the site's WordPress salts, so it never has to be
 * stored. A short fingerprint of the derived key is stored instead; when the
 * salts change (for example, rotated by a security plugin) the fingerprint
 * no longer matches and decryption reports a clear "key unreadable" error
 * instead of failing silently.
 *
 * WordPress core bundles sodium_compat, so the sodium functions exist even
 * when the PHP extension is missing.
 */
class Encryption {

	/**
	 * Ciphertext format prefix (allows future format changes).
	 */
	public const PREFIX = 'v1:';

	/**
	 * Option storing the fingerprint of the key used to encrypt stored secrets.
	 */
	public const FINGERPRINT_OPTION = 'chatlume_key_fingerprint';

	/**
	 * Error code returned when a secret cannot be decrypted.
	 */
	public const ERROR_UNREADABLE = 'chatlume_key_unreadable';

	/**
	 * Status: encryption works and stored secrets are readable.
	 */
	public const STATUS_OK = 'ok';

	/**
	 * Status: the salts changed since secrets were stored.
	 */
	public const STATUS_KEY_CHANGED = 'key_changed';

	/**
	 * Status: libsodium is not available.
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * Whether libsodium (native or sodium_compat) is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'sodium_crypto_generichash' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Encrypts a secret.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @return string|WP_Error Prefixed hex ciphertext, or an error if sodium is unavailable.
	 */
	public function encrypt( string $plaintext ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );

		$this->ensure_fingerprint();

		// Hex encoding keeps the stored value ASCII-safe for the options table.
		return self::PREFIX . bin2hex( $nonce . $box );
	}

	/**
	 * Decrypts a secret produced by encrypt().
	 *
	 * @param string $payload Prefixed hex ciphertext.
	 * @return string|WP_Error Plaintext, or a `chatlume_key_unreadable` error.
	 */
	public function decrypt( string $payload ) {
		if ( ! self::is_available() ) {
			return $this->unavailable_error();
		}

		if ( self::STATUS_KEY_CHANGED === $this->status() ) {
			return $this->unreadable_error();
		}

		if ( 0 !== strpos( $payload, self::PREFIX ) ) {
			return $this->unreadable_error();
		}

		$hex       = substr( $payload, strlen( self::PREFIX ) );
		$min_bytes = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16; // Nonce + Poly1305 tag.

		if ( '' === $hex || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) || strlen( $hex ) / 2 < $min_bytes ) {
			return $this->unreadable_error();
		}

		$raw   = (string) hex2bin( $hex );
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $box, $nonce, $this->key() );

		if ( false === $plaintext ) {
			return $this->unreadable_error();
		}

		return $plaintext;
	}

	/**
	 * Fingerprint of the current derived key (safe to store and display).
	 *
	 * @return string 32 hex characters.
	 */
	public function fingerprint(): string {
		return bin2hex( sodium_crypto_generichash( 'chatlume-fingerprint|' . $this->key(), '', 16 ) );
	}

	/**
	 * Stores the current fingerprint if none is stored yet.
	 *
	 * @return void
	 */
	public function ensure_fingerprint(): void {
		if ( ! self::is_available() ) {
			return;
		}

		if ( '' === (string) get_option( self::FINGERPRINT_OPTION, '' ) ) {
			update_option( self::FINGERPRINT_OPTION, $this->fingerprint(), false );
		}
	}

	/**
	 * Replaces the stored fingerprint with the current one. Call this after
	 * every stored secret has been re-entered following a salt change.
	 *
	 * @return void
	 */
	public function reset_fingerprint(): void {
		if ( self::is_available() ) {
			update_option( self::FINGERPRINT_OPTION, $this->fingerprint(), false );
		}
	}

	/**
	 * Encryption health for the admin.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function status(): string {
		if ( ! self::is_available() ) {
			return self::STATUS_UNAVAILABLE;
		}

		$stored = (string) get_option( self::FINGERPRINT_OPTION, '' );

		if ( '' !== $stored && ! hash_equals( $stored, $this->fingerprint() ) ) {
			return self::STATUS_KEY_CHANGED;
		}

		return self::STATUS_OK;
	}

	/**
	 * Masks a secret for display, showing only its last 4 characters.
	 *
	 * @param string $secret Secret.
	 * @return string
	 */
	public static function mask( string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		if ( mb_strlen( $secret ) <= 8 ) {
			return str_repeat( '•', 8 );
		}

		return str_repeat( '•', 8 ) . mb_substr( $secret, -4 );
	}

	/**
	 * Derives the 32-byte encryption key from the WordPress salts.
	 *
	 * @return string Binary key.
	 */
	protected function key(): string {
		return sodium_crypto_generichash(
			'chatlume-encryption|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ),
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Error for secrets that cannot be decrypted.
	 *
	 * @return WP_Error
	 */
	private function unreadable_error(): WP_Error {
		return new WP_Error(
			self::ERROR_UNREADABLE,
			__( 'A saved API key can no longer be read, usually because your site\'s security keys (salts) were changed. Please re-enter the API key, or define it in wp-config.php so it survives future changes.', 'chatlume-ai-chatbot' )
		);
	}

	/**
	 * Error for servers without libsodium.
	 *
	 * @return WP_Error
	 */
	private function unavailable_error(): WP_Error {
		return new WP_Error(
			'chatlume_encryption_unavailable',
			__( 'Secure storage is unavailable because the server has no libsodium support. Define your API keys in wp-config.php instead.', 'chatlume-ai-chatbot' )
		);
	}
}
