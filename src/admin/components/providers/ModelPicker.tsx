import { __, sprintf } from '@wordpress/i18n';
import {
	Check,
	ChevronsUpDown,
	LoaderCircle,
	RefreshCw,
	Search,
} from 'lucide-react';
import { useId, useMemo, useState, type KeyboardEvent } from 'react';
import { toast } from 'sonner';

import { useModels, useRefreshModels } from '../../hooks/useProviders';
import {
	formatContext,
	formatPrice,
	type ModelInfo,
} from '../../lib/providers';
import { cn } from '../../lib/utils';
import { Badge } from '../ui/badge';
import { Button } from '../ui/button';
import { inputClasses } from '../ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '../ui/popover';
import { Skeleton } from '../ui/skeleton';

interface ModelPickerProps {
	id: string;
	providerId: string;
	kind: 'chat' | 'embedding';
	value: string;
	onChange: ( model: string ) => void;
	disabled?: boolean;
	placeholder?: string;
	describedBy?: string;
	invalid?: boolean;
}

const MODEL_ID = /^[A-Za-z0-9._:/@+\-]{1,200}$/;

/**
 * Filters models by a search string (ID or label, case-insensitive).
 *
 * @param models Models.
 * @param query  Search text.
 */
export function filterModels(
	models: ModelInfo[],
	query: string
): ModelInfo[] {
	const needle = query.trim().toLowerCase();
	if ( needle === '' ) {
		return models;
	}
	return models.filter(
		( model ) =>
			model.id.toLowerCase().includes( needle ) ||
			model.label.toLowerCase().includes( needle )
	);
}

function ModelMeta( { model }: { model: ModelInfo } ) {
	const context = formatContext( model.context_length );
	const prices =
		model.input_price !== null &&
		model.output_price !== null &&
		! model.free
			? sprintf(
					/* translators: 1: Input price, 2: Output price (USD per million tokens). */
					__( '%1$s / %2$s per 1M tokens', 'chatlume-ai-chatbot' ),
					formatPrice( model.input_price ),
					formatPrice( model.output_price )
				)
			: '';

	return (
		<span className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-1.5">
			{ model.recommended && (
				<Badge tone="accent">
					{ __( 'Recommended', 'chatlume-ai-chatbot' ) }
				</Badge>
			) }
			{ model.free && (
				<Badge tone="success">
					{ __( 'Free', 'chatlume-ai-chatbot' ) }
				</Badge>
			) }
			{ context && (
				<span className="chatlume:text-xs chatlume:text-fg-muted">
					{ sprintf(
						/* translators: %s: Context window size such as "128K". */
						__( '%s context', 'chatlume-ai-chatbot' ),
						context
					) }
				</span>
			) }
			{ prices && (
				<span className="chatlume:text-xs chatlume:text-fg-muted">
					{ prices }
				</span>
			) }
		</span>
	);
}

/**
 * Searchable model selector backed by the provider's live model list.
 * Typing an ID that is not listed offers it as a custom model.
 *
 * @param props             Props.
 * @param props.id          Trigger ID (for the field label).
 * @param props.providerId  Provider whose models are listed.
 * @param props.kind        chat or embedding models.
 * @param props.value       Selected model ID.
 * @param props.onChange    Selection handler.
 * @param props.disabled    Disabled.
 * @param props.placeholder Placeholder.
 * @param props.describedBy aria-describedby for the trigger.
 * @param props.invalid     Marks the trigger invalid.
 */
export function ModelPicker( {
	id,
	providerId,
	kind,
	value,
	onChange,
	disabled = false,
	placeholder,
	describedBy,
	invalid = false,
}: ModelPickerProps ) {
	const [ open, setOpen ] = useState( false );
	const [ query, setQuery ] = useState( '' );
	const [ active, setActive ] = useState( 0 );
	const listId = useId();
	const models = useModels( providerId, kind, open && ! disabled );
	const refresh = useRefreshModels();

	const visible = useMemo(
		() => filterModels( models.data ?? [], query ),
		[ models.data, query ]
	);
	const custom = query.trim();
	const offerCustom =
		MODEL_ID.test( custom ) &&
		! ( models.data ?? [] ).some( ( model ) => model.id === custom );
	const options = [
		...visible.map( ( model ) => model.id ),
		...( offerCustom ? [ custom ] : [] ),
	];

	const select = ( model: string ) => {
		onChange( model );
		setOpen( false );
		setQuery( '' );
	};

	const onKeyDown = ( event: KeyboardEvent< HTMLInputElement > ) => {
		if ( event.key === 'ArrowDown' ) {
			event.preventDefault();
			setActive( ( index ) => Math.min( index + 1, options.length - 1 ) );
		} else if ( event.key === 'ArrowUp' ) {
			event.preventDefault();
			setActive( ( index ) => Math.max( index - 1, 0 ) );
		} else if ( event.key === 'Enter' && options[ active ] ) {
			event.preventDefault();
			select( options[ active ] );
		}
	};

	const selected = ( models.data ?? [] ).find(
		( model ) => model.id === value
	);

	let triggerLabel = value;
	if ( value === '' ) {
		triggerLabel =
			placeholder ?? __( 'Choose a model', 'chatlume-ai-chatbot' );
	} else if ( selected?.label && selected.label !== value ) {
		triggerLabel = `${ selected.label } (${ value })`;
	}

	return (
		<Popover
			open={ open }
			onOpenChange={ ( next ) => {
				setOpen( next );
				setActive( 0 );
				if ( ! next ) {
					setQuery( '' );
				}
			} }
		>
			<PopoverTrigger asChild disabled={ disabled }>
				<button
					id={ id }
					type="button"
					role="combobox"
					aria-expanded={ open }
					aria-controls={ listId }
					aria-describedby={ describedBy }
					aria-invalid={ invalid || undefined }
					className={ cn(
						inputClasses,
						'chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2 chatlume:text-start chatlume:cursor-pointer'
					) }
				>
					<span
						className={ cn(
							'chatlume:truncate',
							value === '' && 'chatlume:text-fg-muted'
						) }
					>
						{ triggerLabel }
					</span>
					<ChevronsUpDown
						aria-hidden="true"
						className="chatlume:size-4 chatlume:shrink-0 chatlume:text-fg-muted"
					/>
				</button>
			</PopoverTrigger>
			<PopoverContent className="chatlume:w-[min(32rem,calc(100vw-2rem))] chatlume:p-0">
				<div className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:border-b chatlume:border-border chatlume:px-3 chatlume:py-2">
					<Search
						aria-hidden="true"
						className="chatlume:size-4 chatlume:shrink-0 chatlume:text-fg-muted"
					/>
					<input
						// The popover opens on purpose to search, so focusing the field is expected.
						// eslint-disable-next-line jsx-a11y/no-autofocus
						autoFocus
						value={ query }
						onChange={ ( event ) => {
							setQuery( event.target.value );
							setActive( 0 );
						} }
						onKeyDown={ onKeyDown }
						role="searchbox"
						aria-controls={ listId }
						aria-activedescendant={
							options[ active ]
								? `${ listId }-${ active }`
								: undefined
						}
						aria-label={ __(
							'Search models or type a model ID',
							'chatlume-ai-chatbot'
						) }
						placeholder={ __(
							'Search models or type a model ID…',
							'chatlume-ai-chatbot'
						) }
						className="chatlume:h-8 chatlume:flex-1 chatlume:bg-transparent chatlume:text-sm chatlume:outline-none chatlume:placeholder:text-fg-muted/70"
					/>
					<Button
						variant="ghost"
						size="sm"
						disabled={ refresh.isPending }
						onClick={ () =>
							refresh.mutate(
								{ id: providerId, kind },
								{
									onSuccess: () =>
										toast.success(
											__(
												'Model list refreshed.',
												'chatlume-ai-chatbot'
											)
										),
									onError: ( error ) =>
										toast.error( error.message ),
								}
							)
						}
					>
						{ refresh.isPending ? (
							<LoaderCircle
								aria-hidden="true"
								className="chatlume:animate-spin"
							/>
						) : (
							<RefreshCw aria-hidden="true" />
						) }
						{ __( 'Refresh', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>

				<ul
					id={ listId }
					role="listbox"
					aria-label={ __( 'Models', 'chatlume-ai-chatbot' ) }
					className="chatlume:max-h-80 chatlume:overflow-y-auto chatlume:p-1.5"
				>
					{ models.isPending && (
						<li
							className="chatlume:space-y-2 chatlume:p-2"
							aria-hidden="true"
						>
							<Skeleton className="chatlume:h-9" />
							<Skeleton className="chatlume:h-9" />
							<Skeleton className="chatlume:h-9" />
						</li>
					) }

					{ models.isError && (
						<li
							className="chatlume:px-3 chatlume:py-3 chatlume:text-sm"
							role="alert"
						>
							<p className="chatlume:font-medium chatlume:text-danger">
								{ models.error.message }
							</p>
							<p className="chatlume:mt-1 chatlume:text-fg-muted">
								{ __(
									'Check the API key and base URL, or type a model ID above to use it anyway.',
									'chatlume-ai-chatbot'
								) }
							</p>
						</li>
					) }

					{ models.isSuccess &&
						visible.length === 0 &&
						! offerCustom && (
							<li className="chatlume:px-3 chatlume:py-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted">
								{ __(
									'No models match your search.',
									'chatlume-ai-chatbot'
								) }
							</li>
						) }

					{ visible.map( ( model, index ) => (
						<li
							key={ model.id }
							id={ `${ listId }-${ index }` }
							role="option"
							aria-selected={ model.id === value }
							onMouseEnter={ () => setActive( index ) }
							onClick={ () => select( model.id ) }
							onKeyDown={ ( event ) =>
								event.key === 'Enter' && select( model.id )
							}
							className={ cn(
								'chatlume:flex chatlume:cursor-pointer chatlume:items-start chatlume:gap-2 chatlume:rounded-lg chatlume:px-2.5 chatlume:py-2',
								index === active && 'chatlume:bg-surface-muted'
							) }
						>
							<Check
								aria-hidden="true"
								className={ cn(
									'chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-accent',
									model.id !== value && 'chatlume:invisible'
								) }
							/>
							<span className="chatlume:min-w-0 chatlume:flex-1">
								<span className="chatlume:block chatlume:truncate chatlume:text-sm chatlume:font-medium">
									{ model.label }
								</span>
								{ model.label !== model.id && (
									<span className="chatlume:block chatlume:truncate chatlume:text-xs chatlume:text-fg-muted">
										{ model.id }
									</span>
								) }
								<span className="chatlume:mt-1 chatlume:block">
									<ModelMeta model={ model } />
								</span>
							</span>
						</li>
					) ) }

					{ offerCustom && (
						<li
							id={ `${ listId }-${ visible.length }` }
							role="option"
							aria-selected={ custom === value }
							onMouseEnter={ () => setActive( visible.length ) }
							onClick={ () => select( custom ) }
							onKeyDown={ ( event ) =>
								event.key === 'Enter' && select( custom )
							}
							className={ cn(
								'chatlume:cursor-pointer chatlume:rounded-lg chatlume:px-2.5 chatlume:py-2 chatlume:text-sm',
								active === visible.length &&
									'chatlume:bg-surface-muted'
							) }
						>
							{ sprintf(
								/* translators: %s: Model ID typed by the admin. */
								__(
									'Use “%s” as a custom model ID',
									'chatlume-ai-chatbot'
								),
								custom
							) }
						</li>
					) }
				</ul>
			</PopoverContent>
		</Popover>
	);
}
