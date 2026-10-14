<?php
/**
 * Thrown inside the HTTP chunk callback to stop a stream.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised from the chunk callback (visitor disconnected, time limit reached,
 * or the caller asked to stop). Throwing out of the transport's write
 * callback makes it abandon the upstream connection, so no further tokens
 * are generated or billed. HttpStreamer catches it around the request.
 */
final class StreamAbortedException extends \RuntimeException {

	/**
	 * Why the stream stopped: "client_disconnected", "time_limit", or a caller-defined reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param string $reason Abort reason.
	 */
	public function __construct( string $reason ) {
		parent::__construct( esc_html( 'Chatlume stream aborted: ' . $reason ) );
		$this->reason = $reason;
	}

	/**
	 * Abort reason.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
