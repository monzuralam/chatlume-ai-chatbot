/**
 * Token stylesheet, URLs, message restore, and string formatting.
 */
import { format } from '../../src/widget/app/Composer';
import { restoreMessages } from '../../src/widget/app/useChat';
import { cssValue, safeUrl, themeCss } from '../../src/widget/lib/theme';
import type { ThemeConfig } from '../../src/widget/types';

describe( 'themeCss', () => {
	it( 'declares light tokens on the host and dark ones by attribute', () => {
		const css = themeCss( {
			light: { '--chatlume-primary': '#111111' },
			dark: { '--chatlume-primary': '#eeeeee' },
			vars: {
				'--chatlume-width': '380px',
				'not-a-token': 'x',
				'--chatlume-evil': 'red;}body{display:none',
			},
		} as unknown as ThemeConfig );

		expect( css ).toBe(
			':host{--chatlume-width:380px;--chatlume-evil:redbodydisplay:none;--chatlume-primary:#111111}' +
				':host([data-mode="dark"]){--chatlume-primary:#eeeeee}'
		);
	} );

	it( 'strips characters that could end a rule', () => {
		expect( cssValue( 'a;b{c}<d>\\e\nf' ) ).toBe( 'abcdef' );
	} );
} );

describe( 'safeUrl', () => {
	it( 'allows http(s) only', () => {
		expect( safeUrl( 'https://cdn.test/a.png' ) ).toBe(
			'https://cdn.test/a.png'
		);
		expect( safeUrl( 'javascript:alert(1)' ) ).toBe( '' );
		expect( safeUrl( 42 ) ).toBe( '' );
	} );
} );

describe( 'restoreMessages', () => {
	it( 'drops malformed entries and stops unfinished replies', () => {
		expect(
			restoreMessages( [
				{ id: 'a', role: 'user', text: 'Hi', time: 1, status: 'done' },
				{
					id: 'b',
					role: 'bot',
					text: 'Hal',
					time: 2,
					status: 'streaming',
				},
				{ id: 'c', role: 'system', text: 'x' },
				null,
				'junk',
			] )
		).toEqual( [
			{ id: 'a', role: 'user', text: 'Hi', time: 1, status: 'done' },
			{ id: 'b', role: 'bot', text: 'Hal', time: 2, status: 'stopped' },
		] );
		expect( restoreMessages( 'nope' ) ).toEqual( [] );
	} );
} );

describe( 'format', () => {
	it( 'fills numbered and plain placeholders', () => {
		expect( format( '%1$d / %2$d', 3, 10 ) ).toBe( '3 / 10' );
		expect( format( '%2$d of %1$d', 3, 10 ) ).toBe( '10 of 3' );
		expect( format( '%s left', 'two' ) ).toBe( 'two left' );
	} );
} );
