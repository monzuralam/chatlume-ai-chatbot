/**
 * SSE parsing and stream reading.
 */
import { ReadableStream } from 'stream/web';
import { TextDecoder, TextEncoder } from 'util';

import {
	readEvents,
	SseParser,
	wasBuffered,
	type SseEvent,
} from '../../src/widget/lib/stream';

Object.assign( global, { TextDecoder, TextEncoder } );

/**
 * A body that delivers the given chunks one read at a time.
 *
 * @param chunks Text chunks.
 * @return Stream.
 */
function body( chunks: string[] ) {
	const encoder = new TextEncoder();
	return new ReadableStream< Uint8Array >( {
		start( controller ) {
			chunks.forEach( ( chunk ) =>
				controller.enqueue( encoder.encode( chunk ) )
			);
			controller.close();
		},
	} ) as unknown as globalThis.ReadableStream< Uint8Array >;
}

describe( 'SseParser', () => {
	it( 'handles events split across chunks, comments, and CRLF', () => {
		const parser = new SseParser();
		const events: SseEvent[] = [
			...parser.push( ':' + ' '.repeat( 20 ) + '\n\nevent: tok' ),
			...parser.push( 'en\r\ndata: {"t":"Hi"}\r\n\r\n: ping\n\n' ),
			...parser.push( 'data: line 1\ndata: line 2\n\n' ),
		];
		expect( events ).toEqual( [
			{ event: 'token', data: '{"t":"Hi"}' },
			{ event: 'message', data: 'line 1\nline 2' },
		] );
	} );

	it( 'returns a last event without a trailing blank line', () => {
		const parser = new SseParser();
		expect( parser.push( 'event: done\ndata: {}' ) ).toEqual( [] );
		expect( parser.finish() ).toEqual( [ { event: 'done', data: '{}' } ] );
	} );
} );

describe( 'readEvents', () => {
	it( 'reads every event and counts reads that carried tokens', async () => {
		const seen: string[] = [];
		const stats = await readEvents(
			body( [
				'event: start\ndata: {}\n\nevent: token\ndata: {"t":"a"}\n\n',
				'event: token\ndata: {"t":"b"}\n\nevent: done\ndata: {}\n\n',
			] ),
			( event ) => seen.push( event.event )
		);
		expect( seen ).toEqual( [ 'start', 'token', 'token', 'done' ] );
		expect( stats ).toEqual( { tokens: 2, tokenReads: 2 } );
	} );
} );

describe( 'wasBuffered', () => {
	it( 'flags a streamed reply that arrived in one read', () => {
		expect( wasBuffered( { tokens: 30, tokenReads: 1 }, true ) ).toBe(
			true
		);
		expect( wasBuffered( { tokens: 30, tokenReads: 12 }, true ) ).toBe(
			false
		);
		expect( wasBuffered( { tokens: 1, tokenReads: 1 }, false ) ).toBe(
			false
		);
		expect( wasBuffered( { tokens: 3, tokenReads: 1 }, true ) ).toBe(
			false
		);
	} );
} );
