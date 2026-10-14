<?php
/**
 * Adapter for the Google Gemini API.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ProviderError and StreamAbortedException messages are never printed as HTML: admins receive them
// as JSON through the REST API (rendered as text), visitors only see fixed strings, and
// getMessage() is HTML-escaped as a safeguard. Escaping at each throw would corrupt that text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * generateContent / streamGenerateContent?alt=sse with `systemInstruction`,
 * the `x-goog-api-key` header (so the key never appears in URLs or logs),
 * and batchEmbedContents with outputDimensionality.
 *
 * Plugin Check flags direct AI provider integrations and suggests the
 * WordPress AI Client (WordPress 7.0+). Direct integration is required here:
 * Chatlume supports WordPress versions that have no AI Client, streams every
 * reply, and supports providers the AI Client does not cover.
 */
class GeminiProvider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Google Gemini', 'chatlume-ai-chatbot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_info(): array {
		return array(
			'description'       => __( 'Fast, affordable models from Google with embeddings for the knowledge base.', 'chatlume-ai-chatbot' ),
			'links'             => array(
				'keys'    => 'https://aistudio.google.com/apikey',
				'billing' => 'https://ai.google.dev/gemini-api/docs/billing',
				'docs'    => 'https://ai.google.dev/gemini-api/docs',
				'status'  => 'https://aistudio.google.com/status',
			),
			'key_required'      => true,
			'default_base_url'  => 'https://generativelanguage.googleapis.com/v1beta',
			'base_url_editable' => false,
			'initials'          => 'GE',
			'kind'              => 'native',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array(
			'streaming'            => true,
			'embeddings'           => true,
			'embedding_dimensions' => true,
			'tools'                => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auth_headers(): array {
		return array( 'x-goog-api-key' => (string) $this->config['api_key'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fetch_models(): array {
		$models = array();
		$token  = '';

		for ( $page = 0; $page < 10; $page++ ) {
			$json = $this->request_json( 'GET', '/models?pageSize=1000' . ( '' !== $token ? '&pageToken=' . rawurlencode( $token ) : '' ), array(), 20 );

			foreach ( (array) ( $json['models'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['name'] ) || ! is_string( $item['name'] ) ) {
					continue;
				}
				$methods = isset( $item['supportedGenerationMethods'] ) && is_array( $item['supportedGenerationMethods'] ) ? $item['supportedGenerationMethods'] : array();
				$kind    = in_array( 'generateContent', $methods, true ) ? 'chat' : ( in_array( 'embedContent', $methods, true ) ? 'embedding' : 'other' );

				$models[] = array(
					'id'             => self::strip_prefix( $item['name'] ),
					'label'          => (string) ( $item['displayName'] ?? self::strip_prefix( $item['name'] ) ),
					'kind'           => $kind,
					'context_length' => (int) ( $item['inputTokenLimit'] ?? 0 ),
					'input_price'    => null,
					'output_price'   => null,
					'free'           => false,
					'created'        => 0,
				);
			}

			$token = (string) ( $json['nextPageToken'] ?? '' );
			if ( '' === $token ) {
				break;
			}
		}

		return $models;
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages, array $options ): ChatResponse {
		$this->ensure_ready();

		$started = microtime( true );
		$model   = $this->model( $options );
		$json    = $this->request_json( 'POST', '/models/' . rawurlencode( $model ) . ':generateContent', $this->body( $messages, $options ), $this->timeout( $options ) );

		$this->check_blocked( $json );

		$candidate = $json['candidates'][0] ?? array();

		return new ChatResponse(
			array(
				'text'          => self::candidate_text( $candidate ),
				'input_tokens'  => (int) ( $json['usageMetadata']['promptTokenCount'] ?? 0 ),
				'output_tokens' => (int) ( $json['usageMetadata']['candidatesTokenCount'] ?? 0 ),
				'finish_reason' => (string) ( $candidate['finishReason'] ?? '' ),
				'latency_ms'    => self::elapsed_ms( $started ),
				'provider'      => $this->get_id(),
				'model'         => (string) ( $json['modelVersion'] ?? $model ),
				'raw'           => $json,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function stream( array $messages, array $options, callable $on_token ): ChatResponse {
		$this->ensure_ready();

		$started = microtime( true );
		$model   = $this->model( $options );
		$state   = array(
			'text'          => '',
			'finish_reason' => '',
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'model'         => $model,
			'raw'           => array(),
		);

		$on_event = function ( array $event ) use ( &$state, $on_token ) {
			$json = json_decode( $event['data'], true );
			if ( ! is_array( $json ) ) {
				return;
			}

			if ( isset( $json['error'] ) ) {
				throw $this->map_error( (int) ( is_numeric( $json['error']['code'] ?? null ) ? $json['error']['code'] : 500 ), $json, '', array() );
			}

			$this->check_blocked( $json );
			$state['raw'] = $json;

			// Each event is a complete GenerateContentResponse holding the next fragment.
			$candidate = $json['candidates'][0] ?? array();
			$fragment  = self::candidate_text( is_array( $candidate ) ? $candidate : array() );
			if ( '' !== $fragment ) {
				$state['text'] .= $fragment;
				$on_token( $fragment );
			}
			if ( ! empty( $candidate['finishReason'] ) ) {
				$state['finish_reason'] = (string) $candidate['finishReason'];
			}
			if ( isset( $json['usageMetadata'] ) && is_array( $json['usageMetadata'] ) ) {
				$state['input_tokens']  = (int) ( $json['usageMetadata']['promptTokenCount'] ?? $state['input_tokens'] );
				$state['output_tokens'] = (int) ( $json['usageMetadata']['candidatesTokenCount'] ?? $state['output_tokens'] );
			}
			if ( ! empty( $json['modelVersion'] ) && is_string( $json['modelVersion'] ) ) {
				$state['model'] = $json['modelVersion'];
			}
		};

		try {
			$result = $this->request_stream( '/models/' . rawurlencode( $model ) . ':streamGenerateContent?alt=sse', $this->body( $messages, $options ), $options, $on_event );
		} catch ( ProviderError $error ) {
			throw $error->with_partial_text( $state['text'] );
		}

		return new ChatResponse(
			array(
				'text'          => $state['text'],
				'input_tokens'  => $state['input_tokens'],
				'output_tokens' => $state['output_tokens'],
				'finish_reason' => '' !== $result['aborted'] ? 'aborted' : $state['finish_reason'],
				'latency_ms'    => self::elapsed_ms( $started ),
				'provider'      => $this->get_id(),
				'model'         => $state['model'],
				'raw'           => $state['raw'],
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[]             $texts   Texts.
	 * @param array<string, mixed> $options Options.
	 */
	public function embed( array $texts, array $options ): array {
		$this->ensure_ready();

		$texts = array_values( array_map( 'strval', $texts ) );
		if ( array() === $texts ) {
			return array();
		}

		$model      = self::strip_prefix( $this->model( $options ) );
		$dimensions = (int) ( $options['dimensions'] ?? 0 );
		$requests   = array();

		foreach ( $texts as $text ) {
			$request = array(
				'model'   => 'models/' . $model,
				'content' => array( 'parts' => array( array( 'text' => $text ) ) ),
			);
			if ( $dimensions > 0 ) {
				$request['embedContentConfig'] = array( 'outputDimensionality' => $dimensions );
			}
			$requests[] = $request;
		}

		$json    = $this->request_json( 'POST', '/models/' . rawurlencode( $model ) . ':batchEmbedContents', array( 'requests' => $requests ), $this->timeout( $options ) );
		$vectors = array();

		foreach ( (array) ( $json['embeddings'] ?? array() ) as $embedding ) {
			if ( isset( $embedding['values'] ) && is_array( $embedding['values'] ) ) {
				$vectors[] = array_map( 'floatval', $embedding['values'] );
			}
		}

		if ( count( $vectors ) !== count( $texts ) ) {
			throw $this->error( ProviderError::SERVER_ERROR, __( 'The provider returned a different number of embeddings than requested.', 'chatlume-ai-chatbot' ) );
		}

		return $vectors;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Gemini errors come in two documented shapes: google.rpc
	 * (`{error: {code: 400, status: "INVALID_ARGUMENT"}}`) and the newer
	 * snake_case codes (`{error: {code: "rate_limit_exceeded"}}`).
	 */
	protected function map_error( int $status, array $json, string $raw, array $headers ): ProviderError {
		$generic = parent::map_error( $status, $json, $raw, $headers );
		$codes   = strtolower(
			( is_string( $json['error']['status'] ?? null ) ? $json['error']['status'] : '' ) . ' ' .
			( is_string( $json['error']['code'] ?? null ) ? $json['error']['code'] : '' )
		);
		$message = strtolower( $generic->detail() );

		if ( false !== strpos( $message, 'api key' ) ) {
			$type = ProviderError::INVALID_KEY;
		} else {
			$rules = array(
				'/unauthenticated|authentication|permission_denied/' => ProviderError::INVALID_KEY,
				'/payment_required|failed_precondition|quota_exceeded/' => ProviderError::QUOTA,
				'/resource_exhausted|rate_limit_exceeded|too_many_requests/' => ProviderError::RATE_LIMITED,
				'/not_found|model_not_found/' => ProviderError::MODEL_NOT_FOUND,
				'/deadline_exceeded/'         => ProviderError::TIMEOUT,
				'/unavailable|internal|service_unavailable/' => ProviderError::SERVER_ERROR,
			);
			$type  = $generic->type();
			foreach ( $rules as $pattern => $mapped ) {
				if ( preg_match( $pattern, $codes ) ) {
					$type = $mapped;
					break;
				}
			}
		}

		return $this->error( $type, $generic->detail(), $status, $generic->retry_after() );
	}

	/**
	 * Request body for generateContent.
	 *
	 * @param array<int, mixed>    $messages Messages.
	 * @param array<string, mixed> $options  Options.
	 * @return array<string, mixed>
	 */
	private function body( array $messages, array $options ): array {
		$split    = self::split_system( $messages );
		$contents = array();

		foreach ( self::alternate( $split['messages'] ) as $turn ) {
			$contents[] = array(
				'role'  => 'assistant' === $turn['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $turn['content'] ) ),
			);
		}

		if ( array() === $contents ) {
			throw $this->error( ProviderError::BAD_REQUEST, __( 'There is no message to send.', 'chatlume-ai-chatbot' ) );
		}

		$body   = array( 'contents' => $contents );
		$config = array();

		if ( '' !== $split['system'] ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $split['system'] ) ) );
		}
		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			$config['temperature'] = max( 0.0, min( 2.0, (float) $options['temperature'] ) );
		}
		if ( ! empty( $options['max_tokens'] ) ) {
			$config['maxOutputTokens'] = max( 1, (int) $options['max_tokens'] );
		}
		if ( array() !== $config ) {
			$body['generationConfig'] = $config;
		}

		return $body;
	}

	/**
	 * Text of a candidate, skipping internal "thought" parts.
	 *
	 * @param array<string, mixed> $candidate Candidate.
	 * @return string
	 */
	private static function candidate_text( array $candidate ): string {
		$text = '';

		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}

		return $text;
	}

	/**
	 * Throws when the prompt itself was blocked.
	 *
	 * @param array<string, mixed> $json Response.
	 * @return void
	 * @throws ProviderError When blocked.
	 */
	private function check_blocked( array $json ): void {
		if ( ! empty( $json['promptFeedback']['blockReason'] ) ) {
			throw $this->error( ProviderError::CONTENT_BLOCKED, (string) $json['promptFeedback']['blockReason'] );
		}
	}

	/**
	 * "models/x" → "x".
	 *
	 * @param string $name Model resource name or ID.
	 * @return string
	 */
	private static function strip_prefix( string $name ): string {
		return 0 === strpos( $name, 'models/' ) ? substr( $name, 7 ) : $name;
	}
}
