<?php
/**
 * Tests for GeminiProvider.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\GeminiProvider;
use Chatlume\Providers\ProviderError;
use Chatlume\Tests\Support\FakeStreamer;
use Chatlume\Tests\TestCase;

/**
 * generateContent shape, SSE chunks, embeddings, both error shapes, and models.
 */
class GeminiProviderTest extends TestCase {

	/**
	 * Provider.
	 *
	 * @param FakeStreamer|null $streamer Streamer.
	 * @return GeminiProvider
	 */
	private function provider( ?FakeStreamer $streamer = null ): GeminiProvider {
		return new GeminiProvider(
			array(
				'api_key'       => 'AIzaTestKey',
				'default_model' => 'flash-x',
			),
			$streamer
		);
	}

	/**
	 * Messages.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	private function messages(): array {
		return array(
			array(
				'role'    => 'system',
				'content' => 'Be kind.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Hello',
			),
			array(
				'role'    => 'user',
				'content' => 'Help',
			),
		);
	}

	public function test_request_shape_and_key_in_header(): void {
		$this->queue_response(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array(
							'parts' => array(
								array(
									'text'    => 'thinking…',
									'thought' => true,
								),
								array( 'text' => 'Sure!' ),
							),
						),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 11,
					'candidatesTokenCount' => 2,
				),
				'modelVersion'  => 'flash-x-001',
			)
		);

		$response = $this->provider()->chat(
			$this->messages(),
			array(
				'temperature' => 0.3,
				'max_tokens'  => 99,
			)
		);

		list( $url, $args ) = $this->http_requests[0];
		$body               = $this->sent_body();

		$this->assertSame( 'https://generativelanguage.googleapis.com/v1beta/models/flash-x:generateContent', $url );
		$this->assertStringNotContainsString( 'key=', $url, 'The key never goes into the URL.' );
		$this->assertSame( 'AIzaTestKey', $args['headers']['x-goog-api-key'] );
		$this->assertSame( array( 'parts' => array( array( 'text' => 'Be kind.' ) ) ), $body['systemInstruction'] );
		$this->assertSame( array( 'user', 'model', 'user' ), array_column( $body['contents'], 'role' ) );
		$this->assertSame( 0.3, $body['generationConfig']['temperature'] );
		$this->assertSame( 99, $body['generationConfig']['maxOutputTokens'] );

		$this->assertSame( 'Sure!', $response->text, 'Thought parts are not part of the answer.' );
		$this->assertSame( 'stop', $response->finish_reason );
		$this->assertSame( 11, $response->input_tokens );
		$this->assertSame( 'flash-x-001', $response->model );
	}

	public function test_stream_chunks(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'One ' ) ) ) ) ) ),
					array(
						'candidates'    => array(
							array(
								'content'      => array( 'parts' => array( array( 'text' => 'two' ) ) ),
								'finishReason' => 'MAX_TOKENS',
							),
						),
						'usageMetadata' => array(
							'promptTokenCount'     => 5,
							'candidatesTokenCount' => 2,
						),
					),
				)
			)
		);

		$tokens   = array();
		$response = $this->provider( $streamer )->stream(
			$this->messages(),
			array(),
			static function ( $text ) use ( &$tokens ) {
				$tokens[] = $text;
			}
		);

		$this->assertSame( array( 'One ', 'two' ), $tokens );
		$this->assertSame( 'length', $response->finish_reason );
		$this->assertSame( 2, $response->output_tokens );
		$this->assertStringEndsWith( ':streamGenerateContent?alt=sse', $streamer->requests[0][0] );
	}

	public function test_blocked_prompt(): void {
		$this->queue_response( 200, array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) );

		try {
			$this->provider()->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::CONTENT_BLOCKED, $error->type() );
		}
	}

	/**
	 * Both documented error shapes.
	 *
	 * @return array<string, array{0: int, 1: array<string, mixed>, 2: string}>
	 */
	public function error_cases(): array {
		return array(
			'rpc bad key'     => array(
				400,
				array(
					'error' => array(
						'code'    => 400,
						'message' => 'API key not valid. Please pass a valid API key.',
						'status'  => 'INVALID_ARGUMENT',
					),
				),
				ProviderError::INVALID_KEY,
			),
			'rpc exhausted'   => array(
				429,
				array(
					'error' => array(
						'code'    => 429,
						'message' => 'Resource has been exhausted',
						'status'  => 'RESOURCE_EXHAUSTED',
					),
				),
				ProviderError::RATE_LIMITED,
			),
			'rpc unavailable' => array(
				503,
				array(
					'error' => array(
						'code'    => 503,
						'message' => 'The model is overloaded.',
						'status'  => 'UNAVAILABLE',
					),
				),
				ProviderError::SERVER_ERROR,
			),
			'rpc not found'   => array(
				404,
				array(
					'error' => array(
						'code'    => 404,
						'message' => 'models/x is not found',
						'status'  => 'NOT_FOUND',
					),
				),
				ProviderError::MODEL_NOT_FOUND,
			),
			'snake quota'     => array(
				429,
				array(
					'error' => array(
						'code'    => 'quota_exceeded',
						'message' => 'Daily quota used',
					),
				),
				ProviderError::QUOTA,
			),
			'snake payment'   => array(
				402,
				array(
					'error' => array(
						'code'    => 'payment_required',
						'message' => 'Prepaid credits used up',
					),
				),
				ProviderError::QUOTA,
			),
			'snake auth'      => array(
				401,
				array(
					'error' => array(
						'code'    => 'authentication',
						'message' => 'Unauthenticated',
					),
				),
				ProviderError::INVALID_KEY,
			),
			'snake deadline'  => array(
				504,
				array(
					'error' => array(
						'code'    => 'deadline_exceeded',
						'message' => 'Deadline',
					),
				),
				ProviderError::TIMEOUT,
			),
		);
	}

	/**
	 * Error mapping.
	 *
	 * @dataProvider error_cases
	 *
	 * @param int                  $status   Status.
	 * @param array<string, mixed> $body     Body.
	 * @param string               $expected Expected type.
	 */
	public function test_error_mapping( int $status, array $body, string $expected ): void {
		$this->queue_response( $status, $body );

		try {
			$this->provider()->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( $expected, $error->type() );
		}
	}

	public function test_batch_embeddings_with_dimensions(): void {
		$this->queue_response(
			200,
			array(
				'embeddings' => array(
					array( 'values' => array( 0.1, 0.2 ) ),
					array( 'values' => array( 0.3, 0.4 ) ),
				),
			)
		);

		$vectors = $this->provider()->embed(
			array( 'a', 'b' ),
			array(
				'model'      => 'models/embed-x',
				'dimensions' => 256,
			)
		);
		$body    = $this->sent_body();

		$this->assertSame( array( array( 0.1, 0.2 ), array( 0.3, 0.4 ) ), $vectors );
		$this->assertStringEndsWith( '/models/embed-x:batchEmbedContents', $this->http_requests[0][0] );
		$this->assertSame( 'models/embed-x', $body['requests'][0]['model'] );
		$this->assertSame( 'b', $body['requests'][1]['content']['parts'][0]['text'] );
		$this->assertSame( 256, $body['requests'][0]['embedContentConfig']['outputDimensionality'] );
	}

	public function test_models_are_paginated_and_classified(): void {
		$this->queue_response(
			200,
			array(
				'models'        => array(
					array(
						'name'                       => 'models/flash-x',
						'displayName'                => 'Flash X',
						'inputTokenLimit'            => 1048576,
						'supportedGenerationMethods' => array( 'generateContent', 'countTokens' ),
					),
				),
				'nextPageToken' => 'page2',
			)
		);
		$this->queue_response(
			200,
			array(
				'models' => array(
					array(
						'name'                       => 'models/embed-x',
						'supportedGenerationMethods' => array( 'embedContent' ),
					),
					array(
						'name'                       => 'models/aqa',
						'supportedGenerationMethods' => array( 'generateAnswer' ),
					),
				),
			)
		);

		$models = array_column( $this->provider()->list_models(), 'kind', 'id' );

		$this->assertSame( 'chat', $models['flash-x'] );
		$this->assertSame( 'embedding', $models['embed-x'] );
		$this->assertSame( 'other', $models['aqa'] );
		$this->assertStringContainsString( 'pageToken=page2', $this->http_requests[1][0] );
	}
}
