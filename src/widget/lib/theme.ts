/**
 * Design tokens → CSS for the shadow root.
 *
 * Tokens are declared on `:host`, so a site's own CSS (for example
 * `chatlume-widget { --chatlume-primary: #c00 }` in Additional CSS) still
 * overrides them: outer styles beat `:host` rules. Light and dark sets are
 * swapped with the host's `data-mode` attribute, with no re-render.
 */
import type { ColorMode, ThemeConfig } from '../types';

const TOKEN_NAME = /^--chatlume-[a-z0-9-]+$/;

/**
 * Keeps a token value from closing its declaration or rule.
 *
 * @param value Raw value.
 * @return Safe value.
 */
export function cssValue( value: unknown ): string {
	return String( value ?? '' )
		.replace( /[;{}<>\\\n\r]/g, '' )
		.trim();
}

/**
 * Declarations for a token map.
 *
 * @param tokens Token map.
 * @return Declarations.
 */
function declarations( tokens: Record< string, string > | undefined ): string {
	return Object.entries( tokens ?? {} )
		.filter( ( [ name ] ) => TOKEN_NAME.test( name ) )
		.map( ( [ name, value ] ) => `${ name }:${ cssValue( value ) }` )
		.join( ';' );
}

/**
 * The token stylesheet for a theme.
 *
 * @param theme Resolved theme.
 * @return CSS text.
 */
export function themeCss( theme: ThemeConfig ): string {
	return (
		`:host{${ declarations( theme.vars ) };${ declarations(
			theme.light
		) }}` + `:host([data-mode="dark"]){${ declarations( theme.dark ) }}`
	);
}

/**
 * Light or dark, following the visitor's system setting in auto mode.
 *
 * @param mode Theme mode.
 * @return Resolved mode.
 */
export function resolveMode( mode: ColorMode ): 'light' | 'dark' {
	if ( mode === 'auto' ) {
		return typeof window.matchMedia === 'function' &&
			window.matchMedia( '(prefers-color-scheme: dark)' ).matches
			? 'dark'
			: 'light';
	}
	return mode === 'dark' ? 'dark' : 'light';
}

/**
 * An http(s) URL, or '' (images and links from configuration).
 *
 * @param url Raw URL.
 * @return Safe URL.
 */
export function safeUrl( url: unknown ): string {
	if ( typeof url !== 'string' || url === '' ) {
		return '';
	}
	try {
		const parsed = new URL( url, window.location.href );
		return parsed.protocol === 'http:' || parsed.protocol === 'https:'
			? parsed.href
			: '';
	} catch {
		return '';
	}
}

/**
 * Text direction of the page.
 *
 * @return Direction.
 */
export function pageDirection(): 'ltr' | 'rtl' {
	const root = document.documentElement;
	const dir =
		root.getAttribute( 'dir' ) ||
		document.body?.getAttribute( 'dir' ) ||
		window.getComputedStyle( root ).direction;
	return dir === 'rtl' ? 'rtl' : 'ltr';
}
