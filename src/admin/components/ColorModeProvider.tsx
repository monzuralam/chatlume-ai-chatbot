import { createContext, useContext, useEffect, type ReactNode } from 'react';

import { useColorMode } from '../hooks/useColorMode';
import type { ColorMode } from '../lib/bootstrap';
import type { ResolvedColorMode } from '../lib/colorMode';

interface ColorModeContextValue {
	mode: ColorMode;
	resolved: ResolvedColorMode;
	setMode: ( mode: ColorMode ) => void;
}

const ColorModeContext = createContext< ColorModeContextValue >( {
	mode: 'system',
	resolved: 'light',
	setMode: () => {},
} );

/**
 * Provides the color mode and applies it as `data-theme` on the app root,
 * which swaps the design tokens without re-rendering components.
 *
 * @param props          Props.
 * @param props.root     The #chatlume-admin element.
 * @param props.children Children.
 */
export function ColorModeProvider( {
	root,
	children,
}: {
	root: HTMLElement;
	children: ReactNode;
} ) {
	const value = useColorMode();

	useEffect( () => {
		root.setAttribute( 'data-theme', value.resolved );
	}, [ root, value.resolved ] );

	return (
		<ColorModeContext.Provider value={ value }>
			{ children }
		</ColorModeContext.Provider>
	);
}

export function useColorModeContext(): ColorModeContextValue {
	return useContext( ColorModeContext );
}
