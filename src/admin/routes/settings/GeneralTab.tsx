/**
 * Settings > General: limits, streaming, cache, privacy, troubleshooting,
 * and import/export.
 */
import { __ } from '@wordpress/i18n';
import { Eraser, TriangleAlert } from 'lucide-react';
import { useEffect, useId } from 'react';
import {
	useForm,
	type FieldError,
	type FieldErrors,
	type Resolver,
} from 'react-hook-form';
import { toast } from 'sonner';

import {
	FormSection,
	NumberField,
	SwitchField,
} from '../../components/form/fields';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { Button } from '../../components/ui/button';
import { Card, CardContent } from '../../components/ui/card';
import { Field } from '../../components/ui/field';
import { inputClasses } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { Skeleton } from '../../components/ui/skeleton';
import { useClearCache } from '../../hooks/useHealth';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import {
	generalSettingsFormSchema,
	type GeneralSettingsForm,
	type Settings,
} from '../../lib/settingsSchema';
import { cn } from '../../lib/utils';
import { ImportExport } from './ImportExport';

type FormValues = Omit< GeneralSettingsForm, 'security' > & {
	security: Omit< GeneralSettingsForm[ 'security' ], 'trusted_proxy_ips' > & {
		/** One address or range per line (stored as a list). */
		trusted_proxy_ips: string;
	};
};

/**
 * Form values from stored settings.
 *
 * @param settings Settings.
 * @return Form values.
 */
function toForm( settings: Settings ): FormValues {
	return {
		security: {
			...settings.security,
			trusted_proxy_ips: settings.security.trusted_proxy_ips.join( '\n' ),
		},
		streaming: settings.streaming,
		cache: settings.cache,
		privacy: settings.privacy,
		debug: settings.debug,
	};
}

/**
 * Settings update from form values.
 *
 * @param values Form values.
 * @return Update.
 */
function fromForm( values: FormValues ): GeneralSettingsForm {
	return {
		...values,
		security: {
			...values.security,
			trusted_proxy_ips: values.security.trusted_proxy_ips
				.split( /[\s,]+/ )
				.map( ( line ) => line.trim() )
				.filter( Boolean ),
		},
	};
}

/**
 * Validates the form in its stored shape. Problems with any proxy address
 * are shown on the address list as a whole.
 *
 * @param values Form values.
 * @return Resolver result.
 */
const resolver: Resolver< FormValues > = async ( values ) => {
	const result = generalSettingsFormSchema.safeParse( fromForm( values ) );
	if ( result.success ) {
		return { values, errors: {} };
	}
	const errors: Record< string, Record< string, FieldError > > = {};
	for ( const issue of result.error.issues ) {
		const [ section, field ] = issue.path.map( String );
		if ( section && field && ! errors[ section ]?.[ field ] ) {
			errors[ section ] = {
				...errors[ section ],
				[ field ]: { type: issue.code, message: issue.message },
			};
		}
	}
	return { values: {}, errors: errors as FieldErrors< FormValues > };
};

/**
 * Translated message for a validation problem.
 *
 * @param message Zod message.
 * @return Message.
 */
function problem( message: string | undefined ): string | undefined {
	if ( ! message ) {
		return undefined;
	}
	return message === 'ip'
		? __(
				'Enter one IP address or range per line, for example 203.0.113.0/24.',
				'chatlume-ai-chatbot'
			)
		: __( 'Please check this value.', 'chatlume-ai-chatbot' );
}

/**
 * The General tab.
 */
export function GeneralTab() {
	const settings = useSettings();
	const update = useUpdateSettings();
	const clearCache = useClearCache();
	const proxyId = useId();
	const ipsId = useId();

	const form = useForm< FormValues >( { resolver } );
	const {
		register,
		control,
		formState: { isDirty, errors },
	} = form;

	// Load server values into the form once they arrive.
	const loaded = settings.data;
	useEffect( () => {
		if ( loaded ) {
			form.reset( toForm( loaded ) );
		}
		// Reset only when fresh server data arrives.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ loaded ] );

	useUnsavedChangesWarning( isDirty );

	const save = form.handleSubmit( async ( values ) => {
		try {
			const saved = await update.mutateAsync( fromForm( values ) );
			form.reset( toForm( saved ) );
			toast.success( __( 'Settings saved.', 'chatlume-ai-chatbot' ) );
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

	if ( settings.isPending ) {
		return (
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
				{ [ 0, 1, 2 ].map( ( index ) => (
					<Card key={ index }>
						<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-3">
							<Skeleton className="chatlume:h-5 chatlume:w-48" />
							<Skeleton className="chatlume:h-3 chatlume:w-96 chatlume:max-w-full" />
							<Skeleton className="chatlume:h-9 chatlume:w-28" />
						</CardContent>
					</Card>
				) ) }
			</div>
		);
	}

	if ( settings.isError ) {
		return (
			<div
				role="alert"
				className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
			>
				<span>{ settings.error.message }</span>
				<Button size="sm" onClick={ () => settings.refetch() }>
					{ __( 'Try again', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		);
	}

	const proxy = form.watch( 'security.trusted_proxy' );
	const deleteOnUninstall = form.watch( 'privacy.delete_data_on_uninstall' );
	const storeConversations = form.watch( 'privacy.store_conversations' );

	return (
		<>
			<form
				className="chatlume:flex chatlume:flex-col chatlume:gap-6"
				noValidate
				onSubmit={ ( event ) => {
					event.preventDefault();
					save();
				} }
			>
				<FormSection
					title={ __(
						'Rate limits and security',
						'chatlume-ai-chatbot'
					) }
					description={ __(
						'Protect your AI budget from abuse. Limits apply per visitor session and per visitor IP address (stored only as a salted hash).',
						'chatlume-ai-chatbot'
					) }
				>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-3">
						<NumberField
							register={ register }
							name="security.rate_limit_messages"
							label={ __(
								'Messages allowed',
								'chatlume-ai-chatbot'
							) }
							min={ 1 }
							max={ 1000 }
							error={ problem(
								errors.security?.rate_limit_messages?.message
							) }
						/>
						<NumberField
							register={ register }
							name="security.rate_limit_minutes"
							label={ __(
								'Per this many minutes',
								'chatlume-ai-chatbot'
							) }
							min={ 1 }
							max={ 1440 }
							suffix={ __( 'minutes', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.security?.rate_limit_minutes?.message
							) }
						/>
						<NumberField
							register={ register }
							name="security.session_hours"
							label={ __(
								'Chat session length',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'How long a visitor keeps the same chat session (and its conversation) before a new one starts.',
								'chatlume-ai-chatbot'
							) }
							min={ 1 }
							max={ 720 }
							suffix={ __( 'hours', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.security?.session_hours?.message
							) }
						/>
					</div>

					<Field
						id={ proxyId }
						label={ __( 'Trusted proxy', 'chatlume-ai-chatbot' ) }
						tooltip={ __(
							'Only change this if your site is behind Cloudflare or a load balancer. Otherwise every visitor could look like the same address, or visitors could fake their address.',
							'chatlume-ai-chatbot'
						) }
						hint={ __(
							'Where visitor IP addresses come from, for rate limits.',
							'chatlume-ai-chatbot'
						) }
					>
						<div className="chatlume:sm:max-w-sm">
							<Select
								id={ proxyId }
								aria-describedby={ `${ proxyId }-hint` }
								{ ...register( 'security.trusted_proxy' ) }
							>
								<option value="none">
									{ __(
										'None (direct connection)',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="cloudflare">
									{ __(
										'Cloudflare',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="custom">
									{ __(
										'My own proxy or load balancer',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</div>
					</Field>

					{ proxy === 'custom' && (
						<Field
							id={ ipsId }
							label={ __(
								'Proxy IP addresses',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'One address or range per line. Chatlume reads the X-Forwarded-For header only from these addresses.',
								'chatlume-ai-chatbot'
							) }
							error={ problem(
								errors.security?.trusted_proxy_ips?.message
							) }
						>
							<textarea
								id={ ipsId }
								rows={ 4 }
								spellCheck={ false }
								className={ cn(
									inputClasses,
									'chatlume:h-auto chatlume:py-2 chatlume:font-mono chatlume:sm:max-w-sm'
								) }
								aria-describedby={ `${ ipsId }-hint ${ ipsId }-error` }
								placeholder="203.0.113.0/24"
								{ ...register( 'security.trusted_proxy_ips' ) }
							/>
						</Field>
					) }
				</FormSection>

				<FormSection
					title={ __( 'Streaming', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Replies appear word by word while the AI writes them.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="streaming.enabled"
						label={ __( 'Stream replies', 'chatlume-ai-chatbot' ) }
						description={ __(
							'Turn off if your server cannot stream (the Health check tests this). Visitors then receive each reply in one piece.',
							'chatlume-ai-chatbot'
						) }
					/>
					<div className="chatlume:grid chatlume:gap-5 chatlume:sm:grid-cols-3">
						<NumberField
							register={ register }
							name="streaming.max_concurrent"
							label={ __(
								'Streams at once',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'Each streaming reply keeps one PHP worker busy. On small hosts keep this low; extra visitors get their reply in one piece instead.',
								'chatlume-ai-chatbot'
							) }
							min={ 1 }
							max={ 20 }
							error={ problem(
								errors.streaming?.max_concurrent?.message
							) }
						/>
						<NumberField
							register={ register }
							name="streaming.max_duration"
							label={ __(
								'Longest reply time',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'A reply stops after this time. It always stays below your server’s own time limit.',
								'chatlume-ai-chatbot'
							) }
							min={ 10 }
							max={ 300 }
							suffix={ __( 'seconds', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.streaming?.max_duration?.message
							) }
						/>
						<NumberField
							register={ register }
							name="streaming.first_token_timeout"
							label={ __(
								'Wait for first words',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'If the AI has not started answering after this time, the visitor sees a friendly message with a Retry button.',
								'chatlume-ai-chatbot'
							) }
							min={ 5 }
							max={ 120 }
							suffix={ __( 'seconds', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.streaming?.first_token_timeout?.message
							) }
						/>
					</div>
				</FormSection>

				<FormSection
					title={ __( 'Response cache', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Reuse recent AI answers to identical first questions from logged-out visitors. Saves money and answers instantly.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="cache.enabled"
						label={ __( 'Cache answers', 'chatlume-ai-chatbot' ) }
						description={ __(
							'Never used for logged-in visitors or personalized prompts.',
							'chatlume-ai-chatbot'
						) }
					/>
					<div className="chatlume:flex chatlume:flex-wrap chatlume:items-end chatlume:justify-between chatlume:gap-4">
						<NumberField
							register={ register }
							name="cache.ttl_hours"
							label={ __(
								'Keep answers for',
								'chatlume-ai-chatbot'
							) }
							min={ 1 }
							max={ 720 }
							suffix={ __( 'hours', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.cache?.ttl_hours?.message
							) }
						/>
						<Button
							type="button"
							disabled={ clearCache.isPending }
							onClick={ () =>
								clearCache.mutate( undefined, {
									onSuccess: () =>
										toast.success(
											__(
												'Cached answers cleared. New questions get fresh AI answers.',
												'chatlume-ai-chatbot'
											)
										),
									onError: ( error ) =>
										toast.error( error.message ),
								} )
							}
						>
							<Eraser aria-hidden="true" />
							{ __(
								'Clear cached answers',
								'chatlume-ai-chatbot'
							) }
						</Button>
					</div>
				</FormSection>

				<FormSection
					title={ __(
						'Conversations and privacy',
						'chatlume-ai-chatbot'
					) }
					description={ __(
						'Control what Chatlume keeps and for how long.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="privacy.store_conversations"
						label={ __(
							'Save conversations',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'Needed for conversation logs, statistics, and feedback. When off, a conversation is kept only for 30 minutes of inactivity and nothing is written to the database.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ storeConversations && (
						<NumberField
							register={ register }
							name="privacy.retention_days"
							label={ __(
								'Delete conversations after',
								'chatlume-ai-chatbot'
							) }
							hint={ __(
								'Use 0 to keep them until you delete them.',
								'chatlume-ai-chatbot'
							) }
							min={ 0 }
							max={ 3650 }
							suffix={ __( 'days', 'chatlume-ai-chatbot' ) }
							error={ problem(
								errors.privacy?.retention_days?.message
							) }
						/>
					) }
					<SwitchField
						control={ control }
						name="privacy.delete_data_on_uninstall"
						label={ __(
							'Delete all data on uninstall',
							'chatlume-ai-chatbot'
						) }
						description={ __(
							'When you delete the plugin from the Plugins screen, also remove its database tables, settings, and saved conversations. Deactivating never removes data.',
							'chatlume-ai-chatbot'
						) }
					/>
					{ deleteOnUninstall && (
						<p className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:rounded-control chatlume:bg-warning-soft chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px] chatlume:text-warning">
							<TriangleAlert
								aria-hidden="true"
								className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
							/>
							{ __(
								'Data removed on uninstall cannot be recovered. Export anything you want to keep first.',
								'chatlume-ai-chatbot'
							) }
						</p>
					) }
				</FormSection>

				<FormSection
					title={ __( 'Troubleshooting', 'chatlume-ai-chatbot' ) }
					description={ __(
						'Tools for finding out why the bot behaves as it does.',
						'chatlume-ai-chatbot'
					) }
				>
					<SwitchField
						control={ control }
						name="debug.enabled"
						label={ __( 'Debug logging', 'chatlume-ai-chatbot' ) }
						description={ __(
							'Record a summary of each chat event (reply source, AI model, tokens, timing, errors) on the Logs tab. Message text and visitor details are never recorded. Keeps the latest 100 events.',
							'chatlume-ai-chatbot'
						) }
					/>
				</FormSection>
			</form>

			<div className="chatlume:mt-6">
				<ImportExport disabled={ isDirty } />
			</div>

			<StickySaveBar
				visible={ isDirty }
				saving={ update.isPending }
				onSave={ () => save() }
				onDiscard={ () => loaded && form.reset( toForm( loaded ) ) }
			/>
			{ isDirty && <UnsavedChangesGuard /> }
		</>
	);
}
