/**
 * Integrations: webhooks, email notifications, analytics events, and
 * Cloudflare Turnstile.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import { Copy, Eye, EyeOff, Plus, RefreshCw, Send, Trash2 } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import {
	Controller,
	useFieldArray,
	useForm,
	useWatch,
	type Resolver,
} from 'react-hook-form';
import { toast } from 'sonner';

import { FormSection, SwitchField } from '../../components/form/fields';
import { PageHeader } from '../../components/layout/PageHeader';
import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Checkbox } from '../../components/ui/checkbox';
import { Field } from '../../components/ui/field';
import { Input } from '../../components/ui/input';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ApiError } from '../../lib/api';
import { fieldMessage, issuesToErrors } from '../../lib/forms';
import {
	clearDeliveries,
	deleteTurnstileKey,
	eventLabels,
	fetchIntegrations,
	fromIntegrationsForm,
	integrationsFormSchema,
	integrationsMessage,
	regenerateSecret,
	saveTurnstileKey,
	testEmail,
	testWebhook,
	toIntegrationsForm,
	verifySnippet,
	type IntegrationsForm,
	type IntegrationsInfo,
} from '../../lib/integrations';
import { WEBHOOK_EVENTS, type Settings } from '../../lib/settingsSchema';

const INFO_KEY = [ 'chatlume', 'integrations' ] as const;

const resolver: Resolver< IntegrationsForm > = async ( values ) => {
	const result = integrationsFormSchema.safeParse( values );
	return result.success
		? { values, errors: {} }
		: {
				values: {},
				errors: issuesToErrors< IntegrationsForm >(
					result.error.issues
				),
			};
};

/**
 * A validation message.
 *
 * @param error Field error.
 * @return Text.
 */
function message(
	error: { type?: unknown; message?: unknown } | undefined
): string | undefined {
	return error
		? ( integrationsMessage( error.message ) ?? fieldMessage( error ) )
		: undefined;
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

/**
 * Webhook signing secret and recent deliveries.
 *
 * @param props      Props.
 * @param props.info Integrations info.
 */
function WebhookDetails( { info }: { info: IntegrationsInfo } ) {
	const queryClient = useQueryClient();
	const [ shown, setShown ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const labels = eventLabels();
	const secretId = useId();
	const regenerate = useMutation( {
		mutationFn: regenerateSecret,
		onSuccess: ( data ) => {
			queryClient.setQueryData< IntegrationsInfo >(
				INFO_KEY,
				( current ) =>
					current ? { ...current, secret: data.secret } : current
			);
			toast.success(
				__(
					'New secret created. Update it on every receiving server.',
					'chatlume-ai-chatbot'
				)
			);
		},
	} );
	const clear = useMutation( {
		mutationFn: clearDeliveries,
		onSuccess: () =>
			queryClient.setQueryData< IntegrationsInfo >(
				INFO_KEY,
				( current ) =>
					current ? { ...current, deliveries: [] } : current
			),
	} );

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
				<label
					htmlFor={ secretId }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ __( 'Signing secret', 'chatlume-ai-chatbot' ) }
				</label>
				<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-2">
					<Input
						id={ secretId }
						readOnly
						className="chatlume:min-w-0 chatlume:flex-1 chatlume:font-mono"
						aria-describedby={ `${ secretId }-hint` }
						value={
							shown
								? info.secret
								: '•'.repeat( 24 ) + info.secret.slice( -4 )
						}
					/>
					<Button
						type="button"
						size="icon"
						aria-label={
							shown
								? __( 'Hide secret', 'chatlume-ai-chatbot' )
								: __( 'Show secret', 'chatlume-ai-chatbot' )
						}
						onClick={ () => setShown( ! shown ) }
					>
						{ shown ? (
							<EyeOff aria-hidden="true" />
						) : (
							<Eye aria-hidden="true" />
						) }
					</Button>
					<Button
						type="button"
						size="icon"
						aria-label={ __(
							'Copy secret',
							'chatlume-ai-chatbot'
						) }
						onClick={ () => copy( info.secret ) }
					>
						<Copy aria-hidden="true" />
					</Button>
					<Button
						type="button"
						disabled={ regenerate.isPending }
						onClick={ () => setConfirming( true ) }
					>
						<RefreshCw aria-hidden="true" />
						{ __( 'New secret', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
				<p
					id={ `${ secretId }-hint` }
					className="chatlume:text-[13px] chatlume:text-fg-muted"
				>
					{ __(
						'Every request carries an X-Chatlume-Signature header (sha256= followed by the HMAC-SHA256 of the body, made with this secret) and an X-Chatlume-Delivery ID that stays the same on a retry, so you can ignore repeats. Check the signature before trusting a request:',
						'chatlume-ai-chatbot'
					) }
				</p>
				<pre className="chatlume:overflow-x-auto chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-3 chatlume:text-xs">
					{ verifySnippet() }
				</pre>
			</div>

			<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
				<div className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3">
					<h3 className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
						{ __( 'Recent deliveries', 'chatlume-ai-chatbot' ) }
					</h3>
					{ info.deliveries.length > 0 && (
						<Button
							type="button"
							size="sm"
							variant="ghost"
							disabled={ clear.isPending }
							onClick={ () => clear.mutate() }
						>
							{ __( 'Clear', 'chatlume-ai-chatbot' ) }
						</Button>
					) }
				</div>
				{ info.deliveries.length === 0 ? (
					<p className="chatlume:text-sm chatlume:text-fg-muted">
						{ __(
							'Nothing sent yet. The last 50 deliveries appear here (without their contents).',
							'chatlume-ai-chatbot'
						) }
					</p>
				) : (
					<ul className="chatlume:divide-y chatlume:divide-border chatlume:rounded-control chatlume:border chatlume:border-border">
						{ info.deliveries.map( ( delivery, index ) => (
							<li
								key={ `${ index }-${ delivery.id }` }
								className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-x-3 chatlume:gap-y-1 chatlume:px-3 chatlume:py-2 chatlume:text-sm"
							>
								<Badge
									tone={ delivery.ok ? 'success' : 'danger' }
								>
									{ delivery.status > 0
										? String( delivery.status )
										: __(
												'Failed',
												'chatlume-ai-chatbot'
											) }
								</Badge>
								<span className="chatlume:font-medium chatlume:text-fg">
									{ labels[
										delivery.event as keyof typeof labels
									] ?? delivery.event }
								</span>
								<span className="chatlume:text-fg-muted">
									{ delivery.host }
								</span>
								{ delivery.attempt > 1 && (
									<span className="chatlume:text-fg-muted">
										{ __( 'retry', 'chatlume-ai-chatbot' ) }
									</span>
								) }
								<span className="chatlume:ms-auto chatlume:text-xs chatlume:text-fg-muted">
									{ new Date( delivery.at ).toLocaleString() }
								</span>
								{ delivery.error && (
									<span className="chatlume:w-full chatlume:text-xs chatlume:text-danger">
										{ delivery.error }
									</span>
								) }
							</li>
						) ) }
					</ul>
				) }
			</div>

			<ConfirmDialog
				open={ confirming }
				title={ __( 'Create a new secret?', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Receiving servers that check signatures will reject requests until you give them the new secret.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __(
					'Create new secret',
					'chatlume-ai-chatbot'
				) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				onConfirm={ () => {
					setConfirming( false );
					regenerate.mutate();
				} }
				onCancel={ () => setConfirming( false ) }
			/>
		</div>
	);
}

/**
 * The Turnstile secret key (stored encrypted, shown masked).
 *
 * @param props      Props.
 * @param props.info Integrations info.
 */
function TurnstileKey( { info }: { info: IntegrationsInfo } ) {
	const queryClient = useQueryClient();
	const [ secret, setSecret ] = useState( '' );
	const id = useId();
	const update = ( data: { turnstile: IntegrationsInfo[ 'turnstile' ] } ) =>
		queryClient.setQueryData< IntegrationsInfo >( INFO_KEY, ( current ) =>
			current ? { ...current, turnstile: data.turnstile } : current
		);
	const save = useMutation( {
		mutationFn: saveTurnstileKey,
		onSuccess: ( data ) => {
			update( data );
			setSecret( '' );
			toast.success( __( 'Secret key saved.', 'chatlume-ai-chatbot' ) );
		},
		onError: ( error: ApiError ) => toast.error( error.message ),
	} );
	const remove = useMutation( {
		mutationFn: deleteTurnstileKey,
		onSuccess: update,
	} );
	const status = info.turnstile;

	if ( status.source === 'constant' ) {
		return (
			<p className="chatlume:text-sm chatlume:text-fg-muted">
				{ sprintf(
					/* translators: %s: Constant name. */
					__(
						'The secret key is defined in wp-config.php (%s).',
						'chatlume-ai-chatbot'
					),
					status.constant
				) }
			</p>
		);
	}

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
			<label
				htmlFor={ id }
				className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
			>
				{ __( 'Secret key', 'chatlume-ai-chatbot' ) }
			</label>
			<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-2">
				<Input
					id={ id }
					type="password"
					autoComplete="off"
					className="chatlume:min-w-0 chatlume:flex-1"
					placeholder={
						status.source === 'settings'
							? status.masked
							: __(
									'Paste your secret key',
									'chatlume-ai-chatbot'
								)
					}
					aria-describedby={ `${ id }-hint` }
					value={ secret }
					onChange={ ( event ) => setSecret( event.target.value ) }
				/>
				<Button
					type="button"
					disabled={ ! secret.trim() || save.isPending }
					onClick={ () => save.mutate( secret.trim() ) }
				>
					{ __( 'Save key', 'chatlume-ai-chatbot' ) }
				</Button>
				{ status.source === 'settings' && (
					<Button
						type="button"
						variant="ghost"
						disabled={ remove.isPending }
						onClick={ () => remove.mutate() }
					>
						{ __( 'Remove', 'chatlume-ai-chatbot' ) }
					</Button>
				) }
			</div>
			<p
				id={ `${ id }-hint` }
				className="chatlume:text-[13px] chatlume:text-fg-muted"
			>
				{ status.readable
					? __(
							'Stored encrypted. It is never shown again or sent to visitors.',
							'chatlume-ai-chatbot'
						)
					: __(
							'The saved key can no longer be read (your site’s security keys changed). Please enter it again.',
							'chatlume-ai-chatbot'
						) }
			</p>
		</div>
	);
}

/**
 * The form.
 *
 * @param props          Props.
 * @param props.settings Settings.
 * @param props.info     Integrations info.
 */
function IntegrationsPage( {
	settings,
	info,
}: {
	settings: Settings;
	info: IntegrationsInfo;
} ) {
	const queryClient = useQueryClient();
	const update = useUpdateSettings();
	const form = useForm< IntegrationsForm >( {
		resolver,
		defaultValues: toIntegrationsForm( settings ),
	} );
	const {
		control,
		register,
		formState: { isDirty, errors },
	} = form;
	const values = useWatch( { control } ) as IntegrationsForm;
	const webhooks = useFieldArray( {
		control,
		name: 'integrations.webhooks',
	} );
	const labels = eventLabels();
	const ids = {
		team: useId(),
		alert: useId(),
		siteKey: useId(),
	};
	const [ testing, setTesting ] = useState< number | null >( null );

	useUnsavedChangesWarning( isDirty );

	useEffect( () => {
		if ( ! form.formState.isDirty ) {
			form.reset( toIntegrationsForm( settings ) );
		}
	}, [ settings, form ] );

	const save = form.handleSubmit( async ( next ) => {
		try {
			const saved = await update.mutateAsync(
				fromIntegrationsForm( next )
			);
			form.reset( toIntegrationsForm( saved ) );
			toast.success( __( 'Integrations saved.', 'chatlume-ai-chatbot' ) );
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

	const runTest = async ( index: number ) => {
		const url = (
			values.integrations?.webhooks?.[ index ]?.url ?? ''
		).trim();
		if ( ! /^https?:\/\/\S+$/i.test( url ) ) {
			toast.error(
				__(
					'Enter a web address starting with http:// or https://.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		setTesting( index );
		try {
			const result = await testWebhook( url );
			queryClient.setQueryData< IntegrationsInfo >(
				INFO_KEY,
				( current ) =>
					current
						? { ...current, deliveries: result.deliveries }
						: current
			);
			if ( result.ok ) {
				toast.success(
					sprintf(
						/* translators: 1: HTTP status, 2: Milliseconds. */
						__(
							'Delivered: the server answered %1$d in %2$d ms.',
							'chatlume-ai-chatbot'
						),
						result.status,
						result.ms
					)
				);
			} else {
				toast.error(
					result.status > 0
						? sprintf(
								/* translators: %d: HTTP status. */
								__(
									'The server answered %d. Check the address and that it accepts POST requests.',
									'chatlume-ai-chatbot'
								),
								result.status
							)
						: sprintf(
								/* translators: %s: Error. */
								__(
									'The server could not be reached: %s',
									'chatlume-ai-chatbot'
								),
								result.error
							)
				);
			}
		} catch ( error ) {
			toast.error(
				error instanceof Error
					? error.message
					: __( 'Something went wrong.', 'chatlume-ai-chatbot' )
			);
		} finally {
			setTesting( null );
		}
	};

	const mailTest = useMutation( {
		mutationFn: testEmail,
		onSuccess: ( data ) =>
			toast.success(
				sprintf(
					/* translators: %s: Email address. */
					__( 'Test email sent to %s.', 'chatlume-ai-chatbot' ),
					data.sent_to
				)
			),
		onError: ( error: ApiError ) => toast.error( error.message ),
	} );

	return (
		<>
			<PageHeader
				title={ __( 'Integrations', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Send chat events to other tools, choose where emails go, and protect the chat from bots.',
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
					title={ __( 'Webhooks', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Chatlume sends a signed JSON request to these addresses when the chosen events happen, for example to add leads to your CRM or post to Slack through Zapier or Make.',
						'chatlume-ai-chatbot'
					) }
				>
					{ webhooks.fields.map( ( field, index ) => (
						<fieldset
							key={ field.id }
							className="chatlume:flex chatlume:flex-col chatlume:gap-3 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-4"
						>
							<legend className="chatlume:sr-only">
								{ sprintf(
									/* translators: %d: Webhook number. */
									__( 'Webhook %d', 'chatlume-ai-chatbot' ),
									index + 1
								) }
							</legend>
							<Field
								id={ `chatlume-webhook-${ index }` }
								label={ __( 'Address', 'chatlume-ai-chatbot' ) }
								error={ message(
									errors.integrations?.webhooks?.[ index ]
										?.url
								) }
							>
								<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-2">
									<Input
										id={ `chatlume-webhook-${ index }` }
										type="url"
										placeholder="https://hooks.example.com/…"
										maxLength={ 500 }
										className="chatlume:min-w-0 chatlume:flex-1"
										{ ...register(
											`integrations.webhooks.${ index }.url`
										) }
									/>
									<Button
										type="button"
										disabled={ testing !== null }
										onClick={ () => runTest( index ) }
									>
										<Send aria-hidden="true" />
										{ testing === index
											? __(
													'Sending…',
													'chatlume-ai-chatbot'
												)
											: __(
													'Send test',
													'chatlume-ai-chatbot'
												) }
									</Button>
									<Button
										type="button"
										size="icon"
										variant="ghost"
										aria-label={ __(
											'Remove webhook',
											'chatlume-ai-chatbot'
										) }
										onClick={ () =>
											webhooks.remove( index )
										}
									>
										<Trash2 aria-hidden="true" />
									</Button>
								</div>
							</Field>
							<Controller
								control={ control }
								name={ `integrations.webhooks.${ index }.events` }
								render={ ( { field: events } ) => (
									<div>
										<p className="chatlume:mb-2 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
											{ __(
												'Send for',
												'chatlume-ai-chatbot'
											) }
										</p>
										<div className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-2">
											{ WEBHOOK_EVENTS.map( ( event ) => (
												<span
													key={ event }
													className="chatlume:flex chatlume:items-center chatlume:gap-2 chatlume:text-sm chatlume:text-fg"
												>
													<Checkbox
														label={
															labels[ event ]
														}
														checked={ events.value.includes(
															event
														) }
														onChange={ (
															checked
														) =>
															events.onChange(
																checked
																	? [
																			...events.value,
																			event,
																		]
																	: events.value.filter(
																			(
																				item
																			) =>
																				item !==
																				event
																		)
															)
														}
													/>
													{ labels[ event ] }
												</span>
											) ) }
										</div>
										{ message(
											errors.integrations?.webhooks?.[
												index
											]?.events as
												| { message?: unknown }
												| undefined
										) && (
											<p className="chatlume:mt-2 chatlume:text-[13px] chatlume:text-danger">
												{ message(
													errors.integrations
														?.webhooks?.[ index ]
														?.events as
														| { message?: unknown }
														| undefined
												) }
											</p>
										) }
									</div>
								) }
							/>
						</fieldset>
					) ) }
					{ webhooks.fields.length < 5 && (
						<div>
							<Button
								type="button"
								onClick={ () =>
									webhooks.append( {
										url: '',
										events: [ 'lead_captured' ],
									} )
								}
							>
								<Plus aria-hidden="true" />
								{ __( 'Add webhook', 'chatlume-ai-chatbot' ) }
							</Button>
						</div>
					) }
					<WebhookDetails info={ info } />
				</FormSection>

				<FormSection
					title={ __( 'Email notifications', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Emails are sent by your WordPress site. If they do not arrive, use an SMTP plugin.',
						'chatlume-ai-chatbot'
					) }
					actions={
						<Button
							type="button"
							size="sm"
							disabled={ mailTest.isPending || isDirty }
							onClick={ () => mailTest.mutate() }
						>
							<Send aria-hidden="true" />
							{ __( 'Send a test email', 'chatlume-ai-chatbot' ) }
						</Button>
					}
				>
					<Field
						id={ ids.team }
						label={ __( 'Team address', 'chatlume-ai-chatbot' ) }
						hint={ sprintf(
							/* translators: %s: Site admin email. */
							__(
								'New leads and conversations sent with “Talk to a person”. Empty: %s.',
								'chatlume-ai-chatbot'
							),
							info.admin_email
						) }
						error={ message( errors.team_email ) }
					>
						<Input
							id={ ids.team }
							type="email"
							maxLength={ 100 }
							placeholder={ info.admin_email }
							aria-describedby={ `${ ids.team }-hint` }
							{ ...register( 'team_email' ) }
						/>
					</Field>
					<SwitchField
						control={ control }
						name="notify"
						label={ __(
							'Email the team about new leads',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'One email per new lead, with Reply-To set to the visitor.',
							'chatlume-ai-chatbot'
						) }
					/>
					<Field
						id={ ids.alert }
						label={ __( 'Budget alerts', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'Sent at 80% and 100% of your AI budget. Empty: the site admin email.',
							'chatlume-ai-chatbot'
						) }
						error={ message( errors.alert_email ) }
					>
						<Input
							id={ ids.alert }
							type="email"
							maxLength={ 100 }
							placeholder={ info.admin_email }
							aria-describedby={ `${ ids.alert }-hint` }
							{ ...register( 'alert_email' ) }
						/>
					</Field>
				</FormSection>

				<FormSection
					title={ __( 'Analytics events', 'chatlume-ai-chatbot' ) }
					description={ __(
						'For your own analytics, such as Google Tag Manager. Chatlume itself sends nothing anywhere.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="integrations.analytics"
						label={ __(
							'Report chat events on the page',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Pushes chat_open, message_sent, lead_captured, and handoff to window.dataLayer (when the page has one) and dispatches chatlume:chat_open and the others on document. Events never include messages, names, or email addresses.',
							'chatlume-ai-chatbot'
						) }
					/>
				</FormSection>

				<FormSection
					title={ __( 'Bot protection', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Chatlume already limits messages per visitor and checks that requests come from your site. Cloudflare Turnstile adds a usually invisible check when a visitor starts a chat.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="integrations.turnstile"
						label={ __(
							'Use Cloudflare Turnstile',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Loads Cloudflare’s script when a visitor opens the chat, and sends Cloudflare the check and the visitor’s IP address. Needs a free Cloudflare account; create a widget under Turnstile in your Cloudflare dashboard.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ values.integrations?.turnstile && (
						<>
							<Field
								id={ ids.siteKey }
								label={ __(
									'Site key',
									'chatlume-ai-chatbot'
								) }
								error={ message(
									errors.integrations?.turnstile_site_key
								) }
							>
								<Input
									id={ ids.siteKey }
									maxLength={ 100 }
									autoComplete="off"
									{ ...register(
										'integrations.turnstile_site_key'
									) }
								/>
							</Field>
							<TurnstileKey info={ info } />
							{ info.turnstile.source === 'none' && (
								<p className="chatlume:text-[13px] chatlume:text-warning">
									{ __(
										'Turnstile stays off until both keys are saved.',
										'chatlume-ai-chatbot'
									) }
								</p>
							) }
						</>
					) }
				</FormSection>

				<StickySaveBar
					visible={ isDirty }
					saving={ update.isPending }
					onSave={ () => save() }
					onDiscard={ () =>
						form.reset( toIntegrationsForm( settings ) )
					}
				/>
				{ isDirty && <UnsavedChangesGuard /> }
			</form>
		</>
	);
}

export default function IntegrationsRoute() {
	const settings = useSettings();
	const info = useQuery< IntegrationsInfo, ApiError >( {
		queryKey: INFO_KEY,
		queryFn: fetchIntegrations,
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

	return <IntegrationsPage settings={ settings.data } info={ info.data } />;
}
