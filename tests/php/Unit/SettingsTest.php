<?php
/**
 * Tests for Settings and SettingsSchema.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Settings\Settings;
use Chatlume\Settings\SettingsSchema;
use Chatlume\Tests\TestCase;
use WP_Error;

/**
 * Settings storage, validation, sanitization, and clamping.
 */
class SettingsTest extends TestCase {

	/**
	 * A schema exercising every supported type.
	 *
	 * @var array<string, mixed>
	 */
	private const FIXTURE = array(
		'type'       => 'object',
		'properties' => array(
			'count'    => array(
				'type'    => 'integer',
				'default' => 5,
				'minimum' => 1,
				'maximum' => 10,
			),
			'ratio'    => array(
				'type'    => 'number',
				'default' => 0.5,
				'minimum' => 0,
				'maximum' => 1,
			),
			'position' => array(
				'type'    => 'string',
				'default' => 'right',
				'enum'    => array( 'left', 'right' ),
			),
			'name'     => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 5,
			),
			'color'    => array(
				'type'    => 'string',
				'format'  => 'hex-color',
				'default' => '#000000',
			),
			'tags'     => array(
				'type'     => 'array',
				'items'    => array( 'type' => 'string' ),
				'maxItems' => 2,
			),
			'enabled'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
		),
	);

	public function test_defaults(): void {
		$defaults = SettingsSchema::all_defaults();

		$this->assertSame( array( 'setup', 'privacy', 'providers', 'chatbot', 'quick_answers', 'security', 'costs', 'cache', 'display', 'streaming' ), array_keys( $defaults ) );
		$this->assertSame( array( 'completed' => false ), $defaults['setup'] );
		$this->assertSame(
			array(
				'delete_data_on_uninstall' => false,
				'store_conversations'      => true,
				'retention_days'           => 90,
			),
			$defaults['privacy']
		);
		$this->assertSame(
			array(
				'provider' => '',
				'model'    => '',
			),
			$defaults['providers']['primary']
		);
		$this->assertSame(
			array(
				'provider'   => '',
				'model'      => '',
				'dimensions' => 512,
			),
			$defaults['providers']['embeddings']
		);
		$this->assertSame(
			array( 'openai', 'anthropic', 'gemini', 'openrouter', 'groq', 'mistral', 'deepseek', 'xai', 'together', 'ollama', 'custom' ),
			array_keys( $defaults['providers']['configs'] )
		);
		$this->assertSame(
			array(
				'default_model' => '',
				'timeout'       => 30,
				'extra_headers' => array(),
				'prices'        => array(),
			),
			$defaults['providers']['configs']['openai']
		);
		$this->assertArrayHasKey( 'base_url', $defaults['providers']['configs']['ollama'] );
		$this->assertArrayHasKey( 'app_attribution', $defaults['providers']['configs']['openrouter'] );
		$this->assertArrayNotHasKey( 'prices', $defaults['providers']['configs']['openrouter'] );

		$this->assertSame(
			array(
				'count'    => 5,
				'ratio'    => 0.5,
				'position' => 'right',
				'name'     => '',
				'color'    => '#000000',
				'tags'     => array(),
				'enabled'  => true,
			),
			SettingsSchema::defaults( self::FIXTURE )
		);
	}

	public function test_get_all_fills_defaults_and_drops_unknown_keys(): void {
		$this->options[ Settings::OPTION ] = array(
			'schema_version' => 1,
			'privacy'        => array(
				'delete_data_on_uninstall' => true,
				'legacy'                   => 'x',
			),
			'removed'        => array( 'a' => 1 ),
		);

		$all = Settings::get_all();

		$this->assertSame( array( 'setup', 'privacy', 'providers', 'chatbot', 'quick_answers', 'security', 'costs', 'cache', 'display', 'streaming' ), array_keys( $all ) );
		$this->assertSame( array( 'completed' => false ), $all['setup'] );
		$this->assertTrue( $all['privacy']['delete_data_on_uninstall'] );
	}

	public function test_provider_settings_are_validated_and_sanitized(): void {
		$result = Settings::update(
			array(
				'providers' => array(
					'primary' => array(
						'provider' => 'custom',
						'model'    => ' vendor/model:tag <x> ',
					),
					'configs' => array(
						'custom' => array(
							'base_url'      => 'http://127.0.0.1:11434/v1/',
							'timeout'       => 999,
							'extra_headers' => array(
								array(
									'name'  => 'X-Team',
									'value' => 'a',
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'vendor/model:tagx', $result['providers']['primary']['model'] );
		$this->assertSame( 'http://127.0.0.1:11434/v1', $result['providers']['configs']['custom']['base_url'] );
		$this->assertSame( 120, $result['providers']['configs']['custom']['timeout'] );
		$this->assertSame( 'X-Team', $result['providers']['configs']['custom']['extra_headers'][0]['name'] );

		$invalid = array(
			array( 'primary' => array( 'provider' => 'unknown' ) ),
			array( 'configs' => array( 'custom' => array( 'base_url' => 'ftp://example.test' ) ) ),
			array( 'configs' => array( 'custom' => array( 'base_url' => 'javascript:alert(1)' ) ) ),
			array( 'configs' => array( 'custom' => array( 'extra_headers' => array( array( 'name' => 'Bad Name' ) ) ) ) ),
			array( 'configs' => array( 'unknown' => array() ) ),
			array( 'configs' => array( 'openai' => array( 'base_url' => 'https://example.test' ) ) ),
		);

		foreach ( $invalid as $providers ) {
			$this->assertInstanceOf( WP_Error::class, Settings::update( array( 'providers' => $providers ) ), wp_json_encode( $providers ) );
		}
	}

	public function test_get_reads_dotted_paths(): void {
		$this->options[ Settings::OPTION ] = array( 'setup' => array( 'completed' => true ) );

		$this->assertTrue( Settings::get( 'setup.completed' ) );
		$this->assertSame( 'fallback', Settings::get( 'setup.missing', 'fallback' ) );
		$this->assertNull( Settings::get( 'nope.nope' ) );
	}

	public function test_update_merges_saves_without_autoload_and_stamps_version(): void {
		Settings::ensure_defaults();

		$result = Settings::update( array( 'privacy' => array( 'delete_data_on_uninstall' => 'true' ) ) );

		$this->assertTrue( $result['privacy']['delete_data_on_uninstall'] );
		$this->assertFalse( $result['setup']['completed'] );
		$this->assertSame( Settings::SCHEMA_VERSION, $this->options[ Settings::OPTION ]['schema_version'] );
		$this->assertFalse( $this->autoload[ Settings::OPTION ] );
	}

	/**
	 * Invalid updates.
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public function invalid_updates(): array {
		return array(
			'unknown section' => array( array( 'unknown' => array() ) ),
			'unknown field'   => array( array( 'privacy' => array( 'nope' => true ) ) ),
			'wrong type'      => array( array( 'privacy' => array( 'delete_data_on_uninstall' => 'maybe' ) ) ),
			'list as object'  => array( array( 'privacy' => array( true ) ) ),
			'scalar section'  => array( array( 'setup' => 'yes' ) ),
		);
	}

	/**
	 * Invalid updates are rejected and nothing is saved.
	 *
	 * @dataProvider invalid_updates
	 *
	 * @param array<string, mixed> $update Update.
	 */
	public function test_update_rejects_invalid_values( array $update ): void {
		$result = Settings::update( $update );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_invalid_param', $result->get_error_code() );
		$this->assertArrayNotHasKey( Settings::OPTION, $this->options );
	}

	public function test_validate_enforces_types_enums_and_lengths(): void {
		$this->assertTrue(
			SettingsSchema::validate(
				array(
					'count'    => 50,
					'ratio'    => '0.25',
					'position' => 'left',
					'name'     => 'éèêëa',
					'tags'     => array( 'a', 'b' ),
					'enabled'  => 0,
				),
				self::FIXTURE,
				'fixture'
			)
		);

		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'position' => 'top' ), self::FIXTURE, 'fixture' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'name' => 'éèêëab' ), self::FIXTURE, 'fixture' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'count' => 1.5 ), self::FIXTURE, 'fixture' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'count' => 'many' ), self::FIXTURE, 'fixture' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'tags' => array( 'a', 'b', 'c' ) ), self::FIXTURE, 'fixture' ) );
		$this->assertInstanceOf( WP_Error::class, SettingsSchema::validate( array( 'tags' => array( 'x' => 'a' ) ), self::FIXTURE, 'fixture' ) );

		$error = SettingsSchema::validate( array( 'position' => 'top' ), self::FIXTURE, 'fixture' );
		$this->assertStringContainsString( 'fixture.position', $error->get_error_message() );
	}

	public function test_sanitize_clamps_numbers_and_cleans_strings(): void {
		$this->assertSame(
			array(
				'count'    => 10,
				'ratio'    => 0.0,
				'position' => 'right',
				'name'     => 'éèêëa',
				'color'    => '',
				'tags'     => array( 'one', 'two' ),
				'enabled'  => false,
			),
			SettingsSchema::sanitize(
				array(
					'count'    => 99,
					'ratio'    => -3,
					'position' => 'top',
					'name'     => '<b>éèêëa</b>bc',
					'color'    => 'red',
					'tags'     => array( ' one ', 'two', 'three' ),
					'enabled'  => 'false',
					'unknown'  => 'dropped',
				),
				self::FIXTURE
			)
		);

		$this->assertSame( array( 'count' => 1 ), SettingsSchema::sanitize( array( 'count' => -4 ), self::FIXTURE ) );
		$this->assertSame( array( 'count' => 3 ), SettingsSchema::sanitize( array( 'count' => '2.6' ), self::FIXTURE ) );
	}

	public function test_merge_known_keeps_only_known_keys_and_replaces_lists(): void {
		$base = array(
			'a' => array(
				'b' => 1,
				'c' => 2,
			),
			'l' => array( 1, 2, 3 ),
			's' => 'x',
		);

		$this->assertSame(
			array(
				'a' => array(
					'b' => 9,
					'c' => 2,
				),
				'l' => array( 7 ),
				's' => 'x',
			),
			Settings::merge_known(
				$base,
				array(
					'a' => array(
						'b' => 9,
						'z' => 1,
					),
					'l' => array( 7 ),
					's' => array( 'not', 'a', 'string' ),
					'q' => 1,
				)
			)
		);
	}

	public function test_rest_args_cover_every_section_with_callbacks(): void {
		$args = SettingsSchema::rest_args();

		$this->assertSame( array( 'setup', 'privacy', 'providers', 'chatbot', 'quick_answers', 'security', 'costs', 'cache', 'display', 'streaming' ), array_keys( $args ) );

		foreach ( $args as $arg ) {
			$this->assertSame( 'object', $arg['type'] );
			$this->assertFalse( $arg['required'] );
			$this->assertIsCallable( $arg['validate_callback'] );
			$this->assertIsCallable( $arg['sanitize_callback'] );
		}

		$this->assertTrue( $args['privacy']['validate_callback']( array( 'delete_data_on_uninstall' => true ), null, 'privacy' ) );
		$this->assertInstanceOf( WP_Error::class, $args['privacy']['validate_callback']( array( 'delete_data_on_uninstall' => 'maybe' ), null, 'privacy' ) );
		$this->assertSame(
			array( 'delete_data_on_uninstall' => true ),
			$args['privacy']['sanitize_callback']( array( 'delete_data_on_uninstall' => '1' ), null, 'privacy' )
		);
	}
}
