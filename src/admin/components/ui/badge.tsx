import { cva, type VariantProps } from 'class-variance-authority';
import type { HTMLAttributes } from 'react';

import { cn } from '../../lib/utils';

export const badgeVariants = cva(
	'chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:rounded-full chatlume:px-2.5 chatlume:py-0.5 chatlume:text-xs chatlume:font-medium chatlume:whitespace-nowrap chatlume:[&_svg]:size-3.5',
	{
		variants: {
			tone: {
				neutral: 'chatlume:bg-surface-muted chatlume:text-fg-muted',
				accent: 'chatlume:bg-accent-soft chatlume:text-accent',
				success: 'chatlume:bg-success-soft chatlume:text-success',
				warning: 'chatlume:bg-warning-soft chatlume:text-warning',
				danger: 'chatlume:bg-danger-soft chatlume:text-danger',
			},
		},
		defaultVariants: { tone: 'neutral' },
	}
);

export interface BadgeProps
	extends
		HTMLAttributes< HTMLSpanElement >,
		VariantProps< typeof badgeVariants > {}

export function Badge( { className, tone, ...props }: BadgeProps ) {
	return (
		<span
			className={ cn( badgeVariants( { tone } ), className ) }
			{ ...props }
		/>
	);
}
