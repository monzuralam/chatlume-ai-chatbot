/**
 * "Match my website": a theme proposal from the site's icon, logo, colors,
 * and fonts.
 */
import { __, sprintf } from '@wordpress/i18n';

import type { SiteBrandInfo } from '../appearance';
import { normalizeHex, toOklch } from './color';
import { withBrandColor } from './presets';
import type { Theme } from './types';

/** Styles read from the home page of classic themes (no theme.json palette). */
export interface SampledStyles {
	button: string;
	link: string;
	text: string;
}

/** Palette slugs that usually hold the brand color, best first. */
const BRAND_SLUGS = [
	'primary',
	'brand',
	'accent',
	'secondary',
	'vivid-cyan-blue',
];

const chromatic = ( hex: string ) => {
	const color = toOklch( hex );
	return color.c >= 0.06 && color.l > 0.2 && color.l < 0.9;
};

/**
 * Converts a computed CSS color ("rgb(…)", "rgba(…)") to hex; transparent
 * or unreadable colors give ''.
 *
 * @param value CSS color.
 * @return Hex color or ''.
 */
export function cssColorToHex( value: string ): string {
	const match =
		/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s/]+([\d.]+%?))?\s*\)/.exec(
			value || ''
		);
	if ( ! match ) {
		return normalizeHex( value || '' );
	}
	if ( match[ 4 ] !== undefined && parseFloat( match[ 4 ] ) === 0 ) {
		return '';
	}
	return (
		'#' +
		[ match[ 1 ], match[ 2 ], match[ 3 ] ]
			.map( ( part ) =>
				Math.min( 255, Number( part ) )
					.toString( 16 )
					.padStart( 2, '0' )
			)
			.join( '' )
	);
}

/**
 * The site's brand color: a named palette color, the most colorful palette
 * color, or (classic themes) the button or link color of the home page.
 *
 * @param palette Theme palette.
 * @param sampled Styles from the home page.
 * @return Hex color, or null when the site has no clear brand color.
 */
export function pickBrandColor(
	palette: SiteBrandInfo[ 'palette' ],
	sampled: SampledStyles | null
): string | null {
	for ( const slug of BRAND_SLUGS ) {
		const entry = palette.find( ( item ) => item.slug === slug );
		if ( entry && chromatic( entry.color ) ) {
			return normalizeHex( entry.color );
		}
	}
	const colorful = palette
		.filter( ( item ) => chromatic( item.color ) )
		.sort( ( a, b ) => toOklch( b.color ).c - toOklch( a.color ).c )[ 0 ];
	if ( colorful ) {
		return normalizeHex( colorful.color );
	}
	for ( const color of [ sampled?.button, sampled?.link ] ) {
		if ( color && chromatic( color ) ) {
			return normalizeHex( color );
		}
	}
	return null;
}

/**
 * The proposal and a list of what changes.
 *
 * @param theme   Current theme.
 * @param brand   Site branding.
 * @param sampled Styles from the home page.
 * @return Proposed theme, change descriptions, and whether a brand color was found.
 */
export function matchSite(
	theme: Theme,
	brand: SiteBrandInfo,
	sampled: SampledStyles | null
): { theme: Theme; changes: string[]; colorFound: boolean } {
	const changes: string[] = [];
	let next = theme;

	const color = pickBrandColor( brand.palette, sampled );
	if ( color ) {
		next = withBrandColor( next, color );
		changes.push(
			sprintf(
				/* translators: %s: Hex color. */
				__(
					'Colors built from your site’s brand color %s',
					'chatlume-ai-chatbot'
				),
				color
			)
		);
	}

	if ( brand.site_icon ) {
		next = {
			...next,
			brand: {
				...next.brand,
				avatarType: 'image',
				avatarUrl: brand.site_icon,
			},
		};
		changes.push(
			__( 'Your site icon as the avatar', 'chatlume-ai-chatbot' )
		);
	}

	if ( brand.logo ) {
		next = {
			...next,
			brand: { ...next.brand, logoUrl: brand.logo, showLogo: true },
		};
		changes.push( __( 'Your logo in the header', 'chatlume-ai-chatbot' ) );
	}

	const font = brand.fonts[ 0 ] ?? 'inherit';
	next = {
		...next,
		typography: { ...next.typography, bodyFont: font, headingFont: 'same' },
	};
	changes.push(
		brand.fonts[ 0 ]
			? __( 'Your theme’s font', 'chatlume-ai-chatbot' )
			: __( 'The same font as your site', 'chatlume-ai-chatbot' )
	);

	return { theme: next, changes, colorFound: color !== null };
}
