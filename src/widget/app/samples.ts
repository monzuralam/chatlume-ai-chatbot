/**
 * Conversations shown for the Customizer's forced preview states. The text
 * comes from the Customizer (translated); the widget only arranges it.
 */
import type {
	ChatMessage,
	PreviewSamples,
	PreviewState,
	WidgetStrings,
} from '../types';

/**
 * Sample content with every field present.
 *
 * @param samples Partial samples.
 * @return Samples.
 */
export function withDefaults(
	samples: Partial< PreviewSamples > | undefined
): PreviewSamples {
	return {
		question: samples?.question ?? '',
		answer: samples?.answer ?? '',
		sources: samples?.sources ?? [],
		quickQuestion: samples?.quickQuestion ?? '',
		quickAnswer: samples?.quickAnswer ?? '',
		reply: samples?.reply ?? samples?.answer ?? '',
	};
}

/**
 * Messages for a forced preview state.
 *
 * @param state   Preview state.
 * @param samples Sample content.
 * @param strings Widget strings.
 * @return Messages, or null to keep the current conversation.
 */
export function sampleMessages(
	state: PreviewState,
	samples: PreviewSamples,
	strings: WidgetStrings
): ChatMessage[] | null {
	const time = Date.now();
	let index = 0;
	const message = ( fields: Partial< ChatMessage > ): ChatMessage => ( {
		id: `sample-${ ++index }`,
		role: 'bot',
		text: '',
		time: time - ( 10 - index ) * 60000,
		status: 'done',
		...fields,
	} );

	const user = ( text: string ) => message( { role: 'user', text } );

	switch ( state ) {
		case 'launcher':
			return null;
		case 'empty':
			return [];
		case 'conversation':
			return [
				user( samples.question ),
				message( {
					text: samples.answer,
					source: 'ai',
					sources: samples.sources,
					question: samples.question,
					serverId: 1,
				} ),
				user( samples.quickQuestion ),
				message( {
					text: samples.quickAnswer,
					source: 'quick_answer',
					quickAnswerId: 1,
					question: samples.quickQuestion,
					serverId: 2,
				} ),
			];
		case 'streaming':
			return [
				user( samples.question ),
				message( {
					text: samples.answer.slice(
						0,
						Math.max( 1, Math.floor( samples.answer.length / 2 ) )
					),
					status: 'streaming',
					question: samples.question,
				} ),
			];
		case 'typing':
			return [
				user( samples.question ),
				message( { status: 'pending', question: samples.question } ),
			];
		case 'error':
			return [
				user( samples.question ),
				message( {
					status: 'error',
					error: strings.error,
					question: samples.question,
				} ),
			];
		case 'offline':
			return [
				user( samples.question ),
				message( { text: strings.offline ?? '', source: 'offline' } ),
			];
		case 'lead':
			// The lead form shows on an empty chat (the app adds it).
			return [];
	}
	return null;
}
