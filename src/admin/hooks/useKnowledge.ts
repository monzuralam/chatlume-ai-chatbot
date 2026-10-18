import {
	keepPreviousData,
	useMutation,
	useQuery,
	useQueryClient,
} from '@tanstack/react-query';

import type { ApiError } from '../lib/api';
import {
	createQuickAnswer,
	deleteDocument,
	deleteQuickAnswer,
	fetchDocuments,
	fetchKnowledgeStatus,
	fetchQuickAnswers,
	fetchSources,
	importQuickAnswers,
	startReindex,
	updateQuickAnswer,
	uploadDocument,
	type DocumentsInfo,
	type ImportResult,
	type ImportRow,
	type KnowledgeDocument,
	type KnowledgeStatus,
	type QuickAnswer,
	type QuickAnswerFields,
} from '../lib/knowledge';

const STATUS_KEY = [ 'chatlume', 'knowledge', 'status' ] as const;
const SOURCES_KEY = [ 'chatlume', 'knowledge', 'sources' ] as const;
const DOCUMENTS_KEY = [ 'chatlume', 'knowledge', 'documents' ] as const;
const ANSWERS_KEY = [ 'chatlume', 'quick-answers' ] as const;

/**
 * Index status. While a rebuild or embeddings run, it refreshes every two
 * seconds (each check also nudges the work along when WP-Cron is late).
 */
export function useKnowledgeStatus() {
	return useQuery< KnowledgeStatus, ApiError >( {
		queryKey: STATUS_KEY,
		queryFn: fetchKnowledgeStatus,
		refetchInterval: ( query ) => {
			const data = query.state.data;
			const busy =
				data?.queue.status === 'running' ||
				( data?.embeddings.available &&
					data.embeddings.pending > 0 &&
					data.embeddings.status !== 'error' );
			return busy ? 2000 : false;
		},
	} );
}

/**
 * Indexed sources, a page at a time.
 *
 * @param args        Filters.
 * @param args.type   Source type ('' for all).
 * @param args.search Title search.
 * @param args.page   Page.
 */
export function useKnowledgeSources( args: {
	type: string;
	search: string;
	page: number;
} ) {
	return useQuery( {
		queryKey: [ ...SOURCES_KEY, args ],
		queryFn: () => fetchSources( args ),
		placeholderData: keepPreviousData,
	} );
}

/**
 * Starts "Re-index all".
 */
export function useReindex() {
	const queryClient = useQueryClient();
	return useMutation< KnowledgeStatus, ApiError >( {
		mutationFn: startReindex,
		onSuccess: ( status ) => {
			queryClient.setQueryData( STATUS_KEY, status );
			queryClient.invalidateQueries( { queryKey: SOURCES_KEY } );
		},
	} );
}

/**
 * Refreshes everything that depends on the index.
 *
 * @param queryClient Query client.
 */
function indexChanged( queryClient: ReturnType< typeof useQueryClient > ) {
	queryClient.invalidateQueries( { queryKey: STATUS_KEY } );
	queryClient.invalidateQueries( { queryKey: SOURCES_KEY } );
	queryClient.invalidateQueries( { queryKey: DOCUMENTS_KEY } );
}

/**
 * Uploaded documents, accepted types, and the size limit.
 */
export function useDocuments() {
	return useQuery< DocumentsInfo, ApiError >( {
		queryKey: DOCUMENTS_KEY,
		queryFn: fetchDocuments,
	} );
}

/**
 * Uploads one document.
 */
export function useUploadDocument() {
	const queryClient = useQueryClient();
	return useMutation<
		KnowledgeDocument,
		ApiError,
		{ file: File; language: string }
	>( {
		mutationFn: ( { file, language } ) => uploadDocument( file, language ),
		onSuccess: () => indexChanged( queryClient ),
	} );
}

/**
 * Deletes a document.
 */
export function useDeleteDocument() {
	const queryClient = useQueryClient();
	return useMutation< { deleted: boolean }, ApiError, string >( {
		mutationFn: deleteDocument,
		onSuccess: () => indexChanged( queryClient ),
	} );
}

/**
 * Quick answers, a page at a time.
 *
 * @param args        Filters.
 * @param args.search Search text.
 * @param args.page   Page.
 */
export function useQuickAnswers( args: { search: string; page: number } ) {
	return useQuery( {
		queryKey: [ ...ANSWERS_KEY, args ],
		queryFn: () => fetchQuickAnswers( args ),
		placeholderData: keepPreviousData,
	} );
}

/**
 * Creates, updates, deletes, and imports quick answers; every change
 * refreshes the list (and the Dashboard's counts).
 */
export function useQuickAnswerMutations() {
	const queryClient = useQueryClient();
	const refresh = () => {
		queryClient.invalidateQueries( { queryKey: ANSWERS_KEY } );
		queryClient.invalidateQueries( { queryKey: [ 'chatlume', 'stats' ] } );
	};

	return {
		create: useMutation< QuickAnswer, ApiError, QuickAnswerFields >( {
			mutationFn: createQuickAnswer,
			onSuccess: refresh,
		} ),
		update: useMutation<
			QuickAnswer,
			ApiError,
			{ id: number; fields: Partial< QuickAnswerFields > }
		>( {
			mutationFn: ( { id, fields } ) => updateQuickAnswer( id, fields ),
			onSuccess: refresh,
		} ),
		remove: useMutation< { deleted: boolean }, ApiError, number >( {
			mutationFn: deleteQuickAnswer,
			onSuccess: refresh,
		} ),
		importRows: useMutation< ImportResult, ApiError, ImportRow[] >( {
			mutationFn: importQuickAnswers,
			onSuccess: refresh,
		} ),
	};
}
