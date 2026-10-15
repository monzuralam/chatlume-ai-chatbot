/**
 * Customizer theme math: resolver parity with PHP, OKLCH, contrast, and
 * palettes and presets that always pass WCAG AA.
 */
import defaults from '../fixtures/theme-defaults.json';
import resolved from '../fixtures/theme-resolved.json';
import {
	contrast,
	fixContrast,
	fromOklch,
	generatePalette,
	normalizeHex,
	toOklch,
} from '../../src/admin/lib/theme/color';
import { contrastIssues, fixAll } from '../../src/admin/lib/theme/contrast';
import {
	applyPreset,
	brandColor,
	themePresets,
	withBrandColor,
} from '../../src/admin/lib/theme/presets';
import { resolveTheme } from '../../src/admin/lib/theme/resolve';
import type { Theme } from '../../src/admin/lib/theme/types';

const theme = defaults as unknown as Theme;

describe( 'resolveTheme', () => {
	it( 'matches the PHP TokenResolver exactly', () => {
		const { v, ...config } = resolveTheme( theme );
		expect( v ).toBe( 'draft' );
		expect( config ).toEqual( resolved );
	} );

	it( 'builds the gradient header and minimal header inks', () => {
		const gradient = resolveTheme( {
			...theme,
			header: { style: 'gradient', gradientAngle: 90 },
		} );
		expect( gradient.light[ '--chatlume-header-fill' ] ).toBe(
			'linear-gradient(90deg, #4f46e5, #7c3aed)'
		);
		const minimal = resolveTheme( {
			...theme,
			header: { style: 'minimal', gradientAngle: 90 },
		} );
		expect( minimal.light[ '--chatlume-header-ink' ] ).toBe( '#1f2330' );
	} );
} );

describe( 'color math', () => {
	it( 'round-trips through OKLCH', () => {
		for ( const hex of [
			'#4f46e5',
			'#ffffff',
			'#000000',
			'#15803d',
			'#facc15',
		] ) {
			expect( fromOklch( toOklch( hex ) ) ).toBe( hex );
		}
		expect( normalizeHex( '#ABC' ) ).toBe( '#aabbcc' );
		expect( normalizeHex( '#11223344' ) ).toBe( '#112233' );
		expect( normalizeHex( 'red' ) ).toBe( '' );
	} );

	it( 'measures WCAG contrast', () => {
		expect( contrast( '#ffffff', '#000000' ) ).toBeCloseTo( 21, 5 );
		expect( contrast( '#767676', '#ffffff' ) ).toBeCloseTo( 4.54, 2 );
	} );

	it( 'fixes contrast by changing lightness only', () => {
		const fixed = fixContrast( '#9ca3af', '#ffffff', 4.5 );
		expect( contrast( fixed, '#ffffff' ) ).toBeGreaterThanOrEqual( 4.5 );
		expect(
			Math.abs( toOklch( fixed ).h - toOklch( '#9ca3af' ).h )
		).toBeLessThan( 6 );
		expect( fixContrast( '#000000', '#ffffff' ) ).toBe( '#000000' );
		const onDark = fixContrast( '#334155', '#0f172a', 4.5 );
		expect( contrast( onDark, '#0f172a' ) ).toBeGreaterThanOrEqual( 4.5 );
	} );
} );

describe( 'palettes', () => {
	const brands = [
		'#4f46e5',
		'#facc15',
		'#e0f2fe',
		'#0b0b0b',
		'#ffffff',
		'#ef4444',
		'#22c55e',
		'#06b6d4',
		'#a855f7',
		'#f97316',
		'#84cc16',
		'#808080',
	];

	it.each( brands )( 'from %s passes AA in light and dark', ( brand ) => {
		const result = withBrandColor( theme, brand );
		expect( contrastIssues( result ) ).toEqual( [] );
		expect(
			contrastIssues( {
				...result,
				header: { style: 'gradient', gradientAngle: 135 },
			} )
		).toEqual( [] );
	} );

	it( 'keeps a brand color that already works', () => {
		expect( generatePalette( '#4f46e5' ).light.primary ).toBe( '#4f46e5' );
		expect( brandColor( withBrandColor( theme, '#1e3a8a' ) ) ).toBe(
			'#1e3a8a'
		);
	} );

	it( 'the default theme passes AA', () => {
		expect( contrastIssues( theme ) ).toEqual( [] );
	} );

	it( 'fixes every issue at once', () => {
		const broken: Theme = {
			...theme,
			colors: {
				...theme.colors,
				light: {
					...theme.colors.light,
					botText: '#cccccc',
					link: '#dddddd',
				},
			},
		};
		expect( contrastIssues( broken ).length ).toBeGreaterThan( 0 );
		expect( contrastIssues( fixAll( broken ) ) ).toEqual( [] );
	} );
} );

describe( 'presets', () => {
	const presets = themePresets();

	it( 'offers at least ten', () => {
		expect( presets.length ).toBeGreaterThanOrEqual( 10 );
		expect( new Set( presets.map( ( preset ) => preset.id ) ).size ).toBe(
			presets.length
		);
	} );

	it.each( presets.map( ( preset ) => [ preset.id, preset ] as const ) )(
		'%s passes AA in light and dark, with and without my brand',
		( _id, preset ) => {
			expect( contrastIssues( applyPreset( theme, preset ) ) ).toEqual(
				[]
			);
			const mine = withBrandColor( theme, '#facc15' );
			const kept = applyPreset( mine, preset, true );
			expect( contrastIssues( kept ) ).toEqual( [] );
		}
	);

	it( 'keeps the admin’s texts and images', () => {
		const custom: Theme = {
			...theme,
			brand: {
				...theme.brand,
				headerTitle: 'Ava',
				avatarUrl: 'https://example.test/a.png',
			},
			launcher: { ...theme.launcher, label: 'Chat with us' },
		};
		const applied = applyPreset( custom, presets[ 1 ] );
		expect( applied.brand.headerTitle ).toBe( 'Ava' );
		expect( applied.brand.avatarUrl ).toBe( 'https://example.test/a.png' );
		expect( applied.launcher.label ).toBe( 'Chat with us' );
		expect( applied.mode ).toBe( 'dark' );
	} );
} );
