<?php
/**
 * Normalized chat response.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The same response shape for every provider.
 */
final class ChatResponse {

	/**
	 * Generated text.
	 *
	 * @var string
	 */
	public string $text;

	/**
	 * Prompt tokens reported by the provider (0 when unknown).
	 *
	 * @var int
	 */
	public int $input_tokens;

	/**
	 * Completion tokens reported by the provider (0 when unknown).
	 *
	 * @var int
	 */
	public int $output_tokens;

	/**
	 * Normalized finish reason: stop, length, content_filter, aborted, or other.
	 *
	 * @var string
	 */
	public string $finish_reason;

	/**
	 * Wall-clock time of the request in milliseconds.
	 *
	 * @var int
	 */
	public int $latency_ms;

	/**
	 * Provider ID that produced the response.
	 *
	 * @var string
	 */
	public string $provider;

	/**
	 * Model ID that produced the response.
	 *
	 * @var string
	 */
	public string $model;

	/**
	 * Raw provider payload (final JSON object, or the last stream chunk), for debugging only.
	 *
	 * @var array<string, mixed>
	 */
	public array $raw;

	/**
	 * Whether the fallback provider/model produced this response.
	 *
	 * @var bool
	 */
	public bool $used_fallback = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Response fields.
	 */
	public function __construct( array $data ) {
		$this->text          = (string) ( $data['text'] ?? '' );
		$this->input_tokens  = max( 0, (int) ( $data['input_tokens'] ?? 0 ) );
		$this->output_tokens = max( 0, (int) ( $data['output_tokens'] ?? 0 ) );
		$this->finish_reason = self::normalize_finish_reason( (string) ( $data['finish_reason'] ?? '' ) );
		$this->latency_ms    = max( 0, (int) ( $data['latency_ms'] ?? 0 ) );
		$this->provider      = (string) ( $data['provider'] ?? '' );
		$this->model         = (string) ( $data['model'] ?? '' );
		$this->raw           = is_array( $data['raw'] ?? null ) ? $data['raw'] : array();
	}

	/**
	 * Maps provider-specific finish reasons to a small common set.
	 *
	 * @param string $reason Provider value (e.g. "end_turn", "STOP", "max_tokens").
	 * @return string
	 */
	public static function normalize_finish_reason( string $reason ): string {
		$reason = strtolower( $reason );

		$map = array(
			''               => 'stop',
			'stop'           => 'stop',
			'end_turn'       => 'stop',
			'stop_sequence'  => 'stop',
			'eos'            => 'stop',
			'length'         => 'length',
			'max_tokens'     => 'length',
			'model_length'   => 'length',
			'content_filter' => 'content_filter',
			'safety'         => 'content_filter',
			'recitation'     => 'content_filter',
			'refusal'        => 'content_filter',
			'blocklist'      => 'content_filter',
			'aborted'        => 'aborted',
		);

		return $map[ $reason ] ?? 'other';
	}

	/**
	 * Array form (for REST debug output and logging).
	 *
	 * @param bool $include_raw Whether to include the raw payload.
	 * @return array<string, mixed>
	 */
	public function to_array( bool $include_raw = false ): array {
		$data = array(
			'text'          => $this->text,
			'input_tokens'  => $this->input_tokens,
			'output_tokens' => $this->output_tokens,
			'finish_reason' => $this->finish_reason,
			'latency_ms'    => $this->latency_ms,
			'provider'      => $this->provider,
			'model'         => $this->model,
			'used_fallback' => $this->used_fallback,
		);

		if ( $include_raw ) {
			$data['raw'] = $this->raw;
		}

		return $data;
	}
}
