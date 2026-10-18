<?php
/**
 * A provider connected in WordPress (Settings > Connectors).
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ProviderError messages are never printed as HTML: admins receive them as JSON through the
// REST API (rendered as text), visitors only see fixed strings, and getMessage() is
// HTML-escaped as a safeguard. Escaping at each throw would corrupt that text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Sends chat requests through WordPress's own AI Client (WordPress 7.0+)
 * to a provider the site connected in Settings > Connectors. Chatlume never
 * reads or stores that provider's key: WordPress adds it to each request.
 * The AI Client returns whole replies (no streaming) and offers no
 * embeddings, so those features need a provider with a key in Chatlume.
 */
class CoreAiProvider extends AbstractProvider {

	/**
	 * Prefix of these providers' IDs in Chatlume.
	 */
	public const PREFIX = 'wp-';

	/**
	 * Provider ID in WordPress's AI Client.
	 *
	 * @var string
	 */
	private string $core_id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Where to get a key.
	 *
	 * @var string
	 */
	private string $credentials_url;

	/**
	 * Constructor.
	 *
	 * @param string                    $core_id         Provider ID in the AI Client (e.g. "openai").
	 * @param string                    $name            Display name.
	 * @param string                    $credentials_url Where to get a key ('' if unknown).
	 * @param array<string, mixed>      $config          Configuration.
	 * @param HttpStreamer|null         $streamer        Unused (requests go through WordPress).
	 */
	public function __construct( string $core_id, string $name, string $credentials_url = '', array $config = array(), ?HttpStreamer $streamer = null ) {
		$this->core_id         = $core_id;
		$this->name            = $name;
		$this->credentials_url = $credentials_url;
		parent::__construct( $config, $streamer );
	}

	/**
	 * Chatlume ID for a WordPress provider ID.
	 *
	 * @param string $core_id Provider ID in the AI Client.
	 * @return string
	 */
	public static function id_for( string $core_id ): string {
		return self::PREFIX . substr( (string) preg_replace( '/[^a-z0-9-]/', '-', strtolower( $core_id ) ), 0, 36 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function with_config( array $config ) {
		return new static( $this->core_id, $this->name, $this->credentials_url, $config, $this->streamer );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::id_for( $this->core_id );
	}

	/**
	 * Provider ID in WordPress's AI Client.
	 *
	 * @return string
	 */
	public function core_id(): string {
		return $this->core_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		/* translators: %s: AI provider name. */
		return sprintf( __( '%s through WordPress', 'chatlume-ai-chatbot' ), $this->name );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_info(): array {
		$links = array( 'keys' => admin_url( 'options-connectors.php' ) );
		if ( '' !== $this->credentials_url ) {
			$links['billing'] = $this->credentials_url;
		}

		return array(
			'description'       => __( 'Uses the key saved in Settings > Connectors, so there is nothing to enter here. Replies appear all at once instead of word by word.', 'chatlume-ai-chatbot' ),
			'links'             => $links,
			'key_required'      => false,
			'default_base_url'  => '',
			'base_url_editable' => false,
			'initials'          => strtoupper( substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $this->name ), 0, 2 ) ),
			'kind'              => 'core',
		);
	}

	/**
	 * Whether WordPress can send requests to this provider now: AI is on,
	 * the provider's plugin is active, and its key is saved in Connectors.
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		if ( ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() || ! class_exists( AiClient::class ) ) {
			return false;
		}

		try {
			$registry = AiClient::defaultRegistry();

			return $registry->hasProvider( $this->core_id ) && $registry->isProviderConfigured( $this->core_id );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function capabilities(): array {
		return array(
			'streaming'            => false,
			'embeddings'           => false,
			'embedding_dimensions' => false,
			'tools'                => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function auth_headers(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ensure_ready(): void {
		if ( ! $this->is_connected() ) {
			throw $this->error( ProviderError::NOT_CONFIGURED, __( 'This provider is not connected in WordPress. Add its key in Settings > Connectors.', 'chatlume-ai-chatbot' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function models_cache_key(): string {
		return 'chatlume_models_' . md5( $this->get_id() . '|core' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fetch_models(): array {
		try {
			$found = AiClient::defaultRegistry()->findProviderModelsMetadataForSupport(
				$this->core_id,
				new ModelRequirements( array( CapabilityEnum::textGeneration() ), array() )
			);
		} catch ( \Throwable $error ) {
			throw $this->error( ProviderError::CONNECTION, $error->getMessage() );
		}

		$models = array();
		foreach ( $found as $model ) {
			$models[] = array(
				'id'             => (string) $model->getId(),
				'label'          => (string) $model->getName(),
				'kind'           => 'chat',
				'context_length' => 0,
				'input_price'    => null,
				'output_price'   => null,
				'free'           => false,
				'created'        => 0,
			);
		}

		return $models;
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages, array $options ): ChatResponse {
		$this->ensure_ready();

		$model   = $this->model( $options );
		$started = microtime( true );
		$system  = array();
		$history = array();
		foreach ( $messages as $message ) {
			$role    = (string) ( $message['role'] ?? '' );
			$content = (string) ( $message['content'] ?? '' );
			if ( 'system' === $role ) {
				$system[] = $content;
			} elseif ( 'user' === $role || 'assistant' === $role ) {
				$history[] = array( $role, $content );
			}
		}

		// The AI Client takes the new message as the prompt and the rest as history.
		$last = array_pop( $history );
		if ( null === $last || 'user' !== $last[0] || '' === trim( $last[1] ) ) {
			throw $this->error( ProviderError::BAD_REQUEST, __( 'There is no question to send.', 'chatlume-ai-chatbot' ) );
		}

		$timeout = static function () use ( $options ): float {
			return (float) max( 5, min( 300, (int) ( $options['timeout'] ?? 30 ) ) );
		};
		// The AI Client (WordPress 7.0+) reads its timeout when the prompt is created.
		$builder = null;
		add_filter( 'wp_ai_client_default_request_timeout', $timeout );
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			$builder = wp_ai_client_prompt( $last[1] );
		}
		remove_filter( 'wp_ai_client_default_request_timeout', $timeout );
		if ( null === $builder ) {
			throw $this->error( ProviderError::NOT_CONFIGURED, __( 'This WordPress version has no AI Client.', 'chatlume-ai-chatbot' ) );
		}

		$builder = $builder->using_provider( $this->core_id )->using_model_preference( array( $this->core_id, $model ) );
		if ( array() !== $system ) {
			$builder = $builder->using_system_instruction( implode( "\n\n", $system ) );
		}
		if ( array() !== $history ) {
			$builder = $builder->with_history( ...self::history( $history ) );
		}
		if ( isset( $options['max_tokens'] ) ) {
			$builder = $builder->using_max_tokens( max( 1, (int) $options['max_tokens'] ) );
		}
		if ( isset( $options['temperature'] ) ) {
			$builder = $builder->using_temperature( (float) $options['temperature'] );
		}

		$result = $builder->generate_text_result();
		if ( $result instanceof WP_Error ) {
			throw $this->from_wp_error( $result );
		}

		$usage  = $result->getTokenUsage();
		$finish = '';
		foreach ( $result->getCandidates() as $candidate ) {
			$finish = (string) $candidate->getFinishReason()->value;
			break;
		}

		return new ChatResponse(
			array(
				'text'          => (string) $result->toText(),
				'input_tokens'  => (int) $usage->getPromptTokens(),
				'output_tokens' => (int) $usage->getCompletionTokens(),
				'finish_reason' => $finish,
				'latency_ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
				'provider'      => $this->get_id(),
				'model'         => (string) $result->getModelMetadata()->getId(),
			)
		);
	}

	/**
	 * The AI Client does not stream: the whole reply is sent as one piece.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param array<string, mixed>                             $options  Options.
	 * @param callable                                         $on_token Receives text.
	 * @return ChatResponse
	 */
	public function stream( array $messages, array $options, callable $on_token ): ChatResponse {
		$response = $this->chat( $messages, $options );
		if ( '' !== $response->text ) {
			$on_token( $response->text );
		}

		return $response;
	}

	/**
	 * Chat turns as AI Client messages.
	 *
	 * @param array<int, array{0: string, 1: string}> $turns Role and text.
	 * @return array<int, UserMessage|ModelMessage>
	 */
	private static function history( array $turns ): array {
		$messages = array();
		foreach ( $turns as $turn ) {
			$parts      = array( new MessagePart( $turn[1] ) );
			$messages[] = 'user' === $turn[0] ? new UserMessage( $parts ) : new ModelMessage( $parts );
		}

		return $messages;
	}

	/**
	 * A plain-language error for an AI Client error.
	 *
	 * @param WP_Error $error Error.
	 * @return ProviderError
	 */
	private function from_wp_error( WP_Error $error ): ProviderError {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		$detail = mb_substr( wp_strip_all_tags( $error->get_error_message() ), 0, 300 );

		switch ( $error->get_error_code() ) {
			case 'prompt_prevented':
				return $this->error( ProviderError::NOT_CONFIGURED, __( 'WordPress is set not to send AI requests on this site.', 'chatlume-ai-chatbot' ), $status );
			case 'prompt_token_limit_reached':
				return $this->error( ProviderError::CONTEXT_TOO_LONG, $detail, $status );
			case 'prompt_upstream_server_error':
				return $this->error( ProviderError::SERVER_ERROR, $detail, $status );
			case 'prompt_network_error':
				return $this->error( false !== stripos( $detail, 'timed out' ) ? ProviderError::TIMEOUT : ProviderError::CONNECTION, $detail, $status );
		}

		$by_status = array(
			401 => ProviderError::INVALID_KEY,
			403 => ProviderError::INVALID_KEY,
			402 => ProviderError::QUOTA,
			404 => ProviderError::MODEL_NOT_FOUND,
			408 => ProviderError::TIMEOUT,
			429 => ProviderError::RATE_LIMITED,
		);

		return $this->error( $by_status[ $status ] ?? ( $status >= 500 ? ProviderError::SERVER_ERROR : ProviderError::BAD_REQUEST ), $detail, $status );
	}
}
