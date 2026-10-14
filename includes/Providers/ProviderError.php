<?php
/**
 * Normalized provider failure.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use Chatlume\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every provider failure is converted to one of a few types, each with a
 * plain-language explanation and a fix link for admins. Visitors only ever
 * see visitor_message().
 */
class ProviderError extends \RuntimeException {

	public const INVALID_KEY      = 'invalid_key';
	public const QUOTA            = 'quota';
	public const RATE_LIMITED     = 'rate_limited';
	public const MODEL_NOT_FOUND  = 'model_not_found';
	public const CONTEXT_TOO_LONG = 'context_too_long';
	public const TIMEOUT          = 'timeout';
	public const SERVER_ERROR     = 'server_error';
	public const KEY_UNREADABLE   = 'key_unreadable';
	public const NOT_CONFIGURED   = 'not_configured';
	public const CONNECTION       = 'connection';
	public const UNSUPPORTED      = 'unsupported';
	public const CONTENT_BLOCKED  = 'content_blocked';
	public const BAD_REQUEST      = 'bad_request';

	/**
	 * Error type (one of the constants).
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Provider ID.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * HTTP status (0 when no response).
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Provider's own message, with anything resembling a secret masked.
	 *
	 * @var string
	 */
	private string $detail;

	/**
	 * Seconds to wait before retrying, when the provider said so.
	 *
	 * @var int|null
	 */
	private ?int $retry_after;

	/**
	 * Provider links (keys, billing, docs, status) for fix links.
	 *
	 * @var array<string, string>
	 */
	private array $links;

	/**
	 * Text streamed before the failure (empty when nothing was received).
	 *
	 * @var string
	 */
	private string $partial_text = '';

	/**
	 * Constructor.
	 *
	 * @param string                $type        Error type.
	 * @param string                $provider    Provider ID.
	 * @param string                $detail      Provider message.
	 * @param int                   $status      HTTP status.
	 * @param array<string, string> $links       Provider links.
	 * @param int|null              $retry_after Retry-After seconds.
	 */
	public function __construct( string $type, string $provider = '', string $detail = '', int $status = 0, array $links = array(), ?int $retry_after = null ) {
		$this->type        = in_array( $type, self::types(), true ) ? $type : self::BAD_REQUEST;
		$this->provider    = $provider;
		$this->detail      = self::redact( mb_substr( trim( $detail ), 0, 500 ) );
		$this->status      = $status;
		$this->links       = $links;
		$this->retry_after = $retry_after;

		// Escaped in case an uncaught exception message is ever displayed; accessors return the raw text for JSON.
		parent::__construct( esc_html( $this->type . ( '' !== $this->detail ? ': ' . $this->detail : '' ) ) );
	}

	/**
	 * All error types.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		return array(
			self::INVALID_KEY,
			self::QUOTA,
			self::RATE_LIMITED,
			self::MODEL_NOT_FOUND,
			self::CONTEXT_TOO_LONG,
			self::TIMEOUT,
			self::SERVER_ERROR,
			self::KEY_UNREADABLE,
			self::NOT_CONFIGURED,
			self::CONNECTION,
			self::UNSUPPORTED,
			self::CONTENT_BLOCKED,
			self::BAD_REQUEST,
		);
	}

	/**
	 * Error type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Redacted provider message.
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * Retry-After seconds.
	 *
	 * @return int|null
	 */
	public function retry_after(): ?int {
		return $this->retry_after;
	}

	/**
	 * Text streamed before the failure.
	 *
	 * @return string
	 */
	public function partial_text(): string {
		return $this->partial_text;
	}

	/**
	 * Records text streamed before the failure.
	 *
	 * @param string $text Partial text.
	 * @return static
	 */
	public function with_partial_text( string $text ): self {
		$this->partial_text = $text;
		return $this;
	}

	/**
	 * Short admin-facing title.
	 *
	 * @return string
	 */
	public function admin_title(): string {
		switch ( $this->type ) {
			case self::INVALID_KEY:
				return __( 'The API key was rejected', 'chatlume-ai-chatbot' );
			case self::QUOTA:
				return __( 'Out of credits or quota', 'chatlume-ai-chatbot' );
			case self::RATE_LIMITED:
				return __( 'Too many requests', 'chatlume-ai-chatbot' );
			case self::MODEL_NOT_FOUND:
				return __( 'Model not available', 'chatlume-ai-chatbot' );
			case self::CONTEXT_TOO_LONG:
				return __( 'The conversation is too long for this model', 'chatlume-ai-chatbot' );
			case self::TIMEOUT:
				return __( 'The provider took too long to answer', 'chatlume-ai-chatbot' );
			case self::SERVER_ERROR:
				return __( 'The provider is having problems', 'chatlume-ai-chatbot' );
			case self::KEY_UNREADABLE:
				return __( 'The saved API key can no longer be read', 'chatlume-ai-chatbot' );
			case self::NOT_CONFIGURED:
				return __( 'This provider is not set up yet', 'chatlume-ai-chatbot' );
			case self::CONNECTION:
				return __( 'Could not connect to the provider', 'chatlume-ai-chatbot' );
			case self::UNSUPPORTED:
				return __( 'Not supported by this provider', 'chatlume-ai-chatbot' );
			case self::CONTENT_BLOCKED:
				return __( 'The provider blocked the content', 'chatlume-ai-chatbot' );
			default:
				return __( 'The provider rejected the request', 'chatlume-ai-chatbot' );
		}
	}

	/**
	 * Plain-language explanation and what to do about it.
	 *
	 * @return string
	 */
	public function admin_explanation(): string {
		switch ( $this->type ) {
			case self::INVALID_KEY:
				return __( 'The provider says the API key is wrong, disabled, or lacks permission. Copy the key again from your provider account (without spaces) and save it.', 'chatlume-ai-chatbot' );
			case self::QUOTA:
				return __( 'Your provider account has no credits left or reached its spending limit. Add credits or raise the limit in your provider’s billing settings. Retrying will not help until then.', 'chatlume-ai-chatbot' );
			case self::RATE_LIMITED:
				return __( 'The provider is limiting how fast you can send requests. This usually passes within a minute. If it keeps happening, upgrade your provider plan or set a fallback provider.', 'chatlume-ai-chatbot' );
			case self::MODEL_NOT_FOUND:
				return __( 'The selected model does not exist or your account cannot use it. Refresh the model list and choose another model.', 'chatlume-ai-chatbot' );
			case self::CONTEXT_TOO_LONG:
				return __( 'The prompt, knowledge, and conversation history together exceed what this model can read. Choose a model with a larger context window or shorten the history window.', 'chatlume-ai-chatbot' );
			case self::TIMEOUT:
				return __( 'No answer arrived in time. Try again, raise the timeout for this provider, or choose a faster model.', 'chatlume-ai-chatbot' );
			case self::SERVER_ERROR:
				return __( 'The provider returned a server error or is overloaded. This is usually temporary. A fallback provider keeps your bot answering meanwhile.', 'chatlume-ai-chatbot' );
			case self::KEY_UNREADABLE:
				return __( 'Your site’s security keys (salts) changed, so the stored API key cannot be decrypted. Please re-enter your API key. To avoid this in future, define it in wp-config.php.', 'chatlume-ai-chatbot' );
			case self::NOT_CONFIGURED:
				return __( 'Add an API key (if the provider needs one) and choose a model for this provider.', 'chatlume-ai-chatbot' );
			case self::CONNECTION:
				return __( 'Your server could not reach the provider. Check the base URL, that the service is running (for local servers such as Ollama), and that your host allows outgoing connections.', 'chatlume-ai-chatbot' );
			case self::UNSUPPORTED:
				return __( 'This provider or model does not offer the requested feature. Choose another provider or model for it.', 'chatlume-ai-chatbot' );
			case self::CONTENT_BLOCKED:
				return __( 'The provider’s safety filters blocked the prompt or the answer. Rephrase the question or review your system prompt.', 'chatlume-ai-chatbot' );
			default:
				return __( 'The provider did not accept the request. The provider’s message below explains why.', 'chatlume-ai-chatbot' );
		}
	}

	/**
	 * Where the admin can fix the problem.
	 *
	 * @return array{url: string, label: string}|null
	 */
	public function fix_link(): ?array {
		$providers_page = admin_url( 'admin.php?page=' . Plugin::ADMIN_SLUG . '#/providers' );

		switch ( $this->type ) {
			case self::INVALID_KEY:
			case self::NOT_CONFIGURED:
				return empty( $this->links['keys'] )
					? array(
						'url'   => $providers_page,
						'label' => __( 'Open Providers', 'chatlume-ai-chatbot' ),
					)
					: array(
						'url'   => $this->links['keys'],
						'label' => __( 'Get an API key', 'chatlume-ai-chatbot' ),
					);
			case self::QUOTA:
			case self::RATE_LIMITED:
				return empty( $this->links['billing'] ) ? null : array(
					'url'   => $this->links['billing'],
					'label' => __( 'Open billing settings', 'chatlume-ai-chatbot' ),
				);
			case self::SERVER_ERROR:
				return empty( $this->links['status'] ) ? null : array(
					'url'   => $this->links['status'],
					'label' => __( 'Check provider status', 'chatlume-ai-chatbot' ),
				);
			case self::KEY_UNREADABLE:
			case self::MODEL_NOT_FOUND:
			case self::CONNECTION:
				return array(
					'url'   => $providers_page,
					'label' => __( 'Open Providers', 'chatlume-ai-chatbot' ),
				);
			default:
				return empty( $this->links['docs'] ) ? null : array(
					'url'   => $this->links['docs'],
					'label' => __( 'Provider documentation', 'chatlume-ai-chatbot' ),
				);
		}
	}

	/**
	 * The only message visitors ever see.
	 *
	 * @return string
	 */
	public function visitor_message(): string {
		if ( self::CONTEXT_TOO_LONG === $this->type ) {
			return __( 'This conversation has become too long for me. Please start a new conversation and ask again.', 'chatlume-ai-chatbot' );
		}

		if ( self::CONTENT_BLOCKED === $this->type ) {
			return __( 'Sorry, I can’t help with that request. Could you rephrase it?', 'chatlume-ai-chatbot' );
		}

		return __( 'Sorry, I’m having trouble answering right now. Please try again in a moment.', 'chatlume-ai-chatbot' );
	}

	/**
	 * Admin-facing array for REST responses and the error log. Contains no secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'type'        => $this->type,
			'provider'    => $this->provider,
			'status'      => $this->status,
			'title'       => $this->admin_title(),
			'explanation' => $this->admin_explanation(),
			'detail'      => $this->detail,
			'fix'         => $this->fix_link(),
			'retry_after' => $this->retry_after,
		);
	}

	/**
	 * Masks anything that looks like a credential in provider messages
	 * (some providers echo part of a rejected key).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( string $text ): string {
		return (string) preg_replace_callback(
			// Known key formats (sk-, gsk_, xai-, AIza…) and any long opaque token; ordinary words and error codes are shorter.
			'/\b(?:sk|pk|rk|gsk|xai)[-_][A-Za-z0-9*._\-]{7,}[A-Za-z0-9*]|\bAIza[A-Za-z0-9_\-]{20,}|\b[A-Za-z0-9_\-]{40,}\b/',
			static function ( array $matches ): string {
				$value = $matches[0];
				return substr( $value, 0, 3 ) . '…' . substr( $value, -4 );
			},
			$text
		);
	}

	/**
	 * Builds an error from a WordPress HTTP API transport failure.
	 *
	 * @param \WP_Error             $error    Transport error.
	 * @param string                $provider Provider ID.
	 * @param array<string, string> $links    Provider links.
	 * @return self
	 */
	public static function from_transport( \WP_Error $error, string $provider, array $links = array() ): self {
		$message = $error->get_error_message();
		$type    = preg_match( '/timed out|timeout|cURL error 28/i', $message ) ? self::TIMEOUT : self::CONNECTION;

		return new self( $type, $provider, $message, 0, $links );
	}
}
