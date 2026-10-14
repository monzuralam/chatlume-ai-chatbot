import { ChevronDown } from 'lucide-react';
import { forwardRef, type SelectHTMLAttributes } from 'react';

import { cn } from '../../lib/utils';
import { inputClasses } from './input';

/**
 * Native select (fully accessible and keyboard-friendly) with app styling.
 */
export const Select = forwardRef<
	HTMLSelectElement,
	SelectHTMLAttributes< HTMLSelectElement >
>( ( { className, children, ...props }, ref ) => (
	<div className="chatlume:relative chatlume:w-full">
		<select
			ref={ ref }
			className={ cn(
				inputClasses,
				'chatlume:appearance-none chatlume:pe-9 chatlume:cursor-pointer',
				className
			) }
			{ ...props }
		>
			{ children }
		</select>
		<ChevronDown
			aria-hidden="true"
			className="chatlume:pointer-events-none chatlume:absolute chatlume:end-3 chatlume:top-1/2 chatlume:size-4 chatlume:-translate-y-1/2 chatlume:text-fg-muted"
		/>
	</div>
) );
Select.displayName = 'Select';
