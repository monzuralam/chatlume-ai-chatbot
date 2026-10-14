<?php
/**
 * Tests for SseParser.
 *
 * @package Chatlume
 */

namespace Chatlume\Tests\Unit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Chatlume\Providers\SseParser;
use Chatlume\Tests\TestCase;

/**
 * Incremental SSE parsing across arbitrary chunk boundaries.
 */
class SseParserTest extends TestCase {

	/**
	 * Feeds a stream in chunks of a given size.
	 *
	 * @param string $stream Raw stream.
	 * @param int    $size   Chunk size.
	 * @return array<int, array{event: string, data: string}>
	 */
	private function parse_in_chunks( string $stream, int $size ): array {
		$parser = new SseParser();
		$events = array();

		foreach ( str_split( $stream, $size ) as $chunk ) {
			$events = array_merge( $events, $parser->push( $chunk ) );
		}

		return array_merge( $events, $parser->finish() );
	}

	public function test_same_events_regardless_of_chunk_size(): void {
		$stream   = "data: {\"a\":\"héllo 世界\"}\n\nevent: message_delta\ndata: {\"b\":2}\n\n: keep-alive comment\n\ndata: [DONE]\n\n";
		$expected = array(
			array(
				'event' => 'message',
				'data'  => '{"a":"héllo 世界"}',
			),
			array(
				'event' => 'message_delta',
				'data'  => '{"b":2}',
			),
			array(
				'event' => 'message',
				'data'  => '[DONE]',
			),
		);

		foreach ( array( 1, 2, 3, 7, 64, 4096 ) as $size ) {
			$this->assertSame( $expected, $this->parse_in_chunks( $stream, $size ), "Chunk size {$size}" );
		}
	}

	public function test_crlf_split_across_chunks_does_not_end_events_early(): void {
		$parser = new SseParser();

		$this->assertSame( array(), $parser->push( "data: one\r" ) );
		$this->assertSame( array(), $parser->push( "\ndata: two\r\n" ) );
		$this->assertSame(
			array(
				array(
					'event' => 'message',
					'data'  => "one\ntwo",
				),
			),
			$parser->push( "\r\n" )
		);
	}

	public function test_cr_only_line_endings(): void {
		$this->assertSame(
			array(
				array(
					'event' => 'ping',
					'data'  => 'x',
				),
			),
			$this->parse_in_chunks( "event: ping\rdata: x\r\r", 1 )
		);
	}

	public function test_field_without_space_and_unterminated_final_event(): void {
		$parser = new SseParser();
		$this->assertSame( array(), $parser->push( 'data:{"x":1}' ) );
		$this->assertSame(
			array(
				array(
					'event' => 'message',
					'data'  => '{"x":1}',
				),
			),
			$parser->finish()
		);
		$this->assertSame( array(), $parser->finish() );
	}

	public function test_head_keeps_first_bytes_for_diagnostics(): void {
		$parser = new SseParser();
		$parser->push( '{"error":{"message":"bad key"}}' );

		$this->assertSame( '{"error":{"message":"bad key"}}', $parser->head() );
		$this->assertSame( array(), $parser->finish() );
	}
}
