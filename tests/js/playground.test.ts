/**
 * Playground helpers.
 */
import {
	cacheLabel,
	canAskAi,
	explainReply,
	promptText,
	type PlaygroundReply,
	type ReplyTrace,
} from '../../src/admin/lib/playground';

function reply(
	source: string,
	trace: Partial< ReplyTrace > = {},
	meta: Record< string, unknown > = {},
	quickAnswerId: number | null = null
): PlaygroundReply {
	return {
		conversation_id: 't_1',
		message_id: 0,
		reply: {
			text: 'Hello',
			source,
			sources: [],
			quick_answer_id: quickAnswerId,
		},
		details: {
			provider: '',
			model: '',
			input_tokens: 0,
			output_tokens: 0,
			cost: 0,
			latency_ms: 12,
			error_type: '',
			meta,
			trace: {
				route: 'ai',
				quick_answer: null,
				cache: 'skipped',
				knowledge: [],
				prompt: {},
				...trace,
			},
		},
	};
}

describe( 'explainReply', () => {
	it( 'names the quick answer and its match score', () => {
		expect(
			explainReply(
				reply( 'quick_answer', {
					route: 'quick_answer',
					quick_answer: { id: 7, question: 'Hours?', score: 0.876 },
				} )
			)
		).toBe(
			'Matched your quick answer “Hours?” (88% match). No AI was used, so it cost nothing.'
		);
	} );

	it( 'explains why site search was used', () => {
		expect(
			explainReply(
				reply(
					'site_search',
					{ route: 'site_search' },
					{ no_provider: true }
				)
			)
		).toMatch( /No AI provider is connected yet/ );
		expect(
			explainReply(
				reply(
					'site_search',
					{ route: 'site_search' },
					{ budget: 'day' }
				)
			)
		).toMatch( /budget is used up/ );
		expect(
			explainReply(
				reply(
					'site_search',
					{ route: 'site_search' },
					{ provider_error: 'quota' }
				)
			)
		).toMatch( /provider failed/ );
	} );

	it( 'counts knowledge passages for AI answers', () => {
		expect(
			explainReply(
				reply( 'ai', {
					knowledge: [
						{ title: 'A', url: '', score: 0.5, excerpt: 'x' },
						{ title: 'B', url: '', score: null, excerpt: 'y' },
					],
				} )
			)
		).toBe( 'Answered by the AI using 2 passages from your knowledge.' );
		expect( explainReply( reply( 'ai' ) ) ).toMatch(
			/without matching knowledge/
		);
		expect(
			explainReply( reply( 'cache', { route: 'cache', cache: 'hit' } ) )
		).toMatch( /saved AI answer was reused/ );
	} );
} );

describe( 'promptText', () => {
	it( 'shows every message with its role', () => {
		expect(
			promptText( {
				system: 'Be nice.',
				messages: [
					{ role: 'system', content: 'Be nice.' },
					{ role: 'user', content: 'Hi' },
				],
			} )
		).toBe( '[SYSTEM]\nBe nice.\n\n[USER]\nHi' );
	} );

	it( 'falls back to the system prompt, or nothing', () => {
		expect( promptText( { system: 'Be nice.' } ) ).toBe(
			'[SYSTEM]\nBe nice.'
		);
		expect( promptText( {} ) ).toBe( '' );
	} );
} );

describe( 'cacheLabel and canAskAi', () => {
	it( 'labels each cache state', () => {
		expect( cacheLabel( 'hit' ) ).toBe( 'Reused a saved answer' );
		expect( cacheLabel( 'stored' ) ).toBe( 'Saved for next time' );
		expect( cacheLabel( 'skipped' ) ).toMatch( /^Not used/ );
	} );

	it( 'offers "Ask AI instead" only for quick answers', () => {
		expect( canAskAi( reply( 'quick_answer', {}, {}, 7 ) ) ).toBe( true );
		expect( canAskAi( reply( 'ai' ) ) ).toBe( false );
	} );
} );
