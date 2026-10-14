<?php
/**
 * Tests for Encryption.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Security\Encryption;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Encryption at rest, fingerprints, and the "key unreadable" path.
 */
class EncryptionTest extends TestCase {

	/**
	 * Current salts by scheme.
	 *
	 * @var array<string, string>
	 */
	private array $salts = array(
		'auth'        => 'auth-key-and-salt-1',
		'secure_auth' => 'secure-auth-key-and-salt-1',
	);

	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_salt' )->alias(
			function ( $scheme = 'auth' ) {
				return $this->salts[ $scheme ];
			}
		);
	}

	public function test_sodium_is_available(): void {
		$this->assertTrue( Encryption::is_available() );
	}

	public function test_round_trip(): void {
		$encryption = new Encryption();
		$secret     = 'sk-test-1234567890abcdef';

		$payload = $encryption->encrypt( $secret );

		$this->assertIsString( $payload );
		$this->assertStringStartsWith( Encryption::PREFIX, $payload );
		$this->assertStringNotContainsString( $secret, $payload );
		$this->assertSame( $secret, $encryption->decrypt( $payload ) );
	}

	public function test_multibyte_round_trip(): void {
		$encryption = new Encryption();
		$secret     = 'ключ-密钥-🔑';

		$this->assertSame( $secret, $encryption->decrypt( $encryption->encrypt( $secret ) ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce(): void {
		$encryption = new Encryption();

		$this->assertNotSame( $encryption->encrypt( 'same' ), $encryption->encrypt( 'same' ) );
	}

	public function test_encrypt_stores_fingerprint_once(): void {
		$encryption = new Encryption();
		$encryption->encrypt( 'a' );
		$stored = $this->options[ Encryption::FINGERPRINT_OPTION ];

		$this->assertSame( $encryption->fingerprint(), $stored );
		$this->assertSame( 32, strlen( $stored ) );
		$this->assertFalse( $this->autoload[ Encryption::FINGERPRINT_OPTION ] );

		$encryption->encrypt( 'b' );
		$this->assertSame( $stored, $this->options[ Encryption::FINGERPRINT_OPTION ] );
	}

	public function test_tampered_ciphertext_is_unreadable(): void {
		$encryption = new Encryption();
		$payload    = $encryption->encrypt( 'secret' );

		$last     = substr( $payload, -1 );
		$tampered = substr( $payload, 0, -1 ) . ( '0' === $last ? '1' : '0' );

		$this->assert_unreadable( $encryption->decrypt( $tampered ) );
	}

	/**
	 * Malformed payloads.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function malformed_payloads(): array {
		return array(
			'no prefix'     => array( 'plain-text-key' ),
			'empty body'    => array( 'v1:' ),
			'not hex'       => array( 'v1:zzzz' ),
			'odd length'    => array( 'v1:abc' ),
			'too short'     => array( 'v1:' . str_repeat( 'ab', 10 ) ),
			'other version' => array( 'v2:' . str_repeat( 'ab', 60 ) ),
		);
	}

	/**
	 * Malformed payloads report the key as unreadable without warnings.
	 *
	 * @dataProvider malformed_payloads
	 *
	 * @param string $payload Payload.
	 */
	public function test_malformed_payloads_are_unreadable( string $payload ): void {
		$this->assert_unreadable( ( new Encryption() )->decrypt( $payload ) );
	}

	public function test_rotated_salts_report_key_changed(): void {
		$encryption = new Encryption();
		$payload    = $encryption->encrypt( 'secret' );
		$this->assertSame( Encryption::STATUS_OK, $encryption->status() );

		$this->salts['auth'] = 'rotated-by-a-security-plugin';

		$this->assertSame( Encryption::STATUS_KEY_CHANGED, $encryption->status() );
		$this->assert_unreadable( $encryption->decrypt( $payload ) );
	}

	public function test_reset_fingerprint_accepts_new_salts(): void {
		$encryption = new Encryption();
		$encryption->encrypt( 'old' );
		$this->salts['secure_auth'] = 'new-secure-auth';

		$this->assertSame( Encryption::STATUS_KEY_CHANGED, $encryption->status() );

		$encryption->reset_fingerprint();
		$payload = $encryption->encrypt( 'new' );

		$this->assertSame( Encryption::STATUS_OK, $encryption->status() );
		$this->assertSame( 'new', $encryption->decrypt( $payload ) );
	}

	public function test_mask_shows_only_last_four_characters(): void {
		$this->assertSame( '', Encryption::mask( '' ) );
		$this->assertSame( '••••••••', Encryption::mask( 'short' ) );
		$this->assertSame( '••••••••', Encryption::mask( '12345678' ) );
		$this->assertSame( '••••••••wxyz', Encryption::mask( 'sk-abcdefghwxyz' ) );
	}

	/**
	 * Asserts a decrypt() result is the "key unreadable" error.
	 *
	 * @param mixed $result Result.
	 */
	private function assert_unreadable( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( Encryption::ERROR_UNREADABLE, $result->get_error_code() );
		$this->assertStringContainsString( 're-enter', $result->get_error_message() );
	}
}
