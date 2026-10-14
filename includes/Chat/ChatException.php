<?php
/**
 * Visitor-safe request rejection.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A chat request that cannot be served (invalid session, rate limited,
 * message too long, conversation full). The message is shown to visitors,
 * so it never contains technical details.
 */
class ChatException extends \RuntimeException {

	/**
	 * Machine-readable code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Seconds until the visitor may retry (rate limits).
	 *
	 * @var int
	 */
	private int $retry_after;

	/**
	 * Visitor-facing message (raw text; getMessage() is HTML-escaped).
	 *
	 * @var string
	 */
	private string $visitor_message;

	/**
	 * Constructor.
	 *
	 * @param string $error_code  Code, e.g. "rate_limited".
	 * @param string $message     Visitor-facing message.
	 * @param int    $status      HTTP status.
	 * @param int    $retry_after Retry-after seconds.
	 */
	public function __construct( string $error_code, string $message, int $status = 400, int $retry_after = 0 ) {
		parent::__construct( esc_html( $message ) );
		$this->visitor_message = $message;
		$this->error_code      = $error_code;
		$this->status          = $status;
		$this->retry_after     = $retry_after;
	}

	/**
	 * Visitor-facing message.
	 *
	 * @return string
	 */
	public function visitor_message(): string {
		return $this->visitor_message;
	}

	/**
	 * Code.
	 *
	 * @return string
	 */
	public function error_code(): string {
		return $this->error_code;
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
	 * Retry-after seconds.
	 *
	 * @return int
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}
}
