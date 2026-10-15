/**
 * Export settings to a JSON file and import them back (for example to copy
 * a setup to another site). API keys are never part of settings.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Download, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Button } from '../../components/ui/button';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { useSettings, useUpdateSettings } from '../../hooks/useSettings';
import { getBootstrap } from '../../lib/bootstrap';
import {
	exportSettingsFile,
	importableSettings,
	type SettingsUpdate,
} from '../../lib/settingsSchema';

/** Largest settings file accepted (bytes). */
const MAX_FILE_SIZE = 1024 * 1024;

/**
 * Import/export card.
 *
 * @param props          Props.
 * @param props.disabled Disabled while the form has unsaved changes.
 */
export function ImportExport( { disabled }: { disabled: boolean } ) {
	const settings = useSettings();
	const update = useUpdateSettings();
	const input = useRef< HTMLInputElement >( null );
	const [ pending, setPending ] = useState< SettingsUpdate | null >( null );

	const download = () => {
		if ( ! settings.data ) {
			return;
		}
		const blob = new Blob(
			[
				exportSettingsFile(
					settings.data as unknown as Record< string, unknown >,
					getBootstrap().version
				),
			],
			{ type: 'application/json' }
		);
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `chatlume-settings-${ new Date()
			.toISOString()
			.slice( 0, 10 ) }.json`;
		link.click();
		window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
	};

	const choose = async ( file: File | undefined ) => {
		if ( ! file ) {
			return;
		}
		if ( file.size > MAX_FILE_SIZE ) {
			toast.error(
				__(
					'This file is too large to be a Chatlume settings file.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		let parsed: unknown;
		try {
			parsed = JSON.parse( await file.text() );
		} catch {
			parsed = null;
		}
		const found = importableSettings( parsed );
		if ( ! found ) {
			toast.error(
				__(
					'This file does not contain Chatlume settings. Choose a file exported from Chatlume.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		setPending( found );
	};

	const confirm = async () => {
		const values = pending;
		setPending( null );
		if ( ! values ) {
			return;
		}
		try {
			await update.mutateAsync( values );
			toast.success( __( 'Settings imported.', 'chatlume-ai-chatbot' ) );
		} catch ( error ) {
			toast.error(
				sprintf(
					/* translators: %s: Error message. */
					__(
						'The settings were not imported: %s',
						'chatlume-ai-chatbot'
					),
					error instanceof Error ? error.message : String( error )
				)
			);
		}
	};

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Import and export', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Copy your Chatlume setup to another site. API keys are never included; add them again on the other site.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent className="chatlume:flex chatlume:flex-wrap chatlume:gap-3">
				<Button
					type="button"
					onClick={ download }
					disabled={ ! settings.data }
				>
					<Download aria-hidden="true" />
					{ __( 'Export settings', 'chatlume-ai-chatbot' ) }
				</Button>
				<Button
					type="button"
					onClick={ () => input.current?.click() }
					disabled={ disabled || update.isPending }
					title={
						disabled
							? __(
									'Save or discard your changes first.',
									'chatlume-ai-chatbot'
								)
							: undefined
					}
				>
					<Upload aria-hidden="true" />
					{ __( 'Import settings…', 'chatlume-ai-chatbot' ) }
				</Button>
				<input
					ref={ input }
					type="file"
					accept="application/json,.json"
					className="chatlume:hidden"
					aria-hidden="true"
					tabIndex={ -1 }
					onChange={ ( event ) => {
						choose( event.target.files?.[ 0 ] );
						event.target.value = '';
					} }
				/>
			</CardContent>

			<ConfirmDialog
				open={ pending !== null }
				title={ __( 'Import these settings?', 'chatlume-ai-chatbot' ) }
				description={ sprintf(
					/* translators: %s: Comma-separated list of settings sections. */
					__(
						'These sections will be replaced with the values from the file: %s. API keys and the widget appearance are not changed.',
						'chatlume-ai-chatbot'
					),
					Object.keys( pending ?? {} ).join( ', ' )
				) }
				confirmLabel={ __( 'Import', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				onConfirm={ confirm }
				onCancel={ () => setPending( null ) }
			/>
		</Card>
	);
}
