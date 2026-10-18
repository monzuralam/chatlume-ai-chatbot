/**
 * Setup wizard: AI provider → how to get a key → connect and test → model →
 * look → done, with a live widget preview at every step. "Skip AI for now"
 * sets up no-key mode (quick answers and site search).
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { __, sprintf } from '@wordpress/i18n';
import {
	ArrowLeft,
	ArrowRight,
	Check,
	CircleCheck,
	ExternalLink,
	KeyRound,
	Loader2,
	TriangleAlert,
} from 'lucide-react';
import { useEffect, useId, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router';
import { toast } from 'sonner';

import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { ModelPicker } from '../../components/providers/ModelPicker';
import { ProviderAvatar } from '../../components/providers/ProviderBits';
import { WidgetPreview } from '../../components/preview/WidgetPreview';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Card } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import {
	useApiKey,
	useProviders,
	useTestProvider,
} from '../../hooks/useProviders';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import type { ApiError } from '../../lib/api';
import {
	fetchAppearance,
	publishDraft,
	saveDraft,
	type AppearanceState,
} from '../../lib/appearance';
import { getBootstrap } from '../../lib/bootstrap';
import {
	fetchChatbotInfo,
	previewBehavior,
	previewSamples,
	type ChatbotInfo,
} from '../../lib/chatbot';
import {
	fetchModels,
	type ProviderSummary,
	type TestResult,
} from '../../lib/providers';
import {
	freeTier,
	orderProviders,
	pickModel,
	stepLabels,
	wizardSteps,
	type SetupStep,
} from '../../lib/setup';
import type { Settings, SettingsUpdate } from '../../lib/settingsSchema';
import { resolveTheme } from '../../lib/theme/resolve';
import type { Theme } from '../../lib/theme/types';
import { cn } from '../../lib/utils';
import { Segmented } from '../customize/controls';
import { MatchSite } from '../customize/MatchSite';
import { BrandColor } from '../customize/sections';

/**
 * Progress list.
 *
 * @param props        Props.
 * @param props.steps  Steps.
 * @param props.active Current step.
 */
function Progress( {
	steps,
	active,
}: {
	steps: SetupStep[];
	active: SetupStep;
} ) {
	const labels = stepLabels();
	const current = steps.indexOf( active );
	return (
		<ol className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2 chatlume:text-[13px]">
			{ steps.map( ( step, index ) => (
				<li
					key={ step }
					aria-current={ step === active ? 'step' : undefined }
					className={ cn(
						'chatlume:flex chatlume:items-center chatlume:gap-1.5 chatlume:rounded-full chatlume:px-3 chatlume:py-1',
						index < current && 'chatlume:text-success',
						step === active
							? 'chatlume:bg-accent-soft chatlume:font-medium chatlume:text-accent'
							: 'chatlume:text-fg-muted'
					) }
				>
					{ index < current ? (
						<Check
							aria-hidden="true"
							className="chatlume:size-3.5"
						/>
					) : (
						<span aria-hidden="true">{ index + 1 }.</span>
					) }
					{ labels[ step ] }
				</li>
			) ) }
		</ol>
	);
}

/**
 * Step 1: choose a provider.
 *
 * @param props           Props.
 * @param props.providers Providers.
 * @param props.onChoose  Chooses one.
 * @param props.onSkip    Skips the AI.
 */
function ProviderStep( {
	providers,
	onChoose,
	onSkip,
}: {
	providers: ProviderSummary[];
	onChoose: ( id: string ) => void;
	onSkip: () => void;
} ) {
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div>
				<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
					{ __(
						'Which AI should answer your visitors?',
						'chatlume-ai-chatbot'
					) }
				</h2>
				<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
					{ __(
						'Pick a provider. You can change it any time, and add a fallback later.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</div>
			<ul className="chatlume:grid chatlume:gap-3 chatlume:sm:grid-cols-2">
				{ orderProviders( providers ).map( ( provider ) => {
					const free = freeTier( provider.id );
					return (
						<li key={ provider.id }>
							<button
								type="button"
								className="chatlume:flex chatlume:size-full chatlume:items-start chatlume:gap-3 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-3 chatlume:text-start chatlume:transition-colors chatlume:hover:border-accent chatlume:hover:bg-accent-soft chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
								onClick={ () => onChoose( provider.id ) }
							>
								<ProviderAvatar
									initials={ provider.initials }
								/>
								<span className="chatlume:min-w-0">
									<span className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-1.5 chatlume:text-sm chatlume:font-medium chatlume:text-fg">
										{ provider.label }
										{ provider.kind === 'core' && (
											<Badge tone="accent">
												{ __(
													'Connected in WordPress',
													'chatlume-ai-chatbot'
												) }
											</Badge>
										) }
										{ provider.state === 'ready' && (
											<Badge tone="success">
												{ __(
													'Connected',
													'chatlume-ai-chatbot'
												) }
											</Badge>
										) }
										{ free && (
											<Badge>
												{ __(
													'Free option',
													'chatlume-ai-chatbot'
												) }
											</Badge>
										) }
									</span>
									<span className="chatlume:mt-0.5 chatlume:block chatlume:text-xs chatlume:text-fg-muted">
										{ provider.description }
									</span>
								</span>
							</button>
						</li>
					);
				} ) }
			</ul>
			<div className="chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-4">
				<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
					{ __(
						'Not ready to connect an AI?',
						'chatlume-ai-chatbot'
					) }
				</p>
				<p className="chatlume:mt-1 chatlume:text-[13px] chatlume:text-fg-muted">
					{ __(
						'Your bot can still answer from your quick answers and suggest matching pages from your site. Connect an AI whenever you like.',
						'chatlume-ai-chatbot'
					) }
				</p>
				<Button
					type="button"
					size="sm"
					className="chatlume:mt-3"
					onClick={ onSkip }
				>
					{ __( 'Skip AI for now', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Step 2: how to get a key.
 *
 * @param props          Props.
 * @param props.provider Provider.
 */
function GuideStep( { provider }: { provider: ProviderSummary } ) {
	const free = freeTier( provider.id );
	const keyNeeded = provider.key_required;
	const steps: Array< { text: string; link?: string; label?: string } > =
		keyNeeded
			? [
					{
						text: sprintf(
							/* translators: %s: Provider name. */
							__(
								'Sign in to %s, or create a free account.',
								'chatlume-ai-chatbot'
							),
							provider.label
						),
						link: provider.links.keys,
						label: __(
							'Open the API keys page',
							'chatlume-ai-chatbot'
						),
					},
					{
						text: free
							? free
							: __(
									'Add a payment method or credits. AI providers charge per use; a typical answer costs a fraction of a cent.',
									'chatlume-ai-chatbot'
								),
						link: free ? undefined : provider.links.billing,
						label: __( 'Open billing', 'chatlume-ai-chatbot' ),
					},
					{
						text: __(
							'Create a new secret key, give it a name such as “My website”, and copy it. You only see it once.',
							'chatlume-ai-chatbot'
						),
					},
				]
			: [
					{
						text: __(
							'Start the server that runs your models, and note its address (for Ollama on the same computer: http://localhost:11434).',
							'chatlume-ai-chatbot'
						),
						link: provider.links.docs,
						label: __(
							'Read the setup guide',
							'chatlume-ai-chatbot'
						),
					},
					{
						text: __(
							'Make sure your website can reach that address. A server on your own laptop is not reachable from a hosted website.',
							'chatlume-ai-chatbot'
						),
					},
				];

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div>
				<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
					{ sprintf(
						/* translators: %s: Provider name. */
						__( 'Get ready to connect %s', 'chatlume-ai-chatbot' ),
						provider.label
					) }
				</h2>
				<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
					{ __(
						'This takes a couple of minutes. Keep this page open; links open in a new tab.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</div>
			<ol className="chatlume:flex chatlume:flex-col chatlume:gap-3">
				{ steps.map( ( step, index ) => (
					<li key={ index } className="chatlume:flex chatlume:gap-3">
						<span
							aria-hidden="true"
							className="chatlume:flex chatlume:size-7 chatlume:shrink-0 chatlume:items-center chatlume:justify-center chatlume:rounded-full chatlume:bg-accent-soft chatlume:text-sm chatlume:font-semibold chatlume:text-accent"
						>
							{ index + 1 }
						</span>
						<div className="chatlume:pt-0.5">
							<p className="chatlume:text-sm chatlume:text-fg">
								{ step.text }
							</p>
							{ step.link && (
								<a
									href={ step.link }
									target="_blank"
									rel="noopener noreferrer"
									className="chatlume:mt-1 chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:text-sm chatlume:font-medium chatlume:text-accent chatlume:hover:underline"
								>
									{ step.label }
									<ExternalLink
										aria-hidden="true"
										className="chatlume:size-3.5"
									/>
									<span className="chatlume:sr-only">
										{ __(
											'(opens in a new tab)',
											'chatlume-ai-chatbot'
										) }
									</span>
								</a>
							) }
						</div>
					</li>
				) ) }
			</ol>
			<p className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:text-xs chatlume:text-fg-muted">
				<KeyRound
					aria-hidden="true"
					className="chatlume:mt-0.5 chatlume:size-3.5 chatlume:shrink-0"
				/>
				{ __(
					'Your key is stored encrypted on your site and is never shown to visitors.',
					'chatlume-ai-chatbot'
				) }
			</p>
		</div>
	);
}

/**
 * Step 3: paste the key (or address) and test.
 *
 * @param props             Props.
 * @param props.provider    Provider.
 * @param props.settings    Settings.
 * @param props.onConnected Called after a successful test.
 */
function KeyStep( {
	provider,
	settings,
	onConnected,
}: {
	provider: ProviderSummary;
	settings: Settings;
	onConnected: () => void;
} ) {
	const ids = { key: useId(), url: useId() };
	const { save } = useApiKey();
	const test = useTestProvider();
	const update = useUpdateSettings();
	const configs = settings.providers as unknown as {
		configs?: Record< string, { base_url?: string } >;
	};
	const savedUrl = configs.configs?.[ provider.id ]?.base_url ?? '';
	const [ apiKey, setApiKey ] = useState( '' );
	const [ baseUrl, setBaseUrl ] = useState( savedUrl );
	const [ result, setResult ] = useState< TestResult | null >( null );
	const needsUrl =
		provider.fields.includes( 'base_url' ) && provider.base_url_editable;
	const hasSavedKey = provider.key.source !== 'none';
	const busy = save.isPending || test.isPending || update.isPending;

	const connect = async () => {
		setResult( null );
		try {
			if ( needsUrl && baseUrl.trim() !== savedUrl ) {
				await update.mutateAsync( {
					providers: {
						configs: {
							[ provider.id ]: { base_url: baseUrl.trim() },
						},
					},
				} as unknown as SettingsUpdate );
			}
			if ( apiKey.trim() ) {
				await save.mutateAsync( {
					id: provider.id,
					apiKey: apiKey.trim(),
				} );
			}
			const outcome = await test.mutateAsync( { id: provider.id } );
			setResult( outcome );
			if ( outcome.ok ) {
				setApiKey( '' );
			}
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	const ready = !! result?.ok;
	const canTest =
		! busy &&
		( ! provider.key_required || apiKey.trim() !== '' || hasSavedKey ) &&
		( ! needsUrl ||
			baseUrl.trim() !== '' ||
			provider.default_base_url !== '' );

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div>
				<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
					{ sprintf(
						/* translators: %s: Provider name. */
						__( 'Connect %s', 'chatlume-ai-chatbot' ),
						provider.label
					) }
				</h2>
				<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
					{ __(
						'Chatlume sends one short test message to check that everything works.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</div>
			<form
				className="chatlume:flex chatlume:flex-col chatlume:gap-4"
				onSubmit={ ( event ) => {
					event.preventDefault();
					if ( canTest ) {
						connect();
					}
				} }
			>
				{ needsUrl && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
						<label
							htmlFor={ ids.url }
							className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
						>
							{ __( 'Server address', 'chatlume-ai-chatbot' ) }
						</label>
						<Input
							id={ ids.url }
							type="url"
							value={ baseUrl }
							placeholder={
								provider.default_base_url || 'https://'
							}
							onChange={ ( event ) =>
								setBaseUrl( event.target.value )
							}
						/>
					</div>
				) }
				{ ( provider.key_required ||
					provider.fields.includes( 'api_key' ) ) && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
						<label
							htmlFor={ ids.key }
							className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
						>
							{ provider.key_required
								? __( 'API key', 'chatlume-ai-chatbot' )
								: __(
										'API key (optional)',
										'chatlume-ai-chatbot'
									) }
						</label>
						<Input
							id={ ids.key }
							type="password"
							autoComplete="off"
							spellCheck={ false }
							value={ apiKey }
							placeholder={
								hasSavedKey
									? sprintf(
											/* translators: %s: Last characters of the saved key. */
											__(
												'Saved key %s (paste a new one to replace it)',
												'chatlume-ai-chatbot'
											),
											provider.key.masked
										)
									: ''
							}
							onChange={ ( event ) =>
								setApiKey( event.target.value )
							}
						/>
					</div>
				) }
				<div>
					<Button
						type="submit"
						variant="primary"
						disabled={ ! canTest }
					>
						{ busy && (
							<Loader2
								aria-hidden="true"
								className="chatlume:animate-spin"
							/>
						) }
						{ __( 'Test and save', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
			</form>

			{ result && (
				<div
					role="status"
					className={ cn(
						'chatlume:flex chatlume:items-start chatlume:gap-3 chatlume:rounded-control chatlume:p-4 chatlume:text-sm',
						ready
							? 'chatlume:bg-success-soft chatlume:text-success'
							: 'chatlume:bg-danger-soft chatlume:text-danger'
					) }
				>
					{ ready ? (
						<CircleCheck
							aria-hidden="true"
							className="chatlume:size-5 chatlume:shrink-0"
						/>
					) : (
						<TriangleAlert
							aria-hidden="true"
							className="chatlume:size-5 chatlume:shrink-0"
						/>
					) }
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-1">
						<p className="chatlume:font-medium">
							{ ready
								? sprintf(
										/* translators: %d: Milliseconds. */
										__(
											'Connected. The test answer took %d ms.',
											'chatlume-ai-chatbot'
										),
										result.latency_ms
									)
								: ( result.error?.title ?? result.message ) }
						</p>
						{ ! ready && result.error?.explanation && (
							<p className="chatlume:text-fg">
								{ result.error.explanation }
							</p>
						) }
						{ ! ready && result.error?.fix && (
							<a
								href={ result.error.fix.url }
								target="_blank"
								rel="noopener noreferrer"
								className="chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:font-medium chatlume:underline"
							>
								{ result.error.fix.label }
								<ExternalLink
									aria-hidden="true"
									className="chatlume:size-3.5"
								/>
							</a>
						) }
					</div>
				</div>
			) }

			{ ready && (
				<div>
					<Button
						type="button"
						variant="primary"
						onClick={ onConnected }
					>
						{ __( 'Continue', 'chatlume-ai-chatbot' ) }
						<ArrowRight aria-hidden="true" />
					</Button>
				</div>
			) }
		</div>
	);
}

/**
 * Step 4: choose the model.
 *
 * @param props          Props.
 * @param props.provider Provider.
 * @param props.value    Model.
 * @param props.onChange Change handler.
 */
function ModelStep( {
	provider,
	value,
	onChange,
}: {
	provider: ProviderSummary;
	value: string;
	onChange: ( model: string ) => void;
} ) {
	const id = useId();
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div>
				<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
					{ __( 'Choose a model', 'chatlume-ai-chatbot' ) }
				</h2>
				<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
					{ __(
						'We picked a recommended one. Recommended and free models are marked in the list.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</div>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
				<label
					htmlFor={ id }
					className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
				>
					{ __( 'Model', 'chatlume-ai-chatbot' ) }
				</label>
				<ModelPicker
					id={ id }
					providerId={ provider.id }
					kind="chat"
					value={ value }
					onChange={ onChange }
				/>
			</div>
		</div>
	);
}

/**
 * Step 5: brand color and position.
 *
 * @param props          Props.
 * @param props.theme    Theme.
 * @param props.onChange Change handler.
 */
function LookStep( {
	theme,
	onChange,
}: {
	theme: Theme;
	onChange: ( theme: Theme ) => void;
} ) {
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div>
				<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
					{ __( 'Make it yours', 'chatlume-ai-chatbot' ) }
				</h2>
				<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
					{ __(
						'Pick your brand color, or let Chatlume match your website. Fine-tune everything later in Customize.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</div>
			<div>
				<MatchSite theme={ theme } onApply={ onChange } />
			</div>
			<BrandColor theme={ theme } replace={ onChange } />
			<Segmented
				label={ __( 'Position', 'chatlume-ai-chatbot' ) }
				value={ theme.layout.position }
				onChange={ ( position ) =>
					onChange( {
						...theme,
						layout: { ...theme.layout, position },
					} )
				}
				options={ [
					{
						id: 'left',
						label: __( 'Bottom left', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'right',
						label: __( 'Bottom right', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
		</div>
	);
}

/**
 * Step 6: done.
 *
 * @param props        Props.
 * @param props.skipAi Whether the AI was skipped.
 */
function DoneStep( { skipAi }: { skipAi: boolean } ) {
	const links: Array< { to: string; label: string; description: string } > = [
		{
			to: '/playground',
			label: __( 'Test your bot', 'chatlume-ai-chatbot' ),
			description: __(
				'Ask a few questions and see how it answers.',
				'chatlume-ai-chatbot'
			),
		},
		{
			to: '/chatbot',
			label: __( 'Give it a personality', 'chatlume-ai-chatbot' ),
			description: __(
				'Name, welcome message, starter questions, and instructions.',
				'chatlume-ai-chatbot'
			),
		},
		{
			to: '/customize',
			label: __( 'Customize the look', 'chatlume-ai-chatbot' ),
			description: __(
				'Colors, fonts, launcher, and more, previewed on your site.',
				'chatlume-ai-chatbot'
			),
		},
	];
	if ( skipAi ) {
		links.unshift( {
			to: '/providers',
			label: __( 'Connect an AI later', 'chatlume-ai-chatbot' ),
			description: __(
				'Until then, your bot answers from quick answers and site search.',
				'chatlume-ai-chatbot'
			),
		} );
	}

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
			<div className="chatlume:flex chatlume:items-start chatlume:gap-3">
				<CircleCheck
					aria-hidden="true"
					className="chatlume:size-8 chatlume:shrink-0 chatlume:text-success"
				/>
				<div>
					<h2 className="chatlume:text-lg chatlume:font-semibold chatlume:text-fg">
						{ __( 'Your chatbot is live!', 'chatlume-ai-chatbot' ) }
					</h2>
					<p className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
						{ skipAi
							? __(
									'Visitors see the chat button on your site now. It answers from your quick answers and suggests matching pages.',
									'chatlume-ai-chatbot'
								)
							: __(
									'Visitors see the chat button on your site now, and your AI answers their questions.',
									'chatlume-ai-chatbot'
								) }
					</p>
				</div>
			</div>
			<ul className="chatlume:grid chatlume:gap-3 chatlume:sm:grid-cols-2">
				{ links.map( ( link ) => (
					<li key={ link.to }>
						<Link
							to={ link.to }
							className="chatlume:flex chatlume:size-full chatlume:flex-col chatlume:gap-0.5 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-3 chatlume:hover:border-accent chatlume:hover:bg-accent-soft chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
						>
							<span className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
								{ link.label }
							</span>
							<span className="chatlume:text-xs chatlume:text-fg-muted">
								{ link.description }
							</span>
						</Link>
					</li>
				) ) }
			</ul>
			<div>
				<Link
					to="/"
					className="chatlume:text-sm chatlume:font-medium chatlume:text-accent chatlume:hover:underline"
				>
					{ __( 'Go to the Dashboard', 'chatlume-ai-chatbot' ) }
				</Link>
			</div>
		</div>
	);
}

function Wizard( {
	settings,
	appearance,
	info,
}: {
	settings: Settings;
	appearance: AppearanceState;
	info: ChatbotInfo;
} ) {
	const navigate = useNavigate();
	const queryClient = useQueryClient();
	const providers = useProviders();
	const update = useUpdateSettings();
	const [ step, setStep ] = useState< SetupStep >( 'provider' );
	const [ skipAi, setSkipAi ] = useState( false );
	const [ providerId, setProviderId ] = useState( '' );
	const [ model, setModel ] = useState( '' );
	const [ theme, setTheme ] = useState< Theme >( appearance.draft );
	const [ finishing, setFinishing ] = useState( false );

	const provider = providers.data?.providers.find(
		( item ) => item.id === providerId
	);
	const steps = wizardSteps( skipAi );

	// A recommended model to start with.
	useEffect( () => {
		if ( step !== 'model' || ! providerId || model ) {
			return;
		}
		fetchModels( providerId, 'chat' )
			.then( ( result ) => setModel( pickModel( result.models ) ) )
			.catch( () => undefined );
	}, [ step, providerId, model ] );

	const config = useMemo(
		() => resolveTheme( theme, appearance.fonts.options ),
		[ theme, appearance.fonts.options ]
	);
	const behavior = useMemo(
		() => previewBehavior( settings.chatbot, info.defaults ),
		[ settings.chatbot, info.defaults ]
	);
	const samples = useMemo(
		() => previewSamples( true, getBootstrap().siteUrl ),
		[]
	);

	const go = ( next: SetupStep ) => {
		setStep( next );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	};

	const back = () => {
		const index = steps.indexOf( step );
		if ( index > 0 ) {
			go( steps[ index - 1 ] );
		}
	};

	const finish = async () => {
		setFinishing( true );
		try {
			const changes: SettingsUpdate = { setup: { completed: true } };
			if ( ! skipAi && providerId && model ) {
				changes.providers = {
					primary: { provider: providerId, model },
				};
			}
			await update.mutateAsync( changes );
			await saveDraft( theme );
			const published = await publishDraft();
			queryClient.setQueryData( [ 'chatlume', 'appearance' ], published );
			go( 'done' );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setFinishing( false );
		}
	};

	const skipSetup = async () => {
		try {
			await update.mutateAsync( { setup: { completed: true } } );
			navigate( '/' );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		}
	};

	if ( ! providers.data ) {
		return <PageSkeleton />;
	}

	let body = null;
	let next: { label: string; action: () => void; disabled?: boolean } | null =
		null;
	switch ( step ) {
		case 'provider':
			body = (
				<ProviderStep
					providers={ providers.data.providers }
					onChoose={ ( id ) => {
						setProviderId( id );
						setSkipAi( false );
						setModel( '' );
						go( 'guide' );
					} }
					onSkip={ () => {
						setSkipAi( true );
						go( 'look' );
					} }
				/>
			);
			break;
		case 'guide':
			body = provider && <GuideStep provider={ provider } />;
			next = {
				label: __( 'I have my key', 'chatlume-ai-chatbot' ),
				action: () => go( 'key' ),
			};
			if ( provider && ! provider.key_required ) {
				next.label = __( 'Continue', 'chatlume-ai-chatbot' );
			}
			break;
		case 'key':
			body = provider && (
				<KeyStep
					provider={ provider }
					settings={ settings }
					onConnected={ () => go( 'model' ) }
				/>
			);
			break;
		case 'model':
			body = provider && (
				<ModelStep
					provider={ provider }
					value={ model }
					onChange={ setModel }
				/>
			);
			next = {
				label: __( 'Continue', 'chatlume-ai-chatbot' ),
				action: () => go( 'look' ),
				disabled: ! model,
			};
			break;
		case 'look':
			body = <LookStep theme={ theme } onChange={ setTheme } />;
			next = {
				label: __( 'Finish setup', 'chatlume-ai-chatbot' ),
				action: finish,
				disabled: finishing,
			};
			break;
		default:
			body = <DoneStep skipAi={ skipAi } />;
	}

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
			<div className="chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:justify-between chatlume:gap-3">
				<div>
					<h1 className="chatlume:text-2xl chatlume:font-semibold chatlume:tracking-tight chatlume:text-fg">
						{ __( 'Set up your chatbot', 'chatlume-ai-chatbot' ) }
					</h1>
					<p className="chatlume:mt-1.5 chatlume:text-[15px] chatlume:text-fg-muted">
						{ __(
							'A few quick steps, then your bot is live on your site.',
							'chatlume-ai-chatbot'
						) }
					</p>
				</div>
				{ step !== 'done' && (
					<Button
						type="button"
						size="sm"
						variant="ghost"
						onClick={ skipSetup }
					>
						{ __( 'Skip setup', 'chatlume-ai-chatbot' ) }
					</Button>
				) }
			</div>
			<Progress steps={ steps } active={ step } />
			<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:lg:grid-cols-[minmax(0,1fr)_minmax(0,440px)]">
				<Card className="chatlume:flex chatlume:flex-col chatlume:gap-6 chatlume:p-6">
					{ body }
					{ step !== 'provider' && step !== 'done' && (
						<div className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:border-t chatlume:border-border chatlume:pt-4">
							<Button
								type="button"
								variant="ghost"
								onClick={ back }
							>
								<ArrowLeft aria-hidden="true" />
								{ __( 'Back', 'chatlume-ai-chatbot' ) }
							</Button>
							{ next && (
								<Button
									type="button"
									variant="primary"
									disabled={ next.disabled }
									onClick={ next.action }
								>
									{ next.label }
									<ArrowRight aria-hidden="true" />
								</Button>
							) }
						</div>
					) }
				</Card>
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-2 chatlume:lg:sticky chatlume:lg:top-12">
					<p className="chatlume:text-xs chatlume:font-medium chatlume:text-fg-muted">
						{ __( 'Live preview', 'chatlume-ai-chatbot' ) }
					</p>
					<WidgetPreview
						behavior={ behavior }
						samples={ samples }
						state="conversation"
						theme={ config }
						className="chatlume:h-[600px] chatlume:max-h-[calc(100vh-14rem)]"
					/>
				</div>
			</div>
		</div>
	);
}

/**
 * The setup wizard page.
 */
export default function SetupRoute() {
	const settings = useSettings();
	const appearance = useQuery< AppearanceState, ApiError >( {
		queryKey: [ 'chatlume', 'appearance' ],
		queryFn: fetchAppearance,
	} );
	const info = useQuery< ChatbotInfo, ApiError >( {
		queryKey: [ 'chatlume', 'chatbot-info' ],
		queryFn: fetchChatbotInfo,
	} );

	const error = settings.error ?? appearance.error ?? info.error;
	if ( error ) {
		return (
			<div
				role="alert"
				className="chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
			>
				{ error.message }
			</div>
		);
	}
	if ( ! settings.data || ! appearance.data || ! info.data ) {
		return <PageSkeleton />;
	}

	return (
		<Wizard
			settings={ settings.data }
			appearance={ appearance.data }
			info={ info.data }
		/>
	);
}
