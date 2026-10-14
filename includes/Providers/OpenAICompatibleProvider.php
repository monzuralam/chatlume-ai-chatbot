<?php
/**
 * Providers that speak the OpenAI Chat Completions protocol.
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
 * One implementation, many presets: OpenAI, OpenRouter, Groq, Mistral,
 * DeepSeek, xAI, Together, Ollama, and any custom OpenAI-compatible endpoint.
 *
 * Endpoints: GET /models, POST /chat/completions (SSE with `data: [DONE]`),
 * POST /embeddings. Differences between services are preset flags rather
 * than subclasses.
 *
 * Plugin Check flags direct AI provider integrations and suggests the
 * WordPress AI Client (WordPress 7.0+). Direct integration is required here:
 * Chatlume supports WordPress versions that have no AI Client, streams every
 * reply, and supports providers the AI Client does not cover.
 */
class OpenAICompatibleProvider extends AbstractProvider {

	/**
	 * Preset definition.
	 *
	 * @var array<string, mixed>
	 */
	private array $preset;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $preset   Preset (see presets()).
	 * @param array<string, mixed> $config   Configuration.
	 * @param HttpStreamer|null    $streamer Streaming transport.
	 */
	public function __construct( array $preset, array $config = array(), ?HttpStreamer $streamer = null ) {
		$this->preset = array_merge(
			array(
				'label'             => '',
				'description'       => '',
				'base_url'          => '',
				'base_url_editable' => false,
				'key_required'      => true,
				'initials'          => '',
				'links'             => array(),
				'embeddings'        => false,
				'dimensions_param'  => '',
				'stream_usage'      => false,
				'max_tokens_param'  => 'max_tokens',
				'attribution'       => false,
				'pricing'           => false,
			),
			$preset
		);

		parent::__construct( $config, $streamer );
	}

	/**
	 * Built-in presets, keyed by provider ID.
	 *
	 * Protocol details were checked against each provider's documentation;
	 * links point admins to the pages they need to fix problems.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets(): array {
		$presets = array(
			'openai'     => array(
				'label'            => __( 'OpenAI (ChatGPT)', 'chatlume-ai-chatbot' ),
				'description'      => __( 'Models from the makers of ChatGPT, with embeddings for the knowledge base.', 'chatlume-ai-chatbot' ),
				'base_url'         => 'https://api.openai.com/v1',
				'initials'         => 'OA',
				'links'            => array(
					'keys'    => 'https://platform.openai.com/settings/organization/api-keys',
					'billing' => 'https://platform.openai.com/settings/organization/billing',
					'docs'    => 'https://platform.openai.com/docs',
					'status'  => 'https://status.openai.com',
				),
				'embeddings'       => true,
				'dimensions_param' => 'dimensions',
				'stream_usage'     => true,
				// Newer OpenAI models reject max_tokens in favor of max_completion_tokens.
				'max_tokens_param' => 'max_completion_tokens',
			),
			'openrouter' => array(
				'label'        => __( 'OpenRouter', 'chatlume-ai-chatbot' ),
				'description'  => __( 'One key for hundreds of models from many providers, including free models.', 'chatlume-ai-chatbot' ),
				'base_url'     => 'https://openrouter.ai/api/v1',
				'initials'     => 'OR',
				'links'        => array(
					'keys'    => 'https://openrouter.ai/settings/keys',
					'billing' => 'https://openrouter.ai/settings/credits',
					'docs'    => 'https://openrouter.ai/docs',
					'status'  => 'https://status.openrouter.ai',
				),
				// Usage is always included in the last stream chunk.
				'stream_usage' => false,
				'attribution'  => true,
				'pricing'      => true,
			),
			'groq'       => array(
				'label'        => __( 'Groq', 'chatlume-ai-chatbot' ),
				'description'  => __( 'Very fast responses from open models.', 'chatlume-ai-chatbot' ),
				'base_url'     => 'https://api.groq.com/openai/v1',
				'initials'     => 'GQ',
				'links'        => array(
					'keys'    => 'https://console.groq.com/keys',
					'billing' => 'https://console.groq.com/settings/billing',
					'docs'    => 'https://console.groq.com/docs',
					'status'  => 'https://groqstatus.com',
				),
				'stream_usage' => true,
			),
			'mistral'    => array(
				'label'            => __( 'Mistral AI', 'chatlume-ai-chatbot' ),
				'description'      => __( 'European models with embeddings for the knowledge base.', 'chatlume-ai-chatbot' ),
				'base_url'         => 'https://api.mistral.ai/v1',
				'initials'         => 'MI',
				'links'            => array(
					'keys'    => 'https://console.mistral.ai/api-keys',
					'billing' => 'https://console.mistral.ai/billing',
					'docs'    => 'https://docs.mistral.ai',
					'status'  => 'https://status.mistral.ai',
				),
				'embeddings'       => true,
				'dimensions_param' => 'output_dimension',
			),
			'deepseek'   => array(
				'label'       => __( 'DeepSeek', 'chatlume-ai-chatbot' ),
				'description' => __( 'Capable, low-cost models.', 'chatlume-ai-chatbot' ),
				'base_url'    => 'https://api.deepseek.com',
				'initials'    => 'DS',
				'links'       => array(
					'keys'    => 'https://platform.deepseek.com/api_keys',
					'billing' => 'https://platform.deepseek.com/top_up',
					'docs'    => 'https://api-docs.deepseek.com',
					'status'  => 'https://status.deepseek.com',
				),
				// Usage is always sent in the last stream chunk.
			),
			'xai'        => array(
				'label'        => __( 'xAI', 'chatlume-ai-chatbot' ),
				'description'  => __( 'Models from xAI.', 'chatlume-ai-chatbot' ),
				'base_url'     => 'https://api.x.ai/v1',
				'initials'     => 'XA',
				'links'        => array(
					'keys'    => 'https://console.x.ai',
					'billing' => 'https://console.x.ai',
					'docs'    => 'https://docs.x.ai',
					'status'  => 'https://status.x.ai',
				),
				'stream_usage' => true,
			),
			'together'   => array(
				'label'       => __( 'Together AI', 'chatlume-ai-chatbot' ),
				'description' => __( 'Many open models with embeddings.', 'chatlume-ai-chatbot' ),
				'base_url'    => 'https://api.together.ai/v1',
				'initials'    => 'TG',
				'links'       => array(
					'keys'    => 'https://api.together.ai/settings/api-keys',
					'billing' => 'https://api.together.ai/settings/billing',
					'docs'    => 'https://docs.together.ai',
					'status'  => 'https://status.together.ai',
				),
				'embeddings'  => true,
			),
			'ollama'     => array(
				'label'             => __( 'Ollama (local)', 'chatlume-ai-chatbot' ),
				'description'       => __( 'Models running on your own server. No API key and no usage costs.', 'chatlume-ai-chatbot' ),
				'base_url'          => 'http://localhost:11434/v1',
				'base_url_editable' => true,
				'key_required'      => false,
				'initials'          => 'OL',
				'links'             => array(
					'docs' => 'https://docs.ollama.com/api/openai-compatibility',
				),
				'embeddings'        => true,
				'dimensions_param'  => 'dimensions',
				'stream_usage'      => true,
			),
			'custom'     => array(
				'label'             => __( 'Custom endpoint', 'chatlume-ai-chatbot' ),
				'description'       => __( 'Any service with an OpenAI-compatible API, such as LM Studio, llama.cpp, vLLM, or a company gateway.', 'chatlume-ai-chatbot' ),
				'base_url'          => '',
				'base_url_editable' => true,
				'key_required'      => false,
				'initials'          => 'CE',
				'links'             => array(),
				'embeddings'        => true,
				'dimensions_param'  => 'dimensions',
				'stream_usage'      => true,
			),
		);

		foreach ( $presets as $id => $preset ) {
			$presets[ $id ]['id'] = $id;
		}

		return $presets;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return (string) $this->preset['id'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return (string) $this->preset['label'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_info(): array {
		return array(
			'description'       => (string) $this->preset['description'],
			'links'             => (array) $this->preset['links'],
			'key_required'      => (bool) $this->preset['key_required'],
			'default_base_url'  => (string) $this->preset['base_url'],
			'base_url_editable' => (bool) $this->preset['base_url_editable'],
			'initials'          => (string) $this->preset['initials'],
			'kind'              => 'openai-compatible',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_schema(): array {
		$schema = parent::get_settings_schema();

		if ( $this->preset['attribution'] ) {
			$schema['app_attribution'] = array(
				'type'    => 'boolean',
				'default' => false,
			);
		}

		if ( $this->preset['pricing'] ) {
			// Prices come from the provider's own model list.
			unset( $schema['prices'] );
		}

		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function default_config(): array {
		return array_merge( parent::default_config(), array( 'app_attribution' => false ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array(
			'streaming'            => true,
			'embeddings'           => (bool) $this->preset['embeddings'],
			'embedding_dimensions' => (bool) $this->preset['embeddings'] && '' !== $this->preset['dimensions_param'],
			'tools'                => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auth_headers(): array {
		$headers = array();
		$key     = (string) $this->config['api_key'];

		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		// Optional OpenRouter app attribution (off by default; sends the site URL and name).
		if ( $this->preset['attribution'] && ! empty( $this->config['app_attribution'] ) ) {
			$headers['HTTP-Referer']       = home_url( '/' );
			$headers['X-OpenRouter-Title'] = wp_strip_all_tags( get_bloginfo( 'name' ) );
		}

		return $headers;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fetch_models(): array {
		$json  = $this->request_json( 'GET', '/models', array(), 20 );
		$items = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : $json;

		$models = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || ! is_string( $item['id'] ) ) {
				continue;
			}

			$input  = null;
			$output = null;
			if ( $this->preset['pricing'] && isset( $item['pricing']['prompt'], $item['pricing']['completion'] ) && is_numeric( $item['pricing']['prompt'] ) && is_numeric( $item['pricing']['completion'] ) ) {
				// OpenRouter prices are USD per token; Chatlume uses USD per 1M tokens.
				$input  = round( (float) $item['pricing']['prompt'] * 1000000, 6 );
				$output = round( (float) $item['pricing']['completion'] * 1000000, 6 );
			}

			$models[] = array(
				'id'             => $item['id'],
				'label'          => (string) ( $item['name'] ?? $item['display_name'] ?? $item['id'] ),
				'kind'           => self::model_kind( $item ),
				'context_length' => (int) ( $item['context_length'] ?? $item['max_context_length'] ?? $item['context_window'] ?? 0 ),
				'input_price'    => $input,
				'output_price'   => $output,
				'free'           => null !== $input && 0.0 === $input && 0.0 === $output,
				'created'        => (int) ( $item['created'] ?? 0 ),
			);
		}

		return $models;
	}

	/**
	 * Classifies a model as chat, embedding, or other from the fields
	 * providers expose (Together `type`, OpenRouter modalities, Mistral
	 * capabilities), falling back to common naming conventions.
	 *
	 * @param array<string, mixed> $item Model entry.
	 * @return string
	 */
	public static function model_kind( array $item ): string {
		$id = strtolower( (string) ( $item['id'] ?? '' ) );

		if ( isset( $item['type'] ) && is_string( $item['type'] ) ) {
			$type = strtolower( $item['type'] );
			if ( in_array( $type, array( 'chat', 'language', 'code' ), true ) ) {
				return 'chat';
			}
			return 'embedding' === $type ? 'embedding' : 'other';
		}

		if ( isset( $item['architecture']['output_modalities'] ) && is_array( $item['architecture']['output_modalities'] ) ) {
			$outputs = array_map( 'strtolower', array_filter( $item['architecture']['output_modalities'], 'is_string' ) );
			if ( in_array( 'embeddings', $outputs, true ) ) {
				return 'embedding';
			}
			return in_array( 'text', $outputs, true ) ? 'chat' : 'other';
		}

		if ( isset( $item['capabilities'] ) && is_array( $item['capabilities'] ) && array_key_exists( 'completion_chat', $item['capabilities'] ) ) {
			if ( ! empty( $item['capabilities']['completion_chat'] ) ) {
				return 'chat';
			}
			return false !== strpos( $id, 'embed' ) ? 'embedding' : 'other';
		}

		if ( false !== strpos( $id, 'embed' ) ) {
			return 'embedding';
		}

		if ( preg_match( '/whisper|tts|dall-e|image|audio|transcri|moderation|rerank|realtime|guard|sora|davinci|babbage|computer-use/', $id ) ) {
			return 'other';
		}

		return 'chat';
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages, array $options ): ChatResponse {
		$this->ensure_ready();

		$started = microtime( true );
		$body    = $this->chat_body( $messages, $options, false );

		$json = $this->with_parameter_retry(
			$body,
			function ( array $request_body ) use ( $options ) {
				return $this->request_json( 'POST', '/chat/completions', $request_body, $this->timeout( $options ) );
			}
		);

		// OpenRouter can answer 200 with an error body.
		if ( isset( $json['error'] ) ) {
			throw $this->stream_error( $json );
		}

		return $this->parse_completion( $json, (string) $body['model'], $started );
	}

	/**
	 * {@inheritDoc}
	 */
	public function stream( array $messages, array $options, callable $on_token ): ChatResponse {
		$this->ensure_ready();

		$started = microtime( true );
		$body    = $this->chat_body( $messages, $options, true );
		$state   = array(
			'text'          => '',
			'finish_reason' => '',
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'model'         => (string) $body['model'],
			'raw'           => array(),
		);

		$on_event = function ( array $event ) use ( &$state, $on_token ) {
			$data = trim( $event['data'] );
			if ( '[DONE]' === $data ) {
				return;
			}

			$json = json_decode( $data, true );
			if ( ! is_array( $json ) ) {
				return;
			}

			// Errors after the stream started (OpenRouter sends them as chunks).
			if ( isset( $json['error'] ) ) {
				throw $this->stream_error( $json );
			}

			$state['raw'] = $json;

			if ( ! empty( $json['model'] ) && is_string( $json['model'] ) ) {
				$state['model'] = $json['model'];
			}

			$choice = $json['choices'][0] ?? null;
			if ( is_array( $choice ) ) {
				$delta = $choice['delta']['content'] ?? '';
				if ( is_string( $delta ) && '' !== $delta ) {
					$state['text'] .= $delta;
					$on_token( $delta );
				}
				if ( ! empty( $choice['finish_reason'] ) && is_string( $choice['finish_reason'] ) ) {
					$state['finish_reason'] = $choice['finish_reason'];
				}
			}

			$usage = $json['usage'] ?? ( $json['x_groq']['usage'] ?? null );
			if ( is_array( $usage ) ) {
				$state['input_tokens']  = (int) ( $usage['prompt_tokens'] ?? $state['input_tokens'] );
				$state['output_tokens'] = (int) ( $usage['completion_tokens'] ?? $state['output_tokens'] );
			}
		};

		try {
			$result = $this->with_parameter_retry(
				$body,
				function ( array $request_body ) use ( $options, $on_event ) {
					return $this->request_stream( '/chat/completions', $request_body, $options, $on_event );
				}
			);
		} catch ( ProviderError $error ) {
			throw $error->with_partial_text( $state['text'] );
		}

		// The server ignored the stream flag and returned one JSON body.
		if ( 0 === $result['events'] && '' === $result['aborted'] ) {
			$json = json_decode( $result['body'], true );
			if ( is_array( $json ) && isset( $json['choices'] ) ) {
				$response = $this->parse_completion( $json, (string) $body['model'], $started );
				if ( '' !== $response->text ) {
					$on_token( $response->text );
				}
				return $response;
			}
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
		if ( ! $this->supports( 'embeddings' ) ) {
			return parent::embed( $texts, $options );
		}

		$this->ensure_ready();

		$texts = array_values( array_map( 'strval', $texts ) );
		if ( array() === $texts ) {
			return array();
		}

		$body = array(
			'model' => $this->model( $options ),
			'input' => $texts,
		);

		$dimensions = (int) ( $options['dimensions'] ?? 0 );
		if ( $dimensions > 0 && $this->supports( 'embedding_dimensions' ) ) {
			$body[ (string) $this->preset['dimensions_param'] ] = $dimensions;
		}

		$json = $this->request_json( 'POST', '/embeddings', $body, $this->timeout( $options ) );
		$rows = isset( $json['data'] ) && is_array( $json['data'] ) ? $json['data'] : array();

		usort(
			$rows,
			static function ( $a, $b ): int {
				return (int) ( $a['index'] ?? 0 ) <=> (int) ( $b['index'] ?? 0 );
			}
		);

		$vectors = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['embedding'] ) && is_array( $row['embedding'] ) ) {
				$vectors[] = array_map( 'floatval', $row['embedding'] );
			}
		}

		if ( count( $vectors ) !== count( $texts ) ) {
			throw $this->error( ProviderError::SERVER_ERROR, __( 'The provider returned a different number of embeddings than requested.', 'chatlume-ai-chatbot' ) );
		}

		return $vectors;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function map_error( int $status, array $json, string $raw, array $headers ): ProviderError {
		$error = parent::map_error( $status, $json, $raw, $headers );
		$type  = self::type_from_codes( $json );

		return null === $type ? $error : $this->error( $type, $error->detail(), $status, $error->retry_after() );
	}

	/**
	 * Classification from documented error codes, which is more precise than wording.
	 *
	 * @param array<mixed> $json Decoded error body.
	 * @return string|null
	 */
	private static function type_from_codes( array $json ): ?string {
		$error = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : array();
		$codes = strtolower(
			implode(
				' ',
				array(
					is_scalar( $error['code'] ?? null ) ? (string) $error['code'] : '',
					is_scalar( $error['type'] ?? null ) ? (string) $error['type'] : '',
					is_scalar( $error['metadata']['error_type'] ?? null ) ? (string) $error['metadata']['error_type'] : '',
				)
			)
		);

		$rules = array(
			'/insufficient_quota|credit_balance|spend_limit|usage_limit|insufficient_balance|payment_required|\b402\b/' => ProviderError::QUOTA,
			'/invalid_api_key|authentication|\b401\b/'    => ProviderError::INVALID_KEY,
			'/model_not_found/'                           => ProviderError::MODEL_NOT_FOUND,
			'/context_length_exceeded/'                   => ProviderError::CONTEXT_TOO_LONG,
			'/rate_limit|slow_down|\b429\b/'              => ProviderError::RATE_LIMITED,
			'/overloaded|service_unavailable|\b50[23]\b/' => ProviderError::SERVER_ERROR,
			'/moderation|guardrail|content_filter|\b403\b/' => ProviderError::CONTENT_BLOCKED,
		);

		foreach ( $rules as $pattern => $type ) {
			if ( preg_match( $pattern, $codes ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Error reported inside a 200 response or stream chunk.
	 *
	 * @param array<mixed> $json Chunk with an `error` member.
	 * @return ProviderError
	 */
	private function stream_error( array $json ): ProviderError {
		$code   = $json['error']['code'] ?? 0;
		$status = is_numeric( $code ) ? (int) $code : 0;

		return $this->map_error( $status >= 400 ? $status : 500, $json, '', array() );
	}

	/**
	 * Request body for /chat/completions.
	 *
	 * @param array<int, mixed>    $messages Messages.
	 * @param array<string, mixed> $options  Options.
	 * @param bool                 $stream   Streaming.
	 * @return array<string, mixed>
	 */
	private function chat_body( array $messages, array $options, bool $stream ): array {
		$split = self::split_system( $messages );
		$turns = $split['messages'];

		if ( '' !== $split['system'] ) {
			array_unshift(
				$turns,
				array(
					'role'    => 'system',
					'content' => $split['system'],
				)
			);
		}

		if ( array() === $split['messages'] ) {
			throw $this->error( ProviderError::BAD_REQUEST, __( 'There is no message to send.', 'chatlume-ai-chatbot' ) );
		}

		$body = array(
			'model'    => $this->model( $options ),
			'messages' => $turns,
			'stream'   => $stream,
		);

		if ( isset( $options['temperature'] ) && is_numeric( $options['temperature'] ) ) {
			$body['temperature'] = max( 0.0, min( 2.0, (float) $options['temperature'] ) );
		}

		if ( ! empty( $options['max_tokens'] ) ) {
			$body[ (string) $this->preset['max_tokens_param'] ] = max( 1, (int) $options['max_tokens'] );
		}

		if ( $stream && $this->preset['stream_usage'] ) {
			$body['stream_options'] = array( 'include_usage' => true );
		}

		return $body;
	}

	/**
	 * Runs a request; if the server rejects an optional parameter we sent
	 * (some models do not accept temperature, some servers do not know
	 * stream_options or max_completion_tokens), retries once without it.
	 * Rejections arrive as HTTP errors before any token is streamed.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @param callable             $send Receives the body, returns the result.
	 * @return mixed
	 * @throws ProviderError When the request fails for another reason.
	 */
	private function with_parameter_retry( array $body, callable $send ) {
		try {
			return $send( $body );
		} catch ( ProviderError $error ) {
			if ( ProviderError::BAD_REQUEST !== $error->type() && ProviderError::UNSUPPORTED !== $error->type() ) {
				throw $error;
			}

			$detail  = strtolower( $error->detail() );
			$changed = false;

			foreach ( array( 'stream_options', 'temperature', 'max_completion_tokens' ) as $param ) {
				if ( isset( $body[ $param ] ) && false !== strpos( $detail, $param ) ) {
					if ( 'max_completion_tokens' === $param ) {
						$body['max_tokens'] = $body[ $param ];
					}
					unset( $body[ $param ] );
					$changed = true;
				}
			}

			if ( ! $changed ) {
				throw $error;
			}

			return $send( $body );
		}
	}

	/**
	 * Normalizes a non-streaming completion.
	 *
	 * @param array<string, mixed> $json    Response body.
	 * @param string               $model   Requested model.
	 * @param float                $started Start time.
	 * @return ChatResponse
	 */
	private function parse_completion( array $json, string $model, float $started ): ChatResponse {
		$choice  = $json['choices'][0] ?? array();
		$content = $choice['message']['content'] ?? '';

		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$parts[] = $part['text'];
				}
			}
			$content = implode( '', $parts );
		}

		return new ChatResponse(
			array(
				'text'          => is_string( $content ) ? $content : '',
				'input_tokens'  => (int) ( $json['usage']['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $json['usage']['completion_tokens'] ?? 0 ),
				'finish_reason' => (string) ( $choice['finish_reason'] ?? '' ),
				'latency_ms'    => self::elapsed_ms( $started ),
				'provider'      => $this->get_id(),
				'model'         => is_string( $json['model'] ?? null ) && '' !== $json['model'] ? $json['model'] : $model,
				'raw'           => $json,
			)
		);
	}
}
