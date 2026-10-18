/**
 * Widget texts: every label visitors see, and translations of your own
 * texts on WPML and Polylang sites.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Search } from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { useSearchParams } from 'react-router';
import { toast } from 'sonner';

import { FormSection } from '../../components/form/fields';
import { PageHeader } from '../../components/layout/PageHeader';
import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Field } from '../../components/ui/field';
import { Input, inputClasses } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ApiError } from '../../lib/api';
import {
	fetchTexts,
	labelGroups,
	matchesSearch,
	saveTexts,
	sourceLabel,
	toTextsForm,
	type TextsForm,
	type TextsState,
} from '../../lib/texts';
import { cn } from '../../lib/utils';

const textareaClasses = cn( inputClasses, 'chatlume:h-auto chatlume:py-2' );

/**
 * The form for one language.
 *
 * @param props            Props.
 * @param props.state      Server state.
 * @param props.onLanguage Switches the language.
 */
function TextsPage( {
	state,
	onLanguage,
}: {
	state: TextsState;
	onLanguage: ( code: string ) => void;
} ) {
	const queryClient = useQueryClient();
	const form = useForm< TextsForm >( {
		defaultValues: toTextsForm( state ),
	} );
	const {
		control,
		register,
		formState: { isDirty },
	} = form;
	const [ saving, setSaving ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ pending, setPending ] = useState< string | null >( null );
	const values = useWatch( { control } ) as TextsForm;
	const searchId = useId();
	const languageId = useId();
	const multilingual = state.languages.length > 1;
	const isMain = state.language === state.main;
	const groups = useMemo(
		() => labelGroups( Object.keys( state.labels ) ),
		[ state.labels ]
	);
	const languageName = ( code: string ) =>
		state.languages.find( ( language ) => language.code === code )?.name ??
		code;

	useUnsavedChangesWarning( isDirty );

	useEffect( () => {
		form.reset( toTextsForm( state ) );
	}, [ state, form ] );

	const save = form.handleSubmit( async ( next ) => {
		setSaving( true );
		try {
			const saved = await saveTexts( state.language, next );
			queryClient.setQueryData(
				[ 'chatlume', 'texts', state.language ],
				saved
			);
			form.reset( toTextsForm( saved ) );
			toast.success( __( 'Texts saved.', 'chatlume-ai-chatbot' ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setSaving( false );
		}
	} );

	useSaveShortcut( () => {
		if ( isDirty && ! saving ) {
			save();
		}
	}, isDirty );

	const switchTo = ( code: string ) => {
		if ( isDirty ) {
			setPending( code );
			return;
		}
		onLanguage( code );
	};

	const contentShown = state.content
		.map( ( source, index ) => ( { source, index } ) )
		.filter( ( { source, index } ) =>
			matchesSearch(
				search,
				sourceLabel( source.path ),
				source.main,
				values.content?.[ index ]?.value ?? ''
			)
		);

	return (
		<>
			<PageHeader
				title={ __( 'Widget texts', 'chatlume-ai-chatbot' ) }
				description={
					multilingual
						? __(
								'Reword anything visitors see in the chat, and translate your own texts into each of your site’s languages.',
								'chatlume-ai-chatbot'
							)
						: __(
								'Reword anything visitors see in the chat. With WPML or Polylang, you can also translate every text into each language.',
								'chatlume-ai-chatbot'
							)
				}
			/>

			<form
				className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6"
				onSubmit={ ( event ) => {
					event.preventDefault();
					save();
				} }
				noValidate
			>
				<div className="chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:gap-4">
					{ multilingual && (
						<div className="chatlume:flex chatlume:w-64 chatlume:flex-col chatlume:gap-1.5">
							<label
								htmlFor={ languageId }
								className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
							>
								{ __( 'Language', 'chatlume-ai-chatbot' ) }
							</label>
							<Select
								id={ languageId }
								value={ state.language }
								onChange={ ( event ) =>
									switchTo( event.target.value )
								}
							>
								{ state.languages.map( ( language ) => (
									<option
										key={ language.code }
										value={ language.code }
									>
										{ language.code === state.main
											? sprintf(
													/* translators: %s: Language name. */
													__(
														'%s (main language)',
														'chatlume-ai-chatbot'
													),
													language.name
												)
											: language.name }
									</option>
								) ) }
							</Select>
						</div>
					) }
					<div className="chatlume:flex chatlume:min-w-0 chatlume:flex-1 chatlume:flex-col chatlume:gap-1.5">
						<label
							htmlFor={ searchId }
							className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
						>
							{ __( 'Find a text', 'chatlume-ai-chatbot' ) }
						</label>
						<div className="chatlume:relative">
							<Search
								aria-hidden="true"
								className="chatlume:pointer-events-none chatlume:absolute chatlume:start-3 chatlume:top-1/2 chatlume:size-4 chatlume:-translate-y-1/2 chatlume:text-fg-muted"
							/>
							<Input
								id={ searchId }
								type="search"
								className="chatlume:ps-9"
								value={ search }
								onChange={ ( event ) =>
									setSearch( event.target.value )
								}
							/>
						</div>
					</div>
				</div>

				{ multilingual && isMain && (
					<p className="chatlume:rounded-control chatlume:bg-surface-muted chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-fg-muted">
						{ __(
							'Your own texts (welcome message, starter questions, invitation, header, and others) are written on the Chatbot, Display, and Customize pages in your main language. Choose another language above to translate them.',
							'chatlume-ai-chatbot'
						) }
					</p>
				) }

				{ ! isMain && (
					<FormSection
						title={ __( 'Your texts', 'chatlume-ai-chatbot' ) }
						description={ sprintf(
							/* translators: %s: Language name. */
							__(
								'Translations into %s. An empty field shows the main language’s text.',
								'chatlume-ai-chatbot'
							),
							languageName( state.language )
						) }
					>
						{ state.content.length === 0 && (
							<p className="chatlume:text-sm chatlume:text-fg-muted">
								{ __(
									'You have not written any texts of your own yet. The built-in texts are already translated.',
									'chatlume-ai-chatbot'
								) }
							</p>
						) }
						{ contentShown.map( ( { source, index } ) => {
							const id = `chatlume-text-${ index }`;
							const field = register(
								`content.${ index }.value` as const
							);
							return (
								<Field
									key={ source.path }
									id={ id }
									label={ sourceLabel( source.path ) }
									hint={ sprintf(
										/* translators: %s: Text in the main language. */
										__(
											'Main language: %s',
											'chatlume-ai-chatbot'
										),
										source.main
									) }
								>
									{ source.format === 'text' ? (
										<Input
											id={ id }
											maxLength={ source.max }
											placeholder={ source.main }
											aria-describedby={ `${ id }-hint` }
											{ ...field }
										/>
									) : (
										<textarea
											id={ id }
											rows={ 3 }
											maxLength={ source.max }
											className={ textareaClasses }
											placeholder={ source.main }
											aria-describedby={ `${ id }-hint` }
											{ ...field }
										/>
									) }
								</Field>
							);
						} ) }
					</FormSection>
				) }

				{ groups.map( ( group ) => {
					const keys = group.keys.filter( ( key ) =>
						matchesSearch(
							search,
							state.labels[ key ].default,
							values.labels?.[ key ] ?? ''
						)
					);
					if ( keys.length === 0 ) {
						return null;
					}
					return (
						<FormSection
							key={ group.id }
							title={ group.title }
							description={
								group.id === 'window'
									? __(
											'Interface labels. Leave a field empty to keep the text in grey.',
											'chatlume-ai-chatbot'
										)
									: undefined
							}
						>
							<div className="chatlume:grid chatlume:gap-4 chatlume:md:grid-cols-2">
								{ keys.map( ( key ) => {
									const id = `chatlume-label-${ key }`;
									const changed =
										!! values.labels?.[ key ]?.trim();
									return (
										<div
											key={ key }
											className="chatlume:flex chatlume:flex-col chatlume:gap-1.5"
										>
											<div className="chatlume:flex chatlume:items-center chatlume:gap-2">
												<label
													htmlFor={ id }
													className="chatlume:min-w-0 chatlume:truncate chatlume:text-sm chatlume:font-medium chatlume:text-fg"
													title={
														state.labels[ key ]
															.default
													}
												>
													{
														state.labels[ key ]
															.default
													}
												</label>
												{ changed && (
													<Badge tone="accent">
														{ __(
															'Changed',
															'chatlume-ai-chatbot'
														) }
													</Badge>
												) }
											</div>
											<Input
												id={ id }
												maxLength={ 200 }
												placeholder={
													state.labels[ key ].default
												}
												{ ...register(
													`labels.${ key }` as const
												) }
											/>
										</div>
									);
								} ) }
							</div>
						</FormSection>
					);
				} ) }

				<StickySaveBar
					visible={ isDirty }
					saving={ saving }
					onSave={ () => save() }
					onDiscard={ () => form.reset( toTextsForm( state ) ) }
				/>
				{ isDirty && <UnsavedChangesGuard /> }
			</form>

			<ConfirmDialog
				open={ pending !== null }
				title={ __( 'Switch without saving?', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Your changes to this language will be lost.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Switch language', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Stay', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					const code = pending;
					setPending( null );
					form.reset( toTextsForm( state ) );
					if ( code ) {
						onLanguage( code );
					}
				} }
				onCancel={ () => setPending( null ) }
			/>
		</>
	);
}

export default function TextsRoute() {
	const [ params, setParams ] = useSearchParams();
	const language = params.get( 'language' ) ?? '';
	const query = useQuery< TextsState, ApiError >( {
		queryKey: [ 'chatlume', 'texts', language ],
		queryFn: () => fetchTexts( language ),
	} );

	if ( query.isError ) {
		return (
			<div
				role="alert"
				className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
			>
				<span>{ query.error.message }</span>
				<Button size="sm" onClick={ () => query.refetch() }>
					{ __( 'Try again', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		);
	}

	if ( ! query.data ) {
		return <PageSkeleton />;
	}

	return (
		<TextsPage
			state={ query.data }
			onLanguage={ ( code ) =>
				setParams( code === query.data.main ? {} : { language: code }, {
					replace: true,
				} )
			}
		/>
	);
}
