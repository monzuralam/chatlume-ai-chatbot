/**
 * Keeps several open tabs of the same site in sync.
 *
 * Tabs announce changes over a BroadcastChannel (or, where unavailable,
 * through `storage` events) and share a short-lived lock so only one tab
 * streams a reply at a time.
 */
import { read, remove, storageKey, write } from './storage';

export type TabMessage = { type: 'state' } | { type: 'busy'; active: boolean };

interface Envelope {
	from: string;
	message: TabMessage;
	at: number;
}

interface Lock {
	tab: string;
	until: number;
}

const CHANNEL = 'chatlume';
const SYNC_KEY = 'sync';
const LOCK_KEY = 'lock';
/** A lock outlives the longest stream, so a crashed tab frees it. */
const LOCK_TTL = 5 * 60 * 1000;

export interface TabSync {
	id: string;
	post: ( message: TabMessage ) => void;
	/** Takes the streaming lock; false while another tab holds it. */
	acquire: () => boolean;
	release: () => void;
	/** Whether another tab is streaming now. */
	otherBusy: () => boolean;
	close: () => void;
}

/**
 * Creates the tab channel.
 *
 * @param onMessage Receives messages from other tabs.
 * @param now       Clock (tests).
 * @return Channel.
 */
export function createTabSync(
	onMessage: ( message: TabMessage ) => void,
	now: () => number = Date.now
): TabSync {
	const id = Math.random().toString( 36 ).slice( 2, 10 );
	const channel =
		typeof BroadcastChannel === 'function'
			? new BroadcastChannel( CHANNEL )
			: null;

	const receive = ( envelope: Envelope | null ) => {
		if ( envelope && envelope.from !== id && envelope.message ) {
			onMessage( envelope.message );
		}
	};

	const onChannel = ( event: MessageEvent ) =>
		receive( event.data as Envelope );
	const onStorage = ( event: StorageEvent ) => {
		if ( event.key === storageKey( SYNC_KEY ) && event.newValue ) {
			try {
				receive( JSON.parse( event.newValue ) as Envelope );
			} catch {
				// Ignore malformed values.
			}
		} else if ( event.key === storageKey( 'chat' ) ) {
			onMessage( { type: 'state' } );
		}
	};

	if ( channel ) {
		channel.addEventListener( 'message', onChannel );
	}
	window.addEventListener( 'storage', onStorage );

	const lock = (): Lock | null => {
		const value = read< Lock | null >( LOCK_KEY, null );
		return value && value.until > now() ? value : null;
	};

	const sync: TabSync = {
		id,
		post( message ) {
			const envelope: Envelope = { from: id, message, at: now() };
			if ( channel ) {
				channel.postMessage( envelope );
			} else {
				write( SYNC_KEY, envelope );
			}
		},
		acquire() {
			const current = lock();
			if ( current && current.tab !== id ) {
				return false;
			}
			write( LOCK_KEY, { tab: id, until: now() + LOCK_TTL } );
			sync.post( { type: 'busy', active: true } );
			return true;
		},
		release() {
			const current = lock();
			if ( ! current || current.tab === id ) {
				remove( LOCK_KEY );
				sync.post( { type: 'busy', active: false } );
			}
		},
		otherBusy() {
			const current = lock();
			return !! current && current.tab !== id;
		},
		close() {
			sync.release();
			channel?.removeEventListener( 'message', onChannel );
			channel?.close();
			window.removeEventListener( 'storage', onStorage );
		},
	};

	return sync;
}
