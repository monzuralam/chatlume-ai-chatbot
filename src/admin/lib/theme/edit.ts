/**
 * Immutable updates to a theme by dotted path.
 */
import type { Theme } from './types';

/**
 * A copy of the theme with one value replaced.
 *
 * @param theme Theme.
 * @param path  Dotted path, e.g. "layout.width".
 * @param value New value.
 * @return New theme.
 */
export function setPath< T >( theme: Theme, path: string, value: T ): Theme {
	const keys = path.split( '.' );
	const write = ( node: unknown, index: number ): unknown => {
		const current = ( node ?? {} ) as Record< string, unknown >;
		const key = keys[ index ];
		return {
			...current,
			[ key ]:
				index === keys.length - 1
					? value
					: write( current[ key ], index + 1 ),
		};
	};
	return write( theme, 0 ) as Theme;
}

/**
 * Reads a dotted path.
 *
 * @param theme Theme.
 * @param path  Dotted path.
 * @return Value.
 */
export function getPath( theme: Theme, path: string ): unknown {
	return path
		.split( '.' )
		.reduce< unknown >(
			( node, key ) =>
				( node as Record< string, unknown > | undefined )?.[ key ],
			theme
		);
}

/** Theme sections a "Reset this section" button restores, by panel section. */
export const SECTION_KEYS: Record< string, Array< keyof Theme > > = {
	brand: [ 'brand' ],
	colors: [ 'colors', 'mode', 'header' ],
	typography: [ 'typography' ],
	layout: [ 'layout' ],
	shape: [ 'shape' ],
	conversation: [ 'messages', 'background', 'composer' ],
	launcher: [ 'launcher' ],
	motion: [ 'motion' ],
};

/**
 * Restores one panel section to the defaults.
 *
 * @param theme    Theme.
 * @param defaults Default theme.
 * @param section  Panel section ID.
 * @return New theme.
 */
export function resetSection(
	theme: Theme,
	defaults: Theme,
	section: string
): Theme {
	const next = { ...theme } as Record< string, unknown >;
	for ( const key of SECTION_KEYS[ section ] ?? [] ) {
		next[ key ] = JSON.parse( JSON.stringify( defaults[ key ] ) );
	}
	return next as unknown as Theme;
}
