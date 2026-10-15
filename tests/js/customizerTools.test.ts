/**
 * Customizer tools: "Match my website", theme files, and developer CSS.
 */
import defaults from '../fixtures/theme-defaults.json';
import { parseThemeFile, themeFile } from '../../src/admin/lib/appearance';
import { contrastIssues } from '../../src/admin/lib/theme/contrast';
import { exampleCss, tokensCss } from '../../src/admin/lib/theme/devcss';
import {
	cssColorToHex,
	matchSite,
	pickBrandColor,
} from '../../src/admin/lib/theme/match';
import { resolveTheme } from '../../src/admin/lib/theme/resolve';
import type { Theme } from '../../src/admin/lib/theme/types';

const theme = defaults as unknown as Theme;

// Twenty Twenty-One's palette: only pale and gray colors.
const pastel = [
	{ slug: 'black', name: 'Black', color: '#000000' },
	{ slug: 'dark-gray', name: 'Dark gray', color: '#28303d' },
	{ slug: 'green', name: 'Green', color: '#d1e4dd' },
	{ slug: 'white', name: 'White', color: '#ffffff' },
];

const brand = {
	site_name: 'Shop',
	site_icon: 'https://example.test/icon.png',
	logo: '',
	palette: pastel,
	fonts: [],
	block_theme: false,
};

describe( 'cssColorToHex', () => {
	it( 'reads computed colors', () => {
		expect( cssColorToHex( 'rgb(30, 64, 175)' ) ).toBe( '#1e40af' );
		expect( cssColorToHex( 'rgba(30, 64, 175, 0.5)' ) ).toBe( '#1e40af' );
		expect( cssColorToHex( 'rgba(0, 0, 0, 0)' ) ).toBe( '' );
		expect( cssColorToHex( 'transparent' ) ).toBe( '' );
	} );
} );

describe( 'pickBrandColor', () => {
	it( 'prefers a named, colorful palette color', () => {
		expect(
			pickBrandColor(
				[
					...pastel,
					{ slug: 'primary', name: 'Primary', color: '#1E40AF' },
				],
				null
			)
		).toBe( '#1e40af' );
	} );

	it( 'falls back to the home page’s button, then link', () => {
		expect( pickBrandColor( pastel, null ) ).toBeNull();
		expect(
			pickBrandColor( pastel, {
				button: '#28303d',
				link: '#c2185b',
				text: '#28303d',
			} )
		).toBe( '#c2185b' );
		expect(
			pickBrandColor( pastel, {
				button: '#0f766e',
				link: '#c2185b',
				text: '#000000',
			} )
		).toBe( '#0f766e' );
	} );
} );

describe( 'matchSite', () => {
	it( 'builds a readable proposal and lists the changes', () => {
		const result = matchSite( theme, brand, {
			button: '#0f766e',
			link: '',
			text: '#000000',
		} );
		expect( result.theme.colors.light.primary ).toBe( '#0f766e' );
		expect( result.theme.brand.avatarType ).toBe( 'image' );
		expect( result.theme.brand.avatarUrl ).toBe(
			'https://example.test/icon.png'
		);
		expect( result.theme.typography.bodyFont ).toBe( 'inherit' );
		expect( contrastIssues( result.theme ) ).toEqual( [] );
		expect( result.changes ).toHaveLength( 3 );
		expect( result.colorFound ).toBe( true );
	} );

	it( 'keeps the colors when the site has no brand color', () => {
		const result = matchSite(
			theme,
			{ ...brand, site_icon: '', fonts: [ 'theme:body' ] },
			null
		);
		expect( result.theme.colors ).toEqual( theme.colors );
		expect( result.theme.typography.bodyFont ).toBe( 'theme:body' );
	} );
} );

describe( 'theme files', () => {
	it( 'round-trips and rejects other JSON', () => {
		expect( parseThemeFile( themeFile( theme ) ) ).toEqual( theme );
		expect( () => parseThemeFile( '{"settings": {}}' ) ).toThrow();
		expect( () => parseThemeFile( 'not json' ) ).toThrow();
	} );
} );

describe( 'developer CSS', () => {
	it( 'writes the tokens for chatlume-widget in light and dark mode', () => {
		const css = tokensCss( resolveTheme( theme ) );
		expect( css.startsWith( 'chatlume-widget {\n' ) ).toBe( true );
		expect( css ).toContain( '\t--chatlume-font-size: 15px;' );
		expect( css ).toContain( '\t--chatlume-primary: #4f46e5;' );
		expect( css ).toContain(
			'chatlume-widget[data-mode="dark"] {\n\t--chatlume-primary: #8b8cf8;'
		);
		expect( exampleCss( resolveTheme( theme ) ) ).toContain(
			'chatlume-widget::part(launcher)'
		);
	} );
} );
