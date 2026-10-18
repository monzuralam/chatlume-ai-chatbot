/**
 * Sources: what the bot learns from, and everything indexed so far.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { ExternalLink, EyeOff } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';

import { FormSection, SwitchField } from '../../components/form/fields';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Input, inputClasses } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { useKnowledgeSources } from '../../hooks/useKnowledge';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import {
	sourceTypeLabel,
	type KnowledgeSettings,
	type KnowledgeStatus,
	type SourceType,
} from '../../lib/knowledge';
import { cn } from '../../lib/utils';
import { PagePicker } from '../display/PagePicker';

type SourcesForm = Pick<
	KnowledgeSettings,
	'post_types' | 'products' | 'include_ids' | 'exclude_ids' | 'business_info'
>;

const BUSINESS_INFO_MAX = 20000;

/**
 * Form values from the saved settings.
 *
 * @param knowledge Knowledge settings.
 * @return Values.
 */
function toForm( knowledge: KnowledgeSettings ): SourcesForm {
	return {
		post_types: knowledge.post_types,
		products: knowledge.products,
		include_ids: knowledge.include_ids,
		exclude_ids: knowledge.exclude_ids,
		business_info: knowledge.business_info,
	};
}

/**
 * The source settings form.
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 * @param props.status    Index status (post types, chosen items).
 */
function SourcesSettings( {
	knowledge,
	status,
}: {
	knowledge: KnowledgeSettings;
	status: KnowledgeStatus;
} ) {
	const update = useUpdateSettings();
	const form = useForm< SourcesForm >( {
		defaultValues: toForm( knowledge ),
	} );
	const {
		control,
		register,
		setValue,
		formState: { isDirty },
	} = form;
	const values = useWatch( { control } ) as SourcesForm;
	const infoId = useId();

	useUnsavedChangesWarning( isDirty );

	useEffect( () => {
		if ( ! form.formState.isDirty ) {
			form.reset( toForm( knowledge ) );
		}
	}, [ knowledge, form ] );

	const save = form.handleSubmit( async ( next ) => {
		const typesChanged =
			[ ...next.post_types ].sort().join() !==
				[ ...knowledge.post_types ].sort().join() ||
			next.products !== knowledge.products;
		try {
			const saved = await update.mutateAsync( { knowledge: next } );
			form.reset( toForm( saved.knowledge ) );
			toast.success(
				typesChanged
					? __(
							'Saved. Your knowledge base is being rebuilt in the background.',
							'chatlume-ai-chatbot'
						)
					: __( 'Knowledge sources saved.', 'chatlume-ai-chatbot' )
			);
		} catch ( error ) {
			toast.error(
				error instanceof Error
					? error.message
					: __(
							'Settings could not be saved.',
							'chatlume-ai-chatbot'
						)
			);
		}
	} );

	useSaveShortcut( () => {
		if ( isDirty && ! update.isPending ) {
			save();
		}
	}, isDirty );

	// Products have their own switch; WooCommerce keeps them in sync.
	const postTypes = status.post_types.filter(
		( type ) => ! ( status.woocommerce && type.name === 'product' )
	);
	const chosenTypes = values.post_types ?? knowledge.post_types;
	const infoLength = ( values.business_info ?? '' ).length;

	return (
		<form
			className="chatlume:flex chatlume:flex-col chatlume:gap-6"
			onSubmit={ ( event ) => {
				event.preventDefault();
				save();
			} }
			noValidate
		>
			<FormSection
				title={ __( 'Your website', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Published content of these types is learned automatically and kept up to date as you edit. Drafts, private, and password-protected content is never used.',
					'chatlume-ai-chatbot'
				) }
			>
				<fieldset>
					<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
						{ __( 'Content types', 'chatlume-ai-chatbot' ) }
					</legend>
					<div className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-3">
						{ postTypes.map( ( type ) => (
							<span
								key={ type.name }
								className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:text-sm chatlume:text-fg"
							>
								<Checkbox
									label={ type.label }
									checked={ chosenTypes.includes(
										type.name
									) }
									onChange={ ( checked ) =>
										setValue(
											'post_types',
											checked
												? [ ...chosenTypes, type.name ]
												: chosenTypes.filter(
														( name ) =>
															name !== type.name
													),
											{ shouldDirty: true }
										)
									}
								/>
								{ type.label }
							</span>
						) ) }
					</div>
				</fieldset>

				{ status.woocommerce && (
					<SwitchField
						control={ control }
						name="products"
						label={ __(
							'WooCommerce products',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Every product shown in your shop, with its current price, sale, stock status, categories, and description. Updated when products, stock, or sales change.',
							'chatlume-ai-chatbot'
						) }
					/>
				) }

				<Controller
					control={ control }
					name="include_ids"
					render={ ( { field } ) => (
						<PagePicker
							label={ __(
								'Also include',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'Single items of other types, such as one landing page or one product.',
								'chatlume-ai-chatbot'
							) }
							value={ field.value }
							known={ status.chosen.included }
							onChange={ field.onChange }
						/>
					) }
				/>
				<Controller
					control={ control }
					name="exclude_ids"
					render={ ( { field } ) => (
						<PagePicker
							label={ __( 'Leave out', 'chatlume-ai-chatbot' ) }
							hint={ __(
								'Items the bot should never learn from, even though their type is chosen.',
								'chatlume-ai-chatbot'
							) }
							value={ field.value }
							known={ status.chosen.excluded }
							onChange={ field.onChange }
						/>
					) }
				/>
			</FormSection>

			<FormSection
				title={ __( 'About your business', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Facts that are not on your pages: opening hours, delivery areas, policies, contact details. Write in short, clear sentences.',
					'chatlume-ai-chatbot'
				) }
			>
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
					<label
						htmlFor={ infoId }
						className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
					>
						{ __( 'Business information', 'chatlume-ai-chatbot' ) }
					</label>
					<textarea
						id={ infoId }
						rows={ 8 }
						maxLength={ BUSINESS_INFO_MAX }
						aria-describedby={ `${ infoId }-count` }
						className={ cn(
							inputClasses,
							'chatlume:h-auto chatlume:py-2'
						) }
						placeholder={ __(
							'We are open Monday to Saturday, 9am to 6pm. Orders over $50 ship free. Returns are accepted within 30 days.',
							'chatlume-ai-chatbot'
						) }
						{ ...register( 'business_info' ) }
					/>
					<p
						id={ `${ infoId }-count` }
						className="chatlume:text-end chatlume:text-xs chatlume:text-fg-muted"
					>
						{ sprintf(
							/* translators: 1: Characters used, 2: Character limit. */
							__(
								'%1$s of %2$s characters',
								'chatlume-ai-chatbot'
							),
							infoLength.toLocaleString(),
							BUSINESS_INFO_MAX.toLocaleString()
						) }
					</p>
				</div>
			</FormSection>

			<StickySaveBar
				visible={ isDirty }
				saving={ update.isPending }
				onSave={ () => save() }
				onDiscard={ () => form.reset( toForm( knowledge ) ) }
			/>
			{ isDirty && <UnsavedChangesGuard /> }
		</form>
	);
}

/**
 * Everything indexed, with search, type filter, and "Leave out".
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 */
function IndexedSources( { knowledge }: { knowledge: KnowledgeSettings } ) {
	const [ type, setType ] = useState( '' );
	const [ term, setTerm ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const update = useUpdateSettings();
	const searchId = useId();
	const typeId = useId();

	useEffect( () => {
		const timer = window.setTimeout( () => {
			setSearch( term.trim() );
			setPage( 1 );
		}, 300 );
		return () => window.clearTimeout( timer );
	}, [ term ] );

	const sources = useKnowledgeSources( { type, search, page } );
	const total = sources.data?.total ?? 0;
	const pages = Math.max( 1, Math.ceil( total / 20 ) );

	const leaveOut = async ( id: number, title: string ) => {
		try {
			await update.mutateAsync( {
				knowledge: {
					exclude_ids: [ ...knowledge.exclude_ids, id ],
				},
			} );
			toast.success(
				sprintf(
					/* translators: %s: Page or product title. */
					__(
						'“%s” is left out. You can add it back under Leave out.',
						'chatlume-ai-chatbot'
					),
					title
				)
			);
		} catch ( error ) {
			toast.error(
				error instanceof Error
					? error.message
					: __(
							'Settings could not be saved.',
							'chatlume-ai-chatbot'
						)
			);
		}
	};

	return (
		<FormSection
			title={ __( 'What your bot knows', 'chatlume-ai-chatbot' ) }
			description={ sprintf(
				/* translators: %s: Number of indexed items. */
				_n(
					'%s item is indexed.',
					'%s items are indexed.',
					total,
					'chatlume-ai-chatbot'
				),
				total.toLocaleString()
			) }
		>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-3 chatlume:sm:flex-row">
				<div className="chatlume:flex-1">
					<label htmlFor={ searchId } className="chatlume:sr-only">
						{ __( 'Search by title', 'chatlume-ai-chatbot' ) }
					</label>
					<Input
						id={ searchId }
						type="search"
						value={ term }
						placeholder={ __(
							'Search by title…',
							'chatlume-ai-chatbot'
						) }
						onChange={ ( event ) => setTerm( event.target.value ) }
					/>
				</div>
				<div className="chatlume:sm:w-56">
					<label htmlFor={ typeId } className="chatlume:sr-only">
						{ __( 'Type', 'chatlume-ai-chatbot' ) }
					</label>
					<Select
						id={ typeId }
						value={ type }
						onChange={ ( event ) => {
							setType( event.target.value );
							setPage( 1 );
						} }
					>
						<option value="">
							{ __( 'All types', 'chatlume-ai-chatbot' ) }
						</option>
						{ (
							[
								'post',
								'product',
								'document',
								'business',
							] as SourceType[]
						 ).map( ( item ) => (
							<option key={ item } value={ item }>
								{ sourceTypeLabel( item ) }
							</option>
						) ) }
					</Select>
				</div>
			</div>

			{ sources.isLoading && (
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
					<Skeleton className="chatlume:h-12" />
					<Skeleton className="chatlume:h-12" />
					<Skeleton className="chatlume:h-12" />
				</div>
			) }
			{ sources.isError && (
				<p className="chatlume:text-sm chatlume:text-danger">
					{ sources.error.message }
				</p>
			) }
			{ sources.data && sources.data.items.length === 0 && (
				<p className="chatlume:py-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted">
					{ search || type
						? __( 'Nothing matches.', 'chatlume-ai-chatbot' )
						: __(
								'Nothing is indexed yet. Publish a page or add business information above.',
								'chatlume-ai-chatbot'
							) }
				</p>
			) }
			{ sources.data && sources.data.items.length > 0 && (
				<ul
					className="chatlume:divide-y chatlume:divide-border chatlume:rounded-control chatlume:border chatlume:border-border"
					aria-busy={ sources.isFetching }
				>
					{ sources.data.items.map( ( source ) => {
						const postId = Number( source.id );
						const canLeaveOut =
							( source.type === 'post' ||
								source.type === 'product' ) &&
							postId > 0;
						return (
							<li
								key={ `${ source.type }:${ source.id }` }
								className="chatlume:flex chatlume:items-center chatlume:gap-3 chatlume:px-4 chatlume:py-3"
							>
								<div className="chatlume:min-w-0 chatlume:flex-1">
									<p className="chatlume:flex chatlume:items-center chatlume:gap-1.5 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
										<span className="chatlume:truncate">
											{ source.title ||
												__(
													'(no title)',
													'chatlume-ai-chatbot'
												) }
										</span>
										{ source.url && (
											<a
												href={ source.url }
												target="_blank"
												rel="noreferrer"
												className="chatlume:shrink-0 chatlume:text-fg-muted chatlume:hover:text-accent"
												aria-label={ sprintf(
													/* translators: %s: Page title. */
													__(
														'View %s (opens in a new tab)',
														'chatlume-ai-chatbot'
													),
													source.title
												) }
											>
												<ExternalLink
													aria-hidden="true"
													className="chatlume:size-3.5"
												/>
											</a>
										) }
									</p>
									<p className="chatlume:mt-0.5 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2 chatlume:text-xs chatlume:text-fg-muted">
										<Badge>
											{ sourceTypeLabel( source.type ) }
										</Badge>
										{ source.language && (
											<span>
												{ source.language.toUpperCase() }
											</span>
										) }
										<span>
											{ sprintf(
												/* translators: %d: Number of passages. */
												_n(
													'%d passage',
													'%d passages',
													source.chunks,
													'chatlume-ai-chatbot'
												),
												source.chunks
											) }
										</span>
									</p>
								</div>
								{ canLeaveOut && (
									<Button
										type="button"
										size="sm"
										variant="ghost"
										disabled={ update.isPending }
										onClick={ () =>
											leaveOut( postId, source.title )
										}
									>
										<EyeOff aria-hidden="true" />
										{ __(
											'Leave out',
											'chatlume-ai-chatbot'
										) }
									</Button>
								) }
							</li>
						);
					} ) }
				</ul>
			) }

			{ pages > 1 && (
				<nav
					className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3"
					aria-label={ __(
						'Indexed items pages',
						'chatlume-ai-chatbot'
					) }
				>
					<Button
						type="button"
						size="sm"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __( 'Previous', 'chatlume-ai-chatbot' ) }
					</Button>
					<span className="chatlume:text-sm chatlume:text-fg-muted">
						{ sprintf(
							/* translators: 1: Current page, 2: Number of pages. */
							__( 'Page %1$d of %2$d', 'chatlume-ai-chatbot' ),
							page,
							pages
						) }
					</span>
					<Button
						type="button"
						size="sm"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __( 'Next', 'chatlume-ai-chatbot' ) }
					</Button>
				</nav>
			) }
		</FormSection>
	);
}

/**
 * Sources tab.
 *
 * @param props           Props.
 * @param props.knowledge Saved knowledge settings.
 * @param props.status    Index status.
 */
export function SourcesTab( {
	knowledge,
	status,
}: {
	knowledge: KnowledgeSettings;
	status: KnowledgeStatus;
} ) {
	return (
		<div className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6">
			<SourcesSettings knowledge={ knowledge } status={ status } />
			<IndexedSources knowledge={ knowledge } />
		</div>
	);
}
