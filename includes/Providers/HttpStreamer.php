<?php
/**
 * Streaming HTTP requests through the WordPress HTTP API.
 *
 * @package Chatlume
 */

namespace Chatlume\Providers;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ProviderError and StreamAbortedException messages are never printed as HTML: admins receive them
// as JSON through the REST API (rendered as text), visitors only see fixed strings, and
// getMessage() is HTML-escaped as a safeguard. Escaping at each throw would corrupt that text.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Receives response bodies chunk by chunk while using wp_remote_request().
 *
 * How it works (the request itself is always made by the WordPress HTTP API):
 * - WordPress turns every Requests hook into a `requests-{hook}` action.
 *   Both transports (cURL and fsockopen) fire `request.progress` with each
 *   block of body data as it arrives, so `requests-request.progress`
 *   delivers the stream incrementally.
 * - That action does not say which request the data belongs to, so data is
 *   accepted only after this streamer's own request reached the transport:
 *   `http_api_curl` passes the full request arguments (which carry a unique
 *   stream ID); `requests-fsockopen.before_send` marks the fsockopen start.
 *
 * Stopping early (visitor disconnected, time limit, caller request):
 * - cURL: a transfer-progress callback is attached to the handle WordPress
 *   passes to `http_api_curl`. Returning non-zero makes cURL abandon the
 *   connection ("aborted by callback"). Throwing from the write callback is
 *   not used for cURL because Requests treats a failed write as an encoding
 *   problem and silently re-sends the whole request. The progress callback
 *   also runs about once per second while waiting, so disconnects and time
 *   limits are noticed even when the provider sends nothing.
 * - fsockopen: StreamAbortedException is thrown from the progress hook,
 *   which leaves the read loop; it is caught around the request.
 */
class HttpStreamer {

	/**
	 * Request argument used to recognize this streamer's request.
	 */
	private const ID_ARG = 'chatlume_stream_id';

	/**
	 * Sends a request and passes each body chunk to $on_chunk as it arrives.
	 *
	 * @param string        $url          URL.
	 * @param array         $args         wp_remote_request() arguments.
	 * @param callable      $on_chunk     Receives (string $chunk). May throw StreamAbortedException to stop.
	 * @param callable|null $should_abort Polled while streaming; return a non-empty reason string to stop.
	 * @return array{status: int, headers: array<string, string>, body: string, aborted: string}|WP_Error
	 *         `aborted` is the abort reason, or '' when the stream completed.
	 */
	public function request( string $url, array $args, callable $on_chunk, ?callable $should_abort = null ) {
		$stream_id    = wp_generate_uuid4();
		$transport    = '';
		$received     = '';
		$abort_reason = '';

		$args[ self::ID_ARG ] = $stream_id;
		$args['blocking']     = true;
		$args['stream']       = false;
		$args['decompress']   = false;
		$args['headers']      = array_merge(
			// Compressed chunks cannot be parsed incrementally (fsockopen decompresses only at the end).
			array( 'Accept-Encoding' => 'identity' ),
			isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array()
		);

		// Records an abort; for fsockopen it must also unwind the read loop.
		$abort = static function ( string $reason ) use ( &$abort_reason, &$transport ) {
			if ( '' === $abort_reason ) {
				$abort_reason = $reason;
			}
			if ( 'fsockopen' === $transport ) {
				throw new StreamAbortedException( $abort_reason );
			}
		};

		$poll = static function () use ( $should_abort, $abort, &$abort_reason ) {
			if ( '' === $abort_reason && null !== $should_abort ) {
				$reason = $should_abort();
				if ( is_string( $reason ) && '' !== $reason ) {
					$abort( $reason );
				}
			}
		};

		$on_curl_start = static function ( $handle, $request ) use ( $stream_id, &$transport, &$abort_reason, $should_abort ) {
			if ( ! is_array( $request ) || ( $request[ self::ID_ARG ] ?? '' ) !== $stream_id ) {
				return;
			}
			$transport = 'curl';

			// Attaches an abort switch to the WordPress HTTP API's own cURL handle (see class docblock).
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			curl_setopt(
				$handle,
				CURLOPT_XFERINFOFUNCTION,
				static function () use ( &$abort_reason, $should_abort ) {
					if ( '' === $abort_reason && null !== $should_abort ) {
						$reason = $should_abort();
						if ( is_string( $reason ) && '' !== $reason ) {
							$abort_reason = $reason;
						}
					}
					return '' === $abort_reason ? 0 : 1;
				}
			);
			// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_setopt
		};

		$on_socket_start = static function () use ( &$transport ) {
			if ( '' === $transport ) {
				$transport = 'fsockopen';
			}
		};

		$on_progress = static function ( $data ) use ( &$transport, &$received, &$abort_reason, $on_chunk, $abort, $poll ) {
			if ( '' === $transport || '' !== $abort_reason || ! is_string( $data ) || '' === $data ) {
				return;
			}

			$received .= $data;

			try {
				$on_chunk( $data );
			} catch ( StreamAbortedException $exception ) {
				$abort( $exception->reason() );
				return;
			}

			$poll();
		};

		add_action( 'http_api_curl', $on_curl_start, 10, 2 );
		add_action( 'requests-fsockopen.before_send', $on_socket_start );
		add_action( 'requests-request.progress', $on_progress );

		try {
			$response = wp_remote_request( $url, $args );
		} catch ( StreamAbortedException $exception ) {
			$response = null;
			if ( '' === $abort_reason ) {
				$abort_reason = $exception->reason();
			}
		} finally {
			remove_action( 'http_api_curl', $on_curl_start, 10 );
			remove_action( 'requests-fsockopen.before_send', $on_socket_start );
			remove_action( 'requests-request.progress', $on_progress );
		}

		if ( '' !== $abort_reason ) {
			// The transport reports the deliberate abort as an error; that is expected here.
			return array(
				'status'  => 200,
				'headers' => array(),
				'body'    => $received,
				'aborted' => $abort_reason,
			);
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = (string) wp_remote_retrieve_body( $response );

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => self::flatten_headers( wp_remote_retrieve_headers( $response ) ),
			// Prefer the transport's body; fall back to what was streamed (identical when both exist).
			'body'    => '' !== $body ? $body : $received,
			'aborted' => '',
		);
	}

	/**
	 * Normalizes response headers to a lowercase name => value array.
	 *
	 * @param mixed $headers Headers from wp_remote_retrieve_headers().
	 * @return array<string, string>
	 */
	public static function flatten_headers( $headers ): array {
		$flat = array();

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		if ( ! is_array( $headers ) ) {
			return $flat;
		}

		foreach ( $headers as $name => $value ) {
			$flat[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $flat;
	}
}
