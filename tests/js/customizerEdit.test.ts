/**
 * Customizer editing: the control-to-state mapping and undo/redo.
 */
import defaults from '../fixtures/theme-defaults.json';
import {
	record,
	redo,
	undo,
	type History,
} from '../../src/admin/lib/appearance';
import {
	getPath,
	resetSection,
	SECTION_KEYS,
	setPath,
} from '../../src/admin/lib/theme/edit';
import type { Theme } from '../../src/admin/lib/theme/types';

const theme = defaults as unknown as Theme;

const width = ( value: number ) => setPath( theme, 'layout.width', value );

describe( 'control-to-state mapping', () => {
	it( 'writes one value by path without touching anything else', () => {
		const next = setPath( theme, 'colors.light.primary', '#123456' );

		expect( getPath( next, 'colors.light.primary' ) ).toBe( '#123456' );
		expect( getPath( theme, 'colors.light.primary' ) ).toBe( '#4f46e5' );
		expect( next.colors.dark ).toBe( theme.colors.dark );
		expect( next.layout ).toBe( theme.layout );
		expect( next ).not.toBe( theme );
	} );

	it( 'creates missing branches and reads missing paths as undefined', () => {
		const next = setPath( theme, 'brand.extra.deep', 1 );
		expect( getPath( next, 'brand.extra.deep' ) ).toBe( 1 );
		expect( getPath( theme, 'nope.not.here' ) ).toBeUndefined();
	} );

	it( 'resets one panel section from the defaults', () => {
		const changed = setPath(
			setPath( theme, 'launcher.icon', 'heart' ),
			'layout.width',
			480
		);
		const reset = resetSection( changed, theme, 'launcher' );

		expect( reset.launcher ).toEqual( theme.launcher );
		expect( reset.launcher ).not.toBe( theme.launcher );
		expect( getPath( reset, 'layout.width' ) ).toBe( 480 );
		expect( resetSection( changed, theme, 'unknown' ) ).toEqual( changed );
	} );

	it( 'covers every panel section with real theme keys', () => {
		for ( const keys of Object.values( SECTION_KEYS ) ) {
			for ( const key of keys ) {
				expect( theme ).toHaveProperty( key );
			}
		}
	} );
} );

describe( 'undo and redo', () => {
	const start: History = { past: [], present: theme, future: [] };

	it( 'steps back and forward', () => {
		let history = record( start, width( 400 ) );
		history = record( history, width( 420 ) );

		history = undo( history );
		expect( getPath( history.present, 'layout.width' ) ).toBe( 400 );
		history = undo( history );
		expect( history.present ).toBe( theme );
		expect( undo( history ) ).toBe( history );

		history = redo( history );
		expect( getPath( history.present, 'layout.width' ) ).toBe( 400 );
		history = redo( history );
		expect( getPath( history.present, 'layout.width' ) ).toBe( 420 );
		expect( redo( history ) ).toBe( history );
	} );

	it( 'groups a gesture into one step and ignores no-op changes', () => {
		let history = record( start, width( 401 ) );
		history = record( history, width( 402 ), true );
		history = record( history, width( 403 ), true );

		expect( history.past ).toHaveLength( 1 );
		expect( record( history, width( 403 ) ) ).toBe( history );
		expect( undo( history ).present ).toBe( theme );
	} );

	it( 'a new change clears the redo steps', () => {
		let history = undo( record( start, width( 400 ) ) );
		expect( history.future ).toHaveLength( 1 );
		history = record( history, width( 500 ) );
		expect( history.future ).toHaveLength( 0 );
	} );

	it( 'keeps the last 100 steps', () => {
		let history = start;
		for ( let i = 1; i <= 120; i++ ) {
			history = record( history, width( 300 + i ) );
		}
		expect( history.past ).toHaveLength( 100 );
		expect( getPath( history.past[ 0 ], 'layout.width' ) ).toBe( 320 );
	} );
} );
