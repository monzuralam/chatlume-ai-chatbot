import type { HTMLAttributes } from 'react';

import { cn } from '../../lib/utils';

/**
 * Loading placeholder. The pulse animation is disabled under
 * prefers-reduced-motion by the app's global motion rule.
 *
 * @param props           Div attributes.
 * @param props.className Extra classes.
 */
export function Skeleton( {
	className,
	...props
}: HTMLAttributes< HTMLDivElement > ) {
	return (
		<div
			aria-hidden="true"
			className={ cn(
				'chatlume:animate-pulse chatlume:rounded-control chatlume:bg-surface-muted',
				className
			) }
			{ ...props }
		/>
	);
}
