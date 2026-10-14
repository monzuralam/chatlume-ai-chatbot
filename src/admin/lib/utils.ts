import { clsx, type ClassValue } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/**
 * Tailwind runs with the `chatlume` prefix (classes look like
 * `chatlume:flex`), so tailwind-merge must know about it to resolve conflicts.
 */
const twMerge = extendTailwindMerge( { prefix: 'chatlume' } );

/**
 * Joins class names and resolves conflicting Tailwind utilities
 * (the last one wins).
 *
 * @param inputs Class values.
 */
export function cn( ...inputs: ClassValue[] ): string {
	return twMerge( clsx( inputs ) );
}
