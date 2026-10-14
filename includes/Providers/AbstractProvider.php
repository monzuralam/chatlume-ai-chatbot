<?php
/**
 * Shared provider plumbing.
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
 * Configuration, HTTP (always the WordPress HTTP API), SSE streaming,
 * error classification, cached model lists, and connection tests.
 *
 * Configuration keys: api_key (plain text, resolved by ProviderRegistry and
 * never stored here), key_error (true when the stored key is unreadable),
 * base_url, default_model, timeout, extra_headers, prices.
 */
abstract class AbstractProvider implements ProviderInterface {

	/**
	 * Model lists are cached this long.
	 */
	public const MODELS_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Configuration.
	 *
	 * @var array<string, mixed>
	 */
	protected array $config;

	/**
	 * Streaming transport.
	 *
	 * @var HttpStreamer
	 */
	protected HttpStreamer $streamer;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $config   Configuration.
	 * @param HttpStreamer|null    $streamer Streaming transport (injectable for tests).
	 */
	public function __construct( array $config = array(), ?HttpStreamer $streamer = null ) {
		$this->config   = array_merge( $this->default_config(), $config );
		$this->streamer = $streamer ?? new HttpStreamer();
	}

	/**
	 * Copy of this provider with another configuration.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @return static
	 */
	public function with_config( array $config ) {
		$clone         = clone $this;
		$clone->config = array_merge( $this->default_config(), $config );
		return $clone;
	}

	/**
	 * Current configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Display metadata.
	 *
	 * @return array{description: string, links: array<string, string>, key_required: bool, default_base_url: string, base_url_editable: bool, initials: string, kind: string}
	 */
	abstract public function get_info(): array;

	/**
	 * Feature flags (streaming, embeddings, embedding_dimensions, tools).
	 *
	 * @return array<string, bool>
	 */
	abstract protected function capabilities(): array;

	/**
	 * Fetches and normalizes the model list (uncached).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	abstract protected function fetch_models(): array;

	/**
	 * Request headers, including authentication.
	 *
	 * @return array<string, string>
	 */
	abstract protected function auth_headers(): array;

	/**
	 * {@inheritDoc}
	 *
	 * @param string $feature Feature.
	 */
	public function supports( string $feature ): bool {
		return ! empty( $this->capabilities()[ $feature ] );
	}

	/**
	 * Provider links (keys, billing, docs, status).
	 *
	 * @return array<string, string>
	 */
	public function get_links(): array {
		return $this->get_info()['links'];
	}

	/**
	 * Whether an API key is required.
	 *
	 * @return bool
	 */
	public function requires_key(): bool {
		return $this->get_info()['key_required'];
	}

	/**
	 * Defaults for configuration keys.
	 *
	 * @return array<string, mixed>
	 */
	protected function default_config(): array {
		return array(
			'api_key'       => '',
			'key_error'     => false,
			'base_url'      => '',
			'default_model' => '',
			'timeout'       => 30,
			'extra_headers' => array(),
			'prices'        => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_settings_schema(): array {
		$info   = $this->get_info();
		$schema = array(
			'default_model' => array(
				'type'      => 'string',
				'format'    => 'model',
				'default'   => '',
				'maxLength' => 200,
			),
			'timeout'       => array(
				'type'    => 'integer',
				'default' => 30,
				'minimum' => 5,
				'maximum' => 120,
			),
			'extra_headers' => array(
				'type'     => 'array',
				'maxItems' => 10,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'name'  => array(
							'type'      => 'string',
							'format'    => 'header-name',
							'default'   => '',
							'maxLength' => 64,
						),
						'value' => array(
							'type'      => 'string',
							'default'   => '',
							'maxLength' => 500,
						),
					),
				),
			),
			'prices'        => array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => array(
					'type'       => 'object',
					'properties' => array(
						'model'  => array(
							'type'      => 'string',
							'format'    => 'model',
							'default'   => '',
							'maxLength' => 200,
						),
						'input'  => array(
							'type'    => 'number',
							'default' => 0,
							'minimum' => 0,
							'maximum' => 10000,
						),
						'output' => array(
							'type'    => 'number',
							'default' => 0,
							'minimum' => 0,
							'maximum' => 10000,
						),
					),
				),
			),
		);

		if ( $info['base_url_editable'] ) {
			$schema = array(
				'base_url' => array(
					'type'      => 'string',
					'format'    => 'url',
					'default'   => '',
					'maxLength' => 500,
				),
			) + $schema;
		}

		return $schema;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $refresh Bypass the 12-hour cache.
	 */
	public function list_models( bool $refresh = false ): array {
		$this->ensure_ready();

		if ( ! $refresh ) {
			$cached = $this->cached_models();
			if ( null !== $cached ) {
				return $cached;
			}
		}

		$models = ModelSuggestions::apply( $this->get_id(), $this->fetch_models() );
		set_transient( $this->models_cache_key(), $models, self::MODELS_TTL );

		return $models;
	}

	/**
	 * Cached model list without making a request, or null when not cached.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	public function cached_models(): ?array {
		$cached = get_transient( $this->models_cache_key() );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Cache key: one list per provider, endpoint, and key (different keys can see different models).
	 *
	 * @return string
	 */
	protected function models_cache_key(): string {
		return 'chatlume_models_' . md5( $this->get_id() . '|' . $this->base_url() . '|' . substr( hash( 'sha256', (string) $this->config['api_key'] ), 0, 16 ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[]             $texts   Texts.
	 * @param array<string, mixed> $options Options.
	 */
	public function embed( array $texts, array $options ): array {
		unset( $texts, $options );
		throw $this->error( ProviderError::UNSUPPORTED, __( 'This provider does not offer embeddings.', 'chatlume-ai-chatbot' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): array {
		$started = microtime( true );
		$model   = (string) $this->config['default_model'];

		try {
			$this->ensure_ready();

			if ( '' === $model ) {
				foreach ( $this->list_models() as $candidate ) {
					if ( 'chat' === $candidate['kind'] ) {
						$model = $candidate['id'];
						break;
					}
				}
			}

			$response = $this->chat(
				array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word OK.',
					),
				),
				array(
					'model'      => $model,
					'max_tokens' => 32,
					'timeout'    => min( 30, (int) $this->config['timeout'] ),
				)
			);

			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

			return array(
				'ok'         => true,
				'latency_ms' => $latency,
				'model'      => $response->model,
				'reply'      => mb_substr( $response->text, 0, 200 ),
				'message'    => sprintf(
					/* translators: 1: Model ID, 2: Response time in milliseconds. */
					__( 'Connected. %1$s answered in %2$d ms.', 'chatlume-ai-chatbot' ),
					$response->model,
					$latency
				),
			);
		} catch ( ProviderError $error ) {
			return array(
				'ok'         => false,
				'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'model'      => $model,
				'reply'      => '',
				'message'    => $error->admin_title(),
				'error'      => $error->to_array(),
			);
		}
	}

	/**
	 * Base URL without a trailing slash.
	 *
	 * @return string
	 */
	protected function base_url(): string {
		$url = trim( (string) $this->config['base_url'] );

		return untrailingslashit( '' !== $url ? $url : $this->get_info()['default_base_url'] );
	}

	/**
	 * Model for a request, from the options or the default model.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return string
	 * @throws ProviderError When no model is chosen.
	 */
	protected function model( array $options ): string {
		$model = trim( (string) ( $options['model'] ?? '' ) );
		$model = '' !== $model ? $model : trim( (string) $this->config['default_model'] );

		if ( '' === $model ) {
			throw $this->error( ProviderError::NOT_CONFIGURED, __( 'No model is selected for this provider.', 'chatlume-ai-chatbot' ) );
		}

		return $model;
	}

	/**
	 * Request timeout in seconds.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return int
	 */
	protected function timeout( array $options ): int {
		$timeout = (int) ( $options['timeout'] ?? $this->config['timeout'] );

		return max( 5, min( 300, $timeout ) );
	}

	/**
	 * Throws when the provider cannot be used (missing or unreadable key, no URL).
	 *
	 * @return void
	 * @throws ProviderError When not ready.
	 */
	protected function ensure_ready(): void {
		if ( ! empty( $this->config['key_error'] ) ) {
			throw $this->error( ProviderError::KEY_UNREADABLE );
		}

		if ( $this->requires_key() && '' === (string) $this->config['api_key'] ) {
			throw $this->error( ProviderError::NOT_CONFIGURED, __( 'No API key is saved for this provider.', 'chatlume-ai-chatbot' ) );
		}

		if ( '' === $this->base_url() ) {
			throw $this->error( ProviderError::NOT_CONFIGURED, __( 'No base URL is set for this provider.', 'chatlume-ai-chatbot' ) );
		}
	}

	/**
	 * Builds a ProviderError for this provider.
	 *
	 * @param string   $type        Type.
	 * @param string   $detail      Detail.
	 * @param int      $status      HTTP status.
	 * @param int|null $retry_after Retry-After seconds.
	 * @return ProviderError
	 */
	protected function error( string $type, string $detail = '', int $status = 0, ?int $retry_after = null ): ProviderError {
		return new ProviderError( $type, $this->get_id(), $detail, $status, $this->get_links(), $retry_after );
	}

	/**
	 * All request headers: JSON, authentication, then admin-defined extras
	 * (which cannot replace authentication or framing headers).
	 *
	 * @return array<string, string>
	 */
	protected function headers(): array {
		$headers = array_merge(
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json, text/event-stream',
			),
			$this->auth_headers()
		);

		$protected = array( 'authorization', 'x-api-key', 'x-goog-api-key', 'content-type', 'content-length', 'host', 'accept-encoding', 'transfer-encoding', 'connection' );

		foreach ( (array) $this->config['extra_headers'] as $header ) {
			$name  = is_array( $header ) ? trim( (string) ( $header['name'] ?? '' ) ) : '';
			$value = is_array( $header ) ? (string) ( $header['value'] ?? '' ) : '';

			if ( '' !== $name && preg_match( '/^[A-Za-z0-9-]{1,64}$/', $name ) && ! in_array( strtolower( $name ), $protected, true ) ) {
				$headers[ $name ] = str_replace( array( "\r", "\n" ), '', $value );
			}
		}

		return $headers;
	}

	/**
	 * Full URL for a path.
	 *
	 * @param string $path Path starting with '/'.
	 * @return string
	 */
	protected function url( string $path ): string {
		return $this->base_url() . $path;
	}

	/**
	 * JSON request through the WordPress HTTP API.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path (may include a query string).
	 * @param array<string, mixed> $body    JSON body (ignored for GET).
	 * @param int                  $timeout Timeout in seconds.
	 * @return array<string, mixed>|array<int, mixed> Decoded JSON.
	 * @throws ProviderError On transport or HTTP errors.
	 */
	protected function request_json( string $method, string $path, array $body = array(), int $timeout = 30 ): array {
		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => $this->headers(),
		);

		if ( 'GET' !== $method ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->url( $path ), $args );

		if ( is_wp_error( $response ) ) {
			throw ProviderError::from_transport( $response, $this->get_id(), $this->get_links() );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$headers = HttpStreamer::flatten_headers( wp_remote_retrieve_headers( $response ) );

		if ( $status < 200 || $status >= 300 ) {
			throw $this->map_error( $status, is_array( $decoded ) ? $decoded : array(), $raw, $headers );
		}

		if ( ! is_array( $decoded ) ) {
			throw $this->error( ProviderError::SERVER_ERROR, __( 'The provider returned a response that is not valid JSON.', 'chatlume-ai-chatbot' ), $status );
		}

		return $decoded;
	}

	/**
	 * Streams an SSE response, calling $on_event for each complete event.
	 *
	 * Exceptions must never escape a transport callback (cURL would treat it as
	 * a write failure and Requests would silently resend the request), so any
	 * exception from $on_event stops the stream and is rethrown afterwards.
	 *
	 * @param string               $path     Path.
	 * @param array<string, mixed> $body     JSON body.
	 * @param array<string, mixed> $options  Options (timeout, should_abort).
	 * @param callable             $on_event Receives (array{event: string, data: string} $event).
	 * @return array{aborted: string, body: string, events: int} Raw body is kept for providers that ignore the stream flag.
	 * @throws ProviderError On transport or HTTP errors, or errors reported inside the stream.
	 * @throws \Throwable Rethrows exceptions raised by $on_event.
	 */
	protected function request_stream( string $path, array $body, array $options, callable $on_event ): array {
		$parser       = new SseParser();
		$failure      = null;
		$events       = 0;
		$timeout      = $this->timeout( $options );
		$deadline     = microtime( true ) + $timeout;
		$should_abort = $options['should_abort'] ?? null;

		$dispatch = static function ( array $parsed ) use ( $on_event, &$failure, &$events ) {
			foreach ( $parsed as $event ) {
				++$events;
				try {
					$on_event( $event );
				} catch ( \Throwable $exception ) {
					$failure = $exception;
					throw new StreamAbortedException( 'callback_failed' );
				}
			}
		};

		$result = $this->streamer->request(
			$this->url( $path ),
			array(
				'method'  => 'POST',
				// Transport ceiling slightly above the stream's own time limit.
				'timeout' => $timeout + 5,
				'headers' => $this->headers(),
				'body'    => (string) wp_json_encode( $body ),
			),
			static function ( string $chunk ) use ( $parser, $dispatch ) {
				$dispatch( $parser->push( $chunk ) );
			},
			static function () use ( $deadline, $should_abort ) {
				if ( microtime( true ) > $deadline ) {
					return 'time_limit';
				}
				return is_callable( $should_abort ) ? (string) $should_abort() : '';
			}
		);

		if ( null !== $failure ) {
			throw $failure;
		}

		if ( is_wp_error( $result ) ) {
			throw ProviderError::from_transport( $result, $this->get_id(), $this->get_links() );
		}

		if ( '' !== $result['aborted'] ) {
			if ( 'time_limit' === $result['aborted'] && 0 === $events ) {
				throw $this->error( ProviderError::TIMEOUT, __( 'No response arrived before the time limit.', 'chatlume-ai-chatbot' ) );
			}
			return array(
				'aborted' => $result['aborted'],
				'body'    => '',
				'events'  => $events,
			);
		}

		if ( $result['status'] < 200 || $result['status'] >= 300 ) {
			$decoded = json_decode( $result['body'], true );
			throw $this->map_error( $result['status'], is_array( $decoded ) ? $decoded : array(), $result['body'], $result['headers'] );
		}

		try {
			$dispatch( $parser->finish() );
		} catch ( StreamAbortedException $exception ) {
			unset( $exception );
		}

		if ( null !== $failure ) {
			throw $failure;
		}

		return array(
			'aborted' => '',
			'body'    => $result['body'],
			'events'  => $events,
		);
	}

	/**
	 * Converts an HTTP error response to a ProviderError. Providers refine this
	 * with their documented error codes; this fallback uses status and wording.
	 *
	 * @param int                   $status  HTTP status.
	 * @param array<mixed>          $json    Decoded body ([] when not JSON).
	 * @param string                $raw     Raw body.
	 * @param array<string, string> $headers Lowercase response headers.
	 * @return ProviderError
	 */
	protected function map_error( int $status, array $json, string $raw, array $headers ): ProviderError {
		$message     = self::error_message( $json, $raw );
		$retry_after = isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ? (int) $headers['retry-after'] : null;

		return $this->error( self::classify( $status, $message ), $message, $status, $retry_after );
	}

	/**
	 * Generic classification from HTTP status and message wording.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $message Provider message.
	 * @return string ProviderError type.
	 */
	public static function classify( int $status, string $message ): string {
		$text = strtolower( $message );

		if ( preg_match( '/credit|quota|billing|balance|insufficient funds|payment|spend(ing)? limit/', $text ) ) {
			return ProviderError::QUOTA;
		}
		if ( preg_match( '/context length|context window|too many tokens|maximum context|prompt is too long|too long|exceeds the maximum/', $text ) ) {
			return ProviderError::CONTEXT_TOO_LONG;
		}
		if ( preg_match( '/model.*(not found|does not exist|not exist|unknown|invalid|not available|no access)|(unknown|invalid) model/', $text ) ) {
			return ProviderError::MODEL_NOT_FOUND;
		}
		if ( preg_match( '/api key|api_key|apikey|unauthori[sz]ed|authentication|invalid key|incorrect key/', $text ) && $status < 500 ) {
			return ProviderError::INVALID_KEY;
		}
		if ( preg_match( '/not supported|unsupported|not implemented/', $text ) ) {
			return ProviderError::UNSUPPORTED;
		}

		if ( 401 === $status || 403 === $status ) {
			return ProviderError::INVALID_KEY;
		}
		if ( 402 === $status ) {
			return ProviderError::QUOTA;
		}
		if ( 404 === $status ) {
			return ProviderError::MODEL_NOT_FOUND;
		}
		if ( 408 === $status || 504 === $status ) {
			return ProviderError::TIMEOUT;
		}
		if ( 413 === $status ) {
			return ProviderError::CONTEXT_TOO_LONG;
		}
		if ( 429 === $status ) {
			return ProviderError::RATE_LIMITED;
		}
		if ( 501 === $status ) {
			return ProviderError::UNSUPPORTED;
		}
		if ( $status >= 500 ) {
			return ProviderError::SERVER_ERROR;
		}

		return ProviderError::BAD_REQUEST;
	}

	/**
	 * Extracts a human-readable message from common error shapes.
	 *
	 * @param array<mixed> $json Decoded body.
	 * @param string       $raw  Raw body.
	 * @return string
	 */
	protected static function error_message( array $json, string $raw ): string {
		$error = $json['error'] ?? null;

		if ( is_array( $error ) && isset( $error['message'] ) && is_scalar( $error['message'] ) ) {
			return (string) $error['message'];
		}
		if ( is_string( $error ) && '' !== $error ) {
			return $error;
		}
		if ( isset( $json['message'] ) && is_scalar( $json['message'] ) ) {
			return (string) $json['message'];
		}
		if ( isset( $json['detail'] ) && is_scalar( $json['detail'] ) ) {
			return (string) $json['detail'];
		}

		return mb_substr( trim( wp_strip_all_tags( $raw ) ), 0, 300 );
	}

	/**
	 * Splits normalized messages into the system prompt and the conversation,
	 * dropping invalid entries.
	 *
	 * @param array<int, mixed> $messages Messages.
	 * @return array{system: string, messages: array<int, array{role: string, content: string}>}
	 */
	protected static function split_system( array $messages ): array {
		$system = array();
		$turns  = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			$content = trim( $message['content'] );
			if ( '' === $content ) {
				continue;
			}
			if ( 'system' === $message['role'] ) {
				$system[] = $content;
			} elseif ( in_array( $message['role'], array( 'user', 'assistant' ), true ) ) {
				$turns[] = array(
					'role'    => $message['role'],
					'content' => $content,
				);
			}
		}

		return array(
			'system'   => implode( "\n\n", $system ),
			'messages' => $turns,
		);
	}

	/**
	 * Makes turns strictly alternate and start with the user, as Anthropic and
	 * Gemini require: consecutive same-role turns are joined, and leading
	 * assistant turns are dropped.
	 *
	 * @param array<int, array{role: string, content: string}> $turns Turns.
	 * @return array<int, array{role: string, content: string}>
	 */
	protected static function alternate( array $turns ): array {
		$result = array();

		foreach ( $turns as $turn ) {
			if ( array() === $result && 'assistant' === $turn['role'] ) {
				continue;
			}
			$last = count( $result ) - 1;
			if ( $last >= 0 && $result[ $last ]['role'] === $turn['role'] ) {
				$result[ $last ]['content'] .= "\n\n" . $turn['content'];
			} else {
				$result[] = $turn;
			}
		}

		return $result;
	}

	/**
	 * Milliseconds since a microtime(true) timestamp.
	 *
	 * @param float $started Start time.
	 * @return int
	 */
	protected static function elapsed_ms( float $started ): int {
		return (int) round( ( microtime( true ) - $started ) * 1000 );
	}
}
