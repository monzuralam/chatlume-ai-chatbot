/**
 * Conversations: searchable, filterable log with transcripts, deletion,
 * and export. Filters, page, and the open transcript live in the address.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	ChevronLeft,
	ChevronRight,
	Download,
	ExternalLink,
	FilterX,
	Search,
	ThumbsDown,
	ThumbsUp,
	Trash2,
} from 'lucide-react';
import {
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
	type ReactNode,
} from 'react';
import { useSearchParams } from 'react-router';
import { toast } from 'sonner';

import { EmptyState } from '../../components/layout/EmptyState';
import { PageHeader } from '../../components/layout/PageHeader';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Card } from '../../components/ui/card';
import { Checkbox } from '../../components/ui/checkbox';
import { Input } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import {
	useConversations,
	useDeleteConversations,
} from '../../hooks/useConversations';
import { getBootstrap } from '../../lib/bootstrap';
import {
	activeFilters,
	downloadFile,
	EMPTY_FILTERS,
	fetchExport,
	toCsv,
	type ConversationFilters,
} from '../../lib/conversations';
import { formatCost, formatDateTime, sourceLabel } from './format';
import { TranscriptSheet } from './TranscriptSheet';

const PER_PAGE = 25;

/**
 * A filter control with a small visible label.
 *
 * @param props          Props.
 * @param props.id       Control ID.
 * @param props.label    Label.
 * @param props.children Control.
 */
function FilterLabel( {
	id,
	label,
	children,
}: {
	id: string;
	label: string;
	children: ReactNode;
} ) {
	return (
		<div className="chatlume:flex chatlume:min-w-0 chatlume:flex-col chatlume:gap-1">
			<label
				htmlFor={ id }
				className="chatlume:text-xs chatlume:font-medium chatlume:text-fg-muted"
			>
				{ label }
			</label>
			{ children }
		</div>
	);
}
const SOURCES = [
	'ai',
	'quick_answer',
	'cache',
	'fallback_model',
	'site_search',
	'guardrail',
	'offline',
];

/**
 * Filters from the address.
 *
 * @param params Search params.
 * @return Filters.
 */
function readFilters( params: URLSearchParams ): ConversationFilters {
	const filters = { ...EMPTY_FILTERS };
	(
		Object.keys( EMPTY_FILTERS ) as Array< keyof ConversationFilters >
	 ).forEach( ( key ) => {
		const value = params.get( key );
		if ( value !== null ) {
			( filters as Record< string, string > )[ key ] = value;
		}
	} );
	return filters;
}

/**
 * The Conversations page.
 */
export default function ConversationsRoute() {
	const [ params, setParams ] = useSearchParams();
	const filters = useMemo( () => readFilters( params ), [ params ] );
	const page = Math.max( 1, Number( params.get( 'page' ) ) || 1 );
	const openId = Number( params.get( 'id' ) ) || 0;
	const list = useConversations( filters, page, PER_PAGE );
	const remove = useDeleteConversations();
	const [ search, setSearch ] = useState( filters.search );
	const [ selected, setSelected ] = useState< number[] >( [] );
	const [ confirming, setConfirming ] = useState( false );
	const [ exporting, setExporting ] = useState( false );
	const ids = {
		search: useId(),
		from: useId(),
		to: useId(),
		rating: useId(),
		source: useId(),
		lead: useId(),
		language: useId(),
	};

	// The address this page last asked for. Navigations are asynchronous, so
	// a quick second change (or a delayed search) must build on this, not on
	// the params of an earlier render, or it would undo the first change.
	const latest = useRef( params );
	useEffect( () => {
		latest.current = params;
	}, [ params ] );

	const write = ( next: URLSearchParams ) => {
		latest.current = next;
		setParams( next, { replace: true } );
	};

	const update = ( next: Record< string, string | number | null > ) => {
		const merged = new URLSearchParams( latest.current );
		Object.entries( next ).forEach( ( [ key, value ] ) => {
			if ( value === null || value === '' ) {
				merged.delete( key );
			} else {
				merged.set( key, String( value ) );
			}
		} );
		write( merged );
	};

	const setFilter = ( key: keyof ConversationFilters, value: string ) => {
		update( {
			[ key ]: value === EMPTY_FILTERS[ key ] ? null : value,
			page: null,
		} );
		setSelected( [] );
	};

	// Search as you type, without a request per keystroke.
	useEffect( () => {
		if ( search === filters.search ) {
			return undefined;
		}
		const timer = window.setTimeout(
			() => setFilter( 'search', search.trim() ),
			350
		);
		return () => window.clearTimeout( timer );
		// setFilter only changes with the address.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ search ] );

	const filtered = Object.keys( activeFilters( filters ) ).length > 0;
	const items = list.data?.items ?? [];
	const total = list.data?.total ?? 0;
	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const allSelected =
		items.length > 0 &&
		items.every( ( item ) => selected.includes( item.id ) );

	const runExport = async ( format: 'csv' | 'json' ) => {
		setExporting( true );
		try {
			const data = await fetchExport( filters );
			const stamp = new Date().toISOString().slice( 0, 10 );
			if ( format === 'csv' ) {
				downloadFile(
					toCsv( data ),
					`chatlume-conversations-${ stamp }.csv`,
					'text/csv;charset=utf-8'
				);
			} else {
				downloadFile(
					JSON.stringify( data, null, 2 ),
					`chatlume-conversations-${ stamp }.json`,
					'application/json'
				);
			}
			if ( data.truncated ) {
				toast.warning(
					__(
						'Only the newest 2,000 conversations were exported. Narrow the filters to export the rest.',
						'chatlume-ai-chatbot'
					)
				);
			} else {
				toast.success(
					sprintf(
						/* translators: %d: Number of conversations. */
						_n(
							'%d conversation exported.',
							'%d conversations exported.',
							data.conversations.length,
							'chatlume-ai-chatbot'
						),
						data.conversations.length
					)
				);
			}
		} catch ( error ) {
			toast.error(
				error instanceof Error ? error.message : String( error )
			);
		} finally {
			setExporting( false );
		}
	};

	const header = (
		<PageHeader
			title={ __( 'Conversations', 'chatlume-ai-chatbot' ) }
			description={ __(
				'What visitors asked and how your bot answered.',
				'chatlume-ai-chatbot'
			) }
			actions={
				total > 0 && (
					<>
						<Button
							type="button"
							disabled={ exporting }
							onClick={ () => runExport( 'csv' ) }
						>
							<Download aria-hidden="true" />
							{ __( 'Export CSV', 'chatlume-ai-chatbot' ) }
						</Button>
						<Button
							type="button"
							disabled={ exporting }
							onClick={ () => runExport( 'json' ) }
						>
							<Download aria-hidden="true" />
							{ __( 'Export JSON', 'chatlume-ai-chatbot' ) }
						</Button>
					</>
				)
			}
		/>
	);

	if ( list.isSuccess && total === 0 && ! filtered ) {
		return (
			<>
				{ header }
				<EmptyState
					title={ __(
						'No conversations yet',
						'chatlume-ai-chatbot'
					) }
					description={ __(
						'When visitors chat with your bot, their conversations appear here. Test chats from the admin are not listed.',
						'chatlume-ai-chatbot'
					) }
					action={
						<Button asChild variant="primary">
							<a
								href={ getBootstrap().siteUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __(
									'Open your website',
									'chatlume-ai-chatbot'
								) }
								<ExternalLink aria-hidden="true" />
							</a>
						</Button>
					}
				/>
			</>
		);
	}

	return (
		<>
			{ header }

			<Card className="chatlume:mb-4 chatlume:flex chatlume:flex-col chatlume:gap-3 chatlume:p-4">
				<div className="chatlume:flex chatlume:gap-3">
					<div className="chatlume:relative chatlume:flex-1">
						<label
							htmlFor={ ids.search }
							className="chatlume:sr-only"
						>
							{ __(
								'Search conversations',
								'chatlume-ai-chatbot'
							) }
						</label>
						<Search
							aria-hidden="true"
							className="chatlume:pointer-events-none chatlume:absolute chatlume:start-3 chatlume:top-1/2 chatlume:size-4 chatlume:-translate-y-1/2 chatlume:text-fg-muted"
						/>
						<Input
							id={ ids.search }
							type="search"
							value={ search }
							placeholder={ __(
								'Search messages, names, and emails…',
								'chatlume-ai-chatbot'
							) }
							className="chatlume:ps-9"
							onChange={ ( event ) =>
								setSearch( event.target.value )
							}
						/>
					</div>
					<Button
						type="button"
						variant="ghost"
						disabled={ ! filtered }
						onClick={ () => {
							setSearch( '' );
							write( new URLSearchParams() );
							setSelected( [] );
						} }
					>
						<FilterX aria-hidden="true" />
						{ __( 'Reset', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
				<div className="chatlume:grid chatlume:gap-3 chatlume:grid-cols-2 chatlume:lg:grid-cols-3 chatlume:2xl:grid-cols-6">
					<FilterLabel
						id={ ids.from }
						label={ __( 'From', 'chatlume-ai-chatbot' ) }
					>
						<Input
							id={ ids.from }
							type="date"
							value={ filters.date_from }
							max={ filters.date_to || undefined }
							onChange={ ( event ) =>
								setFilter( 'date_from', event.target.value )
							}
						/>
					</FilterLabel>
					<FilterLabel
						id={ ids.to }
						label={ __( 'To', 'chatlume-ai-chatbot' ) }
					>
						<Input
							id={ ids.to }
							type="date"
							value={ filters.date_to }
							min={ filters.date_from || undefined }
							onChange={ ( event ) =>
								setFilter( 'date_to', event.target.value )
							}
						/>
					</FilterLabel>
					<FilterLabel
						id={ ids.rating }
						label={ __( 'Rating', 'chatlume-ai-chatbot' ) }
					>
						<Select
							id={ ids.rating }
							value={ filters.rating }
							onChange={ ( event ) =>
								setFilter( 'rating', event.target.value )
							}
						>
							<option value="any">
								{ __( 'Any rating', 'chatlume-ai-chatbot' ) }
							</option>
							<option value="up">
								{ __( 'Helpful', 'chatlume-ai-chatbot' ) }
							</option>
							<option value="down">
								{ __( 'Not helpful', 'chatlume-ai-chatbot' ) }
							</option>
							<option value="none">
								{ __( 'Not rated', 'chatlume-ai-chatbot' ) }
							</option>
						</Select>
					</FilterLabel>
					<FilterLabel
						id={ ids.source }
						label={ __( 'Answered by', 'chatlume-ai-chatbot' ) }
					>
						<Select
							id={ ids.source }
							value={ filters.source }
							onChange={ ( event ) =>
								setFilter( 'source', event.target.value )
							}
						>
							<option value="">
								{ __(
									'Any answer type',
									'chatlume-ai-chatbot'
								) }
							</option>
							{ SOURCES.map( ( source ) => (
								<option key={ source } value={ source }>
									{ sourceLabel( source ) }
								</option>
							) ) }
						</Select>
					</FilterLabel>
					<FilterLabel
						id={ ids.lead }
						label={ __( 'Lead', 'chatlume-ai-chatbot' ) }
					>
						<Select
							id={ ids.lead }
							value={ filters.lead }
							onChange={ ( event ) =>
								setFilter( 'lead', event.target.value )
							}
						>
							<option value="any">
								{ __( 'Any', 'chatlume-ai-chatbot' ) }
							</option>
							<option value="yes">
								{ __( 'Has a lead', 'chatlume-ai-chatbot' ) }
							</option>
							<option value="no">
								{ __( 'No lead', 'chatlume-ai-chatbot' ) }
							</option>
						</Select>
					</FilterLabel>
					<FilterLabel
						id={ ids.language }
						label={ __( 'Language', 'chatlume-ai-chatbot' ) }
					>
						<Select
							id={ ids.language }
							value={ filters.language }
							onChange={ ( event ) =>
								setFilter( 'language', event.target.value )
							}
						>
							<option value="">
								{ __( 'Any language', 'chatlume-ai-chatbot' ) }
							</option>
							{ ( list.data?.languages ?? [] ).map(
								( language ) => (
									<option key={ language } value={ language }>
										{ language }
									</option>
								)
							) }
						</Select>
					</FilterLabel>
				</div>
			</Card>

			{ selected.length > 0 && (
				<div
					className="chatlume:mb-3 chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-accent-soft chatlume:px-4 chatlume:py-2 chatlume:text-sm"
					role="status"
				>
					<span>
						{ sprintf(
							/* translators: %d: Number of selected conversations. */
							_n(
								'%d conversation selected',
								'%d conversations selected',
								selected.length,
								'chatlume-ai-chatbot'
							),
							selected.length
						) }
					</span>
					<Button
						type="button"
						size="sm"
						disabled={ remove.isPending }
						onClick={ () => setConfirming( true ) }
					>
						<Trash2 aria-hidden="true" />
						{ __( 'Delete', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
			) }

			<Card className="chatlume:overflow-hidden">
				{ list.isError && (
					<div
						role="alert"
						className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:p-4 chatlume:text-sm chatlume:text-danger"
					>
						<span>{ list.error.message }</span>
						<Button size="sm" onClick={ () => list.refetch() }>
							{ __( 'Try again', 'chatlume-ai-chatbot' ) }
						</Button>
					</div>
				) }
				{ list.isPending && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-3 chatlume:p-4">
						{ [ 0, 1, 2, 3, 4 ].map( ( index ) => (
							<Skeleton
								key={ index }
								className="chatlume:h-9 chatlume:w-full"
							/>
						) ) }
					</div>
				) }
				{ list.isSuccess && total === 0 && (
					<EmptyState
						title={ __(
							'No conversations match these filters',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Try a wider date range or fewer filters.',
							'chatlume-ai-chatbot'
						) }
						action={
							<Button
								type="button"
								onClick={ () => {
									setSearch( '' );
									write( new URLSearchParams() );
								} }
							>
								{ __( 'Reset filters', 'chatlume-ai-chatbot' ) }
							</Button>
						}
					/>
				) }
				{ list.isSuccess && total > 0 && (
					<div className="chatlume:overflow-x-auto">
						<table className="chatlume:w-full chatlume:text-left chatlume:text-sm">
							<thead>
								<tr className="chatlume:border-b chatlume:border-border chatlume:bg-surface-muted chatlume:text-xs chatlume:text-fg-muted">
									<th
										scope="col"
										className="chatlume:w-10 chatlume:px-4 chatlume:py-2.5"
									>
										<Checkbox
											checked={ allSelected }
											label={ __(
												'Select all on this page',
												'chatlume-ai-chatbot'
											) }
											onChange={ () =>
												setSelected(
													allSelected
														? selected.filter(
																( id ) =>
																	! items.some(
																		(
																			item
																		) =>
																			item.id ===
																			id
																	)
															)
														: Array.from(
																new Set( [
																	...selected,
																	...items.map(
																		(
																			item
																		) =>
																			item.id
																	),
																] )
															)
												)
											}
										/>
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:font-medium"
									>
										{ __(
											'Started',
											'chatlume-ai-chatbot'
										) }
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:font-medium"
									>
										{ __(
											'First question',
											'chatlume-ai-chatbot'
										) }
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:font-medium"
									>
										{ __(
											'Answered by',
											'chatlume-ai-chatbot'
										) }
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:text-end chatlume:font-medium"
									>
										{ __(
											'Messages',
											'chatlume-ai-chatbot'
										) }
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:font-medium"
									>
										{ __(
											'Rating',
											'chatlume-ai-chatbot'
										) }
									</th>
									<th
										scope="col"
										className="chatlume:px-3 chatlume:py-2.5 chatlume:text-end chatlume:font-medium"
									>
										{ __( 'Cost', 'chatlume-ai-chatbot' ) }
									</th>
									<th
										scope="col"
										className="chatlume:px-4 chatlume:py-2.5 chatlume:font-medium"
									>
										{ __(
											'Language',
											'chatlume-ai-chatbot'
										) }
									</th>
								</tr>
							</thead>
							<tbody
								className={
									list.isPlaceholderData
										? 'chatlume:opacity-60'
										: undefined
								}
							>
								{ items.map( ( item ) => (
									<tr
										key={ item.id }
										className="chatlume:border-b chatlume:border-border chatlume:last:border-0 chatlume:hover:bg-surface-muted"
									>
										<td className="chatlume:px-4 chatlume:py-2.5">
											<Checkbox
												checked={ selected.includes(
													item.id
												) }
												label={ sprintf(
													/* translators: %d: Conversation number. */
													__(
														'Select conversation #%d',
														'chatlume-ai-chatbot'
													),
													item.id
												) }
												onChange={ () =>
													setSelected(
														selected.includes(
															item.id
														)
															? selected.filter(
																	( id ) =>
																		id !==
																		item.id
																)
															: [
																	...selected,
																	item.id,
																]
													)
												}
											/>
										</td>
										<td className="chatlume:whitespace-nowrap chatlume:px-3 chatlume:py-2.5 chatlume:text-fg-muted">
											{ formatDateTime(
												item.created_at
											) }
										</td>
										<td className="chatlume:max-w-md chatlume:px-3 chatlume:py-2.5">
											<button
												type="button"
												onClick={ () =>
													update( { id: item.id } )
												}
												className="chatlume:block chatlume:max-w-full chatlume:truncate chatlume:text-start chatlume:font-medium chatlume:text-fg chatlume:hover:text-accent chatlume:hover:underline chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring chatlume:rounded-sm"
											>
												{ item.first_question ||
													__(
														'(no question)',
														'chatlume-ai-chatbot'
													) }
											</button>
										</td>
										<td className="chatlume:px-3 chatlume:py-2.5">
											<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-1">
												{ item.sources.map(
													( source ) => (
														<Badge
															key={ source }
															tone={
																source ===
																	'ai' ||
																source ===
																	'fallback_model'
																	? 'accent'
																	: 'neutral'
															}
														>
															{ sourceLabel(
																source
															) }
														</Badge>
													)
												) }
											</div>
										</td>
										<td className="chatlume:px-3 chatlume:py-2.5 chatlume:text-end chatlume:tabular-nums">
											{ item.message_count }
										</td>
										<td className="chatlume:px-3 chatlume:py-2.5">
											{ item.rating === 1 && (
												<ThumbsUp
													className="chatlume:size-4 chatlume:text-success"
													aria-label={ __(
														'Helpful',
														'chatlume-ai-chatbot'
													) }
												/>
											) }
											{ item.rating === -1 && (
												<ThumbsDown
													className="chatlume:size-4 chatlume:text-danger"
													aria-label={ __(
														'Not helpful',
														'chatlume-ai-chatbot'
													) }
												/>
											) }
										</td>
										<td className="chatlume:whitespace-nowrap chatlume:px-3 chatlume:py-2.5 chatlume:text-end chatlume:tabular-nums">
											{ formatCost( item.total_cost ) }
										</td>
										<td className="chatlume:px-4 chatlume:py-2.5 chatlume:text-fg-muted">
											{ item.language }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>

			{ list.isSuccess && total > 0 && (
				<nav
					className="chatlume:mt-4 chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:text-sm chatlume:text-fg-muted"
					aria-label={ __( 'Pages', 'chatlume-ai-chatbot' ) }
				>
					<span>
						{ sprintf(
							/* translators: 1: First item, 2: Last item, 3: Total. */
							__( '%1$d–%2$d of %3$d', 'chatlume-ai-chatbot' ),
							( page - 1 ) * PER_PAGE + 1,
							Math.min( page * PER_PAGE, total ),
							total
						) }
					</span>
					<div className="chatlume:flex chatlume:gap-2">
						<Button
							type="button"
							size="sm"
							disabled={ page <= 1 }
							onClick={ () =>
								update( {
									page: page - 1 > 1 ? page - 1 : null,
								} )
							}
						>
							<ChevronLeft aria-hidden="true" />
							{ __( 'Previous', 'chatlume-ai-chatbot' ) }
						</Button>
						<Button
							type="button"
							size="sm"
							disabled={ page >= pages }
							onClick={ () => update( { page: page + 1 } ) }
						>
							{ __( 'Next', 'chatlume-ai-chatbot' ) }
							<ChevronRight aria-hidden="true" />
						</Button>
					</div>
				</nav>
			) }

			<TranscriptSheet
				id={ openId }
				onClose={ () => update( { id: null } ) }
			/>

			<ConfirmDialog
				open={ confirming }
				title={ sprintf(
					/* translators: %d: Number of conversations. */
					_n(
						'Delete %d conversation?',
						'Delete %d conversations?',
						selected.length,
						'chatlume-ai-chatbot'
					),
					selected.length
				) }
				description={ __(
					'They and all their messages are removed permanently.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Delete', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					const chosen = selected;
					setConfirming( false );
					remove.mutate( chosen, {
						onSuccess: ( result ) => {
							setSelected( [] );
							toast.success(
								sprintf(
									/* translators: %d: Number of conversations. */
									_n(
										'%d conversation deleted.',
										'%d conversations deleted.',
										result.deleted,
										'chatlume-ai-chatbot'
									),
									result.deleted
								)
							);
						},
						onError: ( error ) => toast.error( error.message ),
					} );
				} }
				onCancel={ () => setConfirming( false ) }
			/>
		</>
	);
}
