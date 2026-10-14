import { createContext, useContext } from 'react';

/**
 * Element inside #chatlume-admin that hosts every Radix portal (tooltips,
 * dialogs), so overlays inherit the app's tokens and scoped styles instead
 * of rendering into document.body.
 */
export const PortalContainerContext = createContext< HTMLElement | null >(
	null
);

export function usePortalContainer(): HTMLElement | null {
	return useContext( PortalContainerContext );
}
