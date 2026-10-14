<?php
/**
 * Tests for AnthropicProvider.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\AnthropicProvider;
use Chatlume\Providers\ProviderError;
use Chatlume\Tests\Support\FakeStreamer;
use Chatlume\Tests\TestCase;

/**
 * Messages API request shape, SSE events, errors, and models.
 */
class AnthropicProviderTest extends TestCase {

	/**
	 * Provider.
	 *
	 * @param FakeStreamer|null $streamer Streamer.
	 * @return AnthropicProvider
	 */
	private function provider( ?FakeStreamer $streamer = null ): AnthropicProvider {
		return new AnthropicProvider(
			array(
				'api_key'       => 'sk-ant-test',
				'default_model' => 'model-x',
			),
			$streamer
		);
	}

	/**
	 * Messages starting with an assistant turn and repeated roles.
	 *
	 * @return array<int, array{role: string, content: string}>
	 */
	private function messages(): array {
		return array(
			array(
				'role'    => 'system',
				'content' => 'Rule one.',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Welcome!',
			),
			array(
				'role'    => 'user',
				'content' => 'Hi',
			),
			array(
				'role'    => 'user',
				'content' => 'Are you there?',
			),
			array(
				'role'    => 'system',
				'content' => 'Rule two.',
			),
		);
	}

	public function test_request_shape(): void {
		$this->queue_response(
			200,
			array(
				'model'       => 'model-x-1',
				'content'     => array(
					array(
						'type' => 'text',
						'text' => 'Yes',
					),
				),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 9,
					'output_tokens' => 1,
				),
			)
		);

		$response = $this->provider()->chat( $this->messages(), array( 'temperature' => 1.7 ) );

		list( $url, $args ) = $this->http_requests[0];
		$body               = $this->sent_body();

		$this->assertSame( 'https://api.anthropic.com/v1/messages', $url );
		$this->assertSame( 'sk-ant-test', $args['headers']['x-api-key'] );
		$this->assertSame( AnthropicProvider::API_VERSION, $args['headers']['anthropic-version'] );
		$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
		$this->assertSame( "Rule one.\n\nRule two.", $body['system'] );
		$this->assertSame(
			array(
				array(
					'role'    => 'user',
					'content' => "Hi\n\nAre you there?",
				),
			),
			$body['messages'],
			'Leading assistant turns are dropped and consecutive turns are joined.'
		);
		$this->assertSame( 1024, $body['max_tokens'] );
		$this->assertEquals( 1.0, $body['temperature'], 'Clamped to 0–1.' );

		$this->assertSame( 'Yes', $response->text );
		$this->assertSame( 'stop', $response->finish_reason );
		$this->assertSame( 9, $response->input_tokens );
	}

	public function test_stream_events(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array(
						'__event' => 'message_start',
						'type'    => 'message_start',
						'message' => array(
							'model' => 'model-x-1',
							'usage' => array( 'input_tokens' => 25 ),
						),
					),
					array(
						'__event' => 'ping',
						'type'    => 'ping',
					),
					array(
						'__event' => 'content_block_delta',
						'type'    => 'content_block_delta',
						'delta'   => array(
							'type' => 'text_delta',
							'text' => 'Hi ',
						),
					),
					array(
						'__event' => 'content_block_delta',
						'type'    => 'content_block_delta',
						'delta'   => array(
							'type' => 'text_delta',
							'text' => 'there',
						),
					),
					array(
						'__event' => 'message_delta',
						'type'    => 'message_delta',
						'delta'   => array( 'stop_reason' => 'max_tokens' ),
						'usage'   => array( 'output_tokens' => 2 ),
					),
					array(
						'__event' => 'message_delta',
						'type'    => 'message_delta',
						'delta'   => array( 'stop_reason' => 'max_tokens' ),
						'usage'   => array( 'output_tokens' => 4 ),
					),
					array(
						'__event' => 'message_stop',
						'type'    => 'message_stop',
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

		$this->assertSame( array( 'Hi ', 'there' ), $tokens );
		$this->assertSame( 'Hi there', $response->text );
		$this->assertSame( 25, $response->input_tokens );
		$this->assertSame( 4, $response->output_tokens, 'Usage in message_delta is cumulative.' );
		$this->assertSame( 'length', $response->finish_reason );
		$this->assertSame( 'model-x-1', $response->model );
		$this->assertTrue( $streamer->last_body()['stream'] );
	}

	public function test_error_event_mid_stream(): void {
		$streamer = new FakeStreamer(
			FakeStreamer::sse(
				array(
					array(
						'__event' => 'content_block_delta',
						'type'    => 'content_block_delta',
						'delta'   => array(
							'type' => 'text_delta',
							'text' => 'Part',
						),
					),
					array(
						'__event' => 'error',
						'type'    => 'error',
						'error'   => array(
							'type'    => 'overloaded_error',
							'message' => 'Overloaded',
						),
					),
				)
			)
		);

		try {
			$this->provider( $streamer )->stream( $this->messages(), array(), static function () {} );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( ProviderError::SERVER_ERROR, $error->type() );
			$this->assertSame( 'Part', $error->partial_text() );
		}
	}

	/**
	 * Documented error types.
	 *
	 * @return array<string, array{0: int, 1: string, 2: string, 3: string}>
	 */
	public function error_cases(): array {
		return array(
			'auth'        => array( 401, 'authentication_error', 'invalid x-api-key', ProviderError::INVALID_KEY ),
			'billing'     => array( 402, 'billing_error', 'Billing problem', ProviderError::QUOTA ),
			'low credit'  => array( 400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.', ProviderError::QUOTA ),
			'prompt long' => array( 400, 'invalid_request_error', 'prompt is too long: 250000 tokens > 200000 maximum', ProviderError::CONTEXT_TOO_LONG ),
			'not found'   => array( 404, 'not_found_error', 'model: x', ProviderError::MODEL_NOT_FOUND ),
			'too large'   => array( 413, 'request_too_large', 'Request exceeds the maximum allowed number of bytes.', ProviderError::CONTEXT_TOO_LONG ),
			'rate'        => array( 429, 'rate_limit_error', 'Number of requests has exceeded your rate limit', ProviderError::RATE_LIMITED ),
			'overloaded'  => array( 529, 'overloaded_error', 'Overloaded', ProviderError::SERVER_ERROR ),
			'timeout'     => array( 504, 'timeout_error', 'Request timed out', ProviderError::TIMEOUT ),
		);
	}

	/**
	 * Error mapping.
	 *
	 * @dataProvider error_cases
	 *
	 * @param int    $status   Status.
	 * @param string $type     Anthropic error type.
	 * @param string $message  Message.
	 * @param string $expected Expected type.
	 */
	public function test_error_mapping( int $status, string $type, string $message, string $expected ): void {
		$this->queue_response(
			$status,
			array(
				'type'  => 'error',
				'error' => array(
					'type'    => $type,
					'message' => $message,
				),
			)
		);

		try {
			$this->provider()->chat( $this->messages(), array() );
			$this->fail( 'Expected ProviderError.' );
		} catch ( ProviderError $error ) {
			$this->assertSame( $expected, $error->type() );
		}
	}

	public function test_models_are_paginated(): void {
		$this->queue_response(
			200,
			array(
				'data'     => array(
					array(
						'id'               => 'model-a',
						'display_name'     => 'Model A',
						'created_at'       => '2026-01-01T00:00:00Z',
						'max_input_tokens' => 200000,
					),
				),
				'has_more' => true,
				'last_id'  => 'model-a',
			)
		);
		$this->queue_response(
			200,
			array(
				'data'     => array( array( 'id' => 'model-b' ) ),
				'has_more' => false,
			)
		);

		$models = $this->provider()->list_models();

		$this->assertSame( array( 'model-a', 'model-b' ), array_column( $models, 'id' ) );
		$this->assertSame( 'Model A', $models[0]['label'] );
		$this->assertSame( 200000, $models[0]['context_length'] );
		$this->assertStringContainsString( 'after_id=model-a', $this->http_requests[1][0] );
		$this->assertFalse( $this->provider()->supports( 'embeddings' ) );
	}
}
