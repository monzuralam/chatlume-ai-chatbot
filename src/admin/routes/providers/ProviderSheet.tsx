import { zodResolver } from '@hookform/resolvers/zod';
import { __, sprintf } from '@wordpress/i18n';
import {
	CircleCheck,
	CircleX,
	ExternalLink,
	KeyRound,
	LoaderCircle,
	Plus,
	Trash2,
	TriangleAlert,
	Zap,
} from 'lucide-react';
import { useEffect, useId, useState, type ReactNode } from 'react';
import { Controller, useFieldArray, useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { ModelPicker } from '../../components/providers/ModelPicker';
import {
	ProviderAvatar,
	StateBadge,
} from '../../components/providers/ProviderBits';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Button } from '../../components/ui/button';
import { Field } from '../../components/ui/field';
import { Input } from '../../components/ui/input';
import { Sheet } from '../../components/ui/sheet';
import { Switch } from '../../components/ui/switch';
import { useApiKey, useTestProvider } from '../../hooks/useProviders';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import {
	providerConfigSchema,
	toProviderForm,
	type ProviderConfig,
	type ProviderSummary,
	type TestResult,
} from '../../lib/providers';

/**
 * Translated message for a validation code from providerConfigSchema.
 *
 * @param code Zod message code.
 */
function validationMessage( code: string | undefined ): string | undefined {
	switch ( code ) {
		case undefined:
			return undefined;
		case 'url':
			return __(
				'Enter a web address starting with http:// or https://.',
				'chatlume-ai-chatbot'
			);
		case 'header':
			return __(
				'Header names may contain only letters, numbers, and dashes.',
				'chatlume-ai-chatbot'
			);
		case 'model':
			return __(
				'Enter a model ID (letters, numbers, and . _ : / @ + -).',
				'chatlume-ai-chatbot'
			);
		default:
			return __( 'Please check this value.', 'chatlume-ai-chatbot' );
	}
}

function Section( {
	title,
	children,
}: {
	title: string;
	children: ReactNode;
} ) {
	return (
		<section className="chatlume:border-b chatlume:border-border chatlume:pb-6 chatlume:mb-6 chatlume:last:mb-0 chatlume:last:border-0 chatlume:last:pb-0">
			<h3 className="chatlume:mb-4 chatlume:text-sm chatlume:font-semibold chatlume:uppercase chatlume:tracking-wide chatlume:text-fg-muted">
				{ title }
			</h3>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-4">
				{ children }
			</div>
		</section>
	);
}

function ExternalButton( {
	href,
	children,
}: {
	href: string;
	children: ReactNode;
} ) {
	return (
		<Button variant="ghost" size="sm" asChild>
			<a href={ href } target="_blank" rel="noopener noreferrer">
				<ExternalLink aria-hidden="true" />
				{ children }
				<span className="screen-reader-text">
					{ __( '(opens in a new tab)', 'chatlume-ai-chatbot' ) }
				</span>
			</a>
		</Button>
	);
}

function ApiKeySection( { provider }: { provider: ProviderSummary } ) {
	const id = useId();
	const [ value, setValue ] = useState( '' );
	const [ confirmRemove, setConfirmRemove ] = useState( false );
	const { save, remove } = useApiKey();
	const { key } = provider;

	if ( key.source === 'constant' ) {
		return (
			<p className="chatlume:rounded-control chatlume:bg-surface-muted chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px] chatlume:text-fg">
				{ sprintf(
					/* translators: 1: PHP constant name, 2: Masked key ending. */
					__(
						'The API key is defined in wp-config.php as %1$s (%2$s). Remove that line to manage the key here.',
						'chatlume-ai-chatbot'
					),
					key.constant,
					key.masked
				) }
			</p>
		);
	}

	return (
		<>
			{ ! key.readable && (
				<p
					role="alert"
					className="chatlume:flex chatlume:gap-2 chatlume:rounded-control chatlume:bg-warning-soft chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px] chatlume:text-warning"
				>
					<TriangleAlert
						aria-hidden="true"
						className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
					/>
					{ sprintf(
						/* translators: %s: PHP constant name. */
						__(
							'Please re-enter your API key. The saved key can no longer be read because your site’s security keys (salts) changed. Tip: define it in wp-config.php as %s so this cannot happen again.',
							'chatlume-ai-chatbot'
						),
						key.constant
					) }
				</p>
			) }

			<Field
				id={ id }
				label={
					provider.key_required
						? __( 'API key', 'chatlume-ai-chatbot' )
						: __( 'API key (optional)', 'chatlume-ai-chatbot' )
				}
				hint={
					key.source === 'settings' && key.readable
						? sprintf(
								/* translators: %s: Masked key ending such as "••••1234". */
								__(
									'Saved key: %s. Enter a new key to replace it.',
									'chatlume-ai-chatbot'
								),
								key.masked
							)
						: __(
								'Stored encrypted. It is never shown again or sent to the browser.',
								'chatlume-ai-chatbot'
							)
				}
			>
				<div className="chatlume:flex chatlume:gap-2">
					<Input
						id={ id }
						type="password"
						autoComplete="off"
						spellCheck={ false }
						value={ value }
						aria-describedby={ `${ id }-hint` }
						onChange={ ( event ) =>
							setValue( event.target.value.trim() )
						}
						placeholder={
							key.source === 'settings'
								? key.masked
								: __(
										'Paste your API key',
										'chatlume-ai-chatbot'
									)
						}
					/>
					<Button
						variant="primary"
						disabled={ value === '' || save.isPending }
						onClick={ () =>
							save.mutate(
								{ id: provider.id, apiKey: value },
								{
									onSuccess: () => {
										setValue( '' );
										toast.success(
											__(
												'API key saved.',
												'chatlume-ai-chatbot'
											)
										);
									},
									onError: ( error ) =>
										toast.error( error.message ),
								}
							)
						}
					>
						{ save.isPending ? (
							<LoaderCircle
								aria-hidden="true"
								className="chatlume:animate-spin"
							/>
						) : (
							<KeyRound aria-hidden="true" />
						) }
						{ __( 'Save key', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
			</Field>

			<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-1">
				{ typeof provider.links.keys === 'string' && (
					<ExternalButton href={ provider.links.keys }>
						{ __( 'Get an API key', 'chatlume-ai-chatbot' ) }
					</ExternalButton>
				) }
				{ typeof provider.links.billing === 'string' && (
					<ExternalButton href={ provider.links.billing }>
						{ __( 'Billing', 'chatlume-ai-chatbot' ) }
					</ExternalButton>
				) }
				{ key.source === 'settings' && (
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => setConfirmRemove( true ) }
					>
						<Trash2 aria-hidden="true" />
						{ __( 'Remove key', 'chatlume-ai-chatbot' ) }
					</Button>
				) }
			</div>

			<ConfirmDialog
				open={ confirmRemove }
				title={ __( 'Remove this API key?', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Chatlume will stop using this provider until you add a key again.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Remove key', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onCancel={ () => setConfirmRemove( false ) }
				onConfirm={ () => {
					setConfirmRemove( false );
					remove.mutate(
						{ id: provider.id },
						{
							onSuccess: () =>
								toast.success(
									__(
										'API key removed.',
										'chatlume-ai-chatbot'
									)
								),
							onError: ( error ) => toast.error( error.message ),
						}
					);
				} }
			/>
		</>
	);
}

/**
 * Connection test outcome with the plain-language explanation and fix link.
 *
 * @param props        Props.
 * @param props.result Test result.
 */
export function TestOutcome( { result }: { result: TestResult } ) {
	if ( result.ok ) {
		return (
			<div
				role="status"
				className="chatlume:flex chatlume:gap-2 chatlume:rounded-control chatlume:bg-success-soft chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px] chatlume:text-success"
			>
				<CircleCheck
					aria-hidden="true"
					className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
				/>
				<div>
					<p className="chatlume:font-medium">{ result.message }</p>
					{ result.reply && (
						<p className="chatlume:mt-0.5 chatlume:text-fg-muted">
							{ sprintf(
								/* translators: %s: The model's reply to the test prompt. */
								__( 'Reply: “%s”', 'chatlume-ai-chatbot' ),
								result.reply
							) }
						</p>
					) }
				</div>
			</div>
		);
	}

	const error = result.error;

	return (
		<div
			role="alert"
			className="chatlume:flex chatlume:gap-2 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px]"
		>
			<CircleX
				aria-hidden="true"
				className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-danger"
			/>
			<div className="chatlume:min-w-0">
				<p className="chatlume:font-medium chatlume:text-danger">
					{ error?.title ?? result.message }
				</p>
				{ error?.explanation && (
					<p className="chatlume:mt-1 chatlume:text-fg">
						{ error.explanation }
					</p>
				) }
				{ error?.detail && (
					<p className="chatlume:mt-1 chatlume:break-words chatlume:text-fg-muted">
						{ sprintf(
							/* translators: %s: Message returned by the AI provider. */
							__( 'Provider said: %s', 'chatlume-ai-chatbot' ),
							error.detail
						) }
					</p>
				) }
				{ error?.fix && (
					<div className="chatlume:mt-2">
						<ExternalButton href={ error.fix.url }>
							{ error.fix.label }
						</ExternalButton>
					</div>
				) }
			</div>
		</div>
	);
}

interface ProviderSheetProps {
	provider: ProviderSummary | null;
	isPrimary: boolean;
	isFallback: boolean;
	onOpenChange: ( open: boolean ) => void;
}

/**
 * Slide-out configuration for one provider.
 *
 * @param props              Props.
 * @param props.provider     Provider (null when closed).
 * @param props.isPrimary    Whether it is the primary provider.
 * @param props.isFallback   Whether it is the fallback provider.
 * @param props.onOpenChange Open state handler.
 */
export function ProviderSheet( {
	provider,
	isPrimary,
	isFallback,
	onOpenChange,
}: ProviderSheetProps ) {
	const settings = useSettings();
	const update = useUpdateSettings();
	const test = useTestProvider();
	const [ confirmDiscard, setConfirmDiscard ] = useState( false );
	const ids = {
		baseUrl: useId(),
		model: useId(),
		timeout: useId(),
		attribution: useId(),
	};

	const fields = provider?.fields ?? [];
	const stored = provider
		? ( settings.data?.providers.configs[ provider.id ] as
				Record< string, unknown > | undefined )
		: undefined;

	const form = useForm< ProviderConfig >( {
		resolver: zodResolver( providerConfigSchema ),
		defaultValues: toProviderForm( stored, fields ),
	} );
	const headers = useFieldArray( {
		control: form.control,
		name: 'extra_headers',
	} );
	const prices = useFieldArray( { control: form.control, name: 'prices' } );
	const { isDirty, errors } = form.formState;

	// Load this provider's saved values whenever the panel opens for it.
	const providerId = provider?.id;
	useEffect( () => {
		if ( providerId && settings.data ) {
			form.reset(
				toProviderForm(
					settings.data.providers.configs[ providerId ] as
						Record< string, unknown > | undefined,
					fields
				)
			);
			test.reset();
		}
		// Reset only when another provider is opened or settings load.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ providerId, settings.isSuccess ] );

	const save = form.handleSubmit( async ( values ) => {
		if ( ! provider ) {
			return;
		}
		try {
			await update.mutateAsync( {
				providers: { configs: { [ provider.id ]: values } },
			} );
			form.reset( values );
			toast.success(
				__( 'Provider settings saved.', 'chatlume-ai-chatbot' )
			);
		} catch ( error ) {
			toast.error(
				error instanceof Error ? error.message : String( error )
			);
		}
	} );

	useSaveShortcut(
		() => {
			if ( isDirty && ! update.isPending ) {
				save();
			}
		},
		provider !== null && isDirty
	);

	const setRole = async ( role: 'primary' | 'fallback' ) => {
		if ( ! provider ) {
			return;
		}
		try {
			await update.mutateAsync( {
				providers: {
					[ role ]: {
						provider: provider.id,
						model: form.getValues( 'default_model' ),
					},
				},
			} );
			toast.success(
				role === 'primary'
					? sprintf(
							/* translators: %s: Provider name. */
							__(
								'%s is now the primary provider.',
								'chatlume-ai-chatbot'
							),
							provider.label
						)
					: sprintf(
							/* translators: %s: Provider name. */
							__(
								'%s is now the fallback provider.',
								'chatlume-ai-chatbot'
							),
							provider.label
						)
			);
		} catch ( error ) {
			toast.error(
				error instanceof Error ? error.message : String( error )
			);
		}
	};

	const requestClose = ( open: boolean ) => {
		if ( ! open && isDirty ) {
			setConfirmDiscard( true );
			return;
		}
		onOpenChange( open );
	};

	const runTest = () => {
		if ( ! provider ) {
			return;
		}
		const values = form.getValues();
		test.mutate( {
			id: provider.id,
			model: values.default_model,
			base_url: values.base_url ?? '',
		} );
	};

	const needsKey =
		provider?.state === 'needs_key' || provider?.state === 'key_unreadable';

	return (
		<>
			<Sheet
				open={ provider !== null }
				onOpenChange={ requestClose }
				title={
					provider ? (
						<span className="chatlume:flex chatlume:items-center chatlume:gap-3">
							<ProviderAvatar
								initials={ provider.initials }
								className="chatlume:size-9"
							/>
							{ provider.label }
						</span>
					) : (
						''
					)
				}
				description={ provider?.description }
				footer={
					provider && (
						<>
							<Button
								variant="ghost"
								disabled={ isPrimary || update.isPending }
								onClick={ () => setRole( 'primary' ) }
							>
								{ isPrimary
									? __(
											'Primary provider',
											'chatlume-ai-chatbot'
										)
									: __(
											'Set as primary',
											'chatlume-ai-chatbot'
										) }
							</Button>
							<Button
								variant="ghost"
								disabled={ isFallback || update.isPending }
								onClick={ () => setRole( 'fallback' ) }
							>
								{ isFallback
									? __(
											'Fallback provider',
											'chatlume-ai-chatbot'
										)
									: __(
											'Set as fallback',
											'chatlume-ai-chatbot'
										) }
							</Button>
							<span className="chatlume:flex-1" />
							<Button
								variant="primary"
								disabled={ ! isDirty || update.isPending }
								onClick={ () => save() }
							>
								{ update.isPending && (
									<LoaderCircle
										aria-hidden="true"
										className="chatlume:animate-spin"
									/>
								) }
								{ __( 'Save changes', 'chatlume-ai-chatbot' ) }
							</Button>
						</>
					)
				}
			>
				{ provider && (
					<form
						onSubmit={ ( event ) => {
							event.preventDefault();
							save();
						} }
					>
						<div className="chatlume:mb-6">
							<StateBadge provider={ provider } />
						</div>

						<Section
							title={ __( 'Connection', 'chatlume-ai-chatbot' ) }
						>
							<ApiKeySection provider={ provider } />

							{ fields.includes( 'base_url' ) && (
								<Field
									id={ ids.baseUrl }
									label={ __(
										'Base URL',
										'chatlume-ai-chatbot'
									) }
									hint={
										provider.default_base_url
											? sprintf(
													/* translators: %s: Default URL. */
													__(
														'Leave empty to use %s.',
														'chatlume-ai-chatbot'
													),
													provider.default_base_url
												)
											: __(
													'The address of the OpenAI-compatible API, usually ending in /v1.',
													'chatlume-ai-chatbot'
												)
									}
									error={ validationMessage(
										errors.base_url?.message
									) }
								>
									<Input
										id={ ids.baseUrl }
										inputMode="url"
										placeholder={
											provider.default_base_url ||
											'http://127.0.0.1:8080/v1'
										}
										aria-invalid={ !! errors.base_url }
										aria-describedby={ `${ ids.baseUrl }-hint ${ ids.baseUrl }-error` }
										{ ...form.register( 'base_url' ) }
									/>
								</Field>
							) }
						</Section>

						<Section title={ __( 'Model', 'chatlume-ai-chatbot' ) }>
							<Field
								id={ ids.model }
								label={ __(
									'Default chat model',
									'chatlume-ai-chatbot'
								) }
								hint={
									needsKey
										? __(
												'Save an API key to load the live model list. You can also type a model ID.',
												'chatlume-ai-chatbot'
											)
										: __(
												'Live list from the provider. New models appear automatically; use Refresh to update now.',
												'chatlume-ai-chatbot'
											)
								}
								error={ validationMessage(
									errors.default_model?.message
								) }
							>
								<Controller
									control={ form.control }
									name="default_model"
									render={ ( { field } ) => (
										<ModelPicker
											id={ ids.model }
											providerId={ provider.id }
											kind="chat"
											value={ field.value }
											onChange={ field.onChange }
											describedBy={ `${ ids.model }-hint` }
										/>
									) }
								/>
							</Field>

							<div className="chatlume:flex chatlume:flex-col chatlume:gap-3">
								<div>
									<Button
										onClick={ runTest }
										disabled={ test.isPending }
									>
										{ test.isPending ? (
											<LoaderCircle
												aria-hidden="true"
												className="chatlume:animate-spin"
											/>
										) : (
											<Zap aria-hidden="true" />
										) }
										{ test.isPending
											? __(
													'Testing…',
													'chatlume-ai-chatbot'
												)
											: __(
													'Test connection',
													'chatlume-ai-chatbot'
												) }
									</Button>
								</div>
								{ test.data && (
									<TestOutcome result={ test.data } />
								) }
								{ test.isError && (
									<p
										role="alert"
										className="chatlume:text-[13px] chatlume:text-danger"
									>
										{ test.error.message }
									</p>
								) }
							</div>
						</Section>

						<Section
							title={ __( 'Advanced', 'chatlume-ai-chatbot' ) }
						>
							<Field
								id={ ids.timeout }
								label={ __(
									'Timeout (seconds)',
									'chatlume-ai-chatbot'
								) }
								tooltip={ __(
									'How long to wait for the provider before giving up and trying the fallback.',
									'chatlume-ai-chatbot'
								) }
								hint={ __(
									'Between 5 and 120 seconds.',
									'chatlume-ai-chatbot'
								) }
								error={
									errors.timeout
										? __(
												'Enter a number between 5 and 120.',
												'chatlume-ai-chatbot'
											)
										: undefined
								}
							>
								<Input
									id={ ids.timeout }
									type="number"
									min={ 5 }
									max={ 120 }
									className="chatlume:w-32"
									aria-invalid={ !! errors.timeout }
									aria-describedby={ `${ ids.timeout }-hint ${ ids.timeout }-error` }
									{ ...form.register( 'timeout', {
										valueAsNumber: true,
									} ) }
								/>
							</Field>

							{ fields.includes( 'app_attribution' ) && (
								<div className="chatlume:flex chatlume:items-start chatlume:justify-between chatlume:gap-4">
									<div>
										<label
											htmlFor={ ids.attribution }
											className="chatlume:text-sm chatlume:font-medium"
										>
											{ __(
												'Send app attribution',
												'chatlume-ai-chatbot'
											) }
										</label>
										<p className="chatlume:mt-1 chatlume:text-[13px] chatlume:text-fg-muted">
											{ __(
												'Shares your site’s address and name with OpenRouter so your app appears in their rankings. Off by default.',
												'chatlume-ai-chatbot'
											) }
										</p>
									</div>
									<Controller
										control={ form.control }
										name="app_attribution"
										render={ ( { field } ) => (
											<Switch
												id={ ids.attribution }
												checked={ !! field.value }
												onCheckedChange={
													field.onChange
												}
											/>
										) }
									/>
								</div>
							) }

							<fieldset className="chatlume:flex chatlume:flex-col chatlume:gap-2">
								<legend className="chatlume:mb-1 chatlume:text-sm chatlume:font-medium">
									{ __(
										'Extra headers',
										'chatlume-ai-chatbot'
									) }
								</legend>
								<p className="chatlume:text-[13px] chatlume:text-fg-muted">
									{ __(
										'Sent with every request, for example to a company gateway. Authentication headers cannot be changed here.',
										'chatlume-ai-chatbot'
									) }
								</p>
								{ headers.fields.map( ( item, index ) => (
									<div
										key={ item.id }
										className="chatlume:flex chatlume:items-start chatlume:gap-2"
									>
										<Input
											aria-label={ __(
												'Header name',
												'chatlume-ai-chatbot'
											) }
											placeholder={ __(
												'Name',
												'chatlume-ai-chatbot'
											) }
											aria-invalid={
												!! errors.extra_headers?.[
													index
												]?.name
											}
											{ ...form.register(
												`extra_headers.${ index }.name`
											) }
										/>
										<Input
											aria-label={ __(
												'Header value',
												'chatlume-ai-chatbot'
											) }
											placeholder={ __(
												'Value',
												'chatlume-ai-chatbot'
											) }
											{ ...form.register(
												`extra_headers.${ index }.value`
											) }
										/>
										<Button
											variant="ghost"
											size="icon"
											aria-label={ __(
												'Remove header',
												'chatlume-ai-chatbot'
											) }
											onClick={ () =>
												headers.remove( index )
											}
										>
											<Trash2 aria-hidden="true" />
										</Button>
									</div>
								) ) }
								{ errors.extra_headers && (
									<p
										role="alert"
										className="chatlume:text-[13px] chatlume:font-medium chatlume:text-danger"
									>
										{ validationMessage( 'header' ) }
									</p>
								) }
								{ headers.fields.length < 10 && (
									<div>
										<Button
											size="sm"
											onClick={ () =>
												headers.append( {
													name: '',
													value: '',
												} )
											}
										>
											<Plus aria-hidden="true" />
											{ __(
												'Add header',
												'chatlume-ai-chatbot'
											) }
										</Button>
									</div>
								) }
							</fieldset>

							{ fields.includes( 'prices' ) && (
								<fieldset className="chatlume:flex chatlume:flex-col chatlume:gap-2">
									<legend className="chatlume:mb-1 chatlume:text-sm chatlume:font-medium">
										{ __(
											'Model prices',
											'chatlume-ai-chatbot'
										) }
									</legend>
									<p className="chatlume:text-[13px] chatlume:text-fg-muted">
										{ __(
											'Used to estimate costs and enforce budgets. Enter US dollars per 1 million input and output tokens, from your provider’s pricing page.',
											'chatlume-ai-chatbot'
										) }
									</p>
									{ prices.fields.map( ( item, index ) => (
										<div
											key={ item.id }
											className="chatlume:grid chatlume:grid-cols-[1fr_6rem_6rem_auto] chatlume:items-start chatlume:gap-2"
										>
											<Input
												aria-label={ __(
													'Model ID',
													'chatlume-ai-chatbot'
												) }
												placeholder={ __(
													'Model ID',
													'chatlume-ai-chatbot'
												) }
												aria-invalid={
													!! errors.prices?.[ index ]
														?.model
												}
												{ ...form.register(
													`prices.${ index }.model`
												) }
											/>
											<Input
												type="number"
												min={ 0 }
												step="any"
												aria-label={ __(
													'Input price per 1M tokens (USD)',
													'chatlume-ai-chatbot'
												) }
												placeholder={ __(
													'Input $',
													'chatlume-ai-chatbot'
												) }
												{ ...form.register(
													`prices.${ index }.input`,
													{ valueAsNumber: true }
												) }
											/>
											<Input
												type="number"
												min={ 0 }
												step="any"
												aria-label={ __(
													'Output price per 1M tokens (USD)',
													'chatlume-ai-chatbot'
												) }
												placeholder={ __(
													'Output $',
													'chatlume-ai-chatbot'
												) }
												{ ...form.register(
													`prices.${ index }.output`,
													{ valueAsNumber: true }
												) }
											/>
											<Button
												variant="ghost"
												size="icon"
												aria-label={ __(
													'Remove price',
													'chatlume-ai-chatbot'
												) }
												onClick={ () =>
													prices.remove( index )
												}
											>
												<Trash2 aria-hidden="true" />
											</Button>
										</div>
									) ) }
									{ errors.prices && (
										<p
											role="alert"
											className="chatlume:text-[13px] chatlume:font-medium chatlume:text-danger"
										>
											{ __(
												'Each price needs a model ID and numbers of 0 or more.',
												'chatlume-ai-chatbot'
											) }
										</p>
									) }
									<div>
										<Button
											size="sm"
											onClick={ () =>
												prices.append( {
													model: form.getValues(
														'default_model'
													),
													input: 0,
													output: 0,
												} )
											}
										>
											<Plus aria-hidden="true" />
											{ __(
												'Add price',
												'chatlume-ai-chatbot'
											) }
										</Button>
									</div>
								</fieldset>
							) }
						</Section>
					</form>
				) }
			</Sheet>

			<ConfirmDialog
				open={ confirmDiscard }
				title={ __(
					'Discard unsaved changes?',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Your changes to this provider have not been saved.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Discard', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Keep editing', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onCancel={ () => setConfirmDiscard( false ) }
				onConfirm={ () => {
					setConfirmDiscard( false );
					form.reset();
					onOpenChange( false );
				} }
			/>
		</>
	);
}
