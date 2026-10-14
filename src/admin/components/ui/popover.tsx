import * as PopoverPrimitive from '@radix-ui/react-popover';
import {
	forwardRef,
	type ComponentPropsWithoutRef,
	type ElementRef,
} from 'react';

import { cn } from '../../lib/utils';
import { usePortalContainer } from '../PortalContainer';

export const Popover = PopoverPrimitive.Root;
export const PopoverTrigger = PopoverPrimitive.Trigger;
export const PopoverAnchor = PopoverPrimitive.Anchor;

/**
 * Popover content rendered inside #chatlume-admin.
 */
export const PopoverContent = forwardRef<
	ElementRef< typeof PopoverPrimitive.Content >,
	ComponentPropsWithoutRef< typeof PopoverPrimitive.Content >
>( ( { className, align = 'start', sideOffset = 6, ...props }, ref ) => {
	const container = usePortalContainer();

	return (
		<PopoverPrimitive.Portal container={ container }>
			<PopoverPrimitive.Content
				ref={ ref }
				align={ align }
				sideOffset={ sideOffset }
				className={ cn(
					'chatlume:z-[100002] chatlume:rounded-card chatlume:border chatlume:border-border chatlume:bg-surface chatlume:text-fg chatlume:shadow-float chatlume:outline-none',
					className
				) }
				{ ...props }
			/>
		</PopoverPrimitive.Portal>
	);
} );
PopoverContent.displayName = 'PopoverContent';
