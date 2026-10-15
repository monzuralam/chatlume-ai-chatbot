<?php
/**
 * Tests for streaming replies.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Brain\Monkey\Functions;
use Chatlume\Chat\ChatException;
use Chatlume\Chat\ChatService;
use Chatlume\Chat\StopSignal;
use Chatlume\Chat\StreamHandler;
use Chatlume\Chat\StreamLimiter;
use Chatlume\Settings\Settings;
use Chatlume\Tests\TestCase;

/**
 * Stream limiter, SSE output, and abort handling.
 */
class StreamingTest extends TestCase {

	/**
	 * Frames written by the handler.
	 *
	 * @var string[]
	 */
	private array $frames = array();

	/**
	 * Whether the visitor "left".
	 *
	 * @var bool
	 */
	private bool $gone = false;

	protected function set_up(): void {
		parent::set_up();
		$this->frames           = array();
		$this->gone             = false;
		$this->ext_object_cache = true;
		Functions\when( 'wp_cache_delete' )->alias(
			function ( $key, $group = '' ) {
				unset( $this->cache[ $group . ':' . $key ] );
				return true;
			}
		);

		$counter = 0;
		Functions\when( 'wp_generate_password' )->alias(
			static function () use ( &$counter ) {
				return 'slot' . ( ++$counter );
			}
		);
	}

	/**
	 * A handler around a scripted pipeline.
	 *
	 * @param callable           $handle  Receives ($request, $stream) and returns a result or throws.
	 * @param StreamLimiter|null $limiter Limiter.
	 * @return StreamHandler
	 */
	private function handler( callable $handle, ?StreamLimiter $limiter = null ): StreamHandler {
		$service = new class( $handle ) extends ChatService {
			/**
			 * Scripted handler.
			 *
			 * @var callable
			 */
			private $script;

			/**
			 * Constructor.
			 *
			 * @param callable $script Script.
			 */
			public function __construct( callable $script ) {
				$this->script = $script;
			}

			/**
			 * Runs the script.
			 *
			 * @param array $request Request.
			 * @param array $stream  Stream callbacks.
			 * @return array
			 */
			public function handle( array $request, array $stream = array() ): array {
				return ( $this->script )( $request, $stream );
			}
		};

		return new StreamHandler(
			$service,
			$limiter ?? new StreamLimiter(),
			array(
				'open'         => static function () {},
				'write'        => function ( string $frame ) {
					$this->frames[] = $frame;
				},
				'disconnected' => function () {
					return $this->gone;
				},
				// The handler releases the slot itself; nothing may run after the test.
				'shutdown'     => static function () {},
			)
		);
	}

	/**
	 * Parsed events: [name, data].
	 *
	 * @return array<int, array{0: string, 1: array}>
	 */
	private function events(): array {
		$events = array();
		foreach ( $this->frames as $frame ) {
			if ( preg_match( '/^event: (\w+)\ndata: (.*)\n\n$/s', $frame, $match ) ) {
				$events[] = array( $match[1], json_decode( $match[2], true ) );
			}
		}
		return $events;
	}

	/**
	 * A pipeline result.
	 *
	 * @param string $text Reply text.
	 * @param array  $sources Sources.
	 * @return array
	 */
	private static function result( string $text, array $sources = array() ): array {
		return array(
			'conversation_id' => '12',
			'message_id'      => 34,
			'reply'           => array(
				'text'            => $text,
				'source'          => 'ai',
				'sources'         => $sources,
				'quick_answer_id' => null,
			),
			'details'         => array(),
		);
	}

	public function test_limiter_caps_concurrent_streams_and_frees_slots(): void {
		$this->options[ Settings::OPTION ] = array( 'streaming' => array( 'max_concurrent' => 2 ) );
		$limiter                           = new StreamLimiter();

		$first  = $limiter->acquire( 60 );
		$second = $limiter->acquire( 60 );
		$this->assertNotSame( '', $first );
		$this->assertNotSame( '', $second );
		$this->assertSame( '', $limiter->acquire( 60 ), 'A third stream waits.' );
		$this->assertSame( 2, $limiter->count() );
		$this->assertFalse( $this->autoload[ StreamLimiter::OPTION ] );

		$limiter->release( $first );
		$limiter->release( $first );
		$this->assertSame( 1, $limiter->count() );
		$this->assertNotSame( '', $limiter->acquire( 60 ) );
	}

	public function test_limiter_frees_slots_of_requests_that_died(): void {
		$this->options[ Settings::OPTION ]      = array( 'streaming' => array( 'max_concurrent' => 1 ) );
		$this->options[ StreamLimiter::OPTION ] = array( 'dead' => time() - 1 );

		$this->assertNotSame( '', ( new StreamLimiter() )->acquire( 60 ) );
		$this->assertArrayNotHasKey( 'dead', $this->options[ StreamLimiter::OPTION ] );
	}

	public function test_max_duration_follows_the_setting(): void {
		$this->options[ Settings::OPTION ] = array( 'streaming' => array( 'max_duration' => 45 ) );
		$execution                         = (int) ini_get( 'max_execution_time' );

		$this->assertSame( $execution > 0 ? max( 5, min( 45, $execution - 5 ) ) : 45, StreamHandler::max_duration() );
	}

	public function test_streams_start_tokens_sources_and_done(): void {
		$seen    = array();
		$handler = $this->handler(
			static function ( $request, $stream ) use ( &$seen ) {
				$seen = $stream;
				$stream['on_start']( '12' );
				$stream['on_token']( 'Hel' );
				$stream['on_token']( 'lo' );
				return self::result(
					'Hello',
					array(
						array(
							'title' => 'Docs',
							'url'   => 'https://example.test/docs/',
						),
					)
				);
			}
		);

		$handler->run( array( 'message' => 'Hi' ) );

		$this->assertSame( array( 'on_start', 'timeout', 'on_token', 'should_abort' ), array_keys( $seen ) );
		$this->assertSame(
			array( 'start', 'token', 'token', 'sources', 'done' ),
			array_column( $this->events(), 0 )
		);
		$events = $this->events();
		$this->assertSame( '12', $events[0][1]['conversation_id'] );
		$this->assertSame( 'lo', $events[2][1]['t'] );
		$this->assertTrue( $events[4][1]['streamed'] );
		$this->assertSame( 34, $events[4][1]['message_id'] );
		$this->assertSame( 0, ( new StreamLimiter() )->count(), 'The slot is released.' );
	}

	public function test_replies_that_did_not_stream_arrive_as_one_token(): void {
		$this->handler(
			static function () {
				return self::result( 'Nine to five.' );
			}
		)->run( array() );

		$events = $this->events();
		$this->assertSame( array( 'token', 'done' ), array_column( $events, 0 ) );
		$this->assertSame( 'Nine to five.', $events[0][1]['t'] );
		$this->assertFalse( $events[1][1]['streamed'] );
	}

	public function test_busy_server_answers_without_streaming(): void {
		$this->options[ Settings::OPTION ]      = array( 'streaming' => array( 'max_concurrent' => 1 ) );
		$this->options[ StreamLimiter::OPTION ] = array( 'other' => time() + 100 );
		$seen                                   = null;

		$this->handler(
			static function ( $request, $stream ) use ( &$seen ) {
				$seen = $stream;
				return self::result( 'Whole answer' );
			}
		)->run( array() );

		$this->assertSame( array( 'on_start' ), array_keys( $seen ), 'No token callback: the reply is not streamed.' );
		$this->assertSame( 'Whole answer', $this->events()[0][1]['t'] );
		$this->assertArrayHasKey( 'other', $this->options[ StreamLimiter::OPTION ] );
	}

	public function test_visitor_errors_become_error_events(): void {
		$this->handler(
			static function () {
				throw new ChatException( 'rate_limited', 'Slow down.', 429, 30 );
			}
		)->run( array() );

		$this->assertSame(
			array(
				array(
					'error',
					array(
						'code'        => 'chatlume_rate_limited',
						'message'     => 'Slow down.',
						'status'      => 429,
						'retry_after' => 30,
					),
				),
			),
			$this->events()
		);
		$this->assertSame( 0, ( new StreamLimiter() )->count() );
	}

	public function test_unexpected_errors_send_an_error_event_and_are_rethrown(): void {
		$handler = $this->handler(
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		try {
			$handler->run( array() );
			$this->fail( 'Expected the exception to be rethrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'boom', $exception->getMessage() );
		}

		$this->assertSame( 'error', $this->events()[0][0] );
		$this->assertSame( 'chatlume_unavailable', $this->events()[0][1]['code'] );
	}

	public function test_should_abort_reports_a_disconnected_visitor(): void {
		$reasons = array();
		$this->handler(
			function ( $request, $stream ) use ( &$reasons ) {
				$reasons[]  = $stream['should_abort']();
				$this->gone = true;
				$reasons[]  = $stream['should_abort']();
				return self::result( 'Partial' );
			}
		)->run( array() );

		$this->assertSame( array( '', 'client_disconnected' ), $reasons );
	}

	public function test_frames_contain_only_json(): void {
		$this->handler(
			static function ( $request, $stream ) {
				$stream['on_token']( "</script>\n\nevent: done\ndata: {}" );
				return self::result( 'x' );
			}
		)->run( array() );

		$this->assertSame( array( 'token', 'done' ), array_column( $this->events(), 0 ), 'Newlines in text cannot inject events.' );
		$this->assertSame( "</script>\n\nevent: done\ndata: {}", $this->events()[0][1]['t'] );
	}

	public function test_a_stop_request_aborts_the_stream_of_that_session_only(): void {
		$reasons = array();
		$handler = $this->handler(
			function ( $request, $stream ) use ( &$reasons ) {
				$stream['on_start']( '12' );
				$started   = $this->events()[0][1];
				$reasons[] = $stream['should_abort']();

				// Another session cannot stop this stream.
				( new StopSignal() )->request( StopSignal::key( 'other-session', $started['stream_id'] ) );
				$reasons[] = $stream['should_abort']();

				( new StopSignal() )->request( StopSignal::key( 's1', $started['stream_id'] ) );
				usleep( 550000 );
				$reasons[] = $stream['should_abort']();
				return self::result( 'Partial' );
			}
		);

		$handler->run( array( 'session_id' => 's1' ) );

		$this->assertMatchesRegularExpression( '/' . StopSignal::ID_PATTERN . '/', $this->events()[0][1]['stream_id'] );
		$this->assertSame( array( '', '', 'stopped' ), $reasons );
		$this->assertFalse(
			( new StopSignal() )->requested( StopSignal::key( 's1', $this->events()[0][1]['stream_id'] ) ),
			'The flag is cleared when the stream ends.'
		);
	}

	public function test_stop_flags_use_the_database_without_a_persistent_cache(): void {
		global $wpdb;

		$this->ext_object_cache = false;
		$wpdb                   = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Stored flags.
			 *
			 * @var array<string, int>
			 */
			public array $rows = array();

			/**
			 * Queries.
			 *
			 * @var string[]
			 */
			public array $queries = array();

			/**
			 * Fills placeholders (enough for these queries).
			 *
			 * @param string $query Query.
			 * @param mixed  ...$args Values.
			 * @return array
			 */
			public function prepare( $query, ...$args ) {
				return array( $query, $args );
			}

			/**
			 * Runs a write.
			 *
			 * @param array $prepared Prepared query.
			 * @return int
			 */
			public function query( $prepared ) {
				$this->queries[] = $prepared[0];
				if ( 0 === strpos( $prepared[0], 'INSERT' ) ) {
					$this->rows[ $prepared[1][1] ] = 1;
				} else {
					unset( $this->rows[ $prepared[1][1] ] );
				}
				return 1;
			}

			/**
			 * Reads one value.
			 *
			 * @param array $prepared Prepared query.
			 * @return string|null
			 */
			public function get_var( $prepared ) {
				$this->queries[] = $prepared[0];
				return isset( $this->rows[ $prepared[1][1] ] ) ? '1' : null;
			}
		};

		$signal = new StopSignal();
		$key    = StopSignal::key( 's1', 'abcdef0123456789' );

		$this->assertFalse( $signal->requested( $key ) );
		$signal->request( $key );
		$this->assertTrue( $signal->requested( $key ) );
		$signal->clear( $key );
		$this->assertFalse( $signal->requested( $key ) );
		$this->assertStringStartsWith( 'INSERT INTO %i', $wpdb->queries[1] );

		$wpdb = null;
	}
}
