<?php
/**
 * Incremental Server-Sent Events parser.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns arbitrary network chunks into complete SSE events.
 *
 * Chunks can split anywhere (mid-line, mid-UTF-8 character, mid-event);
 * bytes are buffered until a blank line ends an event. Handles LF, CRLF,
 * and CR line endings, multi-line `data:` fields, comments, and `event:`.
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 */
final class SseParser {

	/**
	 * Unparsed bytes.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Raw bytes received in total (used to detect non-SSE bodies such as JSON errors).
	 *
	 * @var string
	 */
	private string $head = '';

	/**
	 * Feeds a chunk and returns the events it completed.
	 *
	 * @param string $chunk Raw bytes.
	 * @return array<int, array{event: string, data: string}>
	 */
	public function push( string $chunk ): array {
		if ( strlen( $this->head ) < 512 ) {
			$this->head .= substr( $chunk, 0, 512 - strlen( $this->head ) );
		}

		$this->buffer .= $chunk;

		// A trailing CR may be the first half of a CRLF split across chunks; keep it for later.
		$held = '';
		if ( "\r" === substr( $this->buffer, -1 ) ) {
			$held         = "\r";
			$this->buffer = substr( $this->buffer, 0, -1 );
		}
		$this->buffer = str_replace( array( "\r\n", "\r" ), "\n", $this->buffer );

		$events = array();

		while ( false !== ( $end = strpos( $this->buffer, "\n\n" ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard buffer-consuming loop.
			$block        = substr( $this->buffer, 0, $end );
			$this->buffer = (string) substr( $this->buffer, $end + 2 );

			$event = self::parse_block( $block );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		$this->buffer .= $held;

		return $events;
	}

	/**
	 * Flushes a final event that was not followed by a blank line.
	 *
	 * @return array<int, array{event: string, data: string}>
	 */
	public function finish(): array {
		$block        = trim( str_replace( array( "\r\n", "\r" ), "\n", $this->buffer ), "\n" );
		$this->buffer = '';

		if ( '' === $block ) {
			return array();
		}

		$event = self::parse_block( $block );

		return null === $event ? array() : array( $event );
	}

	/**
	 * First bytes received, for diagnosing bodies that are not an event stream.
	 *
	 * @return string
	 */
	public function head(): string {
		return $this->head;
	}

	/**
	 * Parses one event block.
	 *
	 * @param string $block Lines of one event.
	 * @return array{event: string, data: string}|null Null for comment-only blocks.
	 */
	private static function parse_block( string $block ): ?array {
		$event = 'message';
		$data  = array();

		foreach ( explode( "\n", $block ) as $line ) {
			if ( '' === $line || ':' === $line[0] ) {
				continue;
			}

			$colon = strpos( $line, ':' );
			$field = false === $colon ? $line : substr( $line, 0, $colon );
			$value = false === $colon ? '' : substr( $line, $colon + 1 );

			if ( '' !== $value && ' ' === $value[0] ) {
				$value = substr( $value, 1 );
			}

			if ( 'data' === $field ) {
				$data[] = $value;
			} elseif ( 'event' === $field ) {
				$event = $value;
			}
		}

		if ( array() === $data ) {
			return null;
		}

		return array(
			'event' => $event,
			'data'  => implode( "\n", $data ),
		);
	}
}
