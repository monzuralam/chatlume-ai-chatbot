/**
 * Quick answers: your own answers, served instantly and for free. Inline
 * editing, CSV import and export.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { Download, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { toast } from 'sonner';

import { FormSection } from '../../components/form/fields';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Input, inputClasses } from '../../components/ui/input';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import {
	useQuickAnswerMutations,
	useQuickAnswers,
} from '../../hooks/useKnowledge';
import {
	downloadText,
	fetchAllQuickAnswers,
	quickAnswersFromCsv,
	quickAnswersToCsv,
	type QuickAnswer,
	type QuickAnswerFields,
} from '../../lib/knowledge';
import { cn } from '../../lib/utils';

const EMPTY: QuickAnswerFields = {
	question: '',
	answer: '',
	keywords: '',
	language: '',
	enabled: true,
};

const textareaClasses = cn( inputClasses, 'chatlume:h-auto chatlume:py-2' );

/**
 * Editor for one quick answer (new or existing).
 *
 * @param props          Props.
 * @param props.initial  Starting values.
 * @param props.saving   Whether a save is running.
 * @param props.saveText Save button label.
 * @param props.onSave   Receives the values.
 * @param props.onCancel Cancel handler.
 */
function AnswerEditor( {
	initial,
	saving,
	saveText,
	onSave,
	onCancel,
}: {
	initial: QuickAnswerFields;
	saving: boolean;
	saveText: string;
	onSave: ( fields: QuickAnswerFields ) => void;
	onCancel: () => void;
} ) {
	const [ fields, setFields ] = useState( initial );
	const id = useId();
	const set = ( change: Partial< QuickAnswerFields > ) =>
		setFields( ( current ) => ( { ...current, ...change } ) );
	const valid = fields.question.trim() !== '' && fields.answer.trim() !== '';
	const languageValid =
		fields.language === '' ||
		/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,8}){0,3}$/.test( fields.language );

	return (
		<form
			className="chatlume:flex chatlume:flex-col chatlume:gap-3"
			onSubmit={ ( event ) => {
				event.preventDefault();
				if ( valid && languageValid ) {
					onSave( {
						...fields,
						question: fields.question.trim(),
						answer: fields.answer.trim(),
					} );
				}
			} }
		>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
				<label
					htmlFor={ `${ id }-q` }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ __( 'Question', 'chatlume-ai-chatbot' ) }
				</label>
				<Input
					id={ `${ id }-q` }
					maxLength={ 500 }
					// eslint-disable-next-line jsx-a11y/no-autofocus -- The editor opens on the admin's click.
					autoFocus
					value={ fields.question }
					onChange={ ( event ) =>
						set( { question: event.target.value } )
					}
				/>
			</div>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
				<label
					htmlFor={ `${ id }-a` }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ __( 'Answer', 'chatlume-ai-chatbot' ) }
				</label>
				<textarea
					id={ `${ id }-a` }
					rows={ 4 }
					maxLength={ 5000 }
					className={ textareaClasses }
					value={ fields.answer }
					onChange={ ( event ) =>
						set( { answer: event.target.value } )
					}
				/>
			</div>
			<div className="chatlume:grid chatlume:gap-3 chatlume:sm:grid-cols-[1fr_10rem]">
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
					<label
						htmlFor={ `${ id }-k` }
						className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
					>
						{ __( 'Keywords (optional)', 'chatlume-ai-chatbot' ) }
					</label>
					<Input
						id={ `${ id }-k` }
						maxLength={ 500 }
						placeholder={ __(
							'hours, open, closing time',
							'chatlume-ai-chatbot'
						) }
						value={ fields.keywords }
						onChange={ ( event ) =>
							set( { keywords: event.target.value } )
						}
					/>
				</div>
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
					<label
						htmlFor={ `${ id }-l` }
						className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
					>
						{ __( 'Language', 'chatlume-ai-chatbot' ) }
					</label>
					<Input
						id={ `${ id }-l` }
						maxLength={ 20 }
						placeholder={ __( 'Any', 'chatlume-ai-chatbot' ) }
						aria-invalid={ ! languageValid }
						aria-describedby={ `${ id }-l-hint` }
						value={ fields.language }
						onChange={ ( event ) =>
							set( { language: event.target.value } )
						}
					/>
				</div>
			</div>
			<p
				id={ `${ id }-l-hint` }
				className={ cn(
					'chatlume:-mt-1 chatlume:text-xs',
					languageValid
						? 'chatlume:text-fg-muted'
						: 'chatlume:text-danger'
				) }
			>
				{ languageValid
					? __(
							'Leave the language empty to answer in every language, or use a code such as en or pt-BR.',
							'chatlume-ai-chatbot'
						)
					: __(
							'Use a language code such as en or pt-BR.',
							'chatlume-ai-chatbot'
						) }
			</p>
			<div className="chatlume:flex chatlume:justify-end chatlume:gap-2">
				<Button type="button" variant="ghost" onClick={ onCancel }>
					{ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				</Button>
				<Button
					type="submit"
					variant="primary"
					disabled={ ! valid || ! languageValid || saving }
				>
					{ saveText }
				</Button>
			</div>
		</form>
	);
}

/**
 * Quick answers tab.
 */
export function QuickAnswersTab() {
	const [ term, setTerm ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ adding, setAdding ] = useState( false );
	const [ editing, setEditing ] = useState< number | null >( null );
	const [ deleting, setDeleting ] = useState< QuickAnswer | null >( null );
	const [ exporting, setExporting ] = useState( false );
	const fileRef = useRef< HTMLInputElement >( null );
	const searchId = useId();
	const answers = useQuickAnswers( { search, page } );
	const { create, update, remove, importRows } = useQuickAnswerMutations();

	useEffect( () => {
		const timer = window.setTimeout( () => {
			setSearch( term.trim() );
			setPage( 1 );
		}, 300 );
		return () => window.clearTimeout( timer );
	}, [ term ] );

	const total = answers.data?.total ?? 0;
	const pages = Math.max( 1, Math.ceil( total / 20 ) );
	const failed = ( error: unknown ) =>
		toast.error(
			error instanceof Error
				? error.message
				: __( 'Something went wrong.', 'chatlume-ai-chatbot' )
		);

	const importFile = async ( file: File ) => {
		let rows;
		try {
			rows = quickAnswersFromCsv( await file.text() );
		} catch {
			toast.error(
				__( 'This file could not be read.', 'chatlume-ai-chatbot' )
			);
			return;
		}
		if ( rows.length === 0 ) {
			toast.error(
				__(
					'No rows found. The file needs question and answer columns.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		if ( rows.length > 2000 ) {
			toast.error(
				__(
					'Import up to 2,000 quick answers at a time. Split the file and try again.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		try {
			const result = await importRows.mutateAsync( rows );
			const summary = sprintf(
				/* translators: 1: Quick answers added, 2: Quick answers updated, 3: Rows skipped. */
				__(
					'Imported: %1$d added, %2$d updated, %3$d skipped.',
					'chatlume-ai-chatbot'
				),
				result.created,
				result.updated,
				result.skipped
			);
			if ( result.errors.length > 0 ) {
				toast.warning( summary, {
					description: result.errors
						.slice( 0, 3 )
						.map( ( error ) =>
							sprintf(
								/* translators: 1: Row number, 2: Why it was skipped. */
								__( 'Row %1$d: %2$s', 'chatlume-ai-chatbot' ),
								error.row,
								error.message
							)
						)
						.join( ' ' ),
				} );
			} else {
				toast.success( summary );
			}
		} catch ( error ) {
			failed( error );
		}
	};

	const exportAll = async () => {
		setExporting( true );
		try {
			const all = await fetchAllQuickAnswers();
			downloadText(
				quickAnswersToCsv( all ),
				'chatlume-quick-answers.csv',
				'text/csv;charset=utf-8'
			);
		} catch ( error ) {
			failed( error );
		} finally {
			setExporting( false );
		}
	};

	return (
		<div className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6">
			<FormSection
				title={ __( 'Quick answers', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Your own answers to common questions. A matching question gets your answer instantly, without using the AI.',
					'chatlume-ai-chatbot'
				) }
				actions={
					<div className="chatlume:flex chatlume:shrink-0 chatlume:flex-wrap chatlume:gap-2">
						<input
							ref={ fileRef }
							type="file"
							accept=".csv,text/csv"
							className="chatlume:sr-only"
							tabIndex={ -1 }
							aria-hidden="true"
							onChange={ ( event ) => {
								const file = event.target.files?.[ 0 ];
								event.target.value = '';
								if ( file ) {
									importFile( file );
								}
							} }
						/>
						<Button
							type="button"
							size="sm"
							disabled={ importRows.isPending }
							onClick={ () => fileRef.current?.click() }
						>
							<Upload aria-hidden="true" />
							{ __( 'Import CSV', 'chatlume-ai-chatbot' ) }
						</Button>
						<Button
							type="button"
							size="sm"
							disabled={ exporting || total === 0 }
							onClick={ exportAll }
						>
							<Download aria-hidden="true" />
							{ __( 'Export CSV', 'chatlume-ai-chatbot' ) }
						</Button>
					</div>
				}
			>
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-3 chatlume:sm:flex-row">
					<div className="chatlume:flex-1">
						<label
							htmlFor={ searchId }
							className="chatlume:sr-only"
						>
							{ __(
								'Search quick answers',
								'chatlume-ai-chatbot'
							) }
						</label>
						<Input
							id={ searchId }
							type="search"
							value={ term }
							placeholder={ __(
								'Search questions and answers…',
								'chatlume-ai-chatbot'
							) }
							onChange={ ( event ) =>
								setTerm( event.target.value )
							}
						/>
					</div>
					<Button
						type="button"
						variant="primary"
						disabled={ adding }
						onClick={ () => {
							setAdding( true );
							setEditing( null );
						} }
					>
						<Plus aria-hidden="true" />
						{ __( 'Add quick answer', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>

				<p className="chatlume:-mt-2 chatlume:text-xs chatlume:text-fg-muted">
					{ __(
						'CSV columns: question, answer, keywords, language, enabled. A question that already exists in the same language is updated.',
						'chatlume-ai-chatbot'
					) }
				</p>

				{ adding && (
					<div className="chatlume:rounded-control chatlume:border chatlume:border-accent chatlume:p-4">
						<AnswerEditor
							initial={ EMPTY }
							saving={ create.isPending }
							saveText={ __(
								'Add quick answer',
								'chatlume-ai-chatbot'
							) }
							onCancel={ () => setAdding( false ) }
							onSave={ ( fields ) =>
								create.mutate( fields, {
									onSuccess: () => {
										setAdding( false );
										toast.success(
											__(
												'Quick answer added.',
												'chatlume-ai-chatbot'
											)
										);
									},
									onError: failed,
								} )
							}
						/>
					</div>
				) }

				{ answers.isLoading && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
						<Skeleton className="chatlume:h-16" />
						<Skeleton className="chatlume:h-16" />
					</div>
				) }
				{ answers.isError && (
					<p className="chatlume:text-sm chatlume:text-danger">
						{ answers.error.message }
					</p>
				) }
				{ answers.data &&
					answers.data.items.length === 0 &&
					! adding && (
						<p className="chatlume:py-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted">
							{ search
								? __(
										'Nothing matches.',
										'chatlume-ai-chatbot'
									)
								: __(
										'No quick answers yet. Add the questions visitors ask most, such as opening hours, delivery, and returns.',
										'chatlume-ai-chatbot'
									) }
						</p>
					) }
				{ answers.data && answers.data.items.length > 0 && (
					<ul
						className="chatlume:divide-y chatlume:divide-border chatlume:rounded-control chatlume:border chatlume:border-border"
						aria-busy={ answers.isFetching }
					>
						{ answers.data.items.map( ( answer ) => (
							<li key={ answer.id } className="chatlume:p-4">
								{ editing === answer.id ? (
									<AnswerEditor
										initial={ {
											question: answer.question,
											answer: answer.answer,
											keywords: answer.keywords,
											language: answer.language,
											enabled: answer.enabled,
										} }
										saving={ update.isPending }
										saveText={ __(
											'Save',
											'chatlume-ai-chatbot'
										) }
										onCancel={ () => setEditing( null ) }
										onSave={ ( fields ) =>
											update.mutate(
												{ id: answer.id, fields },
												{
													onSuccess: () => {
														setEditing( null );
														toast.success(
															__(
																'Quick answer saved.',
																'chatlume-ai-chatbot'
															)
														);
													},
													onError: failed,
												}
											)
										}
									/>
								) : (
									<div className="chatlume:flex chatlume:items-start chatlume:gap-3">
										<div className="chatlume:min-w-0 chatlume:flex-1">
											<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
												{ answer.question }
											</p>
											<p className="chatlume:mt-1 chatlume:line-clamp-2 chatlume:whitespace-pre-line chatlume:text-sm chatlume:text-fg-muted">
												{ answer.answer }
											</p>
											<p className="chatlume:mt-2 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2 chatlume:text-xs chatlume:text-fg-muted">
												{ answer.language && (
													<Badge>
														{ answer.language }
													</Badge>
												) }
												<span>
													{ sprintf(
														/* translators: %d: Times this quick answer was served. */
														_n(
															'Used %d time',
															'Used %d times',
															answer.hit_count,
															'chatlume-ai-chatbot'
														),
														answer.hit_count
													) }
												</span>
												{ answer.miss_count > 0 && (
													<Badge tone="warning">
														{ sprintf(
															/* translators: %d: Times visitors asked the AI instead. */
															_n(
																'%d visitor asked the AI instead',
																'%d visitors asked the AI instead',
																answer.miss_count,
																'chatlume-ai-chatbot'
															),
															answer.miss_count
														) }
													</Badge>
												) }
												{ answer.keywords && (
													<span className="chatlume:truncate">
														{ answer.keywords }
													</span>
												) }
											</p>
										</div>
										<Switch
											checked={ answer.enabled }
											aria-label={ sprintf(
												/* translators: %s: Question. */
												__(
													'Use “%s”',
													'chatlume-ai-chatbot'
												),
												answer.question
											) }
											disabled={ update.isPending }
											onCheckedChange={ ( enabled ) =>
												update.mutate(
													{
														id: answer.id,
														fields: { enabled },
													},
													{ onError: failed }
												)
											}
										/>
										<Button
											type="button"
											size="icon"
											variant="ghost"
											aria-label={ sprintf(
												/* translators: %s: Question. */
												__(
													'Edit “%s”',
													'chatlume-ai-chatbot'
												),
												answer.question
											) }
											onClick={ () => {
												setEditing( answer.id );
												setAdding( false );
											} }
										>
											<Pencil aria-hidden="true" />
										</Button>
										<Button
											type="button"
											size="icon"
											variant="ghost"
											aria-label={ sprintf(
												/* translators: %s: Question. */
												__(
													'Delete “%s”',
													'chatlume-ai-chatbot'
												),
												answer.question
											) }
											onClick={ () =>
												setDeleting( answer )
											}
										>
											<Trash2 aria-hidden="true" />
										</Button>
									</div>
								) }
							</li>
						) ) }
					</ul>
				) }

				{ pages > 1 && (
					<nav
						className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3"
						aria-label={ __(
							'Quick answers pages',
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
								__(
									'Page %1$d of %2$d',
									'chatlume-ai-chatbot'
								),
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

			<ConfirmDialog
				open={ deleting !== null }
				title={ __(
					'Delete this quick answer?',
					'chatlume-ai-chatbot'
				) }
				description={ sprintf(
					/* translators: %s: Question. */
					__(
						'“%s” will be answered by the AI or your knowledge instead. This cannot be undone.',
						'chatlume-ai-chatbot'
					),
					deleting?.question ?? ''
				) }
				confirmLabel={ __( 'Delete', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					const target = deleting;
					setDeleting( null );
					if ( target ) {
						remove.mutate( target.id, {
							onSuccess: () =>
								toast.success(
									__(
										'Quick answer deleted.',
										'chatlume-ai-chatbot'
									)
								),
							onError: failed,
						} );
					}
				} }
				onCancel={ () => setDeleting( null ) }
			/>
		</div>
	);
}
