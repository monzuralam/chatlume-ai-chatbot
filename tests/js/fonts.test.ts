/**
 * Fonts: catalog lookups, faces sent to the widget, the script note, and
 * document-level registration in the widget.
 */
import defaults from '../fixtures/theme-defaults.json';
import { lacksScript } from '../../src/admin/lib/appearance';
import { resolveFont, resolveTheme } from '../../src/admin/lib/theme/resolve';
import type { FontOption, Theme } from '../../src/admin/lib/theme/types';
import { loadFonts } from '../../src/widget/lib/fonts';

const theme = defaults as unknown as Theme;

const face = ( family: string, file: string ) => ( {
	family,
	src: `https://example.test/fonts/${ file }`,
	weight: '100 900',
	style: 'normal',
	unicodeRange: 'U+0000-00FF',
} );

const catalog: FontOption[] = [
	{
		key: 'system',
		label: 'System',
		group: 'basic',
		stack: 'system-ui, sans-serif',
		faces: [],
		scripts: null,
	},
	{
		key: 'bundled:inter',
		label: 'Inter',
		group: 'bundled',
		stack: '"Chatlume Inter", system-ui, sans-serif',
		faces: [
			face( 'Chatlume Inter', 'inter-latin.woff2' ),
			face( 'Chatlume Inter', 'inter-greek.woff2' ),
		],
		scripts: [ 'latin', 'greek' ],
	},
	{
		key: 'bundled:lora',
		label: 'Lora',
		group: 'bundled',
		stack: '"Chatlume Lora", ui-serif, serif',
		faces: [ face( 'Chatlume Lora', 'lora-latin.woff2' ) ],
		scripts: [ 'latin' ],
	},
];

describe( 'resolveFont', () => {
	it( 'uses the catalog, falling back to the system stack', () => {
		expect( resolveFont( 'bundled:lora', catalog ).stack ).toBe(
			'"Chatlume Lora", ui-serif, serif'
		);
		expect( resolveFont( 'custom:gone', catalog ) ).toEqual( {
			stack: 'system-ui, sans-serif',
			faces: [],
		} );
		expect( resolveFont( 'inherit', [] ).stack ).toBe( 'inherit' );
	} );
} );

describe( 'resolveTheme fonts', () => {
	it( 'sends only the faces of fonts in use, once each', () => {
		const config = resolveTheme(
			{
				...theme,
				typography: {
					...theme.typography,
					bodyFont: 'bundled:inter',
					headingFont: 'bundled:inter',
				},
			},
			catalog
		);
		expect( config.vars[ '--chatlume-font-heading' ] ).toBe(
			'"Chatlume Inter", system-ui, sans-serif'
		);
		expect( config.fonts ).toHaveLength( 2 );

		const mixed = resolveTheme(
			{
				...theme,
				typography: {
					...theme.typography,
					bodyFont: 'bundled:inter',
					headingFont: 'bundled:lora',
				},
			},
			catalog
		);
		expect( mixed.fonts?.map( ( item ) => item.family ) ).toEqual( [
			'Chatlume Inter',
			'Chatlume Inter',
			'Chatlume Lora',
		] );
		expect( resolveTheme( theme, catalog ).fonts ).toEqual( [] );
	} );
} );

describe( 'lacksScript', () => {
	it( 'notes fonts without the site’s letters', () => {
		expect( lacksScript( catalog[ 1 ], 'bengali' ) ).toBe( true );
		expect( lacksScript( catalog[ 1 ], 'greek' ) ).toBe( false );
		expect( lacksScript( catalog[ 0 ], 'bengali' ) ).toBe( false );
		expect( lacksScript( undefined, 'bengali' ) ).toBe( false );
	} );
} );

describe( 'loadFonts', () => {
	const added: Array< {
		family: string;
		source: string;
		descriptors: unknown;
	} > = [];

	beforeEach( () => {
		added.length = 0;
		( window as unknown as { FontFace: unknown } ).FontFace = class {
			constructor(
				public family: string,
				public source: string,
				public descriptors: unknown
			) {}
		};
		Object.defineProperty( document, 'fonts', {
			configurable: true,
			value: {
				add: ( item: {
					family: string;
					source: string;
					descriptors: unknown;
				} ) => added.push( item ),
			},
		} );
	} );

	it( 'registers each face once at document level, lazily (swap)', () => {
		const faces = catalog[ 1 ].faces;
		expect( loadFonts( faces ) ).toBe( 2 );
		expect( loadFonts( faces ) ).toBe( 0 );
		expect( added[ 0 ] ).toEqual( {
			family: 'Chatlume Inter',
			source: 'url("https://example.test/fonts/inter-latin.woff2") format("woff2")',
			descriptors: {
				weight: '100 900',
				style: 'normal',
				display: 'swap',
				unicodeRange: 'U+0000-00FF',
			},
		} );
	} );

	it( 'skips unsafe sources', () => {
		expect(
			loadFonts( [
				{ ...face( 'Evil', 'x.woff2' ), src: 'javascript:alert(1)' },
				{ ...face( '', 'y.woff2' ) },
			] )
		).toBe( 0 );
		expect( loadFonts( undefined ) ).toBe( 0 );
	} );
} );
