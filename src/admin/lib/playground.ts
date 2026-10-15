/**
 * The Playground ("Test your bot"): chat with the real bot and see why
 * each reply was given.
 */
import { __, sprintf } from '@wordpress/i18n';

import { request } from './api';

export type TraceRoute =
	| 'ai'
	| 'fallback_model'
	| 'quick_answer'
	| 'cache'
	| 'site_search'
	| 'blocked_word'
	| 'offline';

export interface TraceChunk {
	title: string;
	url: string;
	score: number | null;
	excerpt: string;
}

export interface PromptMessage {
	role: string;
	content: string;
}

export interface ReplyTrace {
	route: TraceRoute;
	quick_answer: { id: number; question: string; score: number } | null;
	cache: 'hit' | 'miss' | 'stored' | 'skipped';
	knowledge: TraceChunk[];
	prompt: { system?: string; history?: number; messages?: PromptMessage[] };
	budget?: string;
}

export interface PlaygroundReply {
	conversation_id: string;
	message_id: number;
	reply: {
		text: string;
		source: string;
		sources: Array< { title: string; url: string } >;
		quick_answer_id: number | null;
		interrupted?: string;
	};
	details: {
		provider: string;
		model: string;
		input_tokens: number;
		output_tokens: number;
		cost: number;
		latency_ms: number;
		error_type: string;
		meta: Record< string, unknown >;
		trace: ReplyTrace;
	};
}

export interface PlaygroundInput {
	message: string;
	conversation_id?: string;
	skip_quick_answers?: boolean;
	quick_answer_id?: number;
}

/**
 * One line in the Playground chat.
 */
export type PlaygroundTurn =
	| { id: string; role: 'user'; text: string }
	| { id: string; role: 'assistant'; question: string; data: PlaygroundReply }
	| { id: string; role: 'error'; question: string; text: string };

export const sendPlaygroundMessage = ( input: PlaygroundInput ) =>
	request< PlaygroundReply >( '/playground/chat', {
		method: 'POST',
		data: input,
	} );

/**
 * Why the reply was given, in one sentence.
 *
 * @param data Reply.
 * @return Explanation.
 */
export function explainReply( data: PlaygroundReply ): string {
	const { trace, meta } = data.details;

	switch ( trace.route ) {
		case 'quick_answer':
			return trace.quick_answer
				? sprintf(
						/* translators: 1: Quick answer question, 2: Match score in percent. */
						__(
							'Matched your quick answer “%1$s” (%2$d%% match). No AI was used, so it cost nothing.',
							'chatlume-ai-chatbot'
						),
						trace.quick_answer.question,
						Math.round( trace.quick_answer.score * 100 )
					)
				: __(
						'Matched one of your quick answers. No AI was used.',
						'chatlume-ai-chatbot'
					);
		case 'cache':
			return __(
				'Someone asked this recently, so the saved AI answer was reused at no cost.',
				'chatlume-ai-chatbot'
			);
		case 'blocked_word':
			return __(
				'The message contains one of your blocked words, so the bot refused.',
				'chatlume-ai-chatbot'
			);
		case 'offline':
			return meta.closed
				? __(
						'It is outside your business hours, so the bot sent your offline message.',
						'chatlume-ai-chatbot'
					)
				: __(
						'Your AI budget is used up and the bot is set to go offline.',
						'chatlume-ai-chatbot'
					);
		case 'site_search':
			if ( meta.no_provider ) {
				return __(
					'No AI provider is connected yet, so the bot suggested matching pages from your site.',
					'chatlume-ai-chatbot'
				);
			}
			if ( meta.budget ) {
				return __(
					'Your AI budget is used up, so the bot suggested matching pages from your site.',
					'chatlume-ai-chatbot'
				);
			}
			if ( meta.provider_error ) {
				return __(
					'The AI provider failed, so the bot suggested matching pages from your site.',
					'chatlume-ai-chatbot'
				);
			}
			return __(
				'The AI returned nothing, so the bot suggested matching pages from your site.',
				'chatlume-ai-chatbot'
			);
		case 'fallback_model':
			return __(
				'Answered by your fallback AI model.',
				'chatlume-ai-chatbot'
			);
		default:
			return trace.knowledge.length > 0
				? sprintf(
						/* translators: %d: Number of knowledge passages. */
						__(
							'Answered by the AI using %d passages from your knowledge.',
							'chatlume-ai-chatbot'
						),
						trace.knowledge.length
					)
				: __(
						'Answered by the AI without matching knowledge from your site.',
						'chatlume-ai-chatbot'
					);
	}
}

/**
 * Readable state of the response cache for a reply.
 *
 * @param cache Cache state.
 * @return Label.
 */
export function cacheLabel( cache: ReplyTrace[ 'cache' ] ): string {
	switch ( cache ) {
		case 'hit':
			return __( 'Reused a saved answer', 'chatlume-ai-chatbot' );
		case 'miss':
			return __( 'Not cached yet', 'chatlume-ai-chatbot' );
		case 'stored':
			return __( 'Saved for next time', 'chatlume-ai-chatbot' );
		default:
			return __(
				'Not used (only the first question of a conversation is cached, when caching is on)',
				'chatlume-ai-chatbot'
			);
	}
}

/**
 * The final prompt as plain text, one block per message.
 *
 * @param prompt Prompt trace.
 * @return Text.
 */
export function promptText( prompt: ReplyTrace[ 'prompt' ] ): string {
	if ( prompt.messages && prompt.messages.length > 0 ) {
		return prompt.messages
			.map(
				( message ) =>
					`[${ message.role.toUpperCase() }]\n${ message.content }`
			)
			.join( '\n\n' );
	}

	return prompt.system ? `[SYSTEM]\n${ prompt.system }` : '';
}

/**
 * Whether "Ask AI instead" makes sense for a reply.
 *
 * @param data Reply.
 * @return True for quick answers.
 */
export function canAskAi( data: PlaygroundReply ): boolean {
	return (
		data.reply.source === 'quick_answer' &&
		( data.reply.quick_answer_id ?? 0 ) > 0
	);
}
