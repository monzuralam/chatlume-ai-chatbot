/**
 * Validation for the routing form on the Providers page (primary,
 * fallback, and embeddings provider). Mirrors ProviderRegistry rules.
 */
import { __ } from '@wordpress/i18n';

import type { ProviderSummary } from './providers';

export interface RoutingForm {
	primary: { provider: string; model: string };
	fallback: { provider: string; model: string };
	embeddings: { provider: string; model: string; dimensions: number };
}

/**
 * Validates routing choices; returns translated messages keyed by field path.
 *
 * @param values    Form values.
 * @param providers Providers.
 */
export function validateRouting(
	values: RoutingForm,
	providers: ProviderSummary[]
): Record< string, string > {
	const errors: Record< string, string > = {};
	const byId = new Map(
		providers.map( ( provider ) => [ provider.id, provider ] )
	);

	if (
		values.fallback.provider !== '' &&
		values.fallback.provider === values.primary.provider &&
		values.fallback.model === values.primary.model
	) {
		errors[ 'fallback.model' ] = __(
			'The fallback must differ from the primary provider or model.',
			'chatlume-ai-chatbot'
		);
	}

	const embeddingsProvider =
		values.embeddings.provider !== ''
			? values.embeddings.provider
			: values.primary.provider;
	const embeddingsSupported =
		byId.get( embeddingsProvider )?.supports.embeddings ?? false;

	if ( values.embeddings.provider !== '' && ! embeddingsSupported ) {
		errors[ 'embeddings.provider' ] = __(
			'This provider does not offer embeddings.',
			'chatlume-ai-chatbot'
		);
	}
	if (
		embeddingsSupported &&
		values.embeddings.provider !== '' &&
		values.embeddings.model === ''
	) {
		errors[ 'embeddings.model' ] = __(
			'Choose an embeddings model.',
			'chatlume-ai-chatbot'
		);
	}
	if (
		! Number.isInteger( values.embeddings.dimensions ) ||
		values.embeddings.dimensions < 0 ||
		values.embeddings.dimensions > 4096
	) {
		errors[ 'embeddings.dimensions' ] = __(
			'Enter a whole number between 0 and 4096.',
			'chatlume-ai-chatbot'
		);
	}

	return errors;
}
