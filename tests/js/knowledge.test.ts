/**
 * Knowledge page helpers.
 */
import {
	extensionOf,
	formatBytes,
	parseCsv,
	progressPercent,
	quickAnswersFromCsv,
	quickAnswersToCsv,
	sourceTypeLabel,
	uploadProblem,
} from '../../src/admin/lib/knowledge';

const BOM = String.fromCharCode( 0xfeff );

describe( 'parseCsv', () => {
	it( 'reads quoted cells with separators, quotes, and line breaks', () => {
		expect(
			parseCsv( 'a,b\r\n"one, two","say ""hi""\nthere"\n\n' )
		).toEqual( [
			[ 'a', 'b' ],
			[ 'one, two', 'say "hi"\nthere' ],
		] );
	} );

	it( 'detects semicolons and tabs, and skips a byte order mark', () => {
		expect( parseCsv( `${ BOM }q;a\nx;y` ) ).toEqual( [
			[ 'q', 'a' ],
			[ 'x', 'y' ],
		] );
		expect( parseCsv( 'q\ta\nx\ty' ) ).toEqual( [
			[ 'q', 'a' ],
			[ 'x', 'y' ],
		] );
	} );
} );

describe( 'quickAnswersFromCsv', () => {
	it( 'matches columns by header in any order', () => {
		expect(
			quickAnswersFromCsv(
				'Answer,Question,Language,Other\nNine to five.,When are you open?,en,x\n'
			)
		).toEqual( [
			{
				answer: 'Nine to five.',
				question: 'When are you open?',
				language: 'en',
			},
		] );
	} );

	it( 'uses question and answer order without a header', () => {
		expect(
			quickAnswersFromCsv( 'Do you ship?,Yes.\nগিফট?,হ্যাঁ।' )
		).toEqual( [
			{ question: 'Do you ship?', answer: 'Yes.' },
			{ question: 'গিফট?', answer: 'হ্যাঁ।' },
		] );
		expect( quickAnswersFromCsv( '' ) ).toEqual( [] );
	} );

	it( 'round-trips an export, including formula-safe cells', () => {
		const answers = [
			{
				question: 'Price of "Large" mug?',
				answer: '=12, plus tax\nShipping is free.',
				keywords: 'price; mug',
				language: 'en',
				enabled: false,
			},
		];
		const csv = quickAnswersToCsv( answers );

		expect( csv.startsWith( BOM + 'question,answer,keywords' ) ).toBe(
			true
		);
		// A spreadsheet would run a cell starting with "=" as a formula.
		expect( csv ).toContain( `"'=12, plus tax` );
		expect( quickAnswersFromCsv( csv ) ).toEqual( [
			{
				question: 'Price of "Large" mug?',
				answer: '=12, plus tax\nShipping is free.',
				keywords: 'price; mug',
				language: 'en',
				enabled: 'no',
			},
		] );
	} );
} );

describe( 'formatting and checks', () => {
	it( 'formats sizes', () => {
		expect( formatBytes( 512 ) ).toBe( '512 B' );
		expect( formatBytes( 1536 ) ).toBe( '1.5 KB' );
		expect( formatBytes( 10 * 1024 * 1024 ) ).toBe( '10 MB' );
		expect( formatBytes( 3 * 1024 * 1024 * 1024 ) ).toBe( '3 GB' );
	} );

	it( 'reads extensions', () => {
		expect( extensionOf( 'Price List.PDF' ) ).toBe( 'pdf' );
		expect( extensionOf( 'notes.markdown' ) ).toBe( 'md' );
		expect( extensionOf( 'README' ) ).toBe( '' );
	} );

	it( 'checks uploads before sending them', () => {
		const info = {
			types: [ 'pdf', 'docx', 'txt', 'md', 'csv', 'json' ],
			max_bytes: 1000,
			docx: false,
		};
		expect( uploadProblem( { name: 'a.pdf', size: 10 }, info ) ).toBe( '' );
		expect( uploadProblem( { name: 'a.exe', size: 10 }, info ) ).toMatch(
			/not supported/
		);
		expect( uploadProblem( { name: 'a.docx', size: 10 }, info ) ).toMatch(
			/Zip extension/
		);
		expect( uploadProblem( { name: 'a.txt', size: 2000 }, info ) ).toMatch(
			/larger than 1000 B/
		);
		expect( uploadProblem( { name: 'a.txt', size: 0 }, info ) ).toMatch(
			/empty/
		);
	} );

	it( 'reports rebuild progress', () => {
		const queue = {
			status: 'running' as const,
			total: 54,
			done: 27,
			started: '',
			finished: '',
		};
		expect( progressPercent( queue ) ).toBe( 50 );
		expect( progressPercent( { ...queue, done: 54 } ) ).toBe( 99 );
		expect( progressPercent( { ...queue, status: 'done' } ) ).toBe( 100 );
		expect( progressPercent( { ...queue, status: 'idle' } ) ).toBe( 0 );
	} );

	it( 'names source types', () => {
		expect( sourceTypeLabel( 'product' ) ).toBe( 'Product' );
		expect( sourceTypeLabel( 'post' ) ).toBe( 'Page or post' );
	} );
} );
