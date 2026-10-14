import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';
import { Tooltip } from './tooltip';

interface FieldProps {
	id: string;
	label: string;
	hint?: ReactNode;
	tooltip?: string;
	error?: string;
	className?: string;
	children: ReactNode;
}

/**
 * Label, control, hint, and inline error. The control must use `id` and
 * should set aria-describedby={ `${ id }-hint ${ id }-error` } when present.
 *
 * @param props           Props.
 * @param props.id        Control ID.
 * @param props.label     Label text.
 * @param props.hint      Help text under the control.
 * @param props.tooltip   Extra explanation behind an info icon.
 * @param props.error     Validation message.
 * @param props.className Extra classes.
 * @param props.children  Control.
 */
export function Field( {
	id,
	label,
	hint,
	tooltip,
	error,
	className,
	children,
}: FieldProps ) {
	return (
		<div
			className={ cn(
				'chatlume:flex chatlume:flex-col chatlume:gap-1.5',
				className
			) }
		>
			<div className="chatlume:flex chatlume:items-center chatlume:gap-1.5">
				<label
					htmlFor={ id }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ label }
				</label>
				{ tooltip && (
					<Tooltip content={ tooltip }>
						<button
							type="button"
							aria-label={ tooltip }
							className="chatlume:rounded-full chatlume:text-fg-muted chatlume:hover:text-fg"
						>
							<Info
								aria-hidden="true"
								className="chatlume:size-3.5"
							/>
						</button>
					</Tooltip>
				) }
			</div>
			{ children }
			{ hint && ! error && (
				<p
					id={ `${ id }-hint` }
					className="chatlume:text-[13px] chatlume:text-fg-muted"
				>
					{ hint }
				</p>
			) }
			{ error && (
				<p
					id={ `${ id }-error` }
					role="alert"
					className="chatlume:text-[13px] chatlume:font-medium chatlume:text-danger"
				>
					{ error }
				</p>
			) }
		</div>
	);
}
