/**
 * localStorage access that never throws (private windows, blocked storage,
 * full quota). Keys are prefixed with `chatlume:`.
 */
const PREFIX = 'chatlume:';

/**
 * Reads a JSON value.
 *
 * @param key      Key (without prefix).
 * @param fallback Value when missing or unreadable.
 * @return Stored value or the fallback.
 */
export function read< T >( key: string, fallback: T ): T {
	try {
		const raw = window.localStorage.getItem( PREFIX + key );
		return raw === null ? fallback : ( JSON.parse( raw ) as T );
	} catch {
		return fallback;
	}
}

/**
 * Writes a JSON value.
 *
 * @param key   Key (without prefix).
 * @param value Value.
 * @return Whether it was stored.
 */
export function write( key: string, value: unknown ): boolean {
	try {
		window.localStorage.setItem( PREFIX + key, JSON.stringify( value ) );
		return true;
	} catch {
		return false;
	}
}

/**
 * Removes a value.
 *
 * @param key Key (without prefix).
 */
export function remove( key: string ): void {
	try {
		window.localStorage.removeItem( PREFIX + key );
	} catch {
		// Storage unavailable: nothing to remove.
	}
}

/**
 * Full storage key, as seen by `storage` events.
 *
 * @param key Key (without prefix).
 * @return Prefixed key.
 */
export function storageKey( key: string ): string {
	return PREFIX + key;
}
