/**
 * Setup wizard helpers.
 */
import { __ } from '@wordpress/i18n';

import type { ModelInfo, ProviderSummary } from './providers';

export type SetupStep =
	'provider' | 'guide' | 'key' | 'model' | 'look' | 'done';

/** Providers most site owners pick, shown first after core-connected ones. */
export const POPULAR = [ 'openai', 'anthropic', 'gemini', 'openrouter' ];

/**
 * Providers in wizard order: connected in WordPress first, then popular
 * ones, then the rest as listed.
 *
 * @param providers Providers.
 * @return Ordered providers.
 */
export function orderProviders(
	providers: ProviderSummary[]
): ProviderSummary[] {
	const rank = ( provider: ProviderSummary ) => {
		if ( provider.kind === 'core' ) {
			return -1;
		}
		const popular = POPULAR.indexOf( provider.id );
		return popular === -1 ? POPULAR.length : popular;
	};
	return providers
		.map( ( provider, index ) => ( { provider, index } ) )
		.sort(
			( a, b ) =>
				rank( a.provider ) - rank( b.provider ) || a.index - b.index
		)
		.map( ( item ) => item.provider );
}

/**
 * A note about free use, when the provider offers it.
 *
 * @param id Provider ID.
 * @return Note, or null.
 */
export function freeTier( id: string ): string | null {
	switch ( id ) {
		case 'gemini':
			return __(
				'Has a free tier with daily limits, enough to try Chatlume.',
				'chatlume-ai-chatbot'
			);
		case 'groq':
			return __(
				'Has a free tier with rate limits.',
				'chatlume-ai-chatbot'
			);
		case 'openrouter':
			return __(
				'Offers some free models (marked Free in the model list).',
				'chatlume-ai-chatbot'
			);
		case 'ollama':
			return __(
				'Runs models on your own computer or server, with no usage fees.',
				'chatlume-ai-chatbot'
			);
		default:
			return null;
	}
}

/**
 * The model to start with: a recommended chat model, else a free one, else
 * the first chat model.
 *
 * @param models Models.
 * @return Model ID, or '' when none is listed.
 */
export function pickModel( models: ModelInfo[] ): string {
	const chat = models.filter( ( model ) => model.kind === 'chat' );
	return (
		chat.find( ( model ) => model.recommended )?.id ??
		chat.find( ( model ) => model.free )?.id ??
		chat[ 0 ]?.id ??
		''
	);
}

/**
 * Steps shown in the progress list.
 *
 * @param skipAi Whether the admin skipped the AI.
 * @return Steps.
 */
export function wizardSteps( skipAi: boolean ): SetupStep[] {
	return skipAi
		? [ 'provider', 'look', 'done' ]
		: [ 'provider', 'guide', 'key', 'model', 'look', 'done' ];
}

/**
 * Step names.
 *
 * @return Labels.
 */
export function stepLabels(): Record< SetupStep, string > {
	return {
		provider: __( 'AI provider', 'chatlume-ai-chatbot' ),
		guide: __( 'Get a key', 'chatlume-ai-chatbot' ),
		key: __( 'Connect', 'chatlume-ai-chatbot' ),
		model: __( 'Model', 'chatlume-ai-chatbot' ),
		look: __( 'Look', 'chatlume-ai-chatbot' ),
		done: __( 'Done', 'chatlume-ai-chatbot' ),
	};
}
