/**
 * Chatbot: persona, prompt, first impression, answers, guardrails, quick
 * answers, cost controls, and messages, with a live preview drawer.
 */
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Eye, Plus, Trash2, WandSparkles } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import {
	Controller,
	useFieldArray,
	useForm,
	useWatch,
	type Resolver,
} from 'react-hook-form';
import { toast } from 'sonner';

import {
	FormSection,
	NumberField,
	SwitchField,
} from '../../components/form/fields';
import { PageHeader } from '../../components/layout/PageHeader';
import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { WidgetPreview } from '../../components/preview/WidgetPreview';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Button } from '../../components/ui/button';
import { Field } from '../../components/ui/field';
import { Input, inputClasses } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Sheet } from '../../components/ui/sheet';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ApiError } from '../../lib/api';
import {
	chatbotFormSchema,
	fetchChatbotInfo,
	previewSamples,
	fromChatbotForm,
	previewBehavior,
	STARTER_SLOTS,
	toChatbotForm,
	type ChatbotFormValues,
	type ChatbotInfo,
	type Preset,
} from '../../lib/chatbot';
import { fieldMessage, issuesToErrors } from '../../lib/forms';
import type { Settings } from '../../lib/settingsSchema';
import { cn } from '../../lib/utils';

type FormValues = ChatbotFormValues;

const MAX_QUICK_ACTIONS = 4;
const PROMPT_LIMIT = 8000;

const resolver: Resolver< FormValues > = async ( values ) => {
	const result = chatbotFormSchema.safeParse( fromChatbotForm( values ) );
	return result.success
		? { values, errors: {} }
		: {
				values: {},
				errors: issuesToErrors< FormValues >( result.error.issues, [
					'chatbot.blocked_words',
				] ),
			};
};

const textareaClasses = cn( inputClasses, 'chatlume:h-auto chatlume:py-2' );

/**
 * Preset choices.
 *
 * @return Presets with labels.
 */
function presets(): Array< {
	id: Preset;
	label: string;
	description: string;
} > {
	return [
		{
			id: 'support',
			label: __( 'Customer support', 'chatlume-ai-chatbot' ),
			description: __(
				'Warm, helpful answers about your products, services, and policies.',
				'chatlume-ai-chatbot'
			),
		},
		{
			id: 'sales',
			label: __( 'Sales assistant', 'chatlume-ai-chatbot' ),
			description: __(
				'Helps visitors choose and nudges them to the next step.',
				'chatlume-ai-chatbot'
			),
		},
		{
			id: 'faq',
			label: __( 'FAQ bot', 'chatlume-ai-chatbot' ),
			description: __(
				'Short, factual answers straight from your website.',
				'chatlume-ai-chatbot'
			),
		},
		{
			id: 'blank',
			label: __( 'Blank', 'chatlume-ai-chatbot' ),
			description: __(
				'No built-in instructions: write your own prompt.',
				'chatlume-ai-chatbot'
			),
		},
	];
}

/**
 * Amount in US dollars.
 *
 * @param value Amount.
 * @return Text.
 */
function dollars( value: number ): string {
	return new Intl.NumberFormat( undefined, {
		style: 'currency',
		currency: 'USD',
		maximumFractionDigits: value > 0 && value < 1 ? 4 : 2,
	} ).format( value );
}

function ChatbotForm( {
	settings,
	info,
}: {
	settings: Settings;
	info: ChatbotInfo;
} ) {
	const update = useUpdateSettings();
	const form = useForm< FormValues >( {
		resolver,
		defaultValues: toChatbotForm( settings ),
	} );
	const {
		register,
		control,
		setValue,
		formState: { isDirty, errors },
	} = form;
	const actions = useFieldArray( { control, name: 'chatbot.quick_actions' } );
	const values = useWatch( { control } ) as FormValues;
	const [ previewOpen, setPreviewOpen ] = useState( false );
	const [ replacePrompt, setReplacePrompt ] = useState( false );
	const promptRef = useRef< HTMLTextAreaElement | null >( null );
	const ids = {
		name: useId(),
		preset: useId(),
		prompt: useId(),
		welcome: useId(),
		language: useId(),
		fixedLanguage: useId(),
		refusal: useId(),
		blocked: useId(),
		unit: useId(),
		behavior: useId(),
		email: useId(),
		offline: useId(),
		error: useId(),
		siteSearch: useId(),
		leadTiming: useId(),
		consent: useId(),
		contact: useId(),
		handoffEmail: useId(),
		phone: useId(),
		whatsapp: useId(),
		teamEmail: useId(),
	};

	useUnsavedChangesWarning( isDirty );

	// Follow settings saved elsewhere (an import, another tab) unless there are unsaved edits here.
	useEffect( () => {
		if ( ! form.formState.isDirty ) {
			form.reset( toChatbotForm( settings ) );
		}
	}, [ settings, form ] );

	const preset = values.chatbot?.preset ?? 'support';
	const presetDefaults =
		info.defaults.presets[ preset ] ?? info.defaults.presets.support;
	const promptLength = Array.from(
		values.chatbot?.system_prompt ?? ''
	).length;
	const tokensUnit = values.costs?.budget_unit === 'tokens';

	const behavior = useMemo(
		() =>
			values.chatbot
				? previewBehavior(
						fromChatbotForm( values ).chatbot,
						info.defaults
					)
				: previewBehavior( settings.chatbot, info.defaults ),
		[ values, info.defaults, settings.chatbot ]
	);

	const save = form.handleSubmit( async ( next ) => {
		try {
			const saved = await update.mutateAsync( fromChatbotForm( next ) );
			form.reset( toChatbotForm( saved ) );
			toast.success(
				__( 'Chatbot settings saved.', 'chatlume-ai-chatbot' )
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

	const insertVariable = ( variable: string ) => {
		const area = promptRef.current;
		const current = form.getValues( 'chatbot.system_prompt' );
		const start = area?.selectionStart ?? current.length;
		const end = area?.selectionEnd ?? current.length;
		const next =
			current.slice( 0, start ) + variable + current.slice( end );
		setValue( 'chatbot.system_prompt', next, { shouldDirty: true } );
		window.requestAnimationFrame( () => {
			area?.focus();
			area?.setSelectionRange(
				start + variable.length,
				start + variable.length
			);
		} );
	};

	const applyPresetPrompt = () => {
		setValue( 'chatbot.system_prompt', presetDefaults.prompt, {
			shouldDirty: true,
		} );
		setReplacePrompt( false );
		window.requestAnimationFrame( () => promptRef.current?.focus() );
	};

	const promptField = register( 'chatbot.system_prompt' );

	return (
		<>
			<PageHeader
				title={ __( 'Chatbot', 'chatlume-ai-chatbot' ) }
				description={ __(
					'How your bot introduces itself, answers, and stays within budget.',
					'chatlume-ai-chatbot'
				) }
				actions={
					<Button
						type="button"
						onClick={ () => setPreviewOpen( true ) }
					>
						<Eye aria-hidden="true" />
						{ __( 'Preview', 'chatlume-ai-chatbot' ) }
					</Button>
				}
			/>

			<form
				className="chatlume:flex chatlume:flex-col chatlume:gap-6"
				noValidate
				onSubmit={ ( event ) => {
					event.preventDefault();
					save();
				} }
			>
				<FormSection
					title={ __( 'Personality', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Who your bot is and the instructions it follows.',
						'chatlume-ai-chatbot'
					) }
				>
					<Field
						id={ ids.name }
						label={ __( 'Bot name', 'chatlume-ai-chatbot' ) }
						error={ fieldMessage( errors.chatbot?.bot_name ) }
					>
						<Input
							id={ ids.name }
							className="chatlume:sm:max-w-sm"
							maxLength={ 60 }
							placeholder={ info.defaults.bot_name }
							{ ...register( 'chatbot.bot_name' ) }
						/>
					</Field>

					<fieldset>
						<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ __( 'Starting point', 'chatlume-ai-chatbot' ) }
						</legend>
						<Controller
							control={ control }
							name="chatbot.preset"
							render={ ( { field } ) => (
								<div className="chatlume:grid chatlume:gap-3 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-4">
									{ presets().map( ( option ) => (
										<label
											key={ option.id }
											htmlFor={ `${ ids.preset }-${ option.id }` }
											className={ cn(
												'chatlume:flex chatlume:cursor-pointer chatlume:flex-col chatlume:gap-1 chatlume:rounded-control chatlume:border chatlume:p-3 chatlume:transition-colors',
												'chatlume:has-[:focus-visible]:ring-[3px] chatlume:has-[:focus-visible]:ring-ring',
												field.value === option.id
													? 'chatlume:border-accent chatlume:bg-accent-soft'
													: 'chatlume:border-border chatlume:hover:bg-surface-muted'
											) }
										>
											<span className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
												<input
													id={ `${ ids.preset }-${ option.id }` }
													type="radio"
													name={ field.name }
													value={ option.id }
													checked={
														field.value ===
														option.id
													}
													onChange={ () =>
														field.onChange(
															option.id
														)
													}
													onBlur={ field.onBlur }
													className="chatlume:sr-only"
												/>
												{ option.label }
											</span>
											<span className="chatlume:text-[13px] chatlume:text-fg-muted">
												{ option.description }
											</span>
										</label>
									) ) }
								</div>
							) }
						/>
					</fieldset>

					<Field
						id={ ids.prompt }
						label={ __(
							'Instructions (system prompt)',
							'chatlume-ai-chatbot'
						) }
						hint={ __(
							'Leave empty to use the starting point’s instructions shown in grey. Website content from your knowledge base is added automatically.',
							'chatlume-ai-chatbot'
						) }
						error={ fieldMessage( errors.chatbot?.system_prompt ) }
					>
						<textarea
							id={ ids.prompt }
							rows={ 10 }
							maxLength={ PROMPT_LIMIT }
							spellCheck
							className={ textareaClasses }
							placeholder={ presetDefaults.prompt }
							aria-describedby={ `${ ids.prompt }-hint ${ ids.prompt }-error ${ ids.prompt }-count` }
							{ ...promptField }
							ref={ ( element ) => {
								promptField.ref( element );
								promptRef.current = element;
							} }
						/>
						<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-2">
							<div
								className="chatlume:flex chatlume:flex-wrap chatlume:gap-1.5"
								role="group"
								aria-label={ __(
									'Insert a variable',
									'chatlume-ai-chatbot'
								) }
							>
								{ info.variables.map( ( variable ) => (
									<button
										key={ variable.name }
										type="button"
										title={ variable.description }
										onClick={ () =>
											insertVariable( variable.name )
										}
										className="chatlume:rounded-full chatlume:border chatlume:border-border chatlume:px-2 chatlume:py-0.5 chatlume:font-mono chatlume:text-xs chatlume:text-fg-muted chatlume:hover:border-accent chatlume:hover:text-accent chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
									>
										{ variable.name }
										<span className="chatlume:sr-only">
											{ ' — ' + variable.description }
										</span>
									</button>
								) ) }
							</div>
							<div className="chatlume:flex chatlume:items-center chatlume:gap-3">
								{ presetDefaults.prompt && (
									<Button
										type="button"
										size="sm"
										variant="ghost"
										onClick={ () =>
											form
												.getValues(
													'chatbot.system_prompt'
												)
												.trim()
												? setReplacePrompt( true )
												: applyPresetPrompt()
										}
									>
										<WandSparkles aria-hidden="true" />
										{ __(
											'Edit the starting instructions',
											'chatlume-ai-chatbot'
										) }
									</Button>
								) }
								<span
									id={ `${ ids.prompt }-count` }
									className="chatlume:text-xs chatlume:tabular-nums chatlume:text-fg-muted"
								>
									{ sprintf(
										/* translators: 1: Characters typed, 2: Maximum characters. */
										__(
											'%1$d / %2$d',
											'chatlume-ai-chatbot'
										),
										promptLength,
										PROMPT_LIMIT
									) }
								</span>
							</div>
						</div>
					</Field>
				</FormSection>

				<FormSection
					title={ __( 'First impression', 'chatlume-ai-chatbot' ) }
					description={ __(
						'What visitors see when they open the chat.',
						'chatlume-ai-chatbot'
					) }
				>
					<Field
						id={ ids.welcome }
						label={ __( 'Welcome message', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'You can use <strong>bold</strong>, <em>italics</em>, and <a href="…">links</a>.',
							'chatlume-ai-chatbot'
						) }
						error={ fieldMessage(
							errors.chatbot?.welcome_message
						) }
					>
						<textarea
							id={ ids.welcome }
							rows={ 3 }
							maxLength={ 1000 }
							className={ textareaClasses }
							placeholder={ presetDefaults.welcome }
							aria-describedby={ `${ ids.welcome }-hint ${ ids.welcome }-error` }
							{ ...register( 'chatbot.welcome_message' ) }
						/>
					</Field>

					<fieldset className="chatlume:flex chatlume:flex-col chatlume:gap-2">
						<legend className="chatlume:mb-1 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ __( 'Starter questions', 'chatlume-ai-chatbot' ) }
						</legend>
						<p className="chatlume:-mt-1 chatlume:text-[13px] chatlume:text-fg-muted">
							{ __(
								'Up to four one-click questions. Leave all empty to use the suggestions shown in grey.',
								'chatlume-ai-chatbot'
							) }
						</p>
						<div className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-2">
							{ Array.from(
								{ length: STARTER_SLOTS },
								( _, index ) => (
									<Input
										key={ index }
										maxLength={ 120 }
										aria-label={ sprintf(
											/* translators: %d: Starter question number. */
											__(
												'Starter question %d',
												'chatlume-ai-chatbot'
											),
											index + 1
										) }
										placeholder={
											presetDefaults.starters[ index ] ??
											''
										}
										{ ...register(
											`chatbot.starter_questions.${ index }`
										) }
									/>
								)
							) }
						</div>
					</fieldset>

					<fieldset className="chatlume:flex chatlume:flex-col chatlume:gap-2">
						<legend className="chatlume:mb-1 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ __(
								'Quick action buttons',
								'chatlume-ai-chatbot'
							) }
						</legend>
						<p className="chatlume:-mt-1 chatlume:text-[13px] chatlume:text-fg-muted">
							{ __(
								'Buttons that open a page, start an email or call, open WhatsApp, or send a ready-made message.',
								'chatlume-ai-chatbot'
							) }
						</p>
						{ actions.fields.map( ( action, index ) => {
							const rowErrors =
								errors.chatbot?.quick_actions?.[ index ];
							const type =
								values.chatbot?.quick_actions?.[ index ]
									?.type ?? 'url';
							return (
								<div
									key={ action.id }
									className="chatlume:grid chatlume:items-start chatlume:gap-2 chatlume:sm:grid-cols-[1fr_10rem_1.5fr_auto]"
								>
									<div>
										<Input
											maxLength={ 40 }
											aria-label={ __(
												'Button text',
												'chatlume-ai-chatbot'
											) }
											placeholder={ __(
												'Button text',
												'chatlume-ai-chatbot'
											) }
											aria-invalid={
												rowErrors?.label
													? true
													: undefined
											}
											{ ...register(
												`chatbot.quick_actions.${ index }.label`
											) }
										/>
									</div>
									<Select
										aria-label={ __(
											'Action',
											'chatlume-ai-chatbot'
										) }
										{ ...register(
											`chatbot.quick_actions.${ index }.type`
										) }
									>
										<option value="url">
											{ __(
												'Open a page',
												'chatlume-ai-chatbot'
											) }
										</option>
										<option value="email">
											{ __(
												'Send an email',
												'chatlume-ai-chatbot'
											) }
										</option>
										<option value="phone">
											{ __(
												'Call',
												'chatlume-ai-chatbot'
											) }
										</option>
										<option value="whatsapp">
											{ __(
												'WhatsApp',
												'chatlume-ai-chatbot'
											) }
										</option>
										<option value="message">
											{ __(
												'Send a message',
												'chatlume-ai-chatbot'
											) }
										</option>
									</Select>
									<div>
										<Input
											maxLength={ 500 }
											aria-label={ __(
												'Address, number, or message',
												'chatlume-ai-chatbot'
											) }
											aria-invalid={
												rowErrors?.value
													? true
													: undefined
											}
											placeholder={
												{
													url: 'https://',
													email: 'hello@example.com',
													phone: '+1 555 0100',
													whatsapp: '+1 555 0100',
													message: __(
														'What are your prices?',
														'chatlume-ai-chatbot'
													),
												}[ type ]
											}
											{ ...register(
												`chatbot.quick_actions.${ index }.value`
											) }
										/>
										{ ( rowErrors?.label ||
											rowErrors?.value ) && (
											<p
												role="alert"
												className="chatlume:mt-1 chatlume:text-[13px] chatlume:text-danger"
											>
												{ fieldMessage(
													rowErrors.label ??
														rowErrors.value
												) }
											</p>
										) }
									</div>
									<Button
										type="button"
										size="icon"
										variant="ghost"
										aria-label={ __(
											'Remove button',
											'chatlume-ai-chatbot'
										) }
										onClick={ () =>
											actions.remove( index )
										}
									>
										<Trash2 aria-hidden="true" />
									</Button>
								</div>
							);
						} ) }
						{ actions.fields.length < MAX_QUICK_ACTIONS && (
							<div>
								<Button
									type="button"
									size="sm"
									onClick={ () =>
										actions.append( {
											label: '',
											type: 'url',
											value: '',
										} )
									}
								>
									<Plus aria-hidden="true" />
									{ __(
										'Add a button',
										'chatlume-ai-chatbot'
									) }
								</Button>
							</div>
						) }
					</fieldset>
				</FormSection>

				<FormSection
					title={ __( 'How it answers', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Answer length, memory, creativity, and language.',
						'chatlume-ai-chatbot'
					) }
				>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-4">
						<NumberField
							register={ register }
							name="chatbot.temperature"
							label={ __( 'Creativity', 'chatlume-ai-chatbot' ) }
							tooltip={ __(
								'The model’s temperature. Low values (0 to 0.5) give consistent, factual answers; higher values give more varied wording.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 2 }
							step={ 0.1 }
							error={ fieldMessage(
								errors.chatbot?.temperature
							) }
						/>
						<NumberField
							register={ register }
							name="chatbot.max_output_tokens"
							label={ __(
								'Longest answer',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'Upper limit for one answer, in tokens (about ¾ of a word each). Keeps costs predictable.',
								'chatlume-ai-chatbot'
							) }
							min={ 50 }
							max={ 4000 }
							suffix={ __( 'tokens', 'chatlume-ai-chatbot' ) }
							error={ fieldMessage(
								errors.chatbot?.max_output_tokens
							) }
						/>
						<NumberField
							register={ register }
							name="chatbot.history_window"
							label={ __(
								'Messages remembered',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'How many earlier messages of the conversation are sent with each question. More context costs more tokens.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 30 }
							error={ fieldMessage(
								errors.chatbot?.history_window
							) }
						/>
						<NumberField
							register={ register }
							name="chatbot.max_message_length"
							label={ __(
								'Longest visitor message',
								'chatlume-ai-chatbot'
							) }
							min={ 50 }
							max={ 4000 }
							suffix={ __( 'characters', 'chatlume-ai-chatbot' ) }
							error={ fieldMessage(
								errors.chatbot?.max_message_length
							) }
						/>
					</div>

					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2">
						<Field
							id={ ids.language }
							label={ __(
								'Reply language',
								'chatlume-ai-chatbot'
							) }
						>
							<Select
								id={ ids.language }
								{ ...register( 'chatbot.language_mode' ) }
							>
								<option value="visitor">
									{ __(
										'The visitor’s language',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="fixed">
									{ __(
										'Always one language',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</Field>
						{ values.chatbot?.language_mode === 'fixed' && (
							<Field
								id={ ids.fixedLanguage }
								label={ __(
									'Language',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'For example: English, Deutsch, বাংলা.',
									'chatlume-ai-chatbot'
								) }
								error={ fieldMessage(
									errors.chatbot?.fixed_language
								) }
							>
								<Input
									id={ ids.fixedLanguage }
									maxLength={ 40 }
									aria-describedby={ `${ ids.fixedLanguage }-hint` }
									{ ...register( 'chatbot.fixed_language' ) }
								/>
							</Field>
						) }
					</div>
				</FormSection>

				<FormSection
					title={ __( 'Voice', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Uses the visitor’s own browser, at no cost to you. The buttons appear only in browsers that support them.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="chatbot.voice_input"
						label={ __(
							'Let visitors speak their questions',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Adds a microphone button. Some browsers, such as Chrome, use their maker’s online service to turn speech into text.',
							'chatlume-ai-chatbot'
						) }
					/>
					<SwitchField
						control={ control }
						name="chatbot.read_aloud"
						label={ __(
							'Read answers aloud',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Adds a speaker button to each answer, using the voices installed on the visitor’s device.',
							'chatlume-ai-chatbot'
						) }
					/>
				</FormSection>

				<FormSection
					title={ __( 'Lead capture', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Ask visitors for their name and email, so you can follow up. Leads appear in Conversations.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="leads.enabled"
						label={ __(
							'Ask for contact details',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Visitors can always skip unless you require the form before chatting.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ values.leads?.enabled && (
						<>
							<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2">
								<Field
									id={ ids.leadTiming }
									label={ __(
										'When',
										'chatlume-ai-chatbot'
									) }
								>
									<Select
										id={ ids.leadTiming }
										{ ...register( 'leads.timing' ) }
									>
										<option value="before">
											{ __(
												'Before the first message',
												'chatlume-ai-chatbot'
											) }
										</option>
										<option value="after">
											{ __(
												'After a few messages',
												'chatlume-ai-chatbot'
											) }
										</option>
									</Select>
								</Field>
								{ values.leads.timing === 'after' && (
									<NumberField
										register={ register }
										name="leads.after"
										label={ __(
											'After this many messages',
											'chatlume-ai-chatbot'
										) }
										min={ 1 }
										max={ 20 }
										error={ fieldMessage(
											errors.leads?.after
										) }
									/>
								) }
							</div>
							{ values.leads.timing === 'before' && (
								<SwitchField
									control={ control }
									name="leads.required"
									label={ __(
										'Require the form before chatting',
										'chatlume-ai-chatbot'
									) }
									description={ __(
										'Removes “Skip”. Fewer visitors may start a chat.',
										'chatlume-ai-chatbot'
									) }
								/>
							) }
							<SwitchField
								control={ control }
								name="leads.ask_name"
								label={ __(
									'Ask for a name too',
									'chatlume-ai-chatbot'
								) }
								description={ __(
									'The email is always asked; the name is optional for visitors.',
									'chatlume-ai-chatbot'
								) }
							/>
							<Field
								id={ ids.consent }
								label={ __(
									'Consent checkbox',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'Optional. Visitors must tick it to send the form. You can link to your privacy policy with <a href="…">…</a>.',
									'chatlume-ai-chatbot'
								) }
								error={ fieldMessage( errors.leads?.consent ) }
							>
								<Input
									id={ ids.consent }
									maxLength={ 300 }
									placeholder={ __(
										'I agree that you may contact me about my question.',
										'chatlume-ai-chatbot'
									) }
									aria-describedby={ `${ ids.consent }-hint` }
									{ ...register( 'leads.consent' ) }
								/>
							</Field>
							<SwitchField
								control={ control }
								name="leads.notify"
								label={ __(
									'Email me about new leads',
									'chatlume-ai-chatbot'
								) }
								description={ __(
									'Sent to the team address under “Talk to a person”.',
									'chatlume-ai-chatbot'
								) }
							/>
						</>
					) }
				</FormSection>

				<FormSection
					title={ __( 'Talk to a person', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Give visitors a way to reach your team, from the chat menu.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="handoff.enabled"
						label={ __(
							'Show “Talk to a person”',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Shows the ways to reach you that you fill in below.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ values.handoff?.enabled && (
						<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2">
							<Field
								id={ ids.contact }
								label={ __(
									'Contact page',
									'chatlume-ai-chatbot'
								) }
								error={ fieldMessage(
									errors.handoff?.contact
								) }
							>
								<Input
									id={ ids.contact }
									type="url"
									placeholder="https://"
									maxLength={ 500 }
									{ ...register( 'handoff.contact' ) }
								/>
							</Field>
							<Field
								id={ ids.handoffEmail }
								label={ __( 'Email', 'chatlume-ai-chatbot' ) }
								error={ fieldMessage( errors.handoff?.email ) }
							>
								<Input
									id={ ids.handoffEmail }
									type="email"
									maxLength={ 100 }
									{ ...register( 'handoff.email' ) }
								/>
							</Field>
							<Field
								id={ ids.phone }
								label={ __( 'Phone', 'chatlume-ai-chatbot' ) }
							>
								<Input
									id={ ids.phone }
									type="tel"
									maxLength={ 40 }
									{ ...register( 'handoff.phone' ) }
								/>
							</Field>
							<Field
								id={ ids.whatsapp }
								label={ __(
									'WhatsApp number',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'With the country code, for example +44 20 7946 0000.',
									'chatlume-ai-chatbot'
								) }
							>
								<Input
									id={ ids.whatsapp }
									type="tel"
									maxLength={ 40 }
									aria-describedby={ `${ ids.whatsapp }-hint` }
									{ ...register( 'handoff.whatsapp' ) }
								/>
							</Field>
						</div>
					) }
					{ values.handoff?.enabled && (
						<SwitchField
							control={ control }
							name="handoff.email_team"
							label={ __(
								'“Email this conversation to the team”',
								'chatlume-ai-chatbot'
							) }
							description={ __(
								'Sends the whole conversation to your team address. Visitors add their email first, so you can reply.',
								'chatlume-ai-chatbot'
							) }
						/>
					) }
					<SwitchField
						control={ control }
						name="handoff.transcript"
						label={ __(
							'Let visitors email the conversation to themselves',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Only to the email address they gave you, at most 3 times an hour.',
							'chatlume-ai-chatbot'
						) }
					/>
					<Field
						id={ ids.teamEmail }
						label={ __( 'Team address', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'Where conversations and new leads are sent. Leave empty to use the site’s admin email.',
							'chatlume-ai-chatbot'
						) }
						error={ fieldMessage( errors.handoff?.team_email ) }
					>
						<Input
							id={ ids.teamEmail }
							type="email"
							maxLength={ 100 }
							aria-describedby={ `${ ids.teamEmail }-hint` }
							{ ...register( 'handoff.team_email' ) }
						/>
					</Field>
				</FormSection>

				<FormSection
					title={ __( 'Guardrails', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Keep conversations on topic and appropriate.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="chatbot.site_topics_only"
						label={ __(
							'Only answer questions about this website',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Politely declines unrelated requests (for example homework or general trivia), which also saves money.',
							'chatlume-ai-chatbot'
						) }
					/>
					<Field
						id={ ids.refusal }
						label={ __( 'Refusal message', 'chatlume-ai-chatbot' ) }
						error={ fieldMessage(
							errors.chatbot?.refusal_message
						) }
					>
						<textarea
							id={ ids.refusal }
							rows={ 2 }
							maxLength={ 500 }
							className={ textareaClasses }
							placeholder={ info.defaults.refusal }
							{ ...register( 'chatbot.refusal_message' ) }
						/>
					</Field>
					<Field
						id={ ids.blocked }
						label={ __( 'Blocked words', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'One word or phrase per line. Messages containing them get the refusal message and never reach the AI.',
							'chatlume-ai-chatbot'
						) }
						error={ fieldMessage( errors.chatbot?.blocked_words ) }
					>
						<textarea
							id={ ids.blocked }
							rows={ 4 }
							className={ cn(
								textareaClasses,
								'chatlume:sm:max-w-md'
							) }
							aria-describedby={ `${ ids.blocked }-hint ${ ids.blocked }-error` }
							{ ...register( 'chatbot.blocked_words' ) }
						/>
					</Field>
				</FormSection>

				<FormSection
					title={ __( 'Quick answers', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Your own answers to common questions, served instantly and for free when a question clearly matches.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="quick_answers.enabled"
						label={ __(
							'Use quick answers',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Visitors can still choose “Not what you meant? Ask AI”.',
							'chatlume-ai-chatbot'
						) }
					/>
					<NumberField
						register={ register }
						name="quick_answers.threshold"
						label={ __(
							'Match strictness',
							'chatlume-ai-chatbot'
						) }
						tooltip={ __(
							'From 0.5 (loose) to 1 (exact wording). Higher values avoid wrong matches; 0.8 works well.',
							'chatlume-ai-chatbot'
						) }
						min={ 0.5 }
						max={ 1 }
						step={ 0.05 }
						error={ fieldMessage(
							errors.quick_answers?.threshold
						) }
					/>
					<SwitchField
						control={ control }
						name="quick_answers.show_as_starters"
						label={ __(
							'Show quick answers as starter questions',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Fills any free starter slots with your quick answer questions.',
							'chatlume-ai-chatbot'
						) }
					/>
				</FormSection>

				<FormSection
					title={ __( 'Cost controls', 'chatlume-ai-chatbot' ) }
					description={ sprintf(
						/* translators: 1: Amount used today, 2: Amount used this month. */
						__(
							'Used so far: %1$s today, %2$s this month.',
							'chatlume-ai-chatbot'
						),
						tokensUnit
							? sprintf(
									/* translators: %s: Number of tokens. */
									__( '%s tokens', 'chatlume-ai-chatbot' ),
									info.usage.day_tokens.toLocaleString()
								)
							: dollars( info.usage.day_cost ),
						tokensUnit
							? sprintf(
									/* translators: %s: Number of tokens. */
									__( '%s tokens', 'chatlume-ai-chatbot' ),
									info.usage.month_tokens.toLocaleString()
								)
							: dollars( info.usage.month_cost )
					) }
				>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-4">
						<NumberField
							register={ register }
							name="costs.conversation_message_cap"
							label={ __(
								'Messages per conversation',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'0 means no limit.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 1000 }
							error={ fieldMessage(
								errors.costs?.conversation_message_cap
							) }
						/>
						<Field
							id={ ids.unit }
							label={ __( 'Budget in', 'chatlume-ai-chatbot' ) }
						>
							<Select
								id={ ids.unit }
								{ ...register( 'costs.budget_unit' ) }
							>
								<option value="currency">
									{ __(
										'US dollars (estimated)',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="tokens">
									{ __( 'Tokens', 'chatlume-ai-chatbot' ) }
								</option>
							</Select>
						</Field>
						<NumberField
							register={ register }
							name="costs.daily_budget"
							label={ __(
								'Daily budget',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'0 means no limit.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 1_000_000_000 }
							step={ tokensUnit ? 1000 : 0.01 }
							suffix={
								tokensUnit
									? __( 'tokens', 'chatlume-ai-chatbot' )
									: 'USD'
							}
							error={ fieldMessage( errors.costs?.daily_budget ) }
						/>
						<NumberField
							register={ register }
							name="costs.monthly_budget"
							label={ __(
								'Monthly budget',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'0 means no limit.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 1_000_000_000 }
							step={ tokensUnit ? 1000 : 0.01 }
							suffix={
								tokensUnit
									? __( 'tokens', 'chatlume-ai-chatbot' )
									: 'USD'
							}
							error={ fieldMessage(
								errors.costs?.monthly_budget
							) }
						/>
					</div>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2">
						<Field
							id={ ids.behavior }
							label={ __(
								'When a budget is used up',
								'chatlume-ai-chatbot'
							) }
						>
							<Select
								id={ ids.behavior }
								{ ...register( 'costs.cap_behavior' ) }
							>
								<option value="site_search">
									{ __(
										'Answer with quick answers and site search only',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="fallback_model">
									{ __(
										'Switch to the fallback provider',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="offline">
									{ __(
										'Show the offline message',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</Field>
						<Field
							id={ ids.email }
							label={ __( 'Alert email', 'chatlume-ai-chatbot' ) }
							hint={ __(
								'Receives a message at 80% and 100% of each budget. Empty means the site admin email.',
								'chatlume-ai-chatbot'
							) }
							error={ fieldMessage( errors.costs?.alert_email ) }
						>
							<Input
								id={ ids.email }
								type="email"
								maxLength={ 100 }
								aria-describedby={ `${ ids.email }-hint ${ ids.email }-error` }
								aria-invalid={
									errors.costs?.alert_email ? true : undefined
								}
								{ ...register( 'costs.alert_email' ) }
							/>
						</Field>
					</div>
				</FormSection>

				<FormSection
					title={ __( 'Messages', 'chatlume-ai-chatbot' ) }
					description={ __(
						'What visitors see when the bot cannot give an AI answer. Leave empty to use the text shown in grey.',
						'chatlume-ai-chatbot'
					) }
				>
					<div className="chatlume:grid chatlume:gap-5 chatlume:lg:grid-cols-3">
						<Field
							id={ ids.offline }
							label={ __( 'Offline', 'chatlume-ai-chatbot' ) }
							tooltip={ __(
								'Shown when a budget is used up and the offline behavior is chosen.',
								'chatlume-ai-chatbot'
							) }
							error={ fieldMessage(
								errors.chatbot?.offline_message
							) }
						>
							<textarea
								id={ ids.offline }
								rows={ 3 }
								maxLength={ 500 }
								className={ textareaClasses }
								placeholder={ info.defaults.offline }
								{ ...register( 'chatbot.offline_message' ) }
							/>
						</Field>
						<Field
							id={ ids.error }
							label={ __( 'Error', 'chatlume-ai-chatbot' ) }
							tooltip={ __(
								'Shown when something goes wrong, with a Retry button.',
								'chatlume-ai-chatbot'
							) }
							error={ fieldMessage(
								errors.chatbot?.error_message
							) }
						>
							<textarea
								id={ ids.error }
								rows={ 3 }
								maxLength={ 500 }
								className={ textareaClasses }
								placeholder={ info.defaults.error }
								{ ...register( 'chatbot.error_message' ) }
							/>
						</Field>
						<Field
							id={ ids.siteSearch }
							label={ __(
								'Before site search results',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'Shown above matching pages when the AI is not available.',
								'chatlume-ai-chatbot'
							) }
							error={ fieldMessage(
								errors.chatbot?.site_search_message
							) }
						>
							<textarea
								id={ ids.siteSearch }
								rows={ 3 }
								maxLength={ 500 }
								className={ textareaClasses }
								placeholder={ info.defaults.site_search }
								{ ...register( 'chatbot.site_search_message' ) }
							/>
						</Field>
					</div>
				</FormSection>
			</form>

			<Sheet
				open={ previewOpen }
				onOpenChange={ setPreviewOpen }
				title={ __( 'Preview', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Your unsaved changes, with your published widget design. Type a message to see a sample reply; nothing is sent to your AI provider.',
					'chatlume-ai-chatbot'
				) }
			>
				<WidgetPreview
					behavior={ behavior }
					samples={ previewSamples() }
					className="chatlume:h-[640px] chatlume:max-h-[calc(100vh-14rem)]"
				/>
			</Sheet>

			<ConfirmDialog
				open={ replacePrompt }
				title={ __(
					'Replace your instructions?',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Your current instructions will be replaced with the starting point’s text, which you can then edit.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Replace', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				onConfirm={ applyPresetPrompt }
				onCancel={ () => setReplacePrompt( false ) }
			/>

			<StickySaveBar
				visible={ isDirty }
				saving={ update.isPending }
				onSave={ () => save() }
				onDiscard={ () => form.reset( toChatbotForm( settings ) ) }
			/>
			{ isDirty && <UnsavedChangesGuard /> }
		</>
	);
}

/**
 * The Chatbot page.
 */
export default function ChatbotRoute() {
	const settings = useSettings();
	const info = useQuery< ChatbotInfo, ApiError >( {
		queryKey: [ 'chatlume', 'chatbot-info' ],
		queryFn: fetchChatbotInfo,
	} );
	if ( settings.isError || info.isError ) {
		return (
			<div
				role="alert"
				className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
			>
				<span>{ ( settings.error ?? info.error )?.message }</span>
				<Button
					size="sm"
					onClick={ () => {
						settings.refetch();
						info.refetch();
					} }
				>
					{ __( 'Try again', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		);
	}

	if ( ! settings.data || ! info.data ) {
		return <PageSkeleton />;
	}

	return <ChatbotForm settings={ settings.data } info={ info.data } />;
}
