import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { wpRequest } from '../lib/api';
import { getBootstrap, type ColorMode } from '../lib/bootstrap';
import {
	resolveColorMode,
	toColorMode,
	type ResolvedColorMode,
} from '../lib/colorMode';

const DARK_QUERY = '(prefers-color-scheme: dark)';

function prefersDark(): boolean {
	return (
		typeof window.matchMedia === 'function' &&
		window.matchMedia( DARK_QUERY ).matches
	);
}

/**
 * The admin's light/dark preference, stored per user in user meta
 * (`chatlume_admin_color_mode`) through core's /wp/v2/users/me endpoint.
 */
export function useColorMode(): {
	mode: ColorMode;
	resolved: ResolvedColorMode;
	setMode: ( mode: ColorMode ) => void;
} {
	const [ mode, setModeState ] = useState< ColorMode >( () =>
		toColorMode( getBootstrap().colorMode )
	);
	const [ systemDark, setSystemDark ] = useState( prefersDark );

	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return undefined;
		}
		const query = window.matchMedia( DARK_QUERY );
		const onChange = ( event: MediaQueryListEvent ) =>
			setSystemDark( event.matches );
		query.addEventListener( 'change', onChange );
		return () => query.removeEventListener( 'change', onChange );
	}, [] );

	const setMode = useCallback(
		( next: ColorMode ) => {
			const previous = mode;
			setModeState( next );

			wpRequest( '/wp/v2/users/me', {
				method: 'POST',
				data: { meta: { chatlume_admin_color_mode: next } },
			} ).catch( () => {
				setModeState( previous );
				toast.error(
					__(
						'Your color mode preference could not be saved.',
						'chatlume-ai-chatbot'
					)
				);
			} );
		},
		[ mode ]
	);

	return { mode, resolved: resolveColorMode( mode, systemDark ), setMode };
}
