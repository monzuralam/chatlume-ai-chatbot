import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ApiError } from '../lib/api';
import {
	deleteApiKey,
	fetchModels,
	fetchProviders,
	saveApiKey,
	testProvider,
	type ModelInfo,
	type ProvidersResponse,
	type TestResult,
} from '../lib/providers';

export const PROVIDERS_QUERY_KEY = [ 'chatlume', 'providers' ] as const;

/**
 * All providers with status and routing roles.
 */
export function useProviders() {
	return useQuery< ProvidersResponse, ApiError >( {
		queryKey: PROVIDERS_QUERY_KEY,
		queryFn: fetchProviders,
	} );
}

/**
 * Live model list of a provider (cached on the server for 12 hours).
 *
 * @param id      Provider ID ('' disables the query).
 * @param kind    Model kind filter.
 * @param enabled Whether to load.
 */
export function useModels(
	id: string,
	kind: 'chat' | 'embedding',
	enabled = true
) {
	return useQuery< ModelInfo[], ApiError >( {
		queryKey: [ 'chatlume', 'models', id, kind ],
		queryFn: async () => ( await fetchModels( id, kind ) ).models,
		enabled: enabled && id !== '',
		staleTime: 5 * 60_000,
	} );
}

/**
 * Bypasses the server cache and reloads a provider's models.
 */
export function useRefreshModels() {
	const queryClient = useQueryClient();

	return useMutation<
		ModelInfo[],
		ApiError,
		{ id: string; kind: 'chat' | 'embedding' }
	>( {
		mutationFn: async ( { id, kind } ) =>
			( await fetchModels( id, kind, true ) ).models,
		onSuccess: ( models, { id, kind } ) => {
			queryClient.setQueryData(
				[ 'chatlume', 'models', id, kind ],
				models
			);
		},
	} );
}

/**
 * Saves or removes an API key, then refreshes provider status and models.
 */
export function useApiKey() {
	const queryClient = useQueryClient();
	const invalidate = ( id: string ) => {
		queryClient.invalidateQueries( { queryKey: PROVIDERS_QUERY_KEY } );
		queryClient.invalidateQueries( {
			queryKey: [ 'chatlume', 'models', id ],
		} );
	};

	const save = useMutation<
		unknown,
		ApiError,
		{ id: string; apiKey: string }
	>( {
		mutationFn: ( { id, apiKey } ) => saveApiKey( id, apiKey ),
		onSuccess: ( _data, { id } ) => invalidate( id ),
	} );

	const remove = useMutation< unknown, ApiError, { id: string } >( {
		mutationFn: ( { id } ) => deleteApiKey( id ),
		onSuccess: ( _data, { id } ) => invalidate( id ),
	} );

	return { save, remove };
}

/**
 * Runs a connection test and refreshes the provider's status.
 */
export function useTestProvider() {
	const queryClient = useQueryClient();

	return useMutation<
		TestResult,
		ApiError,
		{ id: string; model?: string; api_key?: string; base_url?: string }
	>( {
		mutationFn: ( { id, ...data } ) => testProvider( id, data ),
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: PROVIDERS_QUERY_KEY } ),
	} );
}
