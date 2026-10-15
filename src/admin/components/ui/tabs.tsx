import { useId, useRef, type KeyboardEvent, type ReactNode } from 'react';

import { cn } from '../../lib/utils';

export interface TabItem {
	id: string;
	label: string;
	/** Small badge after the label (for example a count). */
	badge?: ReactNode;
}

interface TabsProps {
	tabs: TabItem[];
	value: string;
	onChange: ( id: string ) => void;
	label: string;
	children: ReactNode;
}

/**
 * Accessible tabs (WAI-ARIA tabs pattern): arrow keys, Home, and End move
 * between tabs, which activate on focus.
 *
 * @param props          Props.
 * @param props.tabs     Tabs.
 * @param props.value    Selected tab ID.
 * @param props.onChange Selection handler.
 * @param props.label    Accessible name of the tab list.
 * @param props.children Panel of the selected tab.
 */
export function Tabs( { tabs, value, onChange, label, children }: TabsProps ) {
	const baseId = useId();
	const refs = useRef< Record< string, HTMLButtonElement | null > >( {} );

	const onKeyDown = ( event: KeyboardEvent< HTMLButtonElement > ) => {
		const index = tabs.findIndex( ( tab ) => tab.id === value );
		let next = -1;
		if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
			next = ( index + 1 ) % tabs.length;
		} else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
			next = ( index - 1 + tabs.length ) % tabs.length;
		} else if ( event.key === 'Home' ) {
			next = 0;
		} else if ( event.key === 'End' ) {
			next = tabs.length - 1;
		}
		if ( next >= 0 ) {
			event.preventDefault();
			onChange( tabs[ next ].id );
			refs.current[ tabs[ next ].id ]?.focus();
		}
	};

	return (
		<div>
			<div
				role="tablist"
				aria-label={ label }
				className="chatlume:mb-6 chatlume:flex chatlume:gap-1 chatlume:overflow-x-auto chatlume:overflow-y-hidden chatlume:border-b chatlume:border-border"
			>
				{ tabs.map( ( tab ) => {
					const selected = tab.id === value;
					return (
						<button
							key={ tab.id }
							ref={ ( node ) => {
								refs.current[ tab.id ] = node;
							} }
							type="button"
							role="tab"
							id={ `${ baseId }-tab-${ tab.id }` }
							aria-selected={ selected }
							aria-controls={ `${ baseId }-panel` }
							tabIndex={ selected ? 0 : -1 }
							onClick={ () => onChange( tab.id ) }
							onKeyDown={ onKeyDown }
							className={ cn(
								'chatlume:inline-flex chatlume:items-center chatlume:gap-2 chatlume:whitespace-nowrap chatlume:border-b-2 chatlume:px-3 chatlume:py-2.5 chatlume:text-sm chatlume:font-medium chatlume:transition-colors',
								'chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring chatlume:rounded-t-control',
								selected
									? 'chatlume:border-accent chatlume:text-fg'
									: 'chatlume:border-transparent chatlume:text-fg-muted chatlume:hover:text-fg'
							) }
						>
							{ tab.label }
							{ tab.badge }
						</button>
					);
				} ) }
			</div>
			<div
				role="tabpanel"
				id={ `${ baseId }-panel` }
				aria-labelledby={ `${ baseId }-tab-${ value }` }
				tabIndex={ 0 }
				className="chatlume:focus-visible:outline-none"
			>
				{ children }
			</div>
		</div>
	);
}
