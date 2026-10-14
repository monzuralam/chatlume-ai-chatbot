import { useEffect } from 'react';

/**
 * Shows the browser's "Leave site?" prompt when reloading or leaving
 * wp-admin with unsaved changes. In-app navigation is guarded separately by
 * rendering <UnsavedChangesGuard /> while the form is dirty.
 *
 * @param isDirty Whether there are unsaved changes.
 */
export function useUnsavedChangesWarning( isDirty: boolean ): void {
	useEffect( () => {
		if ( ! isDirty ) {
			return undefined;
		}
		const onBeforeUnload = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			// Required by some browsers to show the prompt.
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', onBeforeUnload );
		return () =>
			window.removeEventListener( 'beforeunload', onBeforeUnload );
	}, [ isDirty ] );
}
