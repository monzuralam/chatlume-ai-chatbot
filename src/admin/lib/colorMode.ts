import type { ColorMode } from './bootstrap';

export const COLOR_MODES: readonly ColorMode[] = [ 'system', 'light', 'dark' ];

export type ResolvedColorMode = 'light' | 'dark';

/**
 * Resolves the user's preference to the mode actually shown.
 *
 * @param mode        Stored preference.
 * @param prefersDark Whether the OS prefers dark mode.
 */
export function resolveColorMode(
	mode: ColorMode,
	prefersDark: boolean
): ResolvedColorMode {
	if ( mode === 'system' ) {
		return prefersDark ? 'dark' : 'light';
	}
	return mode;
}

/**
 * Next mode for the header toggle: system → light → dark → system.
 *
 * @param mode Current preference.
 */
export function nextColorMode( mode: ColorMode ): ColorMode {
	const index = COLOR_MODES.indexOf( mode );
	return COLOR_MODES[ ( index + 1 ) % COLOR_MODES.length ];
}

/**
 * Narrows an unknown value to a ColorMode, defaulting to "system".
 *
 * @param value Value to check.
 */
export function toColorMode( value: unknown ): ColorMode {
	return COLOR_MODES.includes( value as ColorMode )
		? ( value as ColorMode )
		: 'system';
}
