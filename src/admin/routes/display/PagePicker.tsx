import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Plus, X } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

import { Input } from '../../components/ui/input';
import { searchContent, type ChosenPage } from '../../lib/display';

/**
 * Chooses posts and pages by searching their titles.
 *
 * @param props          Props.
 * @param props.value    Chosen IDs.
 * @param props.known    Titles of pages chosen before.
 * @param props.onChange Receives the new IDs.
 */
export function PagePicker( {
	value,
	known,
	onChange,
}: {
	value: number[];
	known: ChosenPage[];
	onChange: ( ids: number[] ) => void;
} ) {
	const id = useId();
	const [ term, setTerm ] = useState( '' );
	const [ debounced, setDebounced ] = useState( '' );
	const [ titles, setTitles ] = useState< Record< number, ChosenPage > >(
		() => Object.fromEntries( known.map( ( page ) => [ page.id, page ] ) )
	);

	useEffect( () => {
		const timer = window.setTimeout(
			() => setDebounced( term.trim() ),
			300
		);
		return () => window.clearTimeout( timer );
	}, [ term ] );

	const results = useQuery( {
		queryKey: [ 'chatlume', 'display-search', debounced ],
		queryFn: () => searchContent( debounced ),
		enabled: debounced.length >= 2,
		staleTime: 60_000,
	} );

	const add = ( page: ChosenPage ) => {
		setTitles( ( current ) => ( { ...current, [ page.id ]: page } ) );
		if ( ! value.includes( page.id ) ) {
			onChange( [ ...value, page.id ] );
		}
		setTerm( '' );
	};

	const options = ( results.data ?? [] ).filter(
		( page ) => ! value.includes( page.id )
	);

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
			<label
				htmlFor={ id }
				className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
			>
				{ __( 'Specific pages and posts', 'chatlume-ai-chatbot' ) }
			</label>
			{ value.length > 0 && (
				<ul className="chatlume:flex chatlume:flex-wrap chatlume:gap-1.5">
					{ value.map( ( pageId ) => {
						const page = titles[ pageId ];
						const title =
							page?.title ||
							sprintf(
								/* translators: %d: Post ID. */
								__( 'Item #%d', 'chatlume-ai-chatbot' ),
								pageId
							);
						return (
							<li
								key={ pageId }
								className="chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:rounded-full chatlume:bg-accent-soft chatlume:py-0.5 chatlume:ps-2.5 chatlume:pe-1 chatlume:text-xs chatlume:font-medium chatlume:text-accent"
							>
								{ title }
								<button
									type="button"
									className="chatlume:rounded-full chatlume:p-0.5 chatlume:hover:bg-accent chatlume:hover:text-white"
									aria-label={ sprintf(
										/* translators: %s: Page title. */
										__(
											'Remove %s',
											'chatlume-ai-chatbot'
										),
										title
									) }
									onClick={ () =>
										onChange(
											value.filter(
												( item ) => item !== pageId
											)
										)
									}
								>
									<X
										aria-hidden="true"
										className="chatlume:size-3"
									/>
								</button>
							</li>
						);
					} ) }
				</ul>
			) }
			<Input
				id={ id }
				type="search"
				value={ term }
				placeholder={ __( 'Search by title…', 'chatlume-ai-chatbot' ) }
				autoComplete="off"
				onChange={ ( event ) => setTerm( event.target.value ) }
			/>
			{ debounced.length >= 2 && (
				<div
					className="chatlume:rounded-control chatlume:border chatlume:border-border"
					aria-live="polite"
				>
					{ results.isFetching && ! results.data && (
						<p className="chatlume:p-3 chatlume:text-sm chatlume:text-fg-muted">
							{ __( 'Searching…', 'chatlume-ai-chatbot' ) }
						</p>
					) }
					{ results.isError && (
						<p className="chatlume:p-3 chatlume:text-sm chatlume:text-danger">
							{ results.error.message }
						</p>
					) }
					{ results.data && options.length === 0 && (
						<p className="chatlume:p-3 chatlume:text-sm chatlume:text-fg-muted">
							{ __( 'Nothing found.', 'chatlume-ai-chatbot' ) }
						</p>
					) }
					{ options.length > 0 && (
						<ul className="chatlume:max-h-60 chatlume:overflow-y-auto chatlume:py-1">
							{ options.map( ( page ) => (
								<li key={ page.id }>
									<button
										type="button"
										className="chatlume:flex chatlume:w-full chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:px-3 chatlume:py-2 chatlume:text-start chatlume:text-sm chatlume:hover:bg-surface-muted chatlume:focus-visible:bg-surface-muted chatlume:focus-visible:outline-none"
										onClick={ () => add( page ) }
									>
										<span className="chatlume:min-w-0 chatlume:truncate chatlume:text-fg">
											{ page.title ||
												__(
													'(no title)',
													'chatlume-ai-chatbot'
												) }
										</span>
										<span className="chatlume:flex chatlume:shrink-0 chatlume:items-center chatlume:gap-1 chatlume:text-xs chatlume:text-fg-muted">
											{ page.type }
											<Plus
												aria-hidden="true"
												className="chatlume:size-3.5"
											/>
										</span>
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</div>
	);
}
