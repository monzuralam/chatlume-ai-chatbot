<?php
/**
 * Tests for OpenAICompatibleProvider.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\OpenAICompatibleProvider;
use Chatlume\Providers\ProviderError;
use Chatlume\Tests\Support\FakeStreamer;
use Chatlume\Tests\TestCase;

/**
 * Request building, stream parsing, errors, models, and embeddings.
 */
class OpenAICompatibleProviderTest extends TestCase {

	/**
	 * Builds a provider for a preset.
	 *
	 * @param string               $preset   Preset ID.
	 * @param array<string, mixed> $config   Config.
	 * @param FakeStreamer|null    $streamer Streamer.
	 * @return OpenAICompatibleProvider
	 */
	private function provider( string $preset, array $config = array(), ?FakeStreamer $streamer = null ): OpenAICompatibleProvider {
		return new OpenAICompatibleProvider(
			OpenAICompatibleProvider::presets()[ $preset ],
			array_merge(
				array(
					'api_key'       => 'sk-test',
					'default_model' => 'model-a',
				),
				$config
			),
			$streamer
		);
	}

	/**
	 * Messages with a system prompt and history.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	private function messages(): array {
		return array(
			array(
				'role'    => 'system',
				'content' => 'Be brief.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
			array(
				'role'    => 'tool',
				'content' => 'ignored',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello!',
			),
			array(
				'role'    => 'user',
				'content' => 'Prices?',
			),
		);
	}

	public function test_presets_cover_all_services(): void {
		$this->assertSame(
			array( 'openai', 'openrouter', 'groq', 'mistral', 'deepseek', 'xai', 'together', 'ollama', 'custom' ),
			array_keys( OpenAICompatibleProvider::presets() )
		);

		$ollama = $this->provider( 'ollama', array( 'api_key' => '' ) );
		$this->assertFalse( $ollama->requires_key() );
		$this->assertTrue( $ollama->supports( 'embeddings' ) );
		$this->assertFalse( $this->provider( 'groq' )->supports( 'embeddings' ) );
		$this->assertTrue( $this->provider( 'openai' )->supports( 'embedding_dimensions' ) );
		$this->assertFalse( $this->provider( 'together' )->supports( 'embedding_dimensions' ) );
	}

	public function test_chat_request_and_response(): void {
		$this->queue_response(
			200,
			array(
				'model'   => 'model-a-2026',
				'choices' => array(
					array(
						'message'       => array( 'content' => 'Hello there' ),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 12,
					'completion_tokens' => 3,
				),
			)
		);

		$response = $this->provider( 'openai' )->chat(
			$this->messages(),
			array(
				'temperature' => 5,
				'max_tokens'  => 50,
			)
		);

		list( $url, $args ) = $this->http_requests[0];
		$body               = $this->sent_body();

		$this->assertSame( 'https://api.openai.com/v1/chat/completions', $url );
		$this->assertSame( 'Bearer sk-test', $args['headers']['Authorization'] );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertCount( 4, $body['messages'] );
		$this->assertEquals( 2.0, $body['temperature'], 'Clamped to 0–2.' );
		$this->assertSame( 50, $body['max_completion_tokens'] );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertFalse( $body['stream'] );

		$this->assertSame( 'Hello there', $response->text );
		$this->assertSame( 12, $response->input_tokens );
		$this->assertSame( 3, $response->output_tokens );
		$this->assertSame( 'stop', $response->finish_reason );
		$this->assertSame( 'model-a-2026', $response->model );
		$this->assertSame( 'openai', $response->provider );
	}

	public function test_other_presets_use_max_tokens_and_custom_base_url(): void {
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) );

		$this->provider( 'custom', array( 'base_url' => 'http://127.0.0.1:8080/v1/' ) )->chat( $this->messages(), array( 'max_tokens' => 10 ) );

		$this->assertSame( 'http://127.0.0.1:8080/v1/chat/completions', $this->http_requests[0][0] );
		$this->assertSame( 10, $this->sent_body()['max_tokens'] );
	}

	public function test_admin_headers_cannot_replace_authentication(): void {
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) );

		$this->provider(
			'custom',
			array(
				'base_url'      => 'http://gateway.test/v1',
				'extra_headers' => array(
					array(
						'name'  => 'X-Team',
						'value' => "a\r\nInjected: 1",
					),
					array(
						'name'  => 'Authorization',
						'value' => 'Bearer hijack',
					),
					array(
						'name'  => 'Bad Name',
						'value' => 'x',
					),
				),
			)
		)->chat( $this->messages(), array() );

		$headers = $this->http_requests[0][1]['headers'];
		$this->assertSame( 'aInjected: 1', $headers['X-Team'] );
		$this->assertSame( 'Bearer sk-test', $headers['Authorization'] );
		$this->assertArrayNotHasKey( 'Bad Name', $headers );
	}

	public function test_openrouter_attribution_is_opt_in(): void {
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) );
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) );

		$this->provider( 'openrouter' )->chat( $this->messages(), array() );
		$this->provider( 'openrouter', array( 'app_attribution' => true ) )->chat( $this->messages(), array() );

		$this->assertArrayNotHasKey( 'HTTP-Referer', $this->http_requests[0][1]['headers'] );
		$this->assertSame( 'https://example.test/', $this->http_requests[1][1]['headers']['HTTP-Referer'] );
		$this->assertSame( 'Example Site', $this->http_requests[1][1]['headers']['X-OpenRouter-Title'] );
	}

	public function test_stream_parses_tokens_finish_and_usage(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array( 'choices' => array( array( 'delta' => array( 'role' => 'assistant' ) ) ) ),
					array( 'choices' => array( array( 'delta' => array( 'content' => 'Hel' ) ) ) ),
					array(
						'choices' => array(
							array(
								'delta'         => array( 'content' => 'lo' ),
								'finish_reason' => 'length',
							),
						),
					),
					array(
						'choices' => array(),
						'usage'   => array(
							'prompt_tokens'     => 7,
							'completion_tokens' => 2,
						),
						'model'   => 'model-a-final',
					),
					'[DONE]',
				)
			)
		);

		$tokens   = array();
		$response = $this->provider( 'openai', array(), $streamer )->stream(
			$this->messages(),
			array(),
			static function ( $text ) use ( &$tokens ) {
				$tokens[] = $text;
			}
		);

		$this->assertSame( array( 'Hel', 'lo' ), $tokens );
		$this->assertSame( 'Hello', $response->text );
		$this->assertSame( 'length', $response->finish_reason );
		$this->assertSame( 7, $response->input_tokens );
		$this->assertSame( 2, $response->output_tokens );
		$this->assertSame( 'model-a-final', $response->model );
		$this->assertSame( array( 'include_usage' => true ), $streamer->last_body()['stream_options'] );
		$this->assertTrue( $streamer->last_body()['stream'] );
	}

	public function test_stream_reads_groq_usage(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array(
						'choices' => array( array( 'delta' => array( 'content' => 'x' ) ) ),
						'x_groq'  => array(
							'usage' => array(
								'prompt_tokens'     => 4,
								'completion_tokens' => 1,
							),
						),
					),
					'[DONE]',
				)
			)
		);

		$response = $this->provider( 'groq', array(), $streamer )->stream( $this->messages(), array(), static function () {} );

		$this->assertSame( 4, $response->input_tokens );
		$this->assertSame( 1, $response->output_tokens );
	}

	public function test_openrouter_error_inside_stream_keeps_partial_text(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array( 'choices' => array( array( 'delta' => array( 'content' => 'Par' ) ) ) ),
					array(
						'error'   => array(
							'code'    => 402,
							'message' => 'Insufficient credits',
						),
						'choices' => array( array( 'finish_reason' => 'error' ) ),
					),
					array( 'choices' => array( array( 'delta' => array( 'content' => 'never' ) ) ) ),
				)
			)
		);

		try {
			$this->provider( 'openrouter', array(), $streamer )->stream( $this->messages(), array(), static function () {} );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::QUOTA, $error->type() );
			$this->assertSame( 'Par', $error->partial_text() );
			$this->assertSame( 2, $streamer->delivered, 'The stream stops at the error.' );
		}
	}

	public function test_stream_http_error_is_mapped(): void {
		$streamer = new FakeStreamer(
			array(
				'{"error":{"message":"You exceeded your current quota","type":"insufficient_quota","code":"insufficient_quota"}}',
			),
			429
		);

		$this->expectException( ProviderError::class );
		$this->expectExceptionMessage( 'quota' );
		$this->provider( 'openai', array(), $streamer )->stream( $this->messages(), array(), static function () {} );
	}

	public function test_stream_falls_back_to_json_when_server_ignores_stream_flag(): void {
		$streamer = new FakeStreamer( array( '{"choices":[{"message":{"content":"Whole answer"},"finish_reason":"stop"}],"usage":{"prompt_tokens":3,"completion_tokens":2}}' ) );
		$tokens   = array();

		$response = $this->provider( 'custom', array( 'base_url' => 'http://x.test/v1' ), $streamer )->stream(
			$this->messages(),
			array(),
			static function ( $text ) use ( &$tokens ) {
				$tokens[] = $text;
			}
		);

		$this->assertSame( array( 'Whole answer' ), $tokens );
		$this->assertSame( 2, $response->output_tokens );
	}

	public function test_aborted_stream_returns_partial_response(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array( 'choices' => array( array( 'delta' => array( 'content' => 'a' ) ) ) ),
					array( 'choices' => array( array( 'delta' => array( 'content' => 'b' ) ) ) ),
				)
			)
		);

		$response = $this->provider( 'openai', array(), $streamer )->stream(
			$this->messages(),
			array(
				'should_abort' => static function () {
					return 'client_disconnected';
				},
			),
			static function () {}
		);

		$this->assertSame( 'a', $response->text );
		$this->assertSame( 'aborted', $response->finish_reason );
		$this->assertSame( 1, $streamer->delivered );
	}

	/**
	 * Error bodies by code.
	 *
	 * @return array<string, array{0: int, 1: array<string, mixed>, 2: string}>
	 */
	public function error_cases(): array {
		return array(
			'invalid key'       => array(
				401,
				array(
					'error' => array(
						'message' => 'Incorrect API key provided: sk-abc…',
						'code'    => 'invalid_api_key',
					),
				),
				ProviderError::INVALID_KEY,
			),
			'quota by code'     => array(
				429,
				array(
					'error' => array(
						'message' => 'Exceeded',
						'type'    => 'insufficient_quota',
						'code'    => 'credit_balance_exhausted',
					),
				),
				ProviderError::QUOTA,
			),
			'rate limit'        => array(
				429,
				array(
					'error' => array(
						'message' => 'Rate limit reached',
						'type'    => 'requests',
						'code'    => 'rate_limit_exceeded',
					),
				),
				ProviderError::RATE_LIMITED,
			),
			'model'             => array(
				404,
				array(
					'error' => array(
						'message' => 'The model does not exist',
						'code'    => 'model_not_found',
					),
				),
				ProviderError::MODEL_NOT_FOUND,
			),
			'context'           => array(
				400,
				array(
					'error' => array(
						'message' => 'too many',
						'code'    => 'context_length_exceeded',
					),
				),
				ProviderError::CONTEXT_TOO_LONG,
			),
			'overloaded'        => array(
				503,
				array(
					'error' => array(
						'message' => 'busy',
						'code'    => 'server_is_overloaded',
					),
				),
				ProviderError::SERVER_ERROR,
			),
			'openrouter 402'    => array(
				402,
				array(
					'error' => array(
						'message' => 'Payment required',
						'code'    => 402,
					),
				),
				ProviderError::QUOTA,
			),
			'openrouter 403'    => array(
				403,
				array(
					'error' => array(
						'message' => 'Flagged',
						'code'    => 403,
					),
				),
				ProviderError::CONTENT_BLOCKED,
			),
			'deepseek 402 text' => array( 402, array( 'error' => array( 'message' => 'Insufficient Balance' ) ), ProviderError::QUOTA ),
		);
	}

	/**
	 * Documented error codes map to the right type.
	 *
	 * @dataProvider error_cases
	 *
	 * @param int                  $status   Status.
	 * @param array<string, mixed> $body     Body.
	 * @param string               $expected Type.
	 */
	public function test_error_mapping( int $status, array $body, string $expected ): void {
		$this->queue_response( $status, $body, array( 'retry-after' => '7' ) );

		try {
			$this->provider( 'openai' )->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( $expected, $error->type() );
			$this->assertSame( $status, $error->status() );
			$this->assertSame( 7, $error->retry_after() );
		}
	}

	public function test_reads_headers_from_the_wordpress_header_object(): void {
		// Shaped like WpOrg\Requests\Response\Headers: an object whose getAll() returns value lists.
		$headers = new class() {
			/**
			 * All headers.
			 *
			 * @return array<string, string[]>
			 */
			public function getAll(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors the Requests API.
				return array( 'Retry-After' => array( '9' ) );
			}
		};

		$this->http_queue[] = array(
			'response' => array( 'code' => 429 ),
			'body'     => '{"error":{"message":"Rate limit reached","code":"rate_limit_exceeded"}}',
			'headers'  => $headers,
		);

		try {
			$this->provider( 'openai' )->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::RATE_LIMITED, $error->type() );
			$this->assertSame( 9, $error->retry_after() );
		}
	}

	public function test_retries_without_rejected_optional_parameter(): void {
		$this->queue_response( 400, array( 'error' => array( 'message' => "Unsupported value: 'temperature' does not support 0.5 with this model." ) ) );
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'ok' ) ) ) ) );

		$response = $this->provider( 'openai' )->chat( $this->messages(), array( 'temperature' => 0.5 ) );

		$this->assertSame( 'ok', $response->text );
		$this->assertCount( 2, $this->http_requests );
		$this->assertArrayHasKey( 'temperature', $this->sent_body( 0 ) );
		$this->assertArrayNotHasKey( 'temperature', $this->sent_body( 1 ) );
	}

	public function test_does_not_retry_unrelated_errors(): void {
		$this->queue_response( 400, array( 'error' => array( 'message' => 'Something else is wrong' ) ) );

		$this->expectException( ProviderError::class );
		try {
			$this->provider( 'openai' )->chat( $this->messages(), array( 'temperature' => 0.5 ) );
		} finally {
			$this->assertCount( 1, $this->http_requests );
		}
	}

	public function test_not_configured_without_key_or_model(): void {
		try {
			$this->provider( 'openai', array( 'api_key' => '' ) )->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::NOT_CONFIGURED, $error->type() );
		}

		try {
			$this->provider( 'openai', array( 'default_model' => '' ) )->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::NOT_CONFIGURED, $error->type() );
		}

		try {
			$this->provider( 'openai', array( 'key_error' => true ) )->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::KEY_UNREADABLE, $error->type() );
		}

		$this->assertSame( array(), $this->http_requests, 'No request is sent when not configured.' );
	}

	public function test_models_from_openrouter_with_pricing_free_flags_and_cache(): void {
		$this->queue_response(
			200,
			array(
				'data' => array(
					array(
						'id'             => 'vendor/paid',
						'name'           => 'Paid',
						'context_length' => 128000,
						'pricing'        => array(
							'prompt'     => '0.0000025',
							'completion' => '0.00001',
						),
						'architecture'   => array( 'output_modalities' => array( 'text' ) ),
					),
					array(
						'id'           => 'vendor/free:free',
						'pricing'      => array(
							'prompt'     => '0',
							'completion' => '0',
						),
						'architecture' => array( 'output_modalities' => array( 'text' ) ),
					),
				),
			)
		);

		$provider = $this->provider( 'openrouter' );
		$models   = $provider->list_models();

		$this->assertSame( 'vendor/free:free', $models[0]['id'], 'Free models sort before other models.' );
		$this->assertTrue( $models[0]['free'] );
		$this->assertSame( 2.5, $models[1]['input_price'] );
		$this->assertSame( 10.0, $models[1]['output_price'] );
		$this->assertSame( 128000, $models[1]['context_length'] );

		$this->assertSame( $models, $provider->list_models(), 'Second call is served from cache.' );
		$this->assertCount( 1, $this->http_requests );
		$this->assertSame( $models, $provider->cached_models() );
	}

	public function test_models_accept_bare_arrays_and_classify_kinds(): void {
		$this->queue_response(
			200,
			array(
				array(
					'id'   => 'meta/llama-chat',
					'type' => 'chat',
				),
				array(
					'id'   => 'bge-embed',
					'type' => 'embedding',
				),
				array(
					'id'   => 'flux-image',
					'type' => 'image',
				),
			)
		);

		$kinds = array();
		foreach ( $this->provider( 'together' )->list_models( true ) as $model ) {
			$kinds[ $model['id'] ] = $model['kind'];
			$this->assertNull( $model['input_price'], 'Only OpenRouter prices are trusted.' );
		}

		// Order follows the recommendation sort, so compare as a map.
		$this->assertEquals(
			array(
				'meta/llama-chat' => 'chat',
				'bge-embed'       => 'embedding',
				'flux-image'      => 'other',
			),
			$kinds
		);
	}

	public function test_model_kind_heuristics(): void {
		$this->assertSame( 'embedding', OpenAICompatibleProvider::model_kind( array( 'id' => 'text-embedding-x' ) ) );
		$this->assertSame( 'other', OpenAICompatibleProvider::model_kind( array( 'id' => 'whisper-1' ) ) );
		$this->assertSame( 'chat', OpenAICompatibleProvider::model_kind( array( 'id' => 'some-chat-model' ) ) );
		$this->assertSame(
			'chat',
			OpenAICompatibleProvider::model_kind(
				array(
					'id'           => 'mistral-x',
					'capabilities' => array( 'completion_chat' => true ),
				)
			)
		);
	}

	public function test_embeddings_preserve_order_and_dimensions_parameter(): void {
		$this->queue_response(
			200,
			array(
				'data' => array(
					array(
						'index'     => 1,
						'embedding' => array( 0.3, 0.4 ),
					),
					array(
						'index'     => 0,
						'embedding' => array( 0.1, 0.2 ),
					),
				),
			)
		);
		$this->queue_response( 200, array( 'data' => array( array( 'embedding' => array( 1 ) ) ) ) );

		$vectors = $this->provider( 'openai' )->embed(
			array( 'first', 'second' ),
			array(
				'model'      => 'embed-x',
				'dimensions' => 256,
			)
		);
		$this->assertSame( array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ), $vectors );
		$this->assertSame( 256, $this->sent_body( 0 )['dimensions'] );

		$this->provider( 'mistral' )->embed(
			array( 'x' ),
			array(
				'model'      => 'embed-m',
				'dimensions' => 512,
			)
		);
		$this->assertSame( 512, $this->sent_body( 1 )['output_dimension'] );
	}

	public function test_embeddings_unsupported_and_count_mismatch(): void {
		try {
			$this->provider( 'groq' )->embed( array( 'x' ), array( 'model' => 'm' ) );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::UNSUPPORTED, $error->type() );
		}

		$this->queue_response( 200, array( 'data' => array() ) );
		$this->expectException( ProviderError::class );
		$this->provider( 'openai' )->embed( array( 'x' ), array( 'model' => 'm' ) );
	}

	public function test_connection_test_reports_success_and_failure(): void {
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );
		$ok = $this->provider( 'openai' )->test_connection();
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'OK', $ok['reply'] );
		$this->assertSame( 32, $this->sent_body()['max_completion_tokens'] );

		$this->queue_response( 401, array( 'error' => array( 'message' => 'Incorrect API key provided' ) ) );
		$failed = $this->provider( 'openai' )->test_connection();
		$this->assertFalse( $failed['ok'] );
		$this->assertSame( ProviderError::INVALID_KEY, $failed['error']['type'] );
		$this->assertSame( 'https://platform.openai.com/settings/organization/api-keys', $failed['error']['fix']['url'] );
	}

	public function test_connection_test_picks_first_chat_model_when_none_is_set(): void {
		$this->queue_response(
			200,
			array(
				'data' => array(
					array( 'id' => 'text-embedding-1' ),
					array( 'id' => 'chat-1' ),
				),
			)
		);
		$this->queue_response( 200, array( 'choices' => array( array( 'message' => array( 'content' => 'OK' ) ) ) ) );

		$result = $this->provider(
			'custom',
			array(
				'base_url'      => 'http://x.test/v1',
				'default_model' => '',
			)
		)->test_connection();

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'chat-1', $this->sent_body( 1 )['model'] );
	}
}
