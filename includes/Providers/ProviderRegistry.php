<?php
/**
 * Provider registry and routing.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ProviderError and StreamAbortedException messages are never printed as HTML: admins receive them
// as JSON through the REST API (rendered as text), visitors only see fixed strings, and
// getMessage() is HTML-escaped as a safeguard. Escaping at each throw would corrupt that text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Knows every provider (built-in and from `chatlume_register_providers`),
 * builds configured instances, and routes requests: primary with one retry
 * on the fallback, the separate embeddings provider, and cost estimates.
 */
final class ProviderRegistry {

	/**
	 * Option storing the last connection test per provider.
	 */
	public const STATUS_OPTION = 'chatlume_provider_status';

	/**
	 * Unconfigured provider instances by ID.
	 *
	 * @var array<string, ProviderInterface>|null
	 */
	private static ?array $providers = null;

	/**
	 * All providers by ID.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public static function all(): array {
		if ( null !== self::$providers ) {
			return self::$providers;
		}

		$providers = array();

		foreach ( OpenAICompatibleProvider::presets() as $id => $preset ) {
			$providers[ $id ] = new OpenAICompatibleProvider( $preset );
		}
		$providers['anthropic'] = new AnthropicProvider();
		$providers['gemini']    = new GeminiProvider();

		// Keep a sensible display order: OpenAI, Anthropic, Gemini, OpenRouter, then the rest.
		$order     = array( 'openai', 'anthropic', 'gemini', 'openrouter', 'groq', 'mistral', 'deepseek', 'xai', 'together', 'ollama', 'custom' );
		$providers = array_merge( array_flip( $order ), $providers );

		/**
		 * Filters the available AI providers.
		 *
		 * Add-ons can register providers implementing ProviderInterface
		 * (extending AbstractProvider gives configuration, model caching,
		 * streaming, and error handling for free).
		 *
		 * @param array<string, ProviderInterface> $providers Providers keyed by ID.
		 */
		$filtered = apply_filters( 'chatlume_register_providers', $providers );

		self::$providers = array();
		foreach ( is_array( $filtered ) ? $filtered : $providers as $provider ) {
			if ( $provider instanceof ProviderInterface && preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $provider->get_id() ) ) {
				self::$providers[ $provider->get_id() ] = $provider;
			}
		}

		return self::$providers;
	}

	/**
	 * Provider IDs.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	/**
	 * Forgets built instances (tests, or after providers change).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$providers = null;
	}

	/**
	 * Settings schemas of all providers, keyed by ID (for the `providers.configs` section).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings_schemas(): array {
		$schemas = array();

		foreach ( self::all() as $id => $provider ) {
			$schemas[ $id ] = array(
				'type'       => 'object',
				'properties' => $provider->get_settings_schema(),
			);
		}

		return $schemas;
	}

	/**
	 * A provider configured from settings and its stored or constant API key.
	 *
	 * @param string               $id        Provider ID.
	 * @param array<string, mixed> $overrides Configuration overrides (e.g. a key being tested).
	 * @return ProviderInterface|null
	 */
	public static function get( string $id, array $overrides = array() ): ?ProviderInterface {
		$provider = self::all()[ $id ] ?? null;

		if ( $provider instanceof AbstractProvider ) {
			return $provider->with_config( array_merge( self::config( $id ), $overrides ) );
		}

		return $provider;
	}

	/**
	 * Effective configuration (settings plus resolved key).
	 *
	 * @param string $id Provider ID.
	 * @return array<string, mixed>
	 */
	public static function config( string $id ): array {
		$config   = Settings::get( 'providers.configs.' . $id, array() );
		$config   = is_array( $config ) ? $config : array();
		$resolved = ApiKeys::resolve( $id );

		$config['api_key']   = $resolved['key'];
		$config['key_error'] = $resolved['error'];

		return $config;
	}

	/**
	 * A routing role: primary, fallback, or embeddings.
	 *
	 * @param string $role Role.
	 * @return array{provider: string, model: string, dimensions?: int}
	 */
	public static function role( string $role ): array {
		$value = Settings::get( 'providers.' . $role, array() );
		$value = is_array( $value ) ? $value : array();

		$result = array(
			'provider' => (string) ( $value['provider'] ?? '' ),
			'model'    => (string) ( $value['model'] ?? '' ),
		);

		if ( 'embeddings' === $role ) {
			$result['dimensions'] = (int) ( $value['dimensions'] ?? 0 );
		}

		return $result;
	}

	/**
	 * Chat with the primary provider, retrying once with the fallback on any
	 * failure. When streaming, the fallback is used only if no text reached
	 * the visitor yet, so answers are never mixed.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param array<string, mixed>                             $options  Options (model is set per target).
	 * @param callable|null                                    $on_token Streams text fragments when given.
	 * @return ChatResponse
	 * @throws ProviderError When every target fails (the last error).
	 */
	public static function chat( array $messages, array $options = array(), ?callable $on_token = null ): ChatResponse {
		$targets = array( self::role( 'primary' ) );
		$backup  = self::role( 'fallback' );

		if ( '' !== $backup['provider'] && ( $backup['provider'] !== $targets[0]['provider'] || $backup['model'] !== $targets[0]['model'] ) ) {
			$targets[] = $backup;
		}

		$last     = null;
		$streamed = false;

		foreach ( $targets as $index => $target ) {
			$provider = '' !== $target['provider'] ? self::get( $target['provider'] ) : null;

			if ( null === $provider ) {
				$last = new ProviderError( ProviderError::NOT_CONFIGURED, $target['provider'], __( 'No AI provider is selected.', 'chatlume-ai-chatbot' ) );
				continue;
			}

			$request = $options;
			if ( '' !== $target['model'] ) {
				$request['model'] = $target['model'];
			}

			try {
				if ( null !== $on_token && $provider->supports( 'streaming' ) ) {
					$response = $provider->stream(
						$messages,
						$request,
						static function ( string $text ) use ( $on_token, &$streamed ) {
							$streamed = true;
							$on_token( $text );
						}
					);
				} else {
					$response = $provider->chat( $messages, $request );
				}

				$response->used_fallback = $index > 0;
				return $response;
			} catch ( ProviderError $error ) {
				ErrorLog::record( $error, (string) ( $request['model'] ?? '' ) );
				$last = $error;
				if ( $streamed ) {
					break;
				}
			}
		}

		throw $last ?? new ProviderError( ProviderError::NOT_CONFIGURED );
	}

	/**
	 * Provider, model, and dimensions used for embeddings. Defaults to the
	 * primary provider when it supports embeddings.
	 *
	 * @return array{provider: string, model: string, dimensions: int}|null Null when none is usable.
	 */
	public static function embeddings_target(): ?array {
		$role = self::role( 'embeddings' );

		if ( '' === $role['provider'] ) {
			$primary  = self::role( 'primary' );
			$provider = '' !== $primary['provider'] ? ( self::all()[ $primary['provider'] ] ?? null ) : null;
			if ( null === $provider || ! $provider->supports( 'embeddings' ) ) {
				return null;
			}
			$role['provider'] = $primary['provider'];
		}

		$provider = self::all()[ $role['provider'] ] ?? null;
		if ( null === $provider || ! $provider->supports( 'embeddings' ) || '' === $role['model'] ) {
			return null;
		}

		return array(
			'provider'   => $role['provider'],
			'model'      => $role['model'],
			'dimensions' => (int) ( $role['dimensions'] ?? 0 ),
		);
	}

	/**
	 * Embeddings through the embeddings provider.
	 *
	 * @param string[] $texts Texts.
	 * @return array{vectors: array<int, float[]>, model: string, dimensions: int}
	 * @throws ProviderError When no embeddings provider is set up or the request fails.
	 */
	public static function embed( array $texts ): array {
		$target   = self::embeddings_target();
		$provider = null !== $target ? self::get( $target['provider'] ) : null;

		if ( null === $target || null === $provider ) {
			throw new ProviderError( ProviderError::NOT_CONFIGURED, '', __( 'No embeddings provider and model are selected.', 'chatlume-ai-chatbot' ) );
		}

		try {
			$vectors = $provider->embed(
				$texts,
				array(
					'model'      => $target['model'],
					'dimensions' => $provider->supports( 'embedding_dimensions' ) ? $target['dimensions'] : 0,
				)
			);
		} catch ( ProviderError $error ) {
			ErrorLog::record( $error, $target['model'] );
			throw $error;
		}

		return array(
			'vectors'    => $vectors,
			'model'      => $target['provider'] . '/' . $target['model'],
			'dimensions' => isset( $vectors[0] ) ? count( $vectors[0] ) : 0,
		);
	}

	/**
	 * Estimated cost in USD, or null when no price is known. Uses the
	 * admin-entered price for the model, otherwise the provider's own
	 * pricing from its cached model list (OpenRouter). Never makes a request.
	 *
	 * @param string $provider_id   Provider ID.
	 * @param string $model         Model ID.
	 * @param int    $input_tokens  Input tokens.
	 * @param int    $output_tokens Output tokens.
	 * @return float|null
	 */
	public static function estimate_cost( string $provider_id, string $model, int $input_tokens, int $output_tokens ): ?float {
		$price = null;

		foreach ( (array) Settings::get( 'providers.configs.' . $provider_id . '.prices', array() ) as $row ) {
			if ( is_array( $row ) && ( $row['model'] ?? '' ) === $model ) {
				$price = array( (float) $row['input'], (float) $row['output'] );
				break;
			}
		}

		if ( null === $price ) {
			$provider = self::get( $provider_id );
			foreach ( $provider instanceof AbstractProvider ? (array) $provider->cached_models() : array() as $entry ) {
				if ( ( $entry['id'] ?? '' ) === $model && null !== ( $entry['input_price'] ?? null ) && null !== ( $entry['output_price'] ?? null ) ) {
					$price = array( (float) $entry['input_price'], (float) $entry['output_price'] );
					break;
				}
			}
		}

		if ( null === $price ) {
			return null;
		}

		return round( ( max( 0, $input_tokens ) * $price[0] + max( 0, $output_tokens ) * $price[1] ) / 1000000, 6 );
	}

	/**
	 * Remembers the latest connection test of a provider (shown as its status).
	 *
	 * @param string               $id     Provider ID.
	 * @param array<string, mixed> $result test_connection() result.
	 * @return void
	 */
	public static function record_test( string $id, array $result ): void {
		$status        = self::last_tests();
		$status[ $id ] = array(
			'ok'         => ! empty( $result['ok'] ),
			'time'       => time(),
			'latency_ms' => (int) ( $result['latency_ms'] ?? 0 ),
			'model'      => (string) ( $result['model'] ?? '' ),
			'error_type' => (string) ( $result['error']['type'] ?? '' ),
		);

		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Latest connection tests by provider ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function last_tests(): array {
		$status = get_option( self::STATUS_OPTION, array() );

		return is_array( $status ) ? $status : array();
	}
}
