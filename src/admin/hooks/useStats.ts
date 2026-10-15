import {
	keepPreviousData,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';

import type { ApiError } from '../lib/api';
import {
	createQuickAnswer,
	fetchStats,
	type Period,
	type QuickAnswerInput,
	type StatsResponse,
} from '../lib/stats';

export const STATS_QUERY_KEY = [ 'chatlume', 'stats' ] as const;

/**
 * Dashboard statistics for a period.
 *
 * @param days Period in days.
 */
export function useStats( days: Period ) {
	return useQuery< StatsResponse, ApiError >( {
		queryKey: [ ...STATS_QUERY_KEY, days ],
		queryFn: () => fetchStats( days ),
		placeholderData: keepPreviousData,
		staleTime: 60_000,
	} );
}

/**
 * Creates a quick answer and refreshes the checklist.
 */
export function useCreateQuickAnswer() {
	const queryClient = useQueryClient();

	return useMutation< { id: number }, ApiError, QuickAnswerInput >( {
		mutationFn: createQuickAnswer,
		onSuccess: () =>
			queryClient.invalidateQueries( { queryKey: STATS_QUERY_KEY } ),
	} );
}
