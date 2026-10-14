<?php
/**
 * Tests for ApiKeys.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Providers\ApiKeys;
use Chatlume\Security\Encryption;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Encrypted storage, constant overrides, and unreadable keys.
 */
class ApiKeysTest extends TestCase {

	/**
	 * Current auth salt.
	 *
	 * @var string
	 */
	private string $salt = 'salt-one';

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_salt' )->alias(
			function ( $scheme = 'auth' ) {
				return $this->salt . $scheme;
			}
		);
	}

	public function test_constant_name(): void {
		$this->assertSame( 'CHATLUME_OPENAI_API_KEY', ApiKeys::constant_name( 'openai' ) );
		$this->assertSame( 'CHATLUME_MY_PROVIDER_API_KEY', ApiKeys::constant_name( 'my-provider' ) );
	}

	public function test_save_encrypts_and_resolve_decrypts(): void {
		$this->assertTrue( ApiKeys::save( 'groq', '  gsk_secret_value_1234  ' ) );

		$stored = $this->options[ ApiKeys::OPTION ]['groq'];
		$this->assertStringStartsWith( Encryption::PREFIX, $stored );
		$this->assertStringNotContainsString( 'secret', $stored );
		$this->assertFalse( $this->autoload[ ApiKeys::OPTION ] );

		$this->assertSame(
			array(
				'key'    => 'gsk_secret_value_1234',
				'source' => 'settings',
				'error'  => false,
			),
			ApiKeys::resolve( 'groq' )
		);

		$status = ApiKeys::status( 'groq' );
		$this->assertSame( '••••••••1234', $status['masked'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $status ) );
	}

	public function test_empty_key_is_rejected_and_delete_removes(): void {
		$this->assertInstanceOf( WP_Error::class, ApiKeys::save( 'groq', '   ' ) );

		ApiKeys::save( 'groq', 'gsk_123456789' );
		ApiKeys::delete( 'groq' );
		$this->assertSame( 'none', ApiKeys::resolve( 'groq' )['source'] );
	}

	public function test_unreadable_after_salt_change_until_reentered(): void {
		ApiKeys::save( 'groq', 'gsk_old_key_1234' );
		ApiKeys::save( 'mistral', 'mistral_old_key_5678' );

		$this->salt = 'salt-rotated';

		$this->assertTrue( ApiKeys::resolve( 'groq' )['error'] );
		$this->assertFalse( ApiKeys::status( 'groq' )['readable'] );

		// Re-entering one key makes it readable; the other still asks to be re-entered.
		ApiKeys::save( 'groq', 'gsk_new_key_9999' );
		$this->assertSame( 'gsk_new_key_9999', ApiKeys::resolve( 'groq' )['key'] );
		$this->assertTrue( ApiKeys::resolve( 'mistral' )['error'] );
	}

	public function test_constant_overrides_stored_key(): void {
		ApiKeys::save( 'xai', 'xai-stored' );
		define( 'CHATLUME_XAI_API_KEY', 'xai-from-config-4321' );

		$resolved = ApiKeys::resolve( 'xai' );
		$this->assertSame( 'xai-from-config-4321', $resolved['key'] );
		$this->assertSame( 'constant', $resolved['source'] );
		$this->assertSame( '••••••••4321', ApiKeys::status( 'xai' )['masked'] );
	}
}
