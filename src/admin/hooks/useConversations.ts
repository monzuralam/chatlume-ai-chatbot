import {
	keepPreviousData,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';

import type { ApiError } from '../lib/api';
import {
	deleteConversations,
	fetchConversations,
	fetchTranscript,
	type ConversationFilters,
	type ConversationPage,
	type Transcript,
} from '../lib/conversations';

export const CONVERSATIONS_QUERY_KEY = [ 'chatlume', 'conversations' ] as const;

/**
 * One page of the conversation log.
 *
 * @param filters Filters.
 * @param page    Page.
 * @param perPage Items per page.
 */
export function useConversations(
	filters: ConversationFilters,
	page: number,
	perPage: number
) {
	return useQuery< ConversationPage, ApiError >( {
		queryKey: [ ...CONVERSATIONS_QUERY_KEY, filters, page, perPage ],
		queryFn: () => fetchConversations( filters, page, perPage ),
		placeholderData: keepPreviousData,
	} );
}

/**
 * One transcript.
 *
 * @param id Conversation ID (0 disables the query).
 */
export function useTranscript( id: number ) {
	return useQuery< Transcript, ApiError >( {
		queryKey: [ ...CONVERSATIONS_QUERY_KEY, 'transcript', id ],
		queryFn: () => fetchTranscript( id ),
		enabled: id > 0,
	} );
}

/**
 * Deletes conversations and refreshes the list.
 */
export function useDeleteConversations() {
	const queryClient = useQueryClient();

	return useMutation< { deleted: number }, ApiError, number[] >( {
		mutationFn: deleteConversations,
		onSuccess: () =>
			queryClient.invalidateQueries( {
				queryKey: CONVERSATIONS_QUERY_KEY,
			} ),
	} );
}
