import {
	nextColorMode,
	resolveColorMode,
	toColorMode,
} from '../../src/admin/lib/colorMode';

describe( 'color mode', () => {
	it( 'resolves "system" from the OS preference', () => {
		expect( resolveColorMode( 'system', true ) ).toBe( 'dark' );
		expect( resolveColorMode( 'system', false ) ).toBe( 'light' );
	} );

	it( 'keeps explicit choices regardless of the OS', () => {
		expect( resolveColorMode( 'light', true ) ).toBe( 'light' );
		expect( resolveColorMode( 'dark', false ) ).toBe( 'dark' );
	} );

	it( 'cycles system → light → dark → system', () => {
		expect( nextColorMode( 'system' ) ).toBe( 'light' );
		expect( nextColorMode( 'light' ) ).toBe( 'dark' );
		expect( nextColorMode( 'dark' ) ).toBe( 'system' );
	} );

	it( 'falls back to "system" for unknown values', () => {
		expect( toColorMode( 'dark' ) ).toBe( 'dark' );
		expect( toColorMode( 'purple' ) ).toBe( 'system' );
		expect( toColorMode( undefined ) ).toBe( 'system' );
	} );
} );
