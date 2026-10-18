/**
 * Types and REST calls for AI providers (chatlume/v1/providers).
 * Mirrors includes/REST/ProvidersController.php.
 */
import { z } from 'zod';

import { request } from './api';

export type ProviderState =
	| 'ready'
	| 'untested'
	| 'error'
	| 'needs_key'
	| 'needs_url'
	| 'needs_connector'
	| 'key_unreadable';

export interface ProviderKeyStatus {
	source: 'constant' | 'settings' | 'none';
	masked: string;
	readable: boolean;
	constant: string;
}

export interface ProviderSummary {
	id: string;
	label: string;
	description: string;
	initials: string;
	kind: string;
	links: Partial< Record< 'keys' | 'billing' | 'docs' | 'status', string > >;
	key_required: boolean;
	default_base_url: string;
	base_url_editable: boolean;
	supports: {
		streaming: boolean;
		embeddings: boolean;
		embedding_dimensions: boolean;
		tools: boolean;
	};
	fields: string[];
	key: ProviderKeyStatus;
	state: ProviderState;
	last_test: {
		ok: boolean;
		time: number;
		latency_ms: number;
		model: string;
		error_type: string;
	} | null;
}

export interface RoleValue {
	provider: string;
	model: string;
	dimensions?: number;
}

export interface ProvidersResponse {
	providers: ProviderSummary[];
	roles: {
		primary: RoleValue;
		fallback: RoleValue;
		embeddings: RoleValue;
	};
}

export interface ModelInfo {
	id: string;
	label: string;
	kind: 'chat' | 'embedding' | 'other';
	context_length: number;
	input_price: number | null;
	output_price: number | null;
	free: boolean;
	recommended: boolean;
	created: number;
}

export interface ProviderErrorInfo {
	type: string;
	provider: string;
	status: number;
	title: string;
	explanation: string;
	detail: string;
	fix: { url: string; label: string } | null;
	retry_after: number | null;
}

export interface TestResult {
	ok: boolean;
	latency_ms: number;
	model: string;
	reply: string;
	message: string;
	error?: ProviderErrorInfo;
}

export const fetchProviders = () =>
	request< ProvidersResponse >( '/providers' );

export const fetchModels = (
	id: string,
	kind: 'all' | 'chat' | 'embedding',
	refresh = false
) =>
	request< { models: ModelInfo[] } >(
		`/providers/${ encodeURIComponent( id ) }/models?kind=${ kind }${
			refresh ? '&refresh=true' : ''
		}`
	);

export const testProvider = (
	id: string,
	data: { model?: string; api_key?: string; base_url?: string } = {}
) =>
	request< TestResult >( `/providers/${ encodeURIComponent( id ) }/test`, {
		method: 'POST',
		data,
	} );

export const saveApiKey = ( id: string, apiKey: string ) =>
	request< { key: ProviderKeyStatus } >(
		`/providers/${ encodeURIComponent( id ) }/key`,
		{ method: 'POST', data: { api_key: apiKey } }
	);

export const deleteApiKey = ( id: string ) =>
	request< { key: ProviderKeyStatus } >(
		`/providers/${ encodeURIComponent( id ) }/key`,
		{ method: 'DELETE' }
	);

/**
 * Model IDs: same characters the server keeps (SettingsSchema "model" format).
 */
export const modelIdSchema = z
	.string()
	.max( 200 )
	.regex( /^[A-Za-z0-9._:/@+\-]*$/, 'model' );

/**
 * Configuration form for one provider. Optional fields exist only for some
 * providers (`fields` in the provider summary says which).
 */
export const providerConfigSchema = z.object( {
	base_url: z
		.string()
		.max( 500 )
		.refine(
			( value ) => value === '' || /^https?:\/\/\S+$/i.test( value ),
			{
				message: 'url',
			}
		)
		.optional(),
	default_model: modelIdSchema,
	timeout: z.number().int().min( 5 ).max( 120 ),
	extra_headers: z
		.array(
			z.object( {
				name: z
					.string()
					.min( 1, 'header' )
					.max( 64 )
					.regex( /^[A-Za-z0-9-]+$/, 'header' ),
				value: z.string().max( 500 ),
			} )
		)
		.max( 10 ),
	prices: z
		.array(
			z.object( {
				model: modelIdSchema.min( 1, 'model' ),
				input: z.number().min( 0 ).max( 10000 ),
				output: z.number().min( 0 ).max( 10000 ),
			} )
		)
		.max( 200 )
		.optional(),
	app_attribution: z.boolean().optional(),
} );

export type ProviderConfig = z.infer< typeof providerConfigSchema >;

/**
 * Builds form values for a provider from stored settings, keeping only the
 * fields this provider supports.
 *
 * @param stored Stored configuration (may be partial).
 * @param fields Field names the provider supports.
 */
export function toProviderForm(
	stored: Record< string, unknown > | undefined,
	fields: string[]
): ProviderConfig {
	const value = stored ?? {};
	const form: ProviderConfig = {
		default_model: String( value.default_model ?? '' ),
		timeout: Number( value.timeout ?? 30 ),
		extra_headers: Array.isArray( value.extra_headers )
			? ( value.extra_headers as ProviderConfig[ 'extra_headers' ] )
			: [],
	};

	if ( fields.includes( 'base_url' ) ) {
		form.base_url = String( value.base_url ?? '' );
	}
	if ( fields.includes( 'prices' ) ) {
		form.prices = Array.isArray( value.prices )
			? ( value.prices as NonNullable< ProviderConfig[ 'prices' ] > )
			: [];
	}
	if ( fields.includes( 'app_attribution' ) ) {
		form.app_attribution = Boolean( value.app_attribution );
	}

	return form;
}

/**
 * Formats a USD price per 1M tokens.
 *
 * @param value Price or null.
 */
export function formatPrice( value: number | null ): string {
	if ( value === null ) {
		return '';
	}
	if ( value === 0 ) {
		return '$0';
	}
	return `$${ value < 0.01 ? value.toPrecision( 2 ) : value.toFixed( 2 ) }`;
}

/**
 * Formats a context window, e.g. 128000 → "128K".
 *
 * @param tokens Context length in tokens.
 */
export function formatContext( tokens: number ): string {
	if ( ! tokens ) {
		return '';
	}
	if ( tokens >= 1_000_000 ) {
		return `${ Math.round( tokens / 100_000 ) / 10 }M`;
	}
	return `${ Math.round( tokens / 1000 ) }K`;
}
