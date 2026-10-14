import { QueryClient } from '@tanstack/react-query';

import { ApiError } from './api';

/**
 * Shared TanStack Query client for server state.
 */
export const queryClient = new QueryClient( {
	defaultOptions: {
		queries: {
			staleTime: 30_000,
			refetchOnWindowFocus: false,
			retry: ( failureCount, error ) => {
				// Permission and validation errors will not fix themselves.
				if (
					error instanceof ApiError &&
					error.status >= 400 &&
					error.status < 500
				) {
					return false;
				}
				return failureCount < 2;
			},
		},
	},
} );
