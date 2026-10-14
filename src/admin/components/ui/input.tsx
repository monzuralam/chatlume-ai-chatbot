import { forwardRef, type InputHTMLAttributes } from 'react';

import { cn } from '../../lib/utils';

export const inputClasses = cn(
	'chatlume:h-9 chatlume:w-full chatlume:min-w-0 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:bg-surface',
	'chatlume:px-3 chatlume:text-sm chatlume:text-fg chatlume:shadow-card chatlume:transition-colors',
	'chatlume:placeholder:text-fg-muted/70',
	'chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring chatlume:focus-visible:border-accent',
	'chatlume:disabled:cursor-not-allowed chatlume:disabled:opacity-60',
	'chatlume:aria-[invalid=true]:border-danger'
);

export const Input = forwardRef<
	HTMLInputElement,
	InputHTMLAttributes< HTMLInputElement >
>( ( { className, type = 'text', ...props }, ref ) => (
	<input
		ref={ ref }
		type={ type }
		className={ cn( inputClasses, className ) }
		{ ...props }
	/>
) );
Input.displayName = 'Input';
