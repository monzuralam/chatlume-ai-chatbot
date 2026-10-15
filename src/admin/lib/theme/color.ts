/**
 * Color math for the Customizer: OKLCH conversion with gamut mapping, WCAG
 * contrast, automatic contrast fixes, and full light and dark palettes from
 * one brand color. Everything runs in the admin; the widget only receives
 * resolved hex values.
 */

export interface Oklch {
	l: number;
	c: number;
	h: number;
}

const clamp = ( value: number, min = 0, max = 1 ) =>
	Math.min( max, Math.max( min, value ) );

/**
 * Normalizes a hex color to #rrggbb (drops alpha), or '' when invalid.
 *
 * @param value Color.
 * @return Hex color.
 */
export function normalizeHex( value: string ): string {
	const hex = ( value || '' ).trim().toLowerCase();
	const short = /^#([0-9a-f])([0-9a-f])([0-9a-f])$/.exec( hex );
	if ( short ) {
		return `#${ short[ 1 ] }${ short[ 1 ] }${ short[ 2 ] }${ short[ 2 ] }${ short[ 3 ] }${ short[ 3 ] }`;
	}
	const long = /^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/.exec( hex );
	return long ? `#${ long[ 1 ] }` : '';
}

function hexToRgb( hex: string ): [ number, number, number ] {
	const value = normalizeHex( hex ) || '#000000';
	return [ 1, 3, 5 ].map(
		( index ) => parseInt( value.slice( index, index + 2 ), 16 ) / 255
	) as [ number, number, number ];
}

function rgbToHex( rgb: [ number, number, number ] ): string {
	return (
		'#' +
		rgb
			.map( ( channel ) =>
				Math.round( clamp( channel ) * 255 )
					.toString( 16 )
					.padStart( 2, '0' )
			)
			.join( '' )
	);
}

const toLinear = ( channel: number ) =>
	channel <= 0.04045
		? channel / 12.92
		: Math.pow( ( channel + 0.055 ) / 1.055, 2.4 );

const fromLinear = ( channel: number ) =>
	channel <= 0.0031308
		? channel * 12.92
		: 1.055 * Math.pow( channel, 1 / 2.4 ) - 0.055;

/**
 * Hex to OKLCH.
 *
 * @param hex Color.
 * @return OKLCH (l 0–1, c ≥ 0, h in degrees).
 */
export function toOklch( hex: string ): Oklch {
	const [ r, g, b ] = hexToRgb( hex ).map( toLinear );
	const l = Math.cbrt(
		0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b
	);
	const m = Math.cbrt(
		0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b
	);
	const s = Math.cbrt(
		0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b
	);

	const L = 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s;
	const A = 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s;
	const B = 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s;

	const c = Math.sqrt( A * A + B * B );
	const h =
		c < 1e-4 ? 0 : ( ( Math.atan2( B, A ) * 180 ) / Math.PI + 360 ) % 360;
	return { l: L, c, h };
}

function oklchToLinear( { l: L, c, h }: Oklch ): [ number, number, number ] {
	const A = c * Math.cos( ( h * Math.PI ) / 180 );
	const B = c * Math.sin( ( h * Math.PI ) / 180 );
	const l = ( L + 0.3963377774 * A + 0.2158037573 * B ) ** 3;
	const m = ( L - 0.1055613458 * A - 0.0638541728 * B ) ** 3;
	const s = ( L - 0.0894841775 * A - 1.291485548 * B ) ** 3;
	return [
		4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
		-1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
		-0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
	];
}

const inGamut = ( rgb: number[] ) =>
	rgb.every( ( channel ) => channel >= -1e-4 && channel <= 1 + 1e-4 );

/**
 * OKLCH to hex. Colors outside sRGB keep their lightness and hue and lose
 * just enough chroma to fit.
 *
 * @param color OKLCH.
 * @return Hex color.
 */
export function fromOklch( color: Oklch ): string {
	const target = { ...color, l: clamp( color.l ), c: Math.max( 0, color.c ) };
	let rgb = oklchToLinear( target );
	if ( ! inGamut( rgb ) ) {
		let low = 0;
		let high = target.c;
		for ( let step = 0; step < 24; step++ ) {
			const middle = ( low + high ) / 2;
			if ( inGamut( oklchToLinear( { ...target, c: middle } ) ) ) {
				low = middle;
			} else {
				high = middle;
			}
		}
		rgb = oklchToLinear( { ...target, c: low } );
	}
	return rgbToHex(
		rgb.map( ( channel ) => fromLinear( clamp( channel ) ) ) as [
			number,
			number,
			number,
		]
	);
}

/**
 * WCAG relative luminance.
 *
 * @param hex Color.
 * @return 0–1.
 */
export function luminance( hex: string ): number {
	const [ r, g, b ] = hexToRgb( hex ).map( toLinear );
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * WCAG contrast ratio between two colors.
 *
 * @param a Color.
 * @param b Color.
 * @return 1–21.
 */
export function contrast( a: string, b: string ): number {
	const one = luminance( a );
	const two = luminance( b );
	return ( Math.max( one, two ) + 0.05 ) / ( Math.min( one, two ) + 0.05 );
}

/**
 * The nearest color (same hue and chroma, changed lightness) that reaches
 * the ratio against a background. Moves away from the background's
 * lightness; when that cannot reach the ratio, tries the other way.
 *
 * @param foreground Color to adjust.
 * @param background Background.
 * @param ratio      Minimum ratio.
 * @return Adjusted color (unchanged when it already passes).
 */
export function fixContrast(
	foreground: string,
	background: string,
	ratio = 4.5
): string {
	if ( contrast( foreground, background ) >= ratio ) {
		return normalizeHex( foreground );
	}
	const base = toOklch( foreground );
	const lighterFirst = luminance( background ) < 0.18;
	for ( const direction of lighterFirst ? [ 1, -1 ] : [ -1, 1 ] ) {
		for ( let step = 1; step <= 100; step++ ) {
			const candidate = fromOklch( {
				...base,
				l: clamp( base.l + direction * step * 0.01 ),
			} );
			if ( contrast( candidate, background ) >= ratio ) {
				return candidate;
			}
		}
	}
	return lighterFirst ? '#ffffff' : '#000000';
}

/**
 * White or near-black, whichever reads better on a background.
 *
 * @param background Background.
 * @param dark       Dark ink to compare with white.
 * @return Ink color.
 */
export function readableInk( background: string, dark = '#111111' ): string {
	return contrast( '#ffffff', background ) >= contrast( dark, background )
		? '#ffffff'
		: dark;
}

/**
 * A fill that carries readable text: keeps the brand color when white or
 * dark text reaches AA on it, otherwise darkens (or lightens) it just enough.
 *
 * @param brand Brand color.
 * @param dark  Dark ink.
 * @return Fill and its ink.
 */
function readableFill(
	brand: string,
	dark: string
): { fill: string; ink: string } {
	const ink = readableInk( brand, dark );
	if ( contrast( ink, brand ) >= 4.5 ) {
		return { fill: normalizeHex( brand ), ink };
	}
	const towardWhiteInk = fixContrast( brand, '#ffffff', 4.5 );
	const towardDarkInk = fixContrast( brand, dark, 4.5 );
	const original = toOklch( brand ).l;
	return Math.abs( toOklch( towardWhiteInk ).l - original ) <=
		Math.abs( toOklch( towardDarkInk ).l - original )
		? { fill: towardWhiteInk, ink: '#ffffff' }
		: { fill: towardDarkInk, ink: dark };
}

export type Palette = Record< string, string >;

/**
 * Complete light and dark palettes from one brand color. Every text and
 * background pair passes WCAG AA.
 *
 * @param brand Brand color.
 * @return Palettes.
 */
export function generatePalette( brand: string ): {
	light: Palette;
	dark: Palette;
} {
	const seed = toOklch( normalizeHex( brand ) || '#4f46e5' );
	const hue = seed.h;
	const tint = ( l: number, c: number, h = hue ) => fromOklch( { l, c, h } );
	const chroma = Math.min( seed.c, 0.2 );

	// Light mode.
	const lightInk = tint( 0.25, Math.min( chroma, 0.03 ) );
	const primary = readableFill( fromOklch( seed ), lightInk );
	const accentSeed = fromOklch( { ...seed, h: ( hue + 30 ) % 360 } );
	const accent = readableFill( accentSeed, lightInk );
	const headerEnd = readableFill(
		fromOklch( { ...seed, h: ( hue + 40 ) % 360 } ),
		primary.ink
	);
	const light: Palette = {
		primary: primary.fill,
		onPrimary: primary.ink,
		accent: accent.fill,
		background: '#ffffff',
		surface: tint( 0.975, 0.004 ),
		headerBackground: primary.fill,
		headerBackgroundEnd:
			contrast( primary.ink, headerEnd.fill ) >= 4.5
				? headerEnd.fill
				: primary.fill,
		headerText: primary.ink,
		botBubble: tint( 0.96, Math.min( chroma, 0.012 ) ),
		botText: lightInk,
		userBubble: primary.fill,
		userText: primary.ink,
		link: fixContrast(
			fromOklch( seed ),
			tint( 0.96, Math.min( chroma, 0.012 ) )
		),
		border: tint( 0.92, Math.min( chroma, 0.01 ) ),
		inputBackground: '#ffffff',
		inputText: lightInk,
		launcherBackground: primary.fill,
		launcherIcon: primary.ink,
		sourceCard: '#ffffff',
		muted: fixContrast(
			tint( 0.5, Math.min( chroma, 0.02 ) ),
			tint( 0.96, 0.012 )
		),
		error: '#c62828',
		success: '#1b7f4b',
	};

	// Dark mode: the same brand, lighter and calmer on deep surfaces.
	const background = tint( 0.18, Math.min( chroma, 0.012 ) );
	const surface = tint( 0.215, Math.min( chroma, 0.014 ) );
	const darkInk = tint( 0.18, Math.min( chroma, 0.03 ) );
	const darkPrimary = readableFill(
		fromOklch( {
			l: Math.max( seed.l, 0.7 ),
			c: Math.min( chroma, 0.16 ),
			h: hue,
		} ),
		darkInk
	);
	const darkUser = readableFill(
		fromOklch( {
			l: Math.min( Math.max( seed.l, 0.5 ), 0.58 ),
			c: Math.min( chroma, 0.18 ),
			h: hue,
		} ),
		darkInk
	);
	const botBubble = tint( 0.26, Math.min( chroma, 0.016 ) );
	const darkText = tint( 0.94, Math.min( chroma, 0.008 ) );
	const header = tint( 0.3, Math.min( chroma, 0.07 ) );
	const dark: Palette = {
		primary: darkPrimary.fill,
		onPrimary: darkPrimary.ink,
		accent: fixContrast(
			tint( 0.75, Math.min( chroma, 0.14 ), ( hue + 30 ) % 360 ),
			background
		),
		background,
		surface,
		headerBackground: header,
		headerBackgroundEnd: tint(
			0.32,
			Math.min( chroma, 0.08 ),
			( hue + 40 ) % 360
		),
		headerText: tint( 0.97, 0.005 ),
		botBubble,
		botText: darkText,
		userBubble: darkUser.fill,
		userText: darkUser.ink,
		link: fixContrast( tint( 0.8, Math.min( chroma, 0.12 ) ), botBubble ),
		border: tint( 0.3, Math.min( chroma, 0.015 ) ),
		inputBackground: surface,
		inputText: darkText,
		launcherBackground: darkUser.fill,
		launcherIcon: darkUser.ink,
		sourceCard: surface,
		muted: fixContrast( tint( 0.72, Math.min( chroma, 0.02 ) ), botBubble ),
		error: '#ff8a80',
		success: '#6fd49b',
	};

	return { light, dark };
}
