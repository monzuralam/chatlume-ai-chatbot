import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';
import { usePortalContainer } from '../PortalContainer';

export const TooltipProvider = TooltipPrimitive.Provider;

interface TooltipProps {
	content: ReactNode;
	children: ReactNode;
	side?: 'top' | 'right' | 'bottom' | 'left';
	/** Extra classes for the tooltip bubble (e.g. to hide it at some widths). */
	className?: string;
}

/**
 * Accessible tooltip that renders inside #chatlume-admin.
 *
 * @param props           Props.
 * @param props.content   Tooltip text.
 * @param props.children  Trigger element (must accept a ref).
 * @param props.side      Preferred side.
 * @param props.className Extra classes for the bubble.
 */
export function Tooltip( {
	content,
	children,
	side = 'top',
	className,
}: TooltipProps ) {
	const container = usePortalContainer();

	return (
		<TooltipPrimitive.Root>
			<TooltipPrimitive.Trigger asChild>
				{ children }
			</TooltipPrimitive.Trigger>
			<TooltipPrimitive.Portal container={ container }>
				<TooltipPrimitive.Content
					side={ side }
					sideOffset={ 6 }
					className={ cn(
						'chatlume:z-50 chatlume:max-w-xs chatlume:rounded-lg chatlume:bg-fg chatlume:px-2.5 chatlume:py-1.5',
						'chatlume:text-xs chatlume:font-medium chatlume:text-canvas chatlume:shadow-float',
						className
					) }
				>
					{ content }
				</TooltipPrimitive.Content>
			</TooltipPrimitive.Portal>
		</TooltipPrimitive.Root>
	);
}
