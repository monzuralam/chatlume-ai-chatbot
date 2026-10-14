import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { forwardRef, type ButtonHTMLAttributes } from 'react';

import { cn } from '../../lib/utils';

export const buttonVariants = cva(
	[
		'chatlume:inline-flex chatlume:shrink-0 chatlume:items-center chatlume:justify-center chatlume:gap-2',
		'chatlume:rounded-control chatlume:text-sm chatlume:font-medium chatlume:whitespace-nowrap',
		'chatlume:transition-colors chatlume:duration-150 chatlume:select-none',
		'chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
		'chatlume:disabled:pointer-events-none chatlume:disabled:opacity-50',
		'chatlume:[&_svg]:size-4 chatlume:[&_svg]:shrink-0',
	],
	{
		variants: {
			variant: {
				primary:
					'chatlume:bg-accent chatlume:text-accent-fg chatlume:shadow-card chatlume:hover:bg-accent-hover',
				secondary:
					'chatlume:border chatlume:border-border chatlume:bg-surface chatlume:text-fg chatlume:shadow-card chatlume:hover:bg-surface-muted',
				ghost: 'chatlume:text-fg-muted chatlume:hover:bg-surface-muted chatlume:hover:text-fg',
				danger: 'chatlume:bg-danger chatlume:text-white chatlume:hover:opacity-90',
			},
			size: {
				sm: 'chatlume:h-8 chatlume:px-3 chatlume:text-[13px]',
				md: 'chatlume:h-9 chatlume:px-4',
				icon: 'chatlume:size-9',
			},
		},
		defaultVariants: {
			variant: 'secondary',
			size: 'md',
		},
	}
);

export interface ButtonProps
	extends
		ButtonHTMLAttributes< HTMLButtonElement >,
		VariantProps< typeof buttonVariants > {
	/** Render the child element (e.g. a link) with button styles. */
	asChild?: boolean;
}

export const Button = forwardRef< HTMLButtonElement, ButtonProps >(
	( { className, variant, size, asChild = false, type, ...props }, ref ) => {
		const Component = asChild ? Slot : 'button';
		return (
			<Component
				ref={ ref }
				className={ cn(
					buttonVariants( { variant, size } ),
					className
				) }
				{ ...( asChild ? {} : { type: type ?? 'button' } ) }
				{ ...props }
			/>
		);
	}
);
Button.displayName = 'Button';
