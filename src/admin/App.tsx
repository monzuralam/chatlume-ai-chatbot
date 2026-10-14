import { QueryClientProvider } from '@tanstack/react-query';
import { useMemo } from 'react';
import { createHashRouter, RouterProvider } from 'react-router';

import { ColorModeProvider } from './components/ColorModeProvider';
import { AppShell } from './components/layout/AppShell';
import { queryClient } from './lib/queryClient';

/**
 * Root component.
 *
 * Uses a hash-based data router (URLs like admin.php?page=chatlume#/settings)
 * so wp-admin's own query string stays untouched. A single catch-all route
 * lets the page registry decide what renders, so add-on pages registered at
 * any time work without rebuilding the router.
 *
 * @param props      Props.
 * @param props.root The #chatlume-admin element.
 */
export function App( { root }: { root: HTMLElement } ) {
	const router = useMemo(
		() => createHashRouter( [ { path: '*', element: <AppShell /> } ] ),
		[]
	);

	return (
		<QueryClientProvider client={ queryClient }>
			<ColorModeProvider root={ root }>
				<RouterProvider router={ router } />
			</ColorModeProvider>
		</QueryClientProvider>
	);
}
