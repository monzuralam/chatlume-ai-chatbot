/**
 * Tab sync and the single-streaming-tab lock.
 */
import { createTabSync, type TabMessage } from '../../src/widget/lib/tabs';

type WithChannel = { BroadcastChannel?: unknown };

describe( 'createTabSync', () => {
	const original = ( global as WithChannel ).BroadcastChannel;

	beforeEach( () => {
		window.localStorage.clear();
		// Exercise the storage-event fallback (no BroadcastChannel).
		delete ( global as WithChannel ).BroadcastChannel;
	} );

	afterAll( () => {
		( global as WithChannel ).BroadcastChannel = original;
	} );

	it( 'lets only one tab stream at a time', () => {
		let now = 1000;
		const clock = () => now;
		const first = createTabSync( () => undefined, clock );
		const second = createTabSync( () => undefined, clock );

		expect( first.acquire() ).toBe( true );
		expect( second.otherBusy() ).toBe( true );
		expect( second.acquire() ).toBe( false );

		first.release();
		expect( second.acquire() ).toBe( true );
		expect( first.otherBusy() ).toBe( true );

		// A crashed tab's lock expires.
		now += 10 * 60 * 1000;
		expect( first.acquire() ).toBe( true );

		first.close();
		second.close();
	} );

	it( 'delivers messages from other tabs through storage events', () => {
		const received: TabMessage[] = [];
		const sync = createTabSync( ( message ) => received.push( message ) );

		window.dispatchEvent(
			new StorageEvent( 'storage', {
				key: 'chatlume:sync',
				newValue: JSON.stringify( {
					from: 'another-tab',
					message: { type: 'busy', active: true },
					at: 1,
				} ),
			} )
		);
		window.dispatchEvent(
			new StorageEvent( 'storage', {
				key: 'chatlume:sync',
				newValue: JSON.stringify( {
					from: sync.id,
					message: { type: 'state' },
					at: 2,
				} ),
			} )
		);
		window.dispatchEvent(
			new StorageEvent( 'storage', {
				key: 'chatlume:chat',
				newValue: '{}',
			} )
		);

		expect( received ).toEqual( [
			{ type: 'busy', active: true },
			{ type: 'state' },
		] );
		sync.close();
	} );
} );
