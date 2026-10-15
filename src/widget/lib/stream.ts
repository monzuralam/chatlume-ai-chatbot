/**
 * Reading Server-Sent Events from a fetch() response body.
 */

export interface SseEvent {
	event: string;
	data: string;
}

/**
 * Incremental SSE parser: feed text chunks, get complete events.
 */
export class SseParser {
	private buffer = '';
	private event = '';
	private data: string[] = [];

	/**
	 * Adds a chunk.
	 *
	 * @param chunk Text.
	 * @return Events completed by this chunk.
	 */
	push( chunk: string ): SseEvent[] {
		this.buffer += chunk;
		const events: SseEvent[] = [];
		let newline = this.buffer.search( /\r\n|\r|\n/ );

		while ( newline !== -1 ) {
			const line = this.buffer.slice( 0, newline );
			const width = this.buffer.startsWith( '\r\n', newline ) ? 2 : 1;
			this.buffer = this.buffer.slice( newline + width );
			const event = this.line( line );
			if ( event ) {
				events.push( event );
			}
			newline = this.buffer.search( /\r\n|\r|\n/ );
		}

		return events;
	}

	/**
	 * Ends the stream, returning a last event that had no blank line after it.
	 *
	 * @return Remaining events.
	 */
	finish(): SseEvent[] {
		const events = this.buffer ? this.push( '\n' ) : [];
		const last = this.line( '' );
		return last ? [ ...events, last ] : events;
	}

	private line( line: string ): SseEvent | null {
		if ( line === '' ) {
			if ( ! this.data.length ) {
				this.event = '';
				return null;
			}
			const event = {
				event: this.event || 'message',
				data: this.data.join( '\n' ),
			};
			this.event = '';
			this.data = [];
			return event;
		}
		if ( line.startsWith( ':' ) ) {
			return null;
		}
		const colon = line.indexOf( ':' );
		const field = colon === -1 ? line : line.slice( 0, colon );
		let value = colon === -1 ? '' : line.slice( colon + 1 );
		if ( value.startsWith( ' ' ) ) {
			value = value.slice( 1 );
		}
		if ( field === 'event' ) {
			this.event = value;
		} else if ( field === 'data' ) {
			this.data.push( value );
		}
		return null;
	}
}

export interface StreamStats {
	/** Token events received. */
	tokens: number;
	/** Body reads that carried at least one token event. */
	tokenReads: number;
}

/**
 * Reads an event-stream response, calling `onEvent` for each event.
 *
 * @param body    Response body.
 * @param onEvent Event callback.
 * @return Statistics used to detect servers that buffer the stream.
 */
export async function readEvents(
	body: ReadableStream< Uint8Array >,
	onEvent: ( event: SseEvent ) => void
): Promise< StreamStats > {
	const reader = body.getReader();
	const decoder = new TextDecoder();
	const parser = new SseParser();
	const stats: StreamStats = { tokens: 0, tokenReads: 0 };

	const dispatch = ( events: SseEvent[] ) => {
		const tokens = events.filter(
			( event ) => event.event === 'token'
		).length;
		if ( tokens ) {
			stats.tokens += tokens;
			stats.tokenReads++;
		}
		events.forEach( onEvent );
	};

	for (;;) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		dispatch( parser.push( decoder.decode( value, { stream: true } ) ) );
	}

	dispatch( parser.push( decoder.decode() ) );
	dispatch( parser.finish() );
	return stats;
}

/**
 * Whether a streamed reply arrived in one piece although the server sent it
 * token by token: a sign that something between the server and the visitor
 * buffers responses, so streaming brings no benefit there.
 *
 * @param stats    Stream statistics.
 * @param streamed Whether the server streamed the reply.
 * @return Whether the response was buffered.
 */
export function wasBuffered( stats: StreamStats, streamed: boolean ): boolean {
	return streamed && stats.tokens >= 8 && stats.tokenReads <= 1;
}
