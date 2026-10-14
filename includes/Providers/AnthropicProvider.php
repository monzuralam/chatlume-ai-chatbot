<?php
/**
 * Adapter for the Anthropic Messages API.
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
 * Messages API with `x-api-key` and `anthropic-version` headers, the system
 * prompt as a top-level field, and its own SSE event types. No embeddings.
 *
 * Plugin Check flags direct AI provider integrations and suggests the
 * WordPress AI Client (WordPress 7.0+). Direct integration is required here:
 * Chatlume supports WordPress versions that have no AI Client, streams every
 * reply, and supports providers the AI Client does not cover.
 */
class AnthropicProvider extends AbstractProvider {

	/**
	 * API version header value.
	 */
	public const API_VERSION = '2023-06-01';

	/**
	 * Default output limit (the API requires max_tokens).
	 */
	private const DEFAULT_MAX_TOKENS = 1024;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Anthropic (Claude)', 'chatlume-ai-chatbot' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_info(): array {
		return array(
			'description'       => __( 'Thoughtful, reliable models from Anthropic. Choose another provider for knowledge-base embeddings.', 'chatlume-ai-chatbot' ),
			'links'             => array(
				'keys'    => 'https://platform.claude.com/settings/keys',
				'billing' => 'https://platform.claude.com/settings/billing',
				'docs'    => 'https://platform.claude.com/docs',
				'status'  => 'https://status.claude.com',
			),
			'key_required'      => true,
			'default_base_url'  => 'https://api.anthropic.com/v1',
			'base_url_editable' => false,
			'initials'          => 'AN',
			'kind'              => 'native',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array(
			'streaming'            => true,
			'embeddings'           => false,
			'embedding_dimensions' => false,
			'tools'                => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auth_headers(): array {
		return array(
			'x-api-key'         => (string) $this->config['api_key'],
			'anthropic-version' => self::API_VERSION,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fetch_models(): array {
		$models   = array();
		$after_id = '';

		// Paginated with after_id; five pages of 1,000 is far more than exist.
		for ( $page = 0; $page < 5; $page++ ) {
			$query = '/models?limit=1000' . ( '' !== $after_id ? '&after_id=' . rawurlencode( $after_id ) : '' );
			$json  = $this->request_json( 'GET', $query, array(), 20 );

			foreach ( (array) ( $json['data'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_string( $item['id'] ) ) {
					continue;
				}
				$created  = isset( $item['created_at'] ) ? strtotime( (string) $item['created_at'] ) : 0;
				$models[] = array(
					'id'             => $item['id'],
					'label'          => (string) ( $item['display_name'] ?? $item['id'] ),
					'kind'           => 'chat',
					'context_length' => (int) ( $item['max_input_tokens'] ?? 0 ),
					'input_price'    => null,
					'output_price'   => null,
					'free'           => false,
					'created'        => false === $created ? 0 : (int) $created,
				);
			}

			if ( empty( $json['has_more'] ) || empty( $json['last_id'] ) ) {
				break;
			}
			$after_id = (string) $json['last_id'];
		}

		return $models;
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages, array $options ): ChatResponse {
		$this->ensure_ready();

		$started = microtime( true );
		$body    = $this->body( $messages, $options, false );
		$json    = $this->request_json( 'POST', '/messages', $body, $this->timeout( $options ) );

		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		return new ChatResponse(
			array(
				'text'          => $text,
				'input_tokens'  => (int) ( $json['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $json['usage']['output_tokens'] ?? 0 ),
				'finish_reason' => (string) ( $json['stop_reason'] ?? '' ),
				'latency_ms'    => self::elapsed_ms( $started ),
				'provider'      => $this->get_id(),
				'model'         => (string) ( $json['model'] ?? $body['model'] ),
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
		$body    = $this->body( $messages, $options, true );
		$state   = array(
			'text'          => '',
			'finish_reason' => '',
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'model'         => (string) $body['model'],
			'raw'           => array(),
		);

		$on_event = function ( array $event ) use ( &$state, $on_token ) {
			$json = json_decode( $event['data'], true );
			if ( ! is_array( $json ) ) {
				return;
			}

			$type = (string) ( $json['type'] ?? $event['event'] );

			switch ( $type ) {
				case 'message_start':
					$state['model']        = (string) ( $json['message']['model'] ?? $state['model'] );
					$state['input_tokens'] = (int) ( $json['message']['usage']['input_tokens'] ?? 0 );
					break;

				case 'content_block_delta':
					if ( 'text_delta' === ( $json['delta']['type'] ?? '' ) && isset( $json['delta']['text'] ) && '' !== $json['delta']['text'] ) {
						$state['text'] .= (string) $json['delta']['text'];
						$on_token( (string) $json['delta']['text'] );
					}
					break;

				case 'message_delta':
					$state['raw']           = $json;
					$state['finish_reason'] = (string) ( $json['delta']['stop_reason'] ?? $state['finish_reason'] );
					// Usage in message_delta is cumulative.
					$state['output_tokens'] = (int) ( $json['usage']['output_tokens'] ?? $state['output_tokens'] );
					if ( isset( $json['usage']['input_tokens'] ) ) {
						$state['input_tokens'] = (int) $json['usage']['input_tokens'];
					}
					break;

				case 'error':
					throw $this->map_error( 500, $json, '', array() );
			}
		};

		try {
			$result = $this->request_stream( '/messages', $body, $options, $on_event );
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
	 */
	protected function map_error( int $status, array $json, string $raw, array $headers ): ProviderError {
		$generic = parent::map_error( $status, $json, $raw, $headers );
		$type    = (string) ( $json['error']['type'] ?? '' );
		$message = strtolower( $generic->detail() );

		$map = array(
			'authentication_error' => ProviderError::INVALID_KEY,
			'permission_error'     => ProviderError::INVALID_KEY,
			'billing_error'        => ProviderError::QUOTA,
			'not_found_error'      => ProviderError::MODEL_NOT_FOUND,
			'request_too_large'    => ProviderError::CONTEXT_TOO_LONG,
			'rate_limit_error'     => ProviderError::RATE_LIMITED,
			'api_error'            => ProviderError::SERVER_ERROR,
			'overloaded_error'     => ProviderError::SERVER_ERROR,
			'timeout_error'        => ProviderError::TIMEOUT,
		);

		// The low-credit error is documented only by its wording, so it is matched first.
		if ( false !== strpos( $message, 'credit balance is too low' ) ) {
			$resolved = ProviderError::QUOTA;
		} elseif ( 'invalid_request_error' === $type ) {
			$resolved = $generic->type();
		} else {
			$resolved = $map[ $type ] ?? $generic->type();
		}

		return $this->error( $resolved, $generic->detail(), $status, $generic->retry_after() );
	}

	/**
	 * Request body for /messages.
	 *
	 * @param array<int, mixed>    $messages Messages.
	 * @param array<string, mixed> $options  Options.
	 * @param bool                 $stream   Streaming.
	 * @return array<string, mixed>
	 */
	private function body( array $messages, array $options, bool $stream ): array {
		$split = self::split_system( $messages );
		$turns = self::alternate( $split['messages'] );

		if ( array() === $turns ) {
			throw $this->error( ProviderError::BAD_REQUEST, __( 'There is no message to send.', 'chatlume-ai-chatbot' ) );
		}

		$body = array(
			'model'      => $this->model( $options ),
			'max_tokens' => ! empty( $options['max_tokens'] ) ? max( 1, (int) $options['max_tokens'] ) : self::DEFAULT_MAX_TOKENS,
			'messages'   => $turns,
			'stream'     => $stream,
		);

		if ( '' !== $split['system'] ) {
			$body['system'] = $split['system'];
		}

		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			// Anthropic accepts 0–1.
			$body['temperature'] = max( 0.0, min( 1.0, (float) $options['temperature'] ) );
		}

		return $body;
	}
}
