<?php
/**
 * Server-Sent Events output for streaming replies.
 *
 * @package Chatlume
 */

namespace Chatlume\Chat;

use Chatlume\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one chat request and writes the reply as Server-Sent Events:
 *
 * - `start`   {conversation_id, stream_id}  The conversation, known before the
 *             reply, and the ID the widget uses to stop the stream (POST /chat/stop).
 * - `token`   {t: string}             A fragment of the reply text.
 * - `sources` {sources: array}        Source links shown under the reply.
 * - `done`    {conversation_id, message_id, reply}  The final, stored reply
 *             (after the `chatlume_response_text` filter; the widget replaces
 *             the streamed text with it).
 * - `error`   {code, message, status, retry_after}  Visitor-safe error.
 *
 * Only AI replies stream token by token; quick answers, cached answers, and
 * site-search results arrive as one `token` event. When every stream slot is
 * taken the reply is produced without streaming and sent the same way, so the
 * widget needs no second request.
 *
 * Every frame holds only fixed event names and wp_json_encode() output.
 */
class StreamHandler {

	/**
	 * Seconds of silence after which a comment line keeps proxies from closing
	 * the connection (and lets PHP notice a visitor who left).
	 */
	private const HEARTBEAT = 10;

	/**
	 * Seconds kept free below the host's max_execution_time.
	 */
	private const EXECUTION_MARGIN = 5;

	/**
	 * Seconds between checks for a stop request.
	 */
	private const STOP_CHECK = 0.5;

	/**
	 * Chat pipeline.
	 *
	 * @var ChatService
	 */
	private ChatService $service;

	/**
	 * Concurrent stream limiter.
	 *
	 * @var StreamLimiter
	 */
	private StreamLimiter $limiter;

	/**
	 * Stop requests.
	 *
	 * @var StopSignal
	 */
	private StopSignal $stops;

	/**
	 * Output hooks: open (sends headers, clears buffers), write (one frame),
	 * disconnected (whether the visitor left), shutdown (registers a shutdown callback).
	 *
	 * @var array{open: callable, write: callable, disconnected: callable, shutdown: callable}
	 */
	private array $io;

	/**
	 * Time of the last write.
	 *
	 * @var float
	 */
	private float $last_write = 0.0;

	/**
	 * Constructor.
	 *
	 * @param ChatService|null       $service Chat pipeline.
	 * @param StreamLimiter|null     $limiter Stream limiter.
	 * @param array<string, mixed>   $io      Output hooks (tests).
	 * @param StopSignal|null        $stops   Stop requests.
	 */
	public function __construct( ?ChatService $service = null, ?StreamLimiter $limiter = null, array $io = array(), ?StopSignal $stops = null ) {
		$this->service = $service ?? new ChatService();
		$this->limiter = $limiter ?? new StreamLimiter();
		$this->stops   = $stops ?? new StopSignal();
		$this->io      = array(
			'open'         => $io['open'] ?? array( self::class, 'open_stream' ),
			'write'        => $io['write'] ?? array( self::class, 'write_frame' ),
			'disconnected' => $io['disconnected'] ?? 'connection_aborted',
			'shutdown'     => $io['shutdown'] ?? 'register_shutdown_function',
		);
	}

	/**
	 * Maximum seconds one stream may run: the setting, kept below the host's
	 * max_execution_time (which is read, never changed).
	 *
	 * @return int
	 */
	public static function max_duration(): int {
		$duration  = (int) Settings::get( 'streaming.max_duration', 60 );
		$execution = (int) ini_get( 'max_execution_time' );

		if ( $execution > 0 ) {
			$duration = min( $duration, $execution - self::EXECUTION_MARGIN );
		}

		return max( 5, $duration );
	}

	/**
	 * Answers the request as an event stream. The caller exits afterwards.
	 *
	 * @param array<string, mixed> $request Request for ChatService::handle().
	 * @return void
	 * @throws \Throwable Unexpected errors, after an error event was sent (so they are logged).
	 */
	public function run( array $request ): void {
		$duration = self::max_duration();
		$slot     = $this->limiter->acquire( $duration );
		$limiter  = $this->limiter;

		if ( '' !== $slot ) {
			// Frees the slot even if the request ends abruptly.
			( $this->io['shutdown'] )( array( $limiter, 'release' ), $slot );
		}

		( $this->io['open'] )();
		$this->last_write = microtime( true );

		$streamed   = false;
		$stream_id  = StopSignal::new_id();
		$stop_key   = StopSignal::key( (string) ( $request['session_id'] ?? '' ), $stream_id );
		$last_check = 0.0;
		$stream     = array(
			'on_start' => function ( string $conversation_id ) use ( $stream_id ) {
				$this->send(
					'start',
					array(
						'conversation_id' => $conversation_id,
						'stream_id'       => $stream_id,
					)
				);
			},
		);

		if ( '' !== $slot ) {
			$stream += array(
				'timeout'      => $duration,
				'on_token'     => function ( string $text ) use ( &$streamed ) {
					$streamed = true;
					$this->send( 'token', array( 't' => $text ) );
				},
				'should_abort' => function () use ( $stop_key, &$last_check ): string {
					$now = microtime( true );
					if ( $now - $this->last_write >= self::HEARTBEAT ) {
						$this->comment( 'ping' );
					}
					if ( ( $this->io['disconnected'] )() ) {
						return 'client_disconnected';
					}
					if ( $now - $last_check >= self::STOP_CHECK ) {
						$last_check = $now;
						if ( $this->stops->requested( $stop_key ) ) {
							return 'stopped';
						}
					}
					return '';
				},
			);
		}

		try {
			$result = $this->service->handle( $request, $stream );
		} catch ( ChatException $exception ) {
			$this->send(
				'error',
				array(
					'code'        => 'chatlume_' . $exception->error_code(),
					'message'     => $exception->visitor_message(),
					'status'      => $exception->status(),
					'retry_after' => $exception->retry_after(),
				)
			);
			return;
		} catch ( \Throwable $throwable ) {
			$this->send(
				'error',
				array(
					'code'        => 'chatlume_unavailable',
					'message'     => BotText::error(),
					'status'      => 500,
					'retry_after' => 0,
				)
			);
			throw $throwable;
		} finally {
			if ( '' !== $slot ) {
				$limiter->release( $slot );
				$this->stops->clear( $stop_key );
			}
		}

		$reply = $result['reply'];

		if ( ! $streamed && '' !== (string) $reply['text'] ) {
			$this->send( 'token', array( 't' => (string) $reply['text'] ) );
		}

		if ( array() !== (array) $reply['sources'] ) {
			$this->send( 'sources', array( 'sources' => $reply['sources'] ) );
		}

		$this->send(
			'done',
			array(
				'conversation_id' => $result['conversation_id'],
				'message_id'      => $result['message_id'],
				'reply'           => $reply,
				'streamed'        => $streamed,
			)
		);
	}

	/**
	 * Sends one event.
	 *
	 * @param string               $event Event name (fixed by the caller).
	 * @param array<string, mixed> $data  Payload.
	 * @return void
	 */
	private function send( string $event, array $data ): void {
		$json = wp_json_encode( $data );
		$this->write( 'event: ' . $event . "\ndata: " . ( false === $json ? '{}' : $json ) . "\n\n" );
	}

	/**
	 * Sends an SSE comment (ignored by the client).
	 *
	 * @param string $text Comment.
	 * @return void
	 */
	private function comment( string $text ): void {
		$this->write( ': ' . $text . "\n\n" );
	}

	/**
	 * Writes a frame.
	 *
	 * @param string $frame Frame.
	 * @return void
	 */
	private function write( string $frame ): void {
		( $this->io['write'] )( $frame );
		$this->last_write = microtime( true );
	}

	/**
	 * Sends the event-stream headers, closes any PHP session, and removes
	 * output buffers so every frame reaches the visitor immediately.
	 *
	 * @return void
	 */
	public static function open_stream(): void {
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-store, private' );
			// Asks nginx (and compatible proxies) not to buffer the stream.
			header( 'X-Accel-Buffering: no' );
		}

		foreach ( array_reverse( ob_get_status( true ) ) as $buffer ) {
			if ( ! ( (int) ( $buffer['flags'] ?? 0 ) & PHP_OUTPUT_HANDLER_REMOVABLE ) || ! ob_end_clean() ) {
				break;
			}
		}

		// A first block of padding pushes past small proxy and server buffers.
		self::write_frame( ':' . str_repeat( ' ', 2048 ) . "\n\n" );
	}

	/**
	 * Writes a frame and flushes it to the visitor.
	 *
	 * @param string $frame SSE frame.
	 * @return void
	 */
	public static function write_frame( string $frame ): void {
		echo $frame; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE frame: fixed event names and wp_json_encode() output only (text/event-stream, not HTML).
		flush();
	}
}
