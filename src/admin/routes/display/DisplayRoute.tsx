/**
 * Display & business hours: where the floating chat button appears, for
 * whom, and when the bot is available. Also shows how to place the chat
 * inside a page.
 */
import { useQuery } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Copy } from 'lucide-react';
import { useEffect, useId, useMemo } from 'react';
import { Controller, useForm, useWatch, type Resolver } from 'react-hook-form';
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
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Field } from '../../components/ui/field';
import { Input, inputClasses } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Switch } from '../../components/ui/switch';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ApiError } from '../../lib/api';
import {
	copyHours,
	dayNames,
	describeDay,
	displayFormSchema,
	displayMessage,
	fetchDisplayInfo,
	fromDisplayForm,
	toDisplayForm,
	type DisplayFormValues,
	type DisplayInfo,
} from '../../lib/display';
import { fieldMessage, issuesToErrors } from '../../lib/forms';
import { DAY_KEYS, type Settings } from '../../lib/settingsSchema';
import { cn } from '../../lib/utils';
import { isOpen } from '../../../widget/lib/display';
import { PagePicker } from './PagePicker';

const resolver: Resolver< DisplayFormValues > = async ( values ) => {
	const result = displayFormSchema.safeParse( fromDisplayForm( values ) );
	if ( result.success ) {
		return { values, errors: {} };
	}
	// Address patterns and the offline message have their own form fields.
	const issues = result.error.issues.map( ( issue ) => {
		const path = issue.path.map( String );
		if ( path[ 0 ] === 'display' && path[ 1 ] === 'url_patterns' ) {
			return { ...issue, path: [ 'patterns' ] };
		}
		if (
			path[ 0 ] === 'display' &&
			path[ 1 ] === 'triggers' &&
			path[ 2 ] === 'url_patterns'
		) {
			return { ...issue, path: [ 'trigger_patterns' ] };
		}
		if ( path[ 0 ] === 'chatbot' ) {
			return { ...issue, path: [ 'offline_message' ] };
		}
		return issue;
	} );
	return {
		values: {},
		errors: issuesToErrors< DisplayFormValues >( issues ),
	};
};

const textareaClasses = cn( inputClasses, 'chatlume:h-auto chatlume:py-2' );

/**
 * A validation message for this page.
 *
 * @param error Field error.
 * @return Text.
 */
function message(
	error: { type?: unknown; message?: unknown } | undefined
): string | undefined {
	return error
		? ( displayMessage( error.message ) ?? fieldMessage( error ) )
		: undefined;
}

/**
 * Card-style radio choices.
 *
 * @param props          Props.
 * @param props.name     Group name.
 * @param props.value    Current value.
 * @param props.options  Choices.
 * @param props.onChange Receives the choice.
 * @param props.columns  Grid columns from the small breakpoint.
 */
function ChoiceCards< T extends string >( {
	name,
	value,
	options,
	onChange,
	columns = 3,
}: {
	name: string;
	value: T;
	options: Array< { id: T; label: string; description: string } >;
	onChange: ( value: T ) => void;
	columns?: 2 | 3;
} ) {
	return (
		<div
			className={ cn(
				'chatlume:grid chatlume:gap-3',
				columns === 3
					? 'chatlume:sm:grid-cols-3'
					: 'chatlume:sm:grid-cols-2'
			) }
		>
			{ options.map( ( option ) => (
				<label
					key={ option.id }
					htmlFor={ `${ name }-${ option.id }` }
					className={ cn(
						'chatlume:flex chatlume:cursor-pointer chatlume:flex-col chatlume:gap-1 chatlume:rounded-control chatlume:border chatlume:p-3 chatlume:transition-colors',
						'chatlume:has-[:focus-visible]:ring-[3px] chatlume:has-[:focus-visible]:ring-ring',
						value === option.id
							? 'chatlume:border-accent chatlume:bg-accent-soft'
							: 'chatlume:border-border chatlume:hover:bg-surface-muted'
					) }
				>
					<span className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
						<input
							id={ `${ name }-${ option.id }` }
							type="radio"
							name={ name }
							value={ option.id }
							checked={ value === option.id }
							onChange={ () => onChange( option.id ) }
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
	);
}

/**
 * Copies text and confirms it.
 *
 * @param text Text.
 */
async function copy( text: string ) {
	try {
		await window.navigator.clipboard.writeText( text );
		toast.success( __( 'Copied.', 'chatlume-ai-chatbot' ) );
	} catch {
		toast.error(
			__(
				'Copying failed. Select the text and copy it yourself.',
				'chatlume-ai-chatbot'
			)
		);
	}
}

function DisplayForm( {
	settings,
	info,
}: {
	settings: Settings;
	info: DisplayInfo;
} ) {
	const update = useUpdateSettings();
	const form = useForm< DisplayFormValues >( {
		resolver,
		defaultValues: toDisplayForm( settings ),
	} );
	const {
		register,
		control,
		setValue,
		formState: { isDirty, errors },
	} = form;
	const values = useWatch( { control } ) as DisplayFormValues;
	const display = values.display ?? settings.display;
	const ids = {
		mode: useId(),
		devices: useId(),
		visitors: useId(),
		patterns: useId(),
		behavior: useId(),
		offline: useId(),
		shortcode: useId(),
		triggerAction: useId(),
		triggerMessage: useId(),
		triggerPages: useId(),
		triggerPatterns: useId(),
		triggerFrequency: useId(),
	};
	const days = dayNames();

	useUnsavedChangesWarning( isDirty );

	// Follow settings saved elsewhere unless there are unsaved edits here.
	useEffect( () => {
		if ( ! form.formState.isDirty ) {
			form.reset( toDisplayForm( settings ) );
		}
	}, [ settings, form ] );

	const openNow = useMemo(
		() => isOpen( display.hours.days, new Date(), info.timezone.name ),
		[ display.hours.days, info.timezone.name ]
	);

	const save = form.handleSubmit( async ( next ) => {
		try {
			const saved = await update.mutateAsync( fromDisplayForm( next ) );
			form.reset( toDisplayForm( saved ) );
			toast.success(
				__( 'Display settings saved.', 'chatlume-ai-chatbot' )
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

	const toggleIn = (
		name: 'display.post_types' | 'display.roles',
		list: string[],
		item: string,
		checked: boolean
	) =>
		setValue(
			name,
			checked
				? [ ...list, item ]
				: list.filter( ( entry ) => entry !== item ),
			{ shouldDirty: true, shouldValidate: form.formState.isSubmitted }
		);

	const modeError = message( errors.display?.pages_mode );
	const hoursError = message( errors.display?.hours?.enabled );

	return (
		<>
			<PageHeader
				title={ __( 'Display', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Choose where the chat button appears, who sees it, and when your bot is available.',
					'chatlume-ai-chatbot'
				) }
			/>

			<form
				className="chatlume:flex chatlume:max-w-4xl chatlume:flex-col chatlume:gap-6"
				onSubmit={ ( event ) => {
					event.preventDefault();
					save();
				} }
				noValidate
			>
				<FormSection
					title={ __( 'Chat button', 'chatlume-ai-chatbot' ) }
					description={ __(
						'The floating button in the corner of your site. Chats placed inside a page with the shortcode or block always show.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="display.enabled"
						label={ __(
							'Show the chat button on your site',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Turn this off to use only chats placed inside pages.',
							'chatlume-ai-chatbot'
						) }
					/>
					<SwitchField
						control={ control }
						name="display.preload"
						label={ __( 'Open instantly', 'chatlume-ai-chatbot' ) }
						description={ __(
							'Loads the chat in the background after the page is ready, so it opens without a short wait. Uses a little more bandwidth.',
							'chatlume-ai-chatbot'
						) }
					/>
				</FormSection>

				<FormSection
					title={ __( 'Pages', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Show the chat button everywhere, or only on some pages.',
						'chatlume-ai-chatbot'
					) }
				>
					<fieldset aria-describedby={ `${ ids.mode }-error` }>
						<legend className="chatlume:sr-only">
							{ __( 'Pages', 'chatlume-ai-chatbot' ) }
						</legend>
						<Controller
							control={ control }
							name="display.pages_mode"
							render={ ( { field } ) => (
								<ChoiceCards
									name={ ids.mode }
									value={ field.value }
									onChange={ field.onChange }
									options={ [
										{
											id: 'all',
											label: __(
												'All pages',
												'chatlume-ai-chatbot'
											),
											description: __(
												'Everywhere on your site.',
												'chatlume-ai-chatbot'
											),
										},
										{
											id: 'include',
											label: __(
												'Only on…',
												'chatlume-ai-chatbot'
											),
											description: __(
												'Only on the pages you choose below.',
												'chatlume-ai-chatbot'
											),
										},
										{
											id: 'exclude',
											label: __(
												'Everywhere except…',
												'chatlume-ai-chatbot'
											),
											description: __(
												'Hidden on the pages you choose below.',
												'chatlume-ai-chatbot'
											),
										},
									] }
								/>
							) }
						/>
						{ modeError && (
							<p
								id={ `${ ids.mode }-error` }
								role="alert"
								className="chatlume:mt-2 chatlume:text-[13px] chatlume:text-danger"
							>
								{ modeError }
							</p>
						) }
					</fieldset>

					{ display.pages_mode !== 'all' && (
						<div className="chatlume:flex chatlume:flex-col chatlume:gap-5 chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-4">
							<p className="chatlume:text-[13px] chatlume:text-fg-muted">
								{ display.pages_mode === 'include'
									? __(
											'A page qualifies when it matches any of the following.',
											'chatlume-ai-chatbot'
										)
									: __(
											'The button is hidden on a page that matches any of the following.',
											'chatlume-ai-chatbot'
										) }
							</p>
							<Controller
								control={ control }
								name="display.page_ids"
								render={ ( { field } ) => (
									<PagePicker
										value={ field.value }
										known={ info.pages }
										onChange={ field.onChange }
									/>
								) }
							/>

							<fieldset>
								<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
									{ __(
										'Content types',
										'chatlume-ai-chatbot'
									) }
								</legend>
								<p className="chatlume:mb-2 chatlume:text-[13px] chatlume:text-fg-muted">
									{ __(
										'Every single item of the type and its archive page.',
										'chatlume-ai-chatbot'
									) }
								</p>
								<div className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-3">
									{ info.post_types.map( ( type ) => (
										<span
											key={ type.name }
											className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:text-sm chatlume:text-fg"
										>
											<Checkbox
												label={ type.label }
												checked={ display.post_types.includes(
													type.name
												) }
												onChange={ ( checked ) =>
													toggleIn(
														'display.post_types',
														display.post_types,
														type.name,
														checked
													)
												}
											/>
											{ type.label }
										</span>
									) ) }
								</div>
							</fieldset>

							<Field
								id={ ids.patterns }
								label={ __(
									'Page addresses',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'One per line. Use * as a wildcard: /shop/* covers your shop and every page in it; *checkout* covers any address containing "checkout".',
									'chatlume-ai-chatbot'
								) }
								error={ message( errors.patterns ) }
							>
								<textarea
									id={ ids.patterns }
									rows={ 4 }
									className={ cn(
										textareaClasses,
										'chatlume:font-mono'
									) }
									placeholder={ '/shop/*\n/contact' }
									aria-invalid={
										errors.patterns ? true : undefined
									}
									aria-describedby={ `${ ids.patterns }-hint ${ ids.patterns }-error` }
									{ ...register( 'patterns' ) }
								/>
							</Field>
						</div>
					) }
				</FormSection>

				<FormSection
					title={ __(
						'Visitors and devices',
						'chatlume-ai-chatbot'
					) }
				>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-2">
						<Field
							id={ ids.devices }
							label={ __( 'Devices', 'chatlume-ai-chatbot' ) }
							hint={ __(
								'Phones are screens narrower than 768 pixels. Checked in the browser, so page caching does not affect it.',
								'chatlume-ai-chatbot'
							) }
						>
							<Select
								id={ ids.devices }
								aria-describedby={ `${ ids.devices }-hint` }
								{ ...register( 'display.devices' ) }
							>
								<option value="all">
									{ __(
										'Computers and phones',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="desktop">
									{ __(
										'Computers and tablets only',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="mobile">
									{ __(
										'Phones only',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</Field>
						<Field
							id={ ids.visitors }
							label={ __( 'Visitors', 'chatlume-ai-chatbot' ) }
						>
							<Select
								id={ ids.visitors }
								{ ...register( 'display.visitors' ) }
							>
								<option value="all">
									{ __( 'Everyone', 'chatlume-ai-chatbot' ) }
								</option>
								<option value="logged_out">
									{ __(
										'Only visitors who are not logged in',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="logged_in">
									{ __(
										'Only logged-in users',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</Field>
					</div>

					{ display.visitors === 'logged_in' && (
						<fieldset>
							<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
								{ __( 'Roles', 'chatlume-ai-chatbot' ) }
							</legend>
							<p className="chatlume:mb-2 chatlume:text-[13px] chatlume:text-fg-muted">
								{ __(
									'Leave all unchecked to show it to every logged-in user.',
									'chatlume-ai-chatbot'
								) }
							</p>
							<div className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-3">
								{ info.roles.map( ( role ) => (
									<span
										key={ role.slug }
										className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:text-sm chatlume:text-fg"
									>
										<Checkbox
											label={ role.name }
											checked={ display.roles.includes(
												role.slug
											) }
											onChange={ ( checked ) =>
												toggleIn(
													'display.roles',
													display.roles,
													role.slug,
													checked
												)
											}
										/>
										{ role.name }
									</span>
								) ) }
							</div>
						</fieldset>
					) }
				</FormSection>

				<FormSection
					title={ __( 'Business hours', 'chatlume-ai-chatbot' ) }
					description={ sprintf(
						/* translators: %s: Site timezone, e.g. "Europe/Berlin (UTC+2)". */
						__(
							'Times use your site timezone: %s. Change it in Settings > General.',
							'chatlume-ai-chatbot'
						),
						info.timezone.label
					) }
					actions={
						display.hours.enabled && (
							<Badge tone={ openNow ? 'success' : 'neutral' }>
								{ openNow
									? __( 'Open now', 'chatlume-ai-chatbot' )
									: __(
											'Closed now',
											'chatlume-ai-chatbot'
										) }
							</Badge>
						)
					}
				>
					<SwitchField
						control={ control }
						name="display.hours.enabled"
						label={ __(
							'Only answer during business hours',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Outside these hours the bot does not answer, and no AI costs are spent.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ hoursError && (
						<p
							role="alert"
							className="chatlume:-mt-2 chatlume:text-[13px] chatlume:text-danger"
						>
							{ hoursError }
						</p>
					) }

					{ display.hours.enabled && (
						<>
							<div className="chatlume:overflow-x-auto">
								<table className="chatlume:w-full chatlume:text-sm">
									<caption className="chatlume:sr-only">
										{ __(
											'Opening hours',
											'chatlume-ai-chatbot'
										) }
									</caption>
									<thead>
										<tr className="chatlume:text-start chatlume:text-xs chatlume:text-fg-muted">
											<th
												scope="col"
												className="chatlume:pb-2 chatlume:text-start chatlume:font-medium"
											>
												{ __(
													'Day',
													'chatlume-ai-chatbot'
												) }
											</th>
											<th
												scope="col"
												className="chatlume:pb-2 chatlume:text-start chatlume:font-medium"
											>
												{ __(
													'Open',
													'chatlume-ai-chatbot'
												) }
											</th>
											<th
												scope="col"
												className="chatlume:pb-2 chatlume:text-start chatlume:font-medium"
											>
												{ __(
													'From',
													'chatlume-ai-chatbot'
												) }
											</th>
											<th
												scope="col"
												className="chatlume:pb-2 chatlume:text-start chatlume:font-medium"
											>
												{ __(
													'To',
													'chatlume-ai-chatbot'
												) }
											</th>
											<th
												scope="col"
												className="chatlume:sr-only"
											>
												{ __(
													'Summary',
													'chatlume-ai-chatbot'
												) }
											</th>
										</tr>
									</thead>
									<tbody className="chatlume:divide-y chatlume:divide-border">
										{ DAY_KEYS.map( ( day ) => {
											const hours =
												display.hours.days[ day ];
											return (
												<tr key={ day }>
													<th
														scope="row"
														className="chatlume:py-2 chatlume:pe-4 chatlume:text-start chatlume:font-medium chatlume:text-fg"
													>
														{ days[ day ] }
														{ info.now.day ===
															day && (
															<span className="chatlume:ms-2 chatlume:text-xs chatlume:font-normal chatlume:text-fg-muted">
																{ __(
																	'today',
																	'chatlume-ai-chatbot'
																) }
															</span>
														) }
													</th>
													<td className="chatlume:py-2 chatlume:pe-4">
														<Controller
															control={ control }
															name={ `display.hours.days.${ day }.open` }
															render={ ( {
																field,
															} ) => (
																<Switch
																	checked={
																		field.value
																	}
																	onCheckedChange={
																		field.onChange
																	}
																	aria-label={ sprintf(
																		/* translators: %s: Day name. */
																		__(
																			'Open on %s',
																			'chatlume-ai-chatbot'
																		),
																		days[
																			day
																		]
																	) }
																/>
															) }
														/>
													</td>
													<td className="chatlume:py-2 chatlume:pe-3">
														<Input
															type="time"
															step={ 60 }
															className="chatlume:w-32"
															disabled={
																! hours.open
															}
															aria-label={ sprintf(
																/* translators: %s: Day name. */
																__(
																	'Opening time on %s',
																	'chatlume-ai-chatbot'
																),
																days[ day ]
															) }
															{ ...register(
																`display.hours.days.${ day }.start`
															) }
														/>
													</td>
													<td className="chatlume:py-2 chatlume:pe-4">
														<Input
															type="time"
															step={ 60 }
															className="chatlume:w-32"
															disabled={
																! hours.open
															}
															aria-label={ sprintf(
																/* translators: %s: Day name. */
																__(
																	'Closing time on %s',
																	'chatlume-ai-chatbot'
																),
																days[ day ]
															) }
															{ ...register(
																`display.hours.days.${ day }.end`
															) }
														/>
													</td>
													<td className="chatlume:whitespace-nowrap chatlume:py-2 chatlume:text-[13px] chatlume:text-fg-muted">
														{ describeDay( hours ) }
													</td>
												</tr>
											);
										} ) }
									</tbody>
								</table>
							</div>
							<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-2">
								<Button
									type="button"
									size="sm"
									onClick={ () =>
										setValue(
											'display.hours.days',
											copyHours(
												form.getValues(
													'display.hours.days'
												),
												'mon',
												'weekdays'
											),
											{ shouldDirty: true }
										)
									}
								>
									<Copy aria-hidden="true" />
									{ __(
										'Copy Monday to weekdays',
										'chatlume-ai-chatbot'
									) }
								</Button>
								<Button
									type="button"
									size="sm"
									variant="ghost"
									onClick={ () =>
										setValue(
											'display.hours.days',
											copyHours(
												form.getValues(
													'display.hours.days'
												),
												'mon',
												'all'
											),
											{ shouldDirty: true }
										)
									}
								>
									{ __(
										'Copy Monday to every day',
										'chatlume-ai-chatbot'
									) }
								</Button>
							</div>
							<p className="chatlume:text-[13px] chatlume:text-fg-muted">
								{ __(
									'A closing time earlier than the opening time runs past midnight. The same time twice means open all day.',
									'chatlume-ai-chatbot'
								) }
							</p>

							<fieldset>
								<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
									{ __(
										'Outside business hours',
										'chatlume-ai-chatbot'
									) }
								</legend>
								<Controller
									control={ control }
									name="display.hours.behavior"
									render={ ( { field } ) => (
										<ChoiceCards
											name={ ids.behavior }
											value={ field.value }
											onChange={ field.onChange }
											columns={ 2 }
											options={ [
												{
													id: 'offline',
													label: __(
														'Show the offline message',
														'chatlume-ai-chatbot'
													),
													description: __(
														'The chat opens with your offline message; visitors cannot type. Contact buttons keep working.',
														'chatlume-ai-chatbot'
													),
												},
												{
													id: 'hide',
													label: __(
														'Hide the chat button',
														'chatlume-ai-chatbot'
													),
													description: __(
														'The floating button disappears until you open again. Chats inside pages show the offline message.',
														'chatlume-ai-chatbot'
													),
												},
											] }
										/>
									) }
								/>
							</fieldset>

							<Field
								id={ ids.offline }
								label={ __(
									'Offline message',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'Also used when the AI budget is used up and the bot is set to go offline.',
									'chatlume-ai-chatbot'
								) }
								error={ message( errors.offline_message ) }
							>
								<textarea
									id={ ids.offline }
									rows={ 3 }
									maxLength={ 500 }
									className={ textareaClasses }
									placeholder={ __(
										'We are away right now. Please leave your email and we will get back to you.',
										'chatlume-ai-chatbot'
									) }
									aria-describedby={ `${ ids.offline }-hint ${ ids.offline }-error` }
									{ ...register( 'offline_message' ) }
								/>
							</Field>
						</>
					) }
				</FormSection>

				<FormSection
					title={ __(
						'Proactive invitations',
						'chatlume-ai-chatbot'
					) }
					description={ __(
						'Invite visitors to chat when they seem interested: after some time on a page, after scrolling, or when they are about to leave.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="display.triggers.enabled"
						label={ __(
							'Invite visitors to chat',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Never shown while the chat is open, outside business hours, or more often than you allow below.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ display.triggers.enabled && (
						<>
							<fieldset>
								<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
									{ __(
										'Invitation',
										'chatlume-ai-chatbot'
									) }
								</legend>
								<Controller
									control={ control }
									name="display.triggers.action"
									render={ ( { field } ) => (
										<ChoiceCards
											name={ ids.triggerAction }
											value={ field.value }
											onChange={ field.onChange }
											columns={ 2 }
											options={ [
												{
													id: 'teaser',
													label: __(
														'Show a message bubble',
														'chatlume-ai-chatbot'
													),
													description: __(
														'A friendly note next to the chat button. Visitors can open or dismiss it.',
														'chatlume-ai-chatbot'
													),
												},
												{
													id: 'open',
													label: __(
														'Open the chat',
														'chatlume-ai-chatbot'
													),
													description: __(
														'Opens the chat window. On phones the message bubble shows instead, so the page is not covered.',
														'chatlume-ai-chatbot'
													),
												},
											] }
										/>
									) }
								/>
							</fieldset>

							<Field
								id={ ids.triggerMessage }
								label={ __(
									'Bubble message',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'Leave empty for: “Hi there! Have a question? I can help.”',
									'chatlume-ai-chatbot'
								) }
							>
								<Input
									id={ ids.triggerMessage }
									maxLength={ 200 }
									aria-describedby={ `${ ids.triggerMessage }-hint` }
									{ ...register(
										'display.triggers.message'
									) }
								/>
							</Field>

							<fieldset className="chatlume:flex chatlume:flex-col chatlume:gap-4">
								<legend className="chatlume:mb-1 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
									{ __( 'When', 'chatlume-ai-chatbot' ) }
								</legend>
								<p className="chatlume:-mt-2 chatlume:text-[13px] chatlume:text-fg-muted">
									{ __(
										'The invitation shows as soon as one of these happens. With all of them off, it shows right after the page loads.',
										'chatlume-ai-chatbot'
									) }
								</p>
								<div className="chatlume:grid chatlume:gap-4 chatlume:sm:grid-cols-2">
									<NumberField
										register={ register }
										name="display.triggers.delay"
										label={ __(
											'After time on the page',
											'chatlume-ai-chatbot'
										) }
										hint={ __(
											'0 turns this off.',
											'chatlume-ai-chatbot'
										) }
										min={ 0 }
										max={ 600 }
										suffix={ __(
											'seconds',
											'chatlume-ai-chatbot'
										) }
										error={ message(
											errors.display?.triggers?.delay
										) }
									/>
									<NumberField
										register={ register }
										name="display.triggers.scroll"
										label={ __(
											'After scrolling',
											'chatlume-ai-chatbot'
										) }
										hint={ __(
											'Share of the page read. 0 turns this off.',
											'chatlume-ai-chatbot'
										) }
										min={ 0 }
										max={ 100 }
										suffix="%"
										error={ message(
											errors.display?.triggers?.scroll
										) }
									/>
								</div>
								<SwitchField
									control={ control }
									name="display.triggers.exit_intent"
									label={ __(
										'When the visitor is about to leave',
										'chatlume-ai-chatbot'
									) }
									description={ __(
										'When the mouse heads for the browser’s tabs or address bar. Computers only; phones have no mouse.',
										'chatlume-ai-chatbot'
									) }
								/>
							</fieldset>

							<fieldset
								aria-describedby={ `${ ids.triggerPages }-error` }
							>
								<legend className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
									{ __( 'Where', 'chatlume-ai-chatbot' ) }
								</legend>
								<Controller
									control={ control }
									name="display.triggers.pages"
									render={ ( { field } ) => (
										<ChoiceCards
											name={ ids.triggerPages }
											value={ field.value }
											onChange={ field.onChange }
											columns={ 2 }
											options={ [
												{
													id: 'all',
													label: __(
														'Every page with the chat button',
														'chatlume-ai-chatbot'
													),
													description: __(
														'Wherever the rules above show the button.',
														'chatlume-ai-chatbot'
													),
												},
												{
													id: 'include',
													label: __(
														'Only on chosen pages',
														'chatlume-ai-chatbot'
													),
													description: __(
														'For example your pricing or contact page.',
														'chatlume-ai-chatbot'
													),
												},
											] }
										/>
									) }
								/>
								{ message(
									errors.display?.triggers?.pages
								) && (
									<p
										id={ `${ ids.triggerPages }-error` }
										className="chatlume:mt-2 chatlume:text-[13px] chatlume:text-danger"
									>
										{ message(
											errors.display?.triggers?.pages
										) }
									</p>
								) }
							</fieldset>
							{ display.triggers.pages === 'include' && (
								<div className="chatlume:flex chatlume:flex-col chatlume:gap-4 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-4">
									<Controller
										control={ control }
										name="display.triggers.page_ids"
										render={ ( { field } ) => (
											<PagePicker
												value={ field.value }
												known={ info.trigger_pages }
												onChange={ field.onChange }
											/>
										) }
									/>
									<Field
										id={ ids.triggerPatterns }
										label={ __(
											'Page addresses',
											'chatlume-ai-chatbot'
										) }
										hint={ __(
											'One per line, with * as a wildcard, for example /pricing or /shop/*.',
											'chatlume-ai-chatbot'
										) }
										error={ message(
											errors.trigger_patterns
										) }
									>
										<textarea
											id={ ids.triggerPatterns }
											rows={ 3 }
											className={ cn(
												textareaClasses,
												'chatlume:font-mono'
											) }
											placeholder={ '/pricing\n/shop/*' }
											aria-invalid={
												errors.trigger_patterns
													? true
													: undefined
											}
											aria-describedby={ `${ ids.triggerPatterns }-hint ${ ids.triggerPatterns }-error` }
											{ ...register(
												'trigger_patterns'
											) }
										/>
									</Field>
								</div>
							) }

							<Field
								id={ ids.triggerFrequency }
								label={ __(
									'How often',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'For each visitor, on this browser.',
									'chatlume-ai-chatbot'
								) }
							>
								<Select
									id={ ids.triggerFrequency }
									aria-describedby={ `${ ids.triggerFrequency }-hint` }
									{ ...register(
										'display.triggers.frequency'
									) }
								>
									<option value="session">
										{ __(
											'Once per visit',
											'chatlume-ai-chatbot'
										) }
									</option>
									<option value="day">
										{ __(
											'At most once a day',
											'chatlume-ai-chatbot'
										) }
									</option>
									<option value="week">
										{ __(
											'At most once a week',
											'chatlume-ai-chatbot'
										) }
									</option>
									<option value="always">
										{ __(
											'On every page view',
											'chatlume-ai-chatbot'
										) }
									</option>
								</Select>
							</Field>
							<SwitchField
								control={ control }
								name="display.triggers.skip_returning"
								label={ __(
									'Skip visitors who have already chatted',
									'chatlume-ai-chatbot'
								) }
								description={ __(
									'They know where the chat is.',
									'chatlume-ai-chatbot'
								) }
							/>
						</>
					) }
				</FormSection>

				<FormSection
					title={ __(
						'Add the chat inside a page',
						'chatlume-ai-chatbot'
					) }
					description={ __(
						'An embedded chat shows on its page whatever the rules above say, and replaces the floating button there.',
						'chatlume-ai-chatbot'
					) }
				>
					<Field
						id={ ids.shortcode }
						label={ __( 'Shortcode', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'Paste it into any page, post, or Shortcode block. Change the height (in pixels) as you like.',
							'chatlume-ai-chatbot'
						) }
					>
						<div className="chatlume:flex chatlume:gap-2">
							<Input
								id={ ids.shortcode }
								readOnly
								value={ info.shortcode }
								className="chatlume:font-mono"
								aria-describedby={ `${ ids.shortcode }-hint` }
								onFocus={ ( event ) => event.target.select() }
							/>
							<Button
								type="button"
								onClick={ () => copy( info.shortcode ) }
							>
								<Copy aria-hidden="true" />
								{ __( 'Copy', 'chatlume-ai-chatbot' ) }
							</Button>
						</div>
					</Field>
					<div>
						<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ __( 'Block', 'chatlume-ai-chatbot' ) }
						</p>
						<p className="chatlume:mt-1 chatlume:text-[13px] chatlume:text-fg-muted">
							{ __(
								'In the block editor, click + and search for “Chatlume Chat”. Set the height in the block settings.',
								'chatlume-ai-chatbot'
							) }
						</p>
					</div>
				</FormSection>
			</form>

			<StickySaveBar
				visible={ isDirty }
				saving={ update.isPending }
				onSave={ () => save() }
				onDiscard={ () => form.reset( toDisplayForm( settings ) ) }
			/>
			{ isDirty && <UnsavedChangesGuard /> }
		</>
	);
}

/**
 * The Display page.
 */
export default function DisplayRoute() {
	const settings = useSettings();
	const info = useQuery< DisplayInfo, ApiError >( {
		queryKey: [ 'chatlume', 'display-info' ],
		queryFn: fetchDisplayInfo,
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

	return <DisplayForm settings={ settings.data } info={ info.data } />;
}
