/**
 * Preview postMessage protocol.
 */
import {
	createPreviewChannel,
	isPreviewMessage,
	type PreviewBatch,
} from '../../src/widget/lib/preview';

const ORIGIN = 'https://site.test';

/**
 * Dispatches a message event.
 *
 * @param data   Data.
 * @param origin Origin.
 */
function send( data: unknown, origin = ORIGIN ) {
	window.dispatchEvent( new MessageEvent( 'message', { data, origin } ) );
}

describe( 'isPreviewMessage', () => {
	it( 'accepts only Customizer messages from the allowed origin', () => {
		const message = { source: 'chatlume-customizer', type: 'theme' };
		expect(
			isPreviewMessage( { origin: ORIGIN, data: message }, ORIGIN )
		).toBe( true );
		expect(
			isPreviewMessage(
				{ origin: 'https://evil.test', data: message },
				ORIGIN
			)
		).toBe( false );
		expect(
			isPreviewMessage(
				{ origin: ORIGIN, data: { ...message, type: 'eval' } },
				ORIGIN
			)
		).toBe( false );
		expect(
			isPreviewMessage(
				{ origin: ORIGIN, data: { type: 'theme' } },
				ORIGIN
			)
		).toBe( false );
		expect(
			isPreviewMessage( { origin: ORIGIN, data: null }, ORIGIN )
		).toBe( false );
	} );
} );

describe( 'createPreviewChannel', () => {
	it( 'coalesces bursts into one batch per frame', () => {
		const batches: PreviewBatch[] = [];
		const frames: Array< () => void > = [];
		const postMessage = jest.fn();
		const channel = createPreviewChannel(
			ORIGIN,
			( batch ) => batches.push( batch ),
			{ postMessage } as unknown as Window,
			( callback ) => frames.push( callback )
		);

		send( { source: 'chatlume-customizer', type: 'theme', payload: 1 } );
		send( { source: 'chatlume-customizer', type: 'theme', payload: 2 } );
		send( {
			source: 'chatlume-customizer',
			type: 'state',
			payload: 'typing',
		} );
		send(
			{ source: 'chatlume-customizer', type: 'theme', payload: 'evil' },
			'https://evil.test'
		);

		expect( frames ).toHaveLength( 1 );
		frames[ 0 ]();
		expect( batches ).toEqual( [ { theme: 2, state: 'typing' } ] );

		channel.post( 'select', { part: 'header' } );
		expect( postMessage ).toHaveBeenCalledWith(
			{
				source: 'chatlume-preview',
				type: 'select',
				payload: { part: 'header' },
			},
			ORIGIN
		);

		channel.dispose();
		send( { source: 'chatlume-customizer', type: 'theme', payload: 3 } );
		expect( frames ).toHaveLength( 1 );
	} );
} );
