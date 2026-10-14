<?php
/**
 * Tests for ProviderRegistry.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Filters;
use Chatlume\Providers\ErrorLog;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderInterface;
use Chatlume\Providers\ProviderRegistry;
use Chatlume\Settings\Settings;
use Chatlume\Tests\Support\ScriptedProvider;
use Chatlume\Tests\TestCase;

/**
 * Routing, fallback, embeddings target, cost, and the provider filter.
 */
class ProviderRegistryTest extends TestCase {

	/**
	 * Messages.
	 *
	 * @var array<int, array{role: string, content: string}>
	 */
	private array $messages = array(
		array(
			'role'    => 'user',
			'content' => 'Hi',
		),
	);

	/**
	 * Registers scripted providers through the public filter.
	 *
	 * @param ProviderInterface ...$providers Providers.
	 * @return void
	 */
	private function use_providers( ProviderInterface ...$providers ): void {
		Filters\expectApplied( 'chatlume_register_providers' )->andReturnUsing(
			static function ( $built_in ) use ( $providers ) {
				foreach ( $providers as $provider ) {
					$built_in[ $provider->get_id() ] = $provider;
				}
				return $built_in;
			}
		);
	}

	/**
	 * Saves routing roles.
	 *
	 * @param array<string, mixed> $roles Roles.
	 * @return void
	 */
	private function route( array $roles ): void {
		$this->options[ Settings::OPTION ] = array( 'providers' => $roles );
	}

	public function test_built_in_providers_and_invalid_registrations(): void {
		Filters\expectApplied( 'chatlume_register_providers' )->andReturnUsing(
			static function ( $providers ) {
				$providers['bad']    = 'not a provider';
				$providers['spaced'] = new ScriptedProvider( 'Bad ID' );
				return $providers;
			}
		);

		$this->assertSame(
			array( 'openai', 'anthropic', 'gemini', 'openrouter', 'groq', 'mistral', 'deepseek', 'xai', 'together', 'ollama', 'custom' ),
			ProviderRegistry::ids()
		);
	}

	public function test_primary_answers_without_fallback(): void {
		$alpha = new ScriptedProvider( 'alpha' );
		$this->use_providers( $alpha );
		$this->route(
			array(
				'primary' => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
			)
		);

		$response = ProviderRegistry::chat( $this->messages );

		$this->assertSame( 'm1', $response->model );
		$this->assertFalse( $response->used_fallback );
		$this->assertSame( array( array( 'chat', 'm1' ) ), $alpha->calls );
	}

	public function test_falls_back_once_and_logs_the_failure(): void {
		$alpha = new ScriptedProvider( 'alpha', array( 'm1' => ProviderError::RATE_LIMITED ) );
		$beta  = new ScriptedProvider( 'beta' );
		$this->use_providers( $alpha, $beta );
		$this->route(
			array(
				'primary'  => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
				'fallback' => array(
					'provider' => 'beta',
					'model'    => 'm2',
				),
			)
		);

		$tokens   = array();
		$response = ProviderRegistry::chat(
			$this->messages,
			array(),
			static function ( $text ) use ( &$tokens ) {
				$tokens[] = $text;
			}
		);

		$this->assertTrue( $response->used_fallback );
		$this->assertSame( 'beta', $response->provider );
		$this->assertSame( array( 'ok' ), $tokens );
		$this->assertSame( array( array( 'stream', 'm2' ) ), $beta->calls );

		$log = ErrorLog::all();
		$this->assertCount( 1, $log );
		$this->assertSame( 'alpha', $log[0]['provider'] );
		$this->assertSame( 'm1', $log[0]['model'] );
		$this->assertSame( ProviderError::RATE_LIMITED, $log[0]['type'] );
	}

	public function test_never_falls_back_after_text_reached_the_visitor(): void {
		$alpha                        = new ScriptedProvider( 'alpha', array( 'm1' => ProviderError::SERVER_ERROR ) );
		$alpha->tokens_before_failure = array( 'Hal' );
		$beta                         = new ScriptedProvider( 'beta' );
		$this->use_providers( $alpha, $beta );
		$this->route(
			array(
				'primary'  => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
				'fallback' => array(
					'provider' => 'beta',
					'model'    => 'm2',
				),
			)
		);

		try {
			ProviderRegistry::chat( $this->messages, array(), static function () {} );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::SERVER_ERROR, $error->type() );
			$this->assertSame( array(), $beta->calls );
		}
	}

	public function test_identical_fallback_is_skipped_and_last_error_thrown(): void {
		$alpha = new ScriptedProvider( 'alpha', array( 'm1' => ProviderError::QUOTA ) );
		$this->use_providers( $alpha );
		$this->route(
			array(
				'primary'  => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
				'fallback' => array(
					'provider' => 'alpha',
					'model'    => 'm1',
				),
			)
		);

		try {
			ProviderRegistry::chat( $this->messages );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::QUOTA, $error->type() );
			$this->assertCount( 1, $alpha->calls );
		}
	}

	public function test_not_configured_without_primary(): void {
		$this->expectException( ProviderError::class );
		$this->expectExceptionMessage( 'not_configured' );
		ProviderRegistry::chat( $this->messages );
	}

	public function test_embeddings_default_to_primary_when_supported(): void {
		$alpha = new ScriptedProvider( 'alpha', array(), true );
		$this->use_providers( $alpha );
		$this->route(
			array(
				'primary'    => array(
					'provider' => 'alpha',
					'model'    => 'chat-1',
				),
				'embeddings' => array(
					'provider'   => '',
					'model'      => 'embed-1',
					'dimensions' => 256,
				),
			)
		);

		$this->assertSame(
			array(
				'provider'   => 'alpha',
				'model'      => 'embed-1',
				'dimensions' => 256,
			),
			ProviderRegistry::embeddings_target()
		);

		$result = ProviderRegistry::embed( array( 'a', 'b' ) );
		$this->assertCount( 2, $result['vectors'] );
		$this->assertSame( 'alpha/embed-1', $result['model'] );
		$this->assertSame( 3, $result['dimensions'] );
		$this->assertSame( array( 'embed', 'embed-1@256' ), end( $alpha->calls ) );
	}

	public function test_embeddings_need_a_supporting_provider_and_model(): void {
		$alpha = new ScriptedProvider( 'alpha' );
		$this->use_providers( $alpha );

		$this->route(
			array(
				'primary' => array(
					'provider' => 'alpha',
					'model'    => 'x',
				),
			)
		);
		$this->assertNull( ProviderRegistry::embeddings_target() );

		$this->route(
			array(
				'embeddings' => array(
					'provider' => 'openai',
					'model'    => '',
				),
			)
		);
		$this->assertNull( ProviderRegistry::embeddings_target() );

		$this->expectException( ProviderError::class );
		ProviderRegistry::embed( array( 'a' ) );
	}

	public function test_estimate_cost_from_admin_prices_and_cached_list(): void {
		$this->options[ Settings::OPTION ] = array(
			'providers' => array(
				'configs' => array(
					'openai' => array(
						'prices' => array(
							array(
								'model'  => 'gpt-x',
								'input'  => 2.5,
								'output' => 10,
							),
						),
					),
				),
			),
		);

		$this->assertSame( 0.0125, ProviderRegistry::estimate_cost( 'openai', 'gpt-x', 1000, 1000 ) );
		$this->assertNull( ProviderRegistry::estimate_cost( 'openai', 'unknown', 1000, 1000 ) );

		$openrouter                = ProviderRegistry::get( 'openrouter' );
		$this->transients[ ( function () {
			return $this->models_cache_key();
		} )->call( $openrouter ) ] = array(
			array(
				'id'           => 'vendor/model',
				'input_price'  => 1.0,
				'output_price' => 2.0,
			),
		);

		$this->assertSame( 0.005, ProviderRegistry::estimate_cost( 'openrouter', 'vendor/model', 1000, 2000 ) );
		$this->assertSame( array(), $this->http_requests, 'Cost estimates never make requests.' );
	}

	public function test_records_last_test(): void {
		ProviderRegistry::record_test(
			'openai',
			array(
				'ok'         => false,
				'latency_ms' => 120,
				'model'      => 'm',
				'error'      => array( 'type' => 'invalid_key' ),
			)
		);

		$test = ProviderRegistry::last_tests()['openai'];
		$this->assertFalse( $test['ok'] );
		$this->assertSame( 'invalid_key', $test['error_type'] );
		$this->assertFalse( $this->autoload[ ProviderRegistry::STATUS_OPTION ] );
	}

	public function test_error_log_is_capped(): void {
		for ( $i = 0; $i < ErrorLog::LIMIT + 5; $i++ ) {
			ErrorLog::record( new ProviderError( ProviderError::TIMEOUT, 'p' . $i ) );
		}

		$log = ErrorLog::all();
		$this->assertCount( ErrorLog::LIMIT, $log );
		$this->assertSame( 'p' . ( ErrorLog::LIMIT + 4 ), $log[0]['provider'], 'Newest first.' );
	}
}
