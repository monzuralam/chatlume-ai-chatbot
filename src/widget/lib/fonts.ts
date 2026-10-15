/**
 * Registers the theme's font faces at document level.
 *
 * `@font-face` rules inside a shadow root are ignored by some browsers, so
 * faces are added to `document.fonts` with the FontFace API, where the
 * widget's Shadow DOM can use them. Faces are only registered; the browser
 * downloads a file when text needs it (and only the subsets whose
 * unicode-range matches), with `font-display: swap`.
 */
import type { FontFaceSpec } from '../types';
import { safeUrl } from './theme';

const registered = new Set< string >();

/**
 * Registers faces not registered yet.
 *
 * @param faces Faces from the theme configuration.
 * @return Number of newly registered faces.
 */
export function loadFonts( faces: FontFaceSpec[] | undefined ): number {
	if (
		! faces?.length ||
		typeof window === 'undefined' ||
		! ( 'FontFace' in window ) ||
		! document.fonts
	) {
		return 0;
	}

	let added = 0;
	for ( const face of faces ) {
		const src = safeUrl( face.src );
		const key = `${ face.family }|${ src }|${ face.weight }|${ face.style }`;
		if ( ! src || ! face.family || registered.has( key ) ) {
			continue;
		}
		try {
			const descriptors: FontFaceDescriptors = {
				weight: face.weight || '400',
				style: face.style || 'normal',
				display: 'swap',
			};
			if ( face.unicodeRange ) {
				descriptors.unicodeRange = face.unicodeRange;
			}
			document.fonts.add(
				new FontFace(
					face.family,
					`url(${ JSON.stringify( src ) }) format("woff2")`,
					descriptors
				)
			);
			registered.add( key );
			added++;
		} catch {
			// An invalid descriptor skips this face; the fallback stack still applies.
		}
	}
	return added;
}
