/**
 * Allowlist Markdown sanitizer.
 */
import {
	markdownText,
	parseInline,
	parseMarkdown,
	parseRichText,
	safeHref,
} from '../../src/widget/lib/markdown';

describe( 'safeHref', () => {
	it( 'accepts web, mail, and phone links only', () => {
		expect( safeHref( 'https://example.test/a b' ) ).toBe(
			'https://example.test/a%20b'
		);
		expect( safeHref( 'mailto:help@example.test' ) ).toBe(
			'mailto:help@example.test'
		);
		expect( safeHref( 'tel:+441234' ) ).toBe( 'tel:+441234' );
		expect( safeHref( 'javascript:alert(1)' ) ).toBeNull();
		expect( safeHref( 'JaVaScRiPt:alert(1)' ) ).toBeNull();
		expect( safeHref( 'data:text/html,<script>' ) ).toBeNull();
		expect( safeHref( '//evil.test' ) ).toBeNull();
		expect( safeHref( '/relative' ) ).toBeNull();
	} );
} );

describe( 'parseInline', () => {
	it( 'parses bold, italic, code, and links', () => {
		expect(
			parseInline(
				'**Bold** and *it* with `x<y>` [Docs](https://d.test)'
			)
		).toEqual( [
			{ type: 'strong', children: [ { type: 'text', text: 'Bold' } ] },
			{ type: 'text', text: ' and ' },
			{ type: 'em', children: [ { type: 'text', text: 'it' } ] },
			{ type: 'text', text: ' with ' },
			{ type: 'code', text: 'x<y>' },
			{ type: 'text', text: ' ' },
			{
				type: 'link',
				href: 'https://d.test/',
				children: [ { type: 'text', text: 'Docs' } ],
			},
		] );
	} );

	it( 'keeps HTML as visible text', () => {
		expect( parseInline( '<img src=x onerror=alert(1)>' ) ).toEqual( [
			{ type: 'text', text: '<img src=x onerror=alert(1)>' },
		] );
	} );

	it( 'drops unsafe link targets but keeps the label', () => {
		const nodes = parseInline( '[click](javascript:void)' );
		expect( nodes ).toEqual( [ { type: 'text', text: 'click' } ] );
	} );

	it( 'leaves unfinished syntax (streaming) as text', () => {
		expect( parseInline( '**half' ) ).toEqual( [
			{ type: 'text', text: '**half' },
		] );
		expect( parseInline( 'use `code' ) ).toEqual( [
			{ type: 'text', text: 'use `code' },
		] );
	} );

	it( 'does not italicize snake_case words', () => {
		expect( parseInline( 'my_var_name' ) ).toEqual( [
			{ type: 'text', text: 'my_var_name' },
		] );
	} );

	it( 'links bare URLs without trailing punctuation', () => {
		expect( parseInline( 'See https://example.test/page.' ) ).toEqual( [
			{ type: 'text', text: 'See ' },
			{
				type: 'link',
				href: 'https://example.test/page',
				children: [
					{ type: 'text', text: 'https://example.test/page' },
				],
			},
			{ type: 'text', text: '.' },
		] );
	} );

	it( 'turns newlines into line breaks and honors escapes', () => {
		expect( parseInline( 'a\n\\*b\\*' ) ).toEqual( [
			{ type: 'text', text: 'a' },
			{ type: 'br' },
			{ type: 'text', text: '*b*' },
		] );
	} );
} );

describe( 'parseMarkdown', () => {
	it( 'parses blocks', () => {
		const blocks = parseMarkdown(
			[
				'# Title',
				'',
				'Intro line',
				'second',
				'',
				'- one',
				'- two',
				'  more',
				'',
				'3. three',
				'4. four',
				'',
				'> quote',
				'',
				'---',
				'',
				'```js',
				'const a = "<b>";',
				'```',
			].join( '\n' )
		);
		expect( blocks.map( ( block ) => block.type ) ).toEqual( [
			'heading',
			'paragraph',
			'list',
			'list',
			'quote',
			'hr',
			'code',
		] );
		expect( blocks[ 2 ] ).toMatchObject( {
			ordered: false,
			items: [
				[ { type: 'text', text: 'one' } ],
				[
					{ type: 'text', text: 'two' },
					{ type: 'br' },
					{ type: 'text', text: 'more' },
				],
			],
		} );
		expect( blocks[ 3 ] ).toMatchObject( { ordered: true, start: 3 } );
		expect( blocks[ 6 ] ).toEqual( {
			type: 'code',
			lang: 'js',
			text: 'const a = "<b>";',
		} );
	} );

	it( 'renders an unclosed code fence as code (streaming)', () => {
		expect( parseMarkdown( '```\nline 1\nline 2' ) ).toEqual( [
			{ type: 'code', lang: '', text: 'line 1\nline 2' },
		] );
	} );

	it( 'gives plain text for copying', () => {
		expect( markdownText( '**Hi** there\n\n1. a\n2. b' ) ).toBe(
			'Hi there\n\n1. a\n2. b'
		);
	} );
} );

describe( 'parseRichText', () => {
	it( 'keeps only bold, italics, safe links, and breaks', () => {
		expect(
			parseRichText(
				'Hi <strong>there</strong><br><a href="javascript:x">bad</a> <a href="https://ok.test">ok</a><script>alert(1)</script><img src=x onerror=alert(1)>'
			)
		).toEqual( [
			{ type: 'text', text: 'Hi ' },
			{ type: 'strong', children: [ { type: 'text', text: 'there' } ] },
			{ type: 'br' },
			{ type: 'text', text: 'bad' },
			{ type: 'text', text: ' ' },
			{
				type: 'link',
				href: 'https://ok.test/',
				children: [ { type: 'text', text: 'ok' } ],
			},
		] );
	} );
} );
