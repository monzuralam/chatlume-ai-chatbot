import { useSyncExternalStore } from 'react';

import type { Registry } from './registry';

/**
 * Subscribes a component to a registry; re-renders when items change.
 *
 * @param registry Registry to read.
 */
export function useRegistry< T extends { id: string; order?: number } >(
	registry: Registry< T >
): readonly T[] {
	return useSyncExternalStore(
		registry.subscribe,
		registry.getAll,
		registry.getAll
	);
}
