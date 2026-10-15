/**
 * Compact controls for the Customizer panel.
 */
import { __, sprintf } from '@wordpress/i18n';
import { ChevronDown, ImagePlus, Pipette, RotateCcw, X } from 'lucide-react';
import { useEffect, useId, useState, type ReactNode } from 'react';

import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Switch } from '../../components/ui/switch';
import { normalizeHex } from '../../lib/theme/color';
import { cn } from '../../lib/utils';

/** Reports a change; `gesture` groups a drag into one undo step. */
export type Change< T > = ( value: T, gesture?: string ) => void;

interface MediaAttachment {
	url: string;
	sizes?: Record< string, { url: string; width: number } >;
}

interface MediaFrame {
	on: ( event: string, callback: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: string ) => {
			first: () => { toJSON: () => MediaAttachment };
		};
	};
}

declare global {
	interface Window {
		wp?: {
			media?: ( options: Record< string, unknown > ) => MediaFrame;
		};
		EyeDropper?: new () => { open: () => Promise< { sRGBHex: string } > };
	}
}

/**
 * A collapsible panel section with a reset button.
 *
 * @param props          Props.
 * @param props.id       Section ID (click to edit opens it).
 * @param props.title    Title.
 * @param props.open     Whether it is expanded.
 * @param props.onToggle Expands or collapses it.
 * @param props.onReset  Resets the section to defaults.
 * @param props.onFocus  Focus moved into the section.
 * @param props.children Controls.
 */
export function Section( {
	id,
	title,
	open,
	onToggle,
	onReset,
	onFocus,
	children,
}: {
	id: string;
	title: string;
	open: boolean;
	onToggle: () => void;
	onReset?: () => void;
	/** Called when focus moves into the section (context-aware preview). */
	onFocus?: () => void;
	children: ReactNode;
} ) {
	const panel = `chatlume-section-${ id }`;
	return (
		<section
			data-section={ id }
			className="chatlume:border-b chatlume:border-border"
			onFocusCapture={ onFocus }
		>
			<div className="chatlume:flex chatlume:items-center chatlume:gap-1 chatlume:pe-2">
				<button
					type="button"
					className="chatlume:flex chatlume:flex-1 chatlume:items-center chatlume:justify-between chatlume:px-4 chatlume:py-3 chatlume:text-start chatlume:text-sm chatlume:font-semibold chatlume:text-fg chatlume:hover:bg-surface-muted chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
					aria-expanded={ open }
					aria-controls={ panel }
					onClick={ onToggle }
				>
					{ title }
					<ChevronDown
						aria-hidden="true"
						className={ cn(
							'chatlume:size-4 chatlume:text-fg-muted chatlume:transition-transform',
							open && 'chatlume:rotate-180'
						) }
					/>
				</button>
				{ open && onReset && (
					<Button
						type="button"
						size="icon"
						variant="ghost"
						className="chatlume:size-8"
						aria-label={ sprintf(
							/* translators: %s: Section title. */
							__( 'Reset %s', 'chatlume-ai-chatbot' ),
							title
						) }
						title={ __(
							'Reset this section',
							'chatlume-ai-chatbot'
						) }
						onClick={ onReset }
					>
						<RotateCcw aria-hidden="true" />
					</Button>
				) }
			</div>
			{ open && (
				<div
					id={ panel }
					className="chatlume:flex chatlume:flex-col chatlume:gap-4 chatlume:px-4 chatlume:pt-1 chatlume:pb-5"
				>
					{ children }
				</div>
			) }
		</section>
	);
}

/**
 * Label above a control.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.htmlFor  Control ID.
 * @param props.hint     Help text.
 * @param props.children Control.
 */
export function Row( {
	label,
	htmlFor,
	hint,
	children,
}: {
	label: string;
	htmlFor?: string;
	hint?: ReactNode;
	children: ReactNode;
} ) {
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-1.5">
			{ htmlFor ? (
				<label
					htmlFor={ htmlFor }
					className="chatlume:text-[13px] chatlume:font-medium chatlume:text-fg"
				>
					{ label }
				</label>
			) : (
				<span className="chatlume:text-[13px] chatlume:font-medium chatlume:text-fg">
					{ label }
				</span>
			) }
			{ children }
			{ hint && (
				<p className="chatlume:text-xs chatlume:text-fg-muted">
					{ hint }
				</p>
			) }
		</div>
	);
}

/**
 * A color: swatch with the system picker, hex field, and eyedropper.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.value    Hex color.
 * @param props.onChange Change handler.
 * @param props.warning  Contrast warning shown under it.
 */
export function ColorField( {
	label,
	value,
	onChange,
	warning,
}: {
	label: string;
	value: string;
	onChange: Change< string >;
	warning?: string;
} ) {
	const id = useId();
	const [ text, setText ] = useState( value );

	useEffect( () => setText( value ), [ value ] );

	const commit = ( input: string ) => {
		const hex = normalizeHex(
			input.startsWith( '#' ) ? input : `#${ input }`
		);
		if ( hex ) {
			onChange( hex );
		} else {
			setText( value );
		}
	};

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-1">
			<div className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3">
				<label
					htmlFor={ `${ id }-hex` }
					className="chatlume:min-w-0 chatlume:text-[13px] chatlume:text-fg"
				>
					{ label }
				</label>
				<div className="chatlume:flex chatlume:shrink-0 chatlume:items-center chatlume:gap-1.5">
					<input
						type="color"
						value={ normalizeHex( value ) || '#000000' }
						aria-label={ sprintf(
							/* translators: %s: Color name. */
							__( 'Pick %s', 'chatlume-ai-chatbot' ),
							label
						) }
						className="chatlume:size-8 chatlume:cursor-pointer chatlume:rounded-control chatlume:border chatlume:border-border chatlume:bg-surface chatlume:p-0.5"
						onInput={ ( event ) =>
							onChange(
								( event.target as HTMLInputElement ).value,
								`color:${ id }`
							)
						}
						onChange={ () => undefined }
					/>
					<Input
						id={ `${ id }-hex` }
						value={ text }
						maxLength={ 7 }
						spellCheck={ false }
						className="chatlume:h-8 chatlume:w-[5.5rem] chatlume:font-mono chatlume:text-xs"
						onChange={ ( event ) => setText( event.target.value ) }
						onBlur={ () => commit( text ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' ) {
								event.preventDefault();
								commit( text );
							}
						} }
					/>
					{ typeof window !== 'undefined' && window.EyeDropper && (
						<Button
							type="button"
							size="icon"
							variant="ghost"
							className="chatlume:size-8"
							aria-label={ __(
								'Pick a color from the screen',
								'chatlume-ai-chatbot'
							) }
							onClick={ async () => {
								try {
									const picked =
										await new window.EyeDropper!().open();
									onChange( picked.sRGBHex );
								} catch {
									// The admin cancelled.
								}
							} }
						>
							<Pipette aria-hidden="true" />
						</Button>
					) }
				</div>
			</div>
			{ warning && (
				<p className="chatlume:text-xs chatlume:text-warning">
					{ warning }
				</p>
			) }
		</div>
	);
}

/**
 * A range slider with its value.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.value    Value.
 * @param props.min      Minimum.
 * @param props.max      Maximum.
 * @param props.step     Step.
 * @param props.unit     Unit after the value.
 * @param props.format   Formats the shown value.
 * @param props.onChange Change handler.
 */
export function Slider( {
	label,
	value,
	min,
	max,
	step = 1,
	unit = '',
	format,
	onChange,
}: {
	label: string;
	value: number;
	min: number;
	max: number;
	step?: number;
	unit?: string;
	format?: ( value: number ) => string;
	onChange: Change< number >;
} ) {
	const id = useId();
	const shown = format ? format( value ) : `${ value }${ unit }`;
	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-1">
			<div className="chatlume:flex chatlume:items-center chatlume:justify-between">
				<label
					htmlFor={ id }
					className="chatlume:text-[13px] chatlume:text-fg"
				>
					{ label }
				</label>
				<span className="chatlume:font-mono chatlume:text-xs chatlume:text-fg-muted">
					{ shown }
				</span>
			</div>
			<input
				id={ id }
				type="range"
				min={ min }
				max={ max }
				step={ step }
				value={ value }
				aria-valuetext={ shown }
				className="chatlume:w-full chatlume:accent-[var(--chatlume-ui-accent)]"
				onChange={ ( event ) =>
					onChange( Number( event.target.value ), `range:${ id }` )
				}
			/>
		</div>
	);
}

/**
 * A small set of choices shown as buttons.
 *
 * @param props           Props.
 * @param props.label     Label.
 * @param props.value     Selected value.
 * @param props.options   Choices.
 * @param props.onChange  Change handler.
 * @param props.hideLabel Show the label to screen readers only.
 */
export function Segmented< T extends string >( {
	label,
	value,
	options,
	onChange,
	hideLabel = false,
}: {
	label: string;
	value: T;
	options: Array< { id: T; label: string } >;
	onChange: Change< T >;
	hideLabel?: boolean;
} ) {
	const id = useId();
	return (
		<div
			role="radiogroup"
			aria-labelledby={ id }
			className="chatlume:flex chatlume:flex-col chatlume:gap-1.5"
		>
			<span
				id={ id }
				className={
					hideLabel
						? 'chatlume:sr-only'
						: 'chatlume:text-[13px] chatlume:font-medium chatlume:text-fg'
				}
			>
				{ label }
			</span>
			<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-1 chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-1">
				{ options.map( ( option ) => (
					<button
						key={ option.id }
						type="button"
						role="radio"
						aria-checked={ value === option.id }
						className={ cn(
							'chatlume:min-w-0 chatlume:flex-1 chatlume:rounded-[calc(var(--radius-control)-4px)] chatlume:px-2 chatlume:py-1.5 chatlume:text-xs chatlume:font-medium chatlume:transition-colors chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
							value === option.id
								? 'chatlume:bg-surface chatlume:text-fg chatlume:shadow-card'
								: 'chatlume:text-fg-muted chatlume:hover:text-fg'
						) }
						onClick={ () => onChange( option.id ) }
					>
						{ option.label }
					</button>
				) ) }
			</div>
		</div>
	);
}

/**
 * A switch with a label.
 *
 * @param props             Props.
 * @param props.label       Label.
 * @param props.description Explanation.
 * @param props.checked     Value.
 * @param props.onChange    Change handler.
 */
export function Toggle( {
	label,
	description,
	checked,
	onChange,
}: {
	label: string;
	description?: string;
	checked: boolean;
	onChange: Change< boolean >;
} ) {
	const id = useId();
	return (
		<div className="chatlume:flex chatlume:items-start chatlume:justify-between chatlume:gap-4">
			<div>
				<label
					htmlFor={ id }
					className="chatlume:text-[13px] chatlume:text-fg"
				>
					{ label }
				</label>
				{ description && (
					<p
						id={ `${ id }-hint` }
						className="chatlume:mt-0.5 chatlume:text-xs chatlume:text-fg-muted"
					>
						{ description }
					</p>
				) }
			</div>
			<Switch
				id={ id }
				checked={ checked }
				aria-describedby={ description ? `${ id }-hint` : undefined }
				onCheckedChange={ ( next ) => onChange( next ) }
			/>
		</div>
	);
}

/**
 * A short text.
 *
 * @param props             Props.
 * @param props.label       Label.
 * @param props.value       Value.
 * @param props.maxLength   Maximum length.
 * @param props.placeholder Placeholder.
 * @param props.hint        Help text.
 * @param props.onChange    Change handler.
 */
export function TextControl( {
	label,
	value,
	maxLength,
	placeholder,
	hint,
	onChange,
}: {
	label: string;
	value: string;
	maxLength: number;
	placeholder?: string;
	hint?: string;
	onChange: Change< string >;
} ) {
	const id = useId();
	return (
		<Row label={ label } htmlFor={ id } hint={ hint }>
			<Input
				id={ id }
				value={ value }
				maxLength={ maxLength }
				placeholder={ placeholder }
				onChange={ ( event ) =>
					onChange( event.target.value, `text:${ id }` )
				}
			/>
		</Row>
	);
}

/**
 * An image from the Media Library.
 *
 * @param props          Props.
 * @param props.label    Label.
 * @param props.value    Image URL.
 * @param props.hint     Help text.
 * @param props.onChange Change handler.
 */
export function ImageControl( {
	label,
	value,
	hint,
	onChange,
}: {
	label: string;
	value: string;
	hint?: string;
	onChange: Change< string >;
} ) {
	const media = typeof window !== 'undefined' ? window.wp?.media : undefined;

	const choose = () => {
		if ( ! media ) {
			return;
		}
		const frame = media( {
			title: label,
			library: { type: [ 'image/png', 'image/jpeg', 'image/webp' ] },
			multiple: false,
			button: { text: __( 'Use this image', 'chatlume-ai-chatbot' ) },
		} );
		frame.on( 'select', () => {
			const attachment = frame
				.state()
				.get( 'selection' )
				.first()
				.toJSON();
			// A small size is enough for avatars and logos (96–150 px).
			const small = Object.values( attachment.sizes ?? {} )
				.filter( ( size ) => size.width >= 96 )
				.sort( ( a, b ) => a.width - b.width )[ 0 ];
			onChange( small?.url ?? attachment.url );
		} );
		frame.open();
	};

	return (
		<Row label={ label } hint={ hint }>
			<div className="chatlume:flex chatlume:items-center chatlume:gap-2">
				{ value ? (
					<img
						src={ value }
						alt=""
						className="chatlume:size-10 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:object-cover"
					/>
				) : (
					<span className="chatlume:flex chatlume:size-10 chatlume:items-center chatlume:justify-center chatlume:rounded-control chatlume:border chatlume:border-dashed chatlume:border-border chatlume:text-fg-muted">
						<ImagePlus
							aria-hidden="true"
							className="chatlume:size-4"
						/>
					</span>
				) }
				<Button
					type="button"
					size="sm"
					disabled={ ! media }
					onClick={ choose }
				>
					{ value
						? __( 'Replace', 'chatlume-ai-chatbot' )
						: __( 'Choose image', 'chatlume-ai-chatbot' ) }
				</Button>
				{ value && (
					<Button
						type="button"
						size="icon"
						variant="ghost"
						className="chatlume:size-8"
						aria-label={ sprintf(
							/* translators: %s: Image label. */
							__( 'Remove %s', 'chatlume-ai-chatbot' ),
							label
						) }
						onClick={ () => onChange( '' ) }
					>
						<X aria-hidden="true" />
					</Button>
				) }
			</div>
		</Row>
	);
}
