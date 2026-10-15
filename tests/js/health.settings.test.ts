/**
 * Health check helpers and settings export.
 */
import {
	describeDebugEntry,
	judgeStreamTest,
} from '../../src/admin/lib/health';
import {
	exportSettingsFile,
	importableSettings,
} from '../../src/admin/lib/settingsSchema';

describe( 'judgeStreamTest', () => {
	it( 'passes when events arrive spread out', () => {
		expect( judgeStreamTest( [ 0, 250, 500, 750, 1000, 1250 ] ) ).toEqual( {
			events: 6,
			spreadMs: 1250,
			streaming: true,
		} );
	} );

	it( 'fails when everything arrives at once or too little arrives', () => {
		expect(
			judgeStreamTest( [ 900, 900, 900, 901, 901, 902 ] ).streaming
		).toBe( false );
		expect( judgeStreamTest( [ 0, 800 ] ).streaming ).toBe( false );
		expect( judgeStreamTest( [] ) ).toEqual( {
			events: 0,
			spreadMs: 0,
			streaming: false,
		} );
	} );
} );

describe( 'describeDebugEntry', () => {
	it( 'summarizes replies', () => {
		expect(
			describeDebugEntry( {
				time: 1,
				event: 'reply',
				source: 'ai',
				provider: 'openai',
				model: 'm1',
				input_tokens: 120,
				output_tokens: 40,
				streamed: true,
				interrupted: 'stopped',
			} )
		).toBe(
			'ai · openai / m1 · 120 in / 40 out tokens · streamed · interrupted (stopped)'
		);
		expect(
			describeDebugEntry( {
				time: 1,
				event: 'reply',
				source: 'site_search',
				error_type: 'quota',
			} )
		).toBe( 'site_search · provider error: quota' );
	} );

	it( 'summarizes rejections', () => {
		expect(
			describeDebugEntry( {
				time: 1,
				event: 'rejected',
				code: 'rate_limited',
				status: 429,
			} )
		).toBe( 'Request rejected: rate_limited (HTTP 429)' );
	} );
} );

describe( 'exportSettingsFile', () => {
	it( 'exports importable sections only and imports them back', () => {
		const text = exportSettingsFile(
			{
				setup: { completed: true },
				cache: { enabled: false, ttl_hours: 12 },
				streaming: { enabled: true },
			},
			'1.2.3'
		);
		const file = JSON.parse( text );

		expect( file.plugin ).toBe( 'chatlume-ai-chatbot' );
		expect( file.version ).toBe( '1.2.3' );
		expect( Object.keys( file.settings ).sort() ).toEqual( [
			'cache',
			'streaming',
		] );
		expect( importableSettings( file ) ).toEqual( file.settings );
	} );
} );
