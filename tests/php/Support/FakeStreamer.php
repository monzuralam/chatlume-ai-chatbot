<?php
/**
 * HttpStreamer double that replays recorded chunks.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\HttpStreamer;
use Chatlume\Providers\StreamAbortedException;

/**
 * Feeds chunks to the callback exactly like the real streamer: polls
 * should_abort after each chunk and converts StreamAbortedException into an
 * aborted result.
 */
class FakeStreamer extends HttpStreamer {

	/**
	 * Chunks to deliver.
	 *
	 * @var string[]
	 */
	public array $chunks = array();

	/**
	 * HTTP status to report.
	 *
	 * @var int
	 */
	public int $status = 200;

	/**
	 * Headers to report.
	 *
	 * @var array<string, string>
	 */
	public array $headers = array();

	/**
	 * Requests received: [url, args].
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	public array $requests = array();

	/**
	 * Chunks actually delivered before stopping.
	 *
	 * @var int
	 */
	public int $delivered = 0;

	/**
	 * Constructor.
	 *
	 * @param string[] $chunks Chunks.
	 * @param int      $status HTTP status.
	 */
	public function __construct( array $chunks = array(), int $status = 200 ) {
		$this->chunks = $chunks;
		$this->status = $status;
	}

	/**
	 * Replays chunks.
	 *
	 * @param string        $url          URL.
	 * @param array         $args         Args.
	 * @param callable      $on_chunk     Chunk callback.
	 * @param callable|null $should_abort Abort poll.
	 * @return array
	 */
	public function request( string $url, array $args, callable $on_chunk, ?callable $should_abort = null ) {
		$this->requests[] = array( $url, $args );
		$received         = '';

		foreach ( $this->chunks as $chunk ) {
			$received .= $chunk;
			++$this->delivered;
			try {
				$on_chunk( $chunk );
				$reason = null !== $should_abort ? (string) $should_abort() : '';
				if ( '' !== $reason ) {
					throw new StreamAbortedException( $reason );
				}
			} catch ( StreamAbortedException $exception ) {
				return array(
					'status'  => 200,
					'headers' => array(),
					'body'    => $received,
					'aborted' => $exception->reason(),
				);
			}
		}

		return array(
			'status'  => $this->status,
			'headers' => $this->headers,
			'body'    => $received,
			'aborted' => '',
		);
	}

	/**
	 * JSON body of the last request.
	 *
	 * @return array<string, mixed>
	 */
	public function last_body(): array {
		$last = end( $this->requests );
		return false === $last ? array() : ( json_decode( (string) $last[1]['body'], true ) ?? array() );
	}

	/**
	 * Builds SSE chunks from data payloads (arrays are JSON-encoded).
	 *
	 * @param array<int, mixed> $events Data payloads or [event, data] pairs.
	 * @return string[]
	 */
	public static function sse( array $events ): array {
		$chunks = array();
		foreach ( $events as $event ) {
			if ( is_array( $event ) && isset( $event['__event'] ) ) {
				$name = $event['__event'];
				unset( $event['__event'] );
				$chunks[] = "event: {$name}\ndata: " . json_encode( $event ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				continue;
			}
			$chunks[] = 'data: ' . ( is_string( $event ) ? $event : json_encode( $event ) ) . "\n\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		return $chunks;
	}
}
