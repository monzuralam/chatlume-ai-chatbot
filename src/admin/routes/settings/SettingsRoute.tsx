import { zodResolver } from '@hookform/resolvers/zod';
import { __ } from '@wordpress/i18n';
import { TriangleAlert } from 'lucide-react';
import { useEffect, useId } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { toast } from 'sonner';

import { PageHeader } from '../../components/layout/PageHeader';
import { StickySaveBar } from '../../components/layout/StickySaveBar';
import { UnsavedChangesGuard } from '../../components/layout/UnsavedChangesGuard';
import { Button } from '../../components/ui/button';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { Skeleton } from '../../components/ui/skeleton';
import { Switch } from '../../components/ui/switch';
import { settingsSectionRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { useSaveShortcut } from '../../hooks/useSaveShortcut';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import {
	dataPrivacyFormSchema,
	type DataPrivacyForm,
} from '../../lib/settingsSchema';

function DataPrivacySection() {
	const settings = useSettings();
	const update = useUpdateSettings();
	const switchId = useId();
	const descriptionId = useId();

	const form = useForm< DataPrivacyForm >( {
		resolver: zodResolver( dataPrivacyFormSchema ),
		defaultValues: { privacy: { delete_data_on_uninstall: false } },
	} );

	// Load server values into the form once they arrive.
	useEffect( () => {
		if ( settings.data ) {
			form.reset( { privacy: settings.data.privacy } );
		}
	}, [ settings.data, form ] );

	const { isDirty } = form.formState;
	useUnsavedChangesWarning( isDirty );

	const save = form.handleSubmit( async ( values ) => {
		try {
			const saved = await update.mutateAsync( values );
			form.reset( { privacy: saved.privacy } );
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

	const deleteOnUninstall = form.watch( 'privacy.delete_data_on_uninstall' );

	return (
		<>
			<Card>
				<CardHeader>
					<CardTitle>
						{ __( 'Data & privacy', 'chatlume-ai-chatbot' ) }
					</CardTitle>
					<CardDescription>
						{ __(
							'Control what happens to Chatlume’s data on this site.',
							'chatlume-ai-chatbot'
						) }
					</CardDescription>
				</CardHeader>
				<CardContent>
					{ settings.isPending && (
						<div className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-6">
							<div className="chatlume:flex-1">
								<Skeleton className="chatlume:h-4 chatlume:w-56" />
								<Skeleton className="chatlume:mt-2 chatlume:h-3 chatlume:w-80 chatlume:max-w-full" />
							</div>
							<Skeleton className="chatlume:h-6 chatlume:w-11 chatlume:rounded-full" />
						</div>
					) }

					{ settings.isError && (
						<div
							role="alert"
							className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
						>
							<span>{ settings.error.message }</span>
							<Button
								size="sm"
								onClick={ () => settings.refetch() }
							>
								{ __( 'Try again', 'chatlume-ai-chatbot' ) }
							</Button>
						</div>
					) }

					{ settings.isSuccess && (
						<form
							onSubmit={ ( event ) => {
								event.preventDefault();
								save();
							} }
						>
							<div className="chatlume:flex chatlume:items-start chatlume:justify-between chatlume:gap-6">
								<div>
									<label
										htmlFor={ switchId }
										className="chatlume:text-sm chatlume:font-medium chatlume:text-fg"
									>
										{ __(
											'Delete all data on uninstall',
											'chatlume-ai-chatbot'
										) }
									</label>
									<p
										id={ descriptionId }
										className="chatlume:mt-1 chatlume:max-w-xl chatlume:text-[13px] chatlume:text-fg-muted"
									>
										{ __(
											'When you delete the plugin from the Plugins screen, also remove its database tables, settings, and saved conversations. Deactivating never removes data.',
											'chatlume-ai-chatbot'
										) }
									</p>
								</div>
								<Controller
									control={ form.control }
									name="privacy.delete_data_on_uninstall"
									render={ ( { field } ) => (
										<Switch
											id={ switchId }
											aria-describedby={ descriptionId }
											checked={ field.value }
											onCheckedChange={ field.onChange }
											onBlur={ field.onBlur }
										/>
									) }
								/>
							</div>

							{ deleteOnUninstall && (
								<p className="chatlume:mt-4 chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:rounded-control chatlume:bg-warning-soft chatlume:px-3 chatlume:py-2.5 chatlume:text-[13px] chatlume:text-warning">
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
						</form>
					) }
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

/**
 * Settings: built-in sections plus sections registered by add-ons.
 */
export default function SettingsRoute() {
	const sections = useRegistry( settingsSectionRegistry );

	return (
		<>
			<PageHeader
				title={ __( 'Settings', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Site-wide options for Chatlume.',
					'chatlume-ai-chatbot'
				) }
			/>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
				<DataPrivacySection />

				{ sections.map( ( section ) => {
					const SectionComponent = section.component;
					return (
						<Card key={ section.id }>
							<CardHeader>
								<CardTitle>{ section.title }</CardTitle>
								{ section.description && (
									<CardDescription>
										{ section.description }
									</CardDescription>
								) }
							</CardHeader>
							<CardContent>
								<SectionComponent />
							</CardContent>
						</Card>
					);
				} ) }
			</div>
		</>
	);
}
