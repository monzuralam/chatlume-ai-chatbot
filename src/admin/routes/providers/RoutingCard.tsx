import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useId } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { toast } from 'sonner';

import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { ModelPicker } from '../../components/providers/ModelPicker';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { Field } from '../../components/ui/field';
import { Input } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ProviderSummary } from '../../lib/providers';
import { validateRouting, type RoutingForm } from '../../lib/routing';

interface RoutingCardProps {
	providers: ProviderSummary[];
}

/**
 * Primary and fallback chat providers and the embeddings provider.
 *
 * @param props           Props.
 * @param props.providers Providers.
 */
export function RoutingCard( { providers }: RoutingCardProps ) {
	const settings = useSettings();
	const update = useUpdateSettings();
	const ids = {
		primary: useId(),
		primaryModel: useId(),
		fallback: useId(),
		fallbackModel: useId(),
		embeddings: useId(),
		embeddingsModel: useId(),
		dimensions: useId(),
	};

	const form = useForm< RoutingForm >( {
		defaultValues: {
			primary: { provider: '', model: '' },
			fallback: { provider: '', model: '' },
			embeddings: { provider: '', model: '', dimensions: 512 },
		},
		resolver: async ( values ) => {
			const messages = validateRouting( values, providers );
			const errors: Record< string, unknown > = {};
			for ( const [ path, message ] of Object.entries( messages ) ) {
				const [ group, field ] = path.split( '.' );
				errors[ group ] = {
					...( errors[ group ] as object ),
					[ field ]: { type: 'validate', message },
				};
			}
			return Object.keys( messages ).length
				? { values: {}, errors: errors as never }
				: { values, errors: {} };
		},
	} );

	useEffect( () => {
		if ( settings.data ) {
			const { primary, fallback, embeddings } = settings.data.providers;
			form.reset( { primary, fallback, embeddings } );
		}
	}, [ settings.data, form ] );

	const values = useWatch( { control: form.control } );
	const { isDirty, errors } = form.formState;
	useUnsavedChangesWarning( isDirty );

	const save = form.handleSubmit( async ( data ) => {
		try {
			const saved = await update.mutateAsync( { providers: data } );
			const { primary, fallback, embeddings } = saved.providers;
			form.reset( { primary, fallback, embeddings } );
			toast.success( __( 'Routing saved.', 'chatlume-ai-chatbot' ) );
		} catch ( error ) {
			toast.error(
				error instanceof Error ? error.message : String( error )
			);
		}
	} );

	useSaveShortcut( () => {
		if ( isDirty && ! update.isPending ) {
			save();
		}
	}, isDirty );

	const chatProviders = providers;
	const embeddingProviders = providers.filter(
		( provider ) => provider.supports.embeddings
	);
	const primaryProvider = providers.find(
		( p ) => p.id === values.primary?.provider
	);
	const effectiveEmbeddings =
		values.embeddings?.provider || values.primary?.provider || '';
	const effectiveEmbeddingsProvider = providers.find(
		( p ) => p.id === effectiveEmbeddings
	);

	const providerOptions = ( list: ProviderSummary[] ) =>
		list.map( ( provider ) => (
			<option key={ provider.id } value={ provider.id }>
				{ provider.label }
			</option>
		) );

	const roleRow = (
		role: 'primary' | 'fallback',
		label: string,
		emptyLabel: string,
		providerId: string,
		modelId: string
	) => (
		<div className="chatlume:grid chatlume:gap-4 chatlume:md:grid-cols-2">
			<Field id={ providerId } label={ label }>
				<Select
					id={ providerId }
					{ ...form.register( `${ role }.provider`, {
						onChange: () => form.setValue( `${ role }.model`, '' ),
					} ) }
				>
					<option value="">{ emptyLabel }</option>
					{ providerOptions( chatProviders ) }
				</Select>
			</Field>
			<Field
				id={ modelId }
				label={ __( 'Model', 'chatlume-ai-chatbot' ) }
				hint={ __(
					'Empty uses the provider’s default model.',
					'chatlume-ai-chatbot'
				) }
				error={ errors[ role ]?.model?.message }
			>
				<Controller
					control={ form.control }
					name={ `${ role }.model` }
					render={ ( { field } ) => (
						<ModelPicker
							id={ modelId }
							providerId={ values[ role ]?.provider ?? '' }
							kind="chat"
							value={ field.value }
							onChange={ field.onChange }
							disabled={ ! values[ role ]?.provider }
							placeholder={ __(
								'Provider default',
								'chatlume-ai-chatbot'
							) }
							describedBy={ `${ modelId }-hint ${ modelId }-error` }
							invalid={ !! errors[ role ]?.model }
						/>
					) }
				/>
			</Field>
		</div>
	);

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>
						{ __( 'Routing', 'chatlume-ai-chatbot' ) }
					</CardTitle>
					<CardDescription>
						{ __(
							'Which provider answers visitors, which one takes over if it fails, and which one prepares your knowledge base.',
							'chatlume-ai-chatbot'
						) }
					</CardDescription>
				</CardHeader>
				<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-6">
					{ roleRow(
						'primary',
						__( 'Primary provider', 'chatlume-ai-chatbot' ),
						__(
							'None (quick answers and site search only)',
							'chatlume-ai-chatbot'
						),
						ids.primary,
						ids.primaryModel
					) }
					{ roleRow(
						'fallback',
						__( 'Fallback provider', 'chatlume-ai-chatbot' ),
						__( 'No fallback', 'chatlume-ai-chatbot' ),
						ids.fallback,
						ids.fallbackModel
					) }

					<div className="chatlume:grid chatlume:gap-4 chatlume:md:grid-cols-[1fr_1fr_8rem]">
						<Field
							id={ ids.embeddings }
							label={ __(
								'Embeddings provider',
								'chatlume-ai-chatbot'
							) }
							tooltip={ __(
								'Embeddings let the bot find knowledge by meaning, not just keywords. Some chat providers (such as Anthropic) do not offer them, so this can be a different provider.',
								'chatlume-ai-chatbot'
							) }
							error={ errors.embeddings?.provider?.message }
						>
							<Select
								id={ ids.embeddings }
								aria-invalid={ !! errors.embeddings?.provider }
								{ ...form.register( 'embeddings.provider', {
									onChange: () =>
										form.setValue( 'embeddings.model', '' ),
								} ) }
							>
								<option value="">
									{ primaryProvider?.supports.embeddings
										? sprintf(
												/* translators: %s: Provider name. */
												__(
													'Same as primary (%s)',
													'chatlume-ai-chatbot'
												),
												primaryProvider.label
											)
										: __(
												'None (keyword search only)',
												'chatlume-ai-chatbot'
											) }
								</option>
								{ providerOptions( embeddingProviders ) }
							</Select>
						</Field>
						<Field
							id={ ids.embeddingsModel }
							label={ __(
								'Embeddings model',
								'chatlume-ai-chatbot'
							) }
							error={ errors.embeddings?.model?.message }
						>
							<Controller
								control={ form.control }
								name="embeddings.model"
								render={ ( { field } ) => (
									<ModelPicker
										id={ ids.embeddingsModel }
										providerId={ effectiveEmbeddings }
										kind="embedding"
										value={ field.value }
										onChange={ field.onChange }
										disabled={
											! effectiveEmbeddingsProvider
												?.supports.embeddings
										}
										placeholder={ __(
											'Choose an embeddings model',
											'chatlume-ai-chatbot'
										) }
										invalid={ !! errors.embeddings?.model }
									/>
								) }
							/>
						</Field>
						<Field
							id={ ids.dimensions }
							label={ __( 'Dimensions', 'chatlume-ai-chatbot' ) }
							tooltip={ __(
								'Smaller vectors (256–512) are faster and use less space with little loss in quality. 0 uses the model’s full size. Ignored by providers that do not support it.',
								'chatlume-ai-chatbot'
							) }
							error={ errors.embeddings?.dimensions?.message }
						>
							<Input
								id={ ids.dimensions }
								type="number"
								min={ 0 }
								max={ 4096 }
								aria-invalid={
									!! errors.embeddings?.dimensions
								}
								disabled={
									! effectiveEmbeddingsProvider?.supports
										.embedding_dimensions
								}
								{ ...form.register( 'embeddings.dimensions', {
									valueAsNumber: true,
								} ) }
							/>
						</Field>
					</div>
				</CardContent>
			</Card>

			<StickySaveBar
				visible={ isDirty }
				saving={ update.isPending }
				onSave={ () => save() }
				onDiscard={ () => form.reset() }
			/>
			{ isDirty && <UnsavedChangesGuard /> }
		</>
	);
}
