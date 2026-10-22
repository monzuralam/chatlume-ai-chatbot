/**
 * Font choice and font uploads for the Customizer.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Trash2, Upload } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Select } from '../../components/ui/select';
import type { ApiError } from '../../lib/api';
import {
	deleteFont,
	lacksScript,
	uploadFont,
	type AppearanceState,
} from '../../lib/appearance';
import type { FontOption } from '../../lib/theme/types';
import { Row } from './controls';

/**
 * Group names.
 *
 * @return Labels.
 */
function groupLabels(): Record< FontOption[ 'group' ], string > {
	return {
		basic: __( 'Basic', 'chatlume-ai-chatbot' ),
		bundled: __( 'Included with Chatlume', 'chatlume-ai-chatbot' ),
		theme: __( 'From your theme', 'chatlume-ai-chatbot' ),
		library: __( 'WordPress Font Library', 'chatlume-ai-chatbot' ),
		custom: __( 'Uploaded', 'chatlume-ai-chatbot' ),
	};
}

/**
 * Script names for the support note.
 *
 * @return Labels.
 */
function scriptLabels(): Record< string, string > {
	return {
		bengali: __( 'Bengali', 'chatlume-ai-chatbot' ),
		arabic: __( 'Arabic', 'chatlume-ai-chatbot' ),
		cyrillic: __( 'Cyrillic', 'chatlume-ai-chatbot' ),
		greek: __( 'Greek', 'chatlume-ai-chatbot' ),
		vietnamese: __( 'Vietnamese', 'chatlume-ai-chatbot' ),
		devanagari: __( 'Devanagari', 'chatlume-ai-chatbot' ),
		cjk: __( 'Chinese, Japanese, and Korean', 'chatlume-ai-chatbot' ),
		thai: __( 'Thai', 'chatlume-ai-chatbot' ),
		hebrew: __( 'Hebrew', 'chatlume-ai-chatbot' ),
		latin: __( 'Latin', 'chatlume-ai-chatbot' ),
	};
}

/**
 * A font select grouped by source, with a script support note.
 *
 * @param props            Props.
 * @param props.label      Label.
 * @param props.value      Font key.
 * @param props.options    Catalog.
 * @param props.siteScript Script of the site language.
 * @param props.same       Offer "Same as the text" first.
 * @param props.onChange   Change handler.
 */
export function FontSelect( {
	label,
	value,
	options,
	siteScript,
	same = false,
	onChange,
}: {
	label: string;
	value: string;
	options: FontOption[];
	siteScript: string;
	same?: boolean;
	onChange: ( key: string ) => void;
} ) {
	const id = useId();
	const groups = groupLabels();
	const selected = options.find( ( option ) => option.key === value );
	const missing = value !== 'same' && ! selected;
	const note = lacksScript( selected, siteScript )
		? sprintf(
				/* translators: 1: Font name, 2: Writing system, e.g. "Bengali". */
				__(
					'%1$s has no %2$s letters. Those use the visitor’s system font, so text stays readable.',
					'chatlume-ai-chatbot'
				),
				selected?.label ?? '',
				scriptLabels()[ siteScript ] ?? siteScript
			)
		: undefined;

	return (
		<Row
			label={ label }
			htmlFor={ id }
			hint={
				missing
					? __(
							'This font is no longer available, so the system font is used.',
							'chatlume-ai-chatbot'
						)
					: note
			}
		>
			<Select
				id={ id }
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
			>
				{ same && (
					<option value="same">
						{ __( 'Same as the text', 'chatlume-ai-chatbot' ) }
					</option>
				) }
				{ missing && (
					<option value={ value }>
						{ __( 'Unavailable font', 'chatlume-ai-chatbot' ) }
					</option>
				) }
				{ (
					Object.keys( groups ) as Array< FontOption[ 'group' ] >
				 ).map( ( group ) => {
					const items = options.filter(
						( option ) => option.group === group
					);
					return items.length ? (
						<optgroup key={ group } label={ groups[ group ] }>
							{ items.map( ( option ) => (
								<option key={ option.key } value={ option.key }>
									{ option.label }
								</option>
							) ) }
						</optgroup>
					) : null;
				} ) }
			</Select>
		</Row>
	);
}

/**
 * Uploaded woff2 fonts: add and delete.
 *
 * @param props          Props.
 * @param props.fonts    Font state.
 * @param props.onChange Receives the new Customizer state.
 */
export function FontUploads( {
	fonts,
	onChange,
}: {
	fonts: AppearanceState[ 'fonts' ];
	onChange: ( state: AppearanceState ) => void;
} ) {
	const ids = { file: useId(), name: useId(), weight: useId() };
	const input = useRef< HTMLInputElement >( null );
	const [ file, setFile ] = useState< File | null >( null );
	const [ name, setName ] = useState( '' );
	const [ typed, setTyped ] = useState( false );
	const [ weight, setWeight ] = useState( '400' );
	const [ busy, setBusy ] = useState( false );

	const upload = async () => {
		if ( ! file ) {
			return;
		}
		setBusy( true );
		try {
			onChange( await uploadFont( file, name, weight ) );
			toast.success(
				__(
					'Font uploaded. Choose it in the font list.',
					'chatlume-ai-chatbot'
				)
			);
			setFile( null );
			setName( '' );
			setTyped( false );
			if ( input.current ) {
				input.current.value = '';
			}
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const remove = async ( id: string ) => {
		setBusy( true );
		try {
			onChange( await deleteFont( id ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<details className="chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-3">
			<summary className="chatlume:cursor-pointer chatlume:text-[13px] chatlume:font-medium chatlume:text-fg">
				{ __( 'Upload your own font', 'chatlume-ai-chatbot' ) }
			</summary>
			<div className="chatlume:mt-3 chatlume:flex chatlume:flex-col chatlume:gap-3">
				<p className="chatlume:text-xs chatlume:text-fg-muted">
					{ __(
						'A .woff2 file up to 2 MB. Make sure its license allows use on your website.',
						'chatlume-ai-chatbot'
					) }
				</p>
				<Row
					label={ __( 'Font file', 'chatlume-ai-chatbot' ) }
					htmlFor={ ids.file }
				>
					<input
						id={ ids.file }
						ref={ input }
						type="file"
						accept=".woff2,font/woff2"
						className="chatlume:text-xs chatlume:text-fg"
						onChange={ ( event ) => {
							const chosen = event.target.files?.[ 0 ] ?? null;
							setFile( chosen );
							// The name follows the file until the admin types one.
							if ( chosen && ! typed ) {
								setName(
									chosen.name.replace( /\.woff2$/i, '' )
								);
							}
						} }
					/>
				</Row>
				<Row
					label={ __( 'Name', 'chatlume-ai-chatbot' ) }
					htmlFor={ ids.name }
				>
					<Input
						id={ ids.name }
						value={ name }
						maxLength={ 60 }
						onChange={ ( event ) => {
							setName( event.target.value );
							setTyped( event.target.value !== '' );
						} }
					/>
				</Row>
				<Row
					label={ __( 'Weight', 'chatlume-ai-chatbot' ) }
					htmlFor={ ids.weight }
				>
					<Select
						id={ ids.weight }
						value={ weight }
						onChange={ ( event ) =>
							setWeight( event.target.value )
						}
					>
						<option value="400">
							{ __( 'Regular (400)', 'chatlume-ai-chatbot' ) }
						</option>
						<option value="500">
							{ __( 'Medium (500)', 'chatlume-ai-chatbot' ) }
						</option>
						<option value="600">
							{ __( 'Semibold (600)', 'chatlume-ai-chatbot' ) }
						</option>
						<option value="700">
							{ __( 'Bold (700)', 'chatlume-ai-chatbot' ) }
						</option>
						<option value="100 900">
							{ __(
								'Variable (all weights)',
								'chatlume-ai-chatbot'
							) }
						</option>
					</Select>
				</Row>
				<Button
					type="button"
					size="sm"
					disabled={ ! file || busy }
					onClick={ upload }
				>
					<Upload aria-hidden="true" />
					{ __( 'Upload font', 'chatlume-ai-chatbot' ) }
				</Button>
				{ fonts.uploaded.length > 0 && (
					<ul className="chatlume:flex chatlume:flex-col chatlume:gap-1">
						{ fonts.uploaded.map( ( font ) => (
							<li
								key={ font.id }
								className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2 chatlume:text-[13px] chatlume:text-fg"
							>
								<span className="chatlume:min-w-0 chatlume:truncate">
									{ font.name }
								</span>
								<Button
									type="button"
									size="icon"
									variant="ghost"
									className="chatlume:size-8"
									disabled={ busy }
									aria-label={ sprintf(
										/* translators: %s: Name of the item to delete. */
										__(
											'Delete %s',
											'chatlume-ai-chatbot'
										),
										font.name
									) }
									onClick={ () => remove( font.id ) }
								>
									<Trash2 aria-hidden="true" />
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</div>
		</details>
	);
}
