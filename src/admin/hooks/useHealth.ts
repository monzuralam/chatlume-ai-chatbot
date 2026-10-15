import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import type { ApiError } from '../lib/api';
import {
	clearDebugLog,
	clearProviderErrors,
	clearResponseCache,
	fetchHealth,
	runStreamTest,
	type HealthResponse,
	type StreamTestResult,
} from '../lib/health';

export const HEALTH_QUERY_KEY = [ 'chatlume', 'health' ] as const;

/**
 * Health check results and logs.
 */
export function useHealth() {
	return useQuery< HealthResponse, ApiError >( {
		queryKey: HEALTH_QUERY_KEY,
		queryFn: fetchHealth,
		staleTime: 30_000,
	} );
}

/**
 * Clears a log and refreshes the health data.
 *
 * @param log Which log.
 */
export function useClearLog( log: 'errors' | 'debug' ) {
	const queryClient = useQueryClient();

	return useMutation< { cleared: boolean }, ApiError >( {
		mutationFn: log === 'errors' ? clearProviderErrors : clearDebugLog,
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: HEALTH_QUERY_KEY } ),
	} );
}

/**
 * Invalidates every cached answer.
 */
export function useClearCache() {
	return useMutation< { cleared: boolean }, ApiError >( {
		mutationFn: clearResponseCache,
	} );
}

/**
 * The live streaming test.
 */
export function useStreamTest() {
	return useMutation< StreamTestResult, ApiError >( {
		mutationFn: runStreamTest,
	} );
}
