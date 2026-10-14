import * as SwitchPrimitive from '@radix-ui/react-switch';
import {
	forwardRef,
	type ComponentPropsWithoutRef,
	type ElementRef,
} from 'react';

import { cn } from '../../lib/utils';

export const Switch = forwardRef<
	ElementRef< typeof SwitchPrimitive.Root >,
	ComponentPropsWithoutRef< typeof SwitchPrimitive.Root >
>( ( { className, ...props }, ref ) => (
	<SwitchPrimitive.Root
		ref={ ref }
		className={ cn(
			'chatlume:peer chatlume:inline-flex chatlume:h-6 chatlume:w-11 chatlume:shrink-0 chatlume:items-center chatlume:rounded-full chatlume:p-0.5',
			'chatlume:bg-border chatlume:transition-colors chatlume:duration-150 chatlume:data-[state=checked]:bg-accent',
			'chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
			'chatlume:disabled:cursor-not-allowed chatlume:disabled:opacity-50',
			className
		) }
		{ ...props }
	>
		<SwitchPrimitive.Thumb
			className={ cn(
				'chatlume:block chatlume:size-5 chatlume:rounded-full chatlume:bg-white chatlume:shadow-card',
				'chatlume:transition-transform chatlume:duration-150 chatlume:data-[state=checked]:translate-x-5',
				'chatlume:rtl:data-[state=checked]:-translate-x-5'
			) }
		/>
	</SwitchPrimitive.Root>
) );
Switch.displayName = 'Switch';
