import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { request, type ApiError } from '../lib/api';
import {
	mergeSettings,
	settingsSchema,
	type Settings,
	type SettingsUpdate,
} from '../lib/settingsSchema';

export const SETTINGS_QUERY_KEY = [ 'chatlume', 'settings' ] as const;

/**
 * Loads all settings from GET /settings.
 */
export function useSettings() {
	return useQuery< Settings, ApiError >( {
		queryKey: SETTINGS_QUERY_KEY,
		queryFn: async () =>
			settingsSchema.parse( await request( '/settings' ) ),
	} );
}

/**
 * Saves a partial settings update with an optimistic cache update that is
 * rolled back if the server rejects it.
 */
export function useUpdateSettings() {
	const queryClient = useQueryClient();

	return useMutation<
		Settings,
		ApiError,
		SettingsUpdate,
		{ previous?: Settings }
	>( {
		mutationFn: async ( update ) =>
			settingsSchema.parse(
				await request( '/settings', { method: 'POST', data: update } )
			),
		onMutate: async ( update ) => {
			await queryClient.cancelQueries( { queryKey: SETTINGS_QUERY_KEY } );
			const previous =
				queryClient.getQueryData< Settings >( SETTINGS_QUERY_KEY );
			if ( previous ) {
				queryClient.setQueryData(
					SETTINGS_QUERY_KEY,
					mergeSettings( previous, update )
				);
			}
			return { previous };
		},
		onError: ( _error, _update, context ) => {
			if ( context?.previous ) {
				queryClient.setQueryData(
					SETTINGS_QUERY_KEY,
					context.previous
				);
			}
		},
		onSuccess: ( settings, update ) => {
			queryClient.setQueryData( SETTINGS_QUERY_KEY, settings );
			// The server re-indexes right away when knowledge sources change.
			if ( 'knowledge' in update ) {
				queryClient.invalidateQueries( {
					queryKey: [ 'chatlume', 'knowledge' ],
				} );
			}
		},
	} );
}
