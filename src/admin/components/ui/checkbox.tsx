import { Check } from 'lucide-react';
import { useId } from 'react';

/**
 * Checkbox: a native, accessible input (visually hidden) with a drawn box,
 * so wp-admin form styles cannot change how it looks.
 *
 * @param props          Props.
 * @param props.checked  Whether it is checked.
 * @param props.onChange Change handler.
 * @param props.label    Accessible label.
 */
export function Checkbox( {
	checked,
	onChange,
	label,
}: {
	checked: boolean;
	onChange: ( checked: boolean ) => void;
	label: string;
} ) {
	const id = useId();
	return (
		<label
			htmlFor={ id }
			className="chatlume:relative chatlume:inline-flex chatlume:cursor-pointer chatlume:align-middle"
		>
			<input
				id={ id }
				type="checkbox"
				className="chatlume:peer chatlume:sr-only"
				checked={ checked }
				aria-label={ label }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<span
				aria-hidden="true"
				className="chatlume:flex chatlume:size-4 chatlume:items-center chatlume:justify-center chatlume:rounded-[4px] chatlume:border chatlume:border-fg-muted/50 chatlume:bg-surface chatlume:text-white chatlume:transition-colors chatlume:peer-checked:border-accent chatlume:peer-checked:bg-accent chatlume:peer-focus-visible:ring-[3px] chatlume:peer-focus-visible:ring-ring"
			>
				{ checked && (
					<Check className="chatlume:size-3" strokeWidth={ 3 } />
				) }
			</span>
		</label>
	);
}
