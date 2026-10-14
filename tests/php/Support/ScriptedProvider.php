<?php
/**
 * Scripted provider double for routing tests.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Test double: thrown ProviderError messages are asserted on, never printed.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use Chatlume\Providers\ChatResponse;
use Chatlume\Providers\ProviderError;
use Chatlume\Providers\ProviderInterface;

/**
 * Scripted provider for routing tests.
 */
class ScriptedProvider implements ProviderInterface {

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	public string $id;

	/**
	 * Model IDs that fail, mapped to the error type.
	 *
	 * @var array<string, string>
	 */
	public array $failing;

	/**
	 * Tokens to stream before failing (for mid-stream failures).
	 *
	 * @var string[]
	 */
	public array $tokens_before_failure = array();

	/**
	 * Calls received: [method, model].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public array $calls = array();

	/**
	 * Whether embeddings are supported.
	 *
	 * @var bool
	 */
	public bool $embeddings;

	/**
	 * Constructor.
	 *
	 * @param string                $id         ID.
	 * @param array<string, string> $failing    Failing models.
	 * @param bool                  $embeddings Embeddings support.
	 */
	public function __construct( string $id, array $failing = array(), bool $embeddings = false ) {
		$this->id         = $id;
		$this->failing    = $failing;
		$this->embeddings = $embeddings;
	}

	/** {@inheritDoc} */
	public function get_id(): string {
		return $this->id;
	}

	/** {@inheritDoc} */
	public function get_label(): string {
		return ucfirst( $this->id );
	}

	/** {@inheritDoc} */
	public function get_settings_schema(): array {
		return array();
	}

	/** {@inheritDoc} */
	public function list_models(): array {
		return array();
	}

	/**
	 * Fails or answers.
	 *
	 * @param string        $method   Method.
	 * @param array         $options  Options.
	 * @param callable|null $on_token Token callback.
	 * @return ChatResponse
	 * @throws ProviderError When scripted to fail.
	 */
	private function answer( string $method, array $options, ?callable $on_token = null ): ChatResponse {
		$model         = (string) ( $options['model'] ?? 'default' );
		$this->calls[] = array( $method, $model );

		if ( isset( $this->failing[ $model ] ) ) {
			foreach ( $this->tokens_before_failure as $token ) {
				if ( $on_token ) {
					$on_token( $token );
				}
			}
			throw new ProviderError( $this->failing[ $model ], $this->id );
		}

		if ( $on_token ) {
			$on_token( 'ok' );
		}

		return new ChatResponse(
			array(
				'text'     => 'ok',
				'provider' => $this->id,
				'model'    => $model,
			)
		);
	}

	/** {@inheritDoc} */
	public function chat( array $messages, array $options ): ChatResponse {
		return $this->answer( 'chat', $options );
	}

	/** {@inheritDoc} */
	public function stream( array $messages, array $options, callable $on_token ): ChatResponse {
		return $this->answer( 'stream', $options, $on_token );
	}

	/** {@inheritDoc} */
	public function embed( array $texts, array $options ): array {
		$this->calls[] = array( 'embed', (string) $options['model'] . '@' . (int) $options['dimensions'] );
		return array_fill( 0, count( $texts ), array( 0.5, 0.5, 0.5 ) );
	}

	/** {@inheritDoc} */
	public function test_connection(): array {
		return array( 'ok' => true );
	}

	/** {@inheritDoc} */
	public function supports( string $feature ): bool {
		return 'streaming' === $feature || ( $this->embeddings && in_array( $feature, array( 'embeddings', 'embedding_dimensions' ), true ) );
	}
}
