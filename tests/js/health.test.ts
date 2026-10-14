import { summarizeHealth } from '../../src/admin/components/layout/useSystemHealth';
import { isSaveShortcut } from '../../src/admin/hooks/useSaveShortcut';
import type { SystemStatus } from '../../src/admin/lib/bootstrap';

const base: SystemStatus = {
	wpVersion: '7.1',
	phpVersion: '8.2.0',
	dbVersion: 1,
	dbVersionExpected: 1,
	fulltext: true,
	encryption: 'ok',
	isMultisite: false,
	features: {
		abilitiesApi: true,
		aiClient: true,
		connectors: true,
		fontLibrary: true,
		commandPalette: true,
	},
};

describe( 'summarizeHealth', () => {
	it( 'is ready when everything works', () => {
		expect( summarizeHealth( base ).ok ).toBe( true );
	} );

	it( 'flags rotated salts, missing sodium, and pending upgrades', () => {
		expect(
			summarizeHealth( { ...base, encryption: 'key_changed' } ).ok
		).toBe( false );
		expect(
			summarizeHealth( { ...base, encryption: 'unavailable' } ).ok
		).toBe( false );
		expect( summarizeHealth( { ...base, dbVersion: 0 } ).ok ).toBe( false );
	} );

	it( 'does not treat a missing FULLTEXT index as a problem', () => {
		expect( summarizeHealth( { ...base, fulltext: false } ).ok ).toBe(
			true
		);
	} );
} );

describe( 'isSaveShortcut', () => {
	const key = ( overrides: Partial< KeyboardEvent > ) => ( {
		key: 's',
		metaKey: false,
		ctrlKey: false,
		altKey: false,
		...overrides,
	} );

	it( 'detects Cmd+S and Ctrl+S', () => {
		expect( isSaveShortcut( key( { metaKey: true } ) ) ).toBe( true );
		expect( isSaveShortcut( key( { ctrlKey: true, key: 'S' } ) ) ).toBe(
			true
		);
	} );

	it( 'ignores other combinations', () => {
		expect( isSaveShortcut( key( {} ) ) ).toBe( false );
		expect( isSaveShortcut( key( { ctrlKey: true, altKey: true } ) ) ).toBe(
			false
		);
		expect( isSaveShortcut( key( { ctrlKey: true, key: 'a' } ) ) ).toBe(
			false
		);
	} );
} );
