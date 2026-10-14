import { useEffect, useRef } from 'react';

/**
 * Whether a keyboard event is Cmd+S (macOS) or Ctrl+S (elsewhere).
 *
 * @param event Keyboard event.
 */
export function isSaveShortcut(
	event: Pick< KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey' | 'altKey' >
): boolean {
	return (
		( event.metaKey || event.ctrlKey ) &&
		! event.altKey &&
		event.key.toLowerCase() === 's'
	);
}

/**
 * Calls `onSave` on Cmd/Ctrl+S while enabled, and stops the browser's
 * "Save page" dialog.
 *
 * @param onSave  Save handler.
 * @param enabled Whether the shortcut is active.
 */
export function useSaveShortcut( onSave: () => void, enabled = true ): void {
	const handler = useRef( onSave );
	handler.current = onSave;

	useEffect( () => {
		if ( ! enabled ) {
			return undefined;
		}
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( isSaveShortcut( event ) ) {
				event.preventDefault();
				handler.current();
			}
		};
		window.addEventListener( 'keydown', onKeyDown );
		return () => window.removeEventListener( 'keydown', onKeyDown );
	}, [ enabled ] );
}
