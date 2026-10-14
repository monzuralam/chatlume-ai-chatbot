import { cn } from '../../src/admin/lib/utils';

describe( 'cn', () => {
	it( 'joins class names and drops falsy values', () => {
		expect(
			cn( 'chatlume:flex', false, undefined, 'chatlume:gap-2' )
		).toBe( 'chatlume:flex chatlume:gap-2' );
	} );

	it( 'resolves conflicting prefixed utilities (last one wins)', () => {
		expect( cn( 'chatlume:px-4', 'chatlume:px-6' ) ).toBe(
			'chatlume:px-6'
		);
		expect(
			cn( 'chatlume:hover:bg-surface', 'chatlume:hover:bg-accent' )
		).toBe( 'chatlume:hover:bg-accent' );
	} );

	it( 'keeps utilities that do not conflict', () => {
		expect( cn( 'chatlume:text-sm', 'chatlume:text-fg' ) ).toBe(
			'chatlume:text-sm chatlume:text-fg'
		);
	} );
} );
