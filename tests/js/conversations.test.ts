/**
 * Conversation log helpers: filters in the address and the CSV export.
 */
import {
	activeFilters,
	csvCell,
	EMPTY_FILTERS,
	toCsv,
	type ExportData,
} from '../../src/admin/lib/conversations';

const BOM = String.fromCharCode( 0xfeff );

describe( 'activeFilters', () => {
	it( 'keeps only filters that narrow the list', () => {
		expect( activeFilters( EMPTY_FILTERS ) ).toEqual( {} );
		expect(
			activeFilters( {
				...EMPTY_FILTERS,
				search: 'refund',
				rating: 'down',
			} )
		).toEqual( { search: 'refund', rating: 'down' } );
	} );
} );

describe( 'csvCell', () => {
	it( 'quotes commas, quotes, and line breaks', () => {
		expect( csvCell( 'plain' ) ).toBe( 'plain' );
		expect( csvCell( 'a, b' ) ).toBe( '"a, b"' );
		expect( csvCell( 'say "hi"' ) ).toBe( '"say ""hi"""' );
		expect( csvCell( 'line 1\nline 2' ) ).toBe( '"line 1\nline 2"' );
		expect( csvCell( null ) ).toBe( '' );
		expect( csvCell( 0 ) ).toBe( '0' );
	} );

	it( 'neutralizes spreadsheet formulas from visitor text', () => {
		expect( csvCell( '=HYPERLINK("http://evil.test")' ) ).toBe(
			'"\'=HYPERLINK(""http://evil.test"")"'
		);
		expect( csvCell( '+1 555' ) ).toBe( "'+1 555" );
		expect( csvCell( '-2' ) ).toBe( "'-2" );
		expect( csvCell( '@SUM(A1)' ) ).toBe( "'@SUM(A1)" );
	} );
} );

describe( 'toCsv', () => {
	it( 'writes one row per message with its conversation', () => {
		const data: ExportData = {
			truncated: false,
			conversations: [
				{
					id: 7,
					logged_in: false,
					page_url: 'https://example.test/',
					language: 'en',
					provider: 'openai',
					model: 'm1',
					lead_name: '',
					lead_email: '',
					rating: 1,
					message_count: 2,
					total_cost: 0.001,
					created_at: '2026-10-15T08:00:00Z',
					updated_at: '2026-10-15T08:01:00Z',
					first_question: 'Hi, there',
					sources: [ 'ai' ],
					messages: [
						{
							id: 1,
							role: 'user',
							content: 'Hi, there',
							source: '',
							input_tokens: 0,
							output_tokens: 0,
							cost: 0,
							latency_ms: 0,
							feedback: null,
							sources: [],
							error_type: '',
							interrupted: '',
							created_at: '2026-10-15T08:00:00Z',
						},
						{
							id: 2,
							role: 'assistant',
							content: 'Hello!',
							source: 'ai',
							input_tokens: 10,
							output_tokens: 3,
							cost: 0.001,
							latency_ms: 700,
							feedback: 1,
							sources: [
								{
									title: 'Docs',
									url: 'https://example.test/docs/',
								},
							],
							error_type: '',
							interrupted: '',
							created_at: '2026-10-15T08:00:01Z',
						},
					],
				},
			],
		};

		const lines = toCsv( data ).replace( BOM, '' ).trim().split( '\r\n' );

		expect( toCsv( data ).startsWith( BOM ) ).toBe( true );
		expect( lines ).toHaveLength( 3 );
		expect( lines[ 0 ] ).toMatch(
			/^conversation_id,conversation_started,/
		);
		expect( lines[ 1 ] ).toContain( ',user,"Hi, there",' );
		expect( lines[ 2 ] ).toContain(
			',assistant,Hello!,ai,10,3,0.001,700,1,https://example.test/docs/'
		);
	} );
} );
