<?php
/**
 * Contract for AI providers.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every provider (built-in or registered through `chatlume_register_providers`)
 * implements this interface. Failures are thrown as ProviderError.
 *
 * Messages use the normalized shape `array{role: 'system'|'user'|'assistant', content: string}`.
 * Options: `model` (string), `temperature` (float), `max_tokens` (int),
 * `timeout` (int seconds), `should_abort` (callable returning a reason string,
 * streaming only), `dimensions` (int, embeddings only).
 */
interface ProviderInterface {

	/**
	 * Unique ID (lowercase letters, numbers, dashes).
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * Settings schema for this provider's configuration (SettingsSchema format).
	 * The API key is not part of it; keys are stored separately and encrypted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_settings_schema(): array;

	/**
	 * Live list of models from the provider.
	 *
	 * @return array<int, array<string, mixed>> Items: id, label, kind (chat|embedding|other),
	 *                                          context_length, input_price, output_price (USD per 1M tokens or null), free, created.
	 */
	public function list_models(): array;

	/**
	 * Non-streaming chat completion.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param array<string, mixed>                             $options  Options.
	 * @return ChatResponse
	 */
	public function chat( array $messages, array $options ): ChatResponse;

	/**
	 * Streaming chat completion. Calls $on_token with each text fragment.
	 *
	 * @param array<int, array{role: string, content: string}> $messages Messages.
	 * @param array<string, mixed>                             $options  Options.
	 * @param callable                                         $on_token Receives (string $text).
	 * @return ChatResponse Full text and usage; finish_reason "aborted" if stopped early.
	 */
	public function stream( array $messages, array $options, callable $on_token ): ChatResponse;

	/**
	 * Embeddings for several texts, in the same order.
	 *
	 * @param string[]             $texts   Texts.
	 * @param array<string, mixed> $options Options (model, dimensions).
	 * @return array<int, float[]>
	 */
	public function embed( array $texts, array $options ): array;

	/**
	 * Sends a tiny prompt and reports the outcome.
	 *
	 * @return array<string, mixed> ok, latency_ms, model, reply, message, and error (ProviderError::to_array()) on failure.
	 */
	public function test_connection(): array;

	/**
	 * Feature support: streaming, embeddings, embedding_dimensions, tools.
	 *
	 * @param string $feature Feature name.
	 * @return bool
	 */
	public function supports( string $feature ): bool;
}
