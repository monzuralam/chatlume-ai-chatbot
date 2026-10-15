import { __, sprintf } from '@wordpress/i18n';
import { Check, History, Palette, X } from 'lucide-react';
import { useId, useMemo, useState } from 'react';

import { Button } from '../../components/ui/button';
import {
	Popover,
	PopoverContent,
	PopoverTrigger,
} from '../../components/ui/popover';
import { Input } from '../../components/ui/input';
import { Switch } from '../../components/ui/switch';
import type { AppearanceVersion, SavedPreset } from '../../lib/appearance';
import { applyPreset, themePresets } from '../../lib/theme/presets';
import { headerFill } from '../../lib/theme/resolve';
import type { Theme } from '../../lib/theme/types';
import { formatDateTime } from '../conversations/format';

/**
 * A miniature chat window in the preset's colors.
 *
 * @param props       Props.
 * @param props.theme Theme the preset produces.
 */
export function Thumbnail( { theme }: { theme: Theme } ) {
	const colors =
		theme.mode === 'dark' ? theme.colors.dark : theme.colors.light;
	const radius = Math.min( theme.shape.radii.window, 12 );
	const header = headerFill( theme.header, colors );
	return (
		<span
			aria-hidden="true"
			className="chatlume:flex chatlume:h-20 chatlume:w-full chatlume:flex-col chatlume:overflow-hidden chatlume:border chatlume:border-border"
			style={ { background: colors.background, borderRadius: radius } }
		>
			<span
				className="chatlume:h-4 chatlume:shrink-0"
				style={ {
					background: header,
					borderBottom:
						theme.header.style === 'minimal'
							? `1px solid ${ colors.border }`
							: undefined,
				} }
			/>
			<span className="chatlume:flex chatlume:flex-1 chatlume:flex-col chatlume:gap-1 chatlume:p-1.5">
				<span
					className="chatlume:h-2.5 chatlume:w-3/5"
					style={ {
						background: colors.botBubble,
						borderRadius: Math.min( theme.shape.radii.bubble, 8 ),
					} }
				/>
				<span
					className="chatlume:h-2.5 chatlume:w-2/5 chatlume:self-end"
					style={ {
						background: colors.userBubble,
						borderRadius: Math.min( theme.shape.radii.bubble, 8 ),
					} }
				/>
				<span
					className="chatlume:mt-auto chatlume:h-2.5 chatlume:w-full"
					style={ {
						border: `1px solid ${ colors.border }`,
						background: colors.inputBackground,
						borderRadius: Math.min( theme.shape.radii.input, 8 ),
					} }
				/>
			</span>
		</span>
	);
}

/**
 * Theme presets with thumbnails.
 *
 * @param props          Props.
 * @param props.theme    Current theme.
 * @param props.onApply  Applies a preset.
 * @param props.saved    The admin's saved presets.
 * @param props.onSave   Saves the current look under a name.
 * @param props.onDelete Deletes a saved preset.
 */
export function PresetPicker( {
	theme,
	onApply,
	saved,
	onSave,
	onDelete,
}: {
	theme: Theme;
	onApply: ( theme: Theme ) => void;
	saved: SavedPreset[];
	onSave: ( name: string ) => Promise< boolean >;
	onDelete: ( id: string ) => void;
} ) {
	const [ open, setOpen ] = useState( false );
	const [ keepBrand, setKeepBrand ] = useState( false );
	const [ name, setName ] = useState( '' );
	const keepId = useId();
	const nameId = useId();
	const presets = useMemo( () => themePresets(), [] );
	const previews = useMemo(
		() =>
			presets.map( ( preset ) => ( {
				preset,
				theme: applyPreset( theme, preset, keepBrand ),
			} ) ),
		[ presets, theme, keepBrand ]
	);

	return (
		<Popover open={ open } onOpenChange={ setOpen }>
			<PopoverTrigger asChild>
				<Button type="button" size="sm">
					<Palette aria-hidden="true" />
					{ __( 'Presets', 'chatlume-ai-chatbot' ) }
				</Button>
			</PopoverTrigger>
			<PopoverContent
				align="start"
				className="chatlume:w-[min(92vw,560px)] chatlume:p-4"
			>
				<div className="chatlume:mb-3 chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-3">
					<p className="chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
						{ __( 'Theme presets', 'chatlume-ai-chatbot' ) }
					</p>
					<span className="chatlume:flex chatlume:items-center chatlume:gap-2">
						<Switch
							id={ keepId }
							checked={ keepBrand }
							onCheckedChange={ setKeepBrand }
						/>
						<label
							htmlFor={ keepId }
							className="chatlume:text-[13px] chatlume:text-fg"
						>
							{ __(
								'Keep my brand color',
								'chatlume-ai-chatbot'
							) }
						</label>
					</span>
				</div>
				<div className="chatlume:grid chatlume:grid-cols-2 chatlume:gap-3 chatlume:sm:grid-cols-5">
					{ previews.map( ( { preset, theme: result } ) => (
						<button
							key={ preset.id }
							type="button"
							className="chatlume:flex chatlume:flex-col chatlume:gap-1.5 chatlume:rounded-control chatlume:p-1 chatlume:text-start chatlume:text-xs chatlume:font-medium chatlume:text-fg chatlume:hover:bg-surface-muted chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
							aria-label={ sprintf(
								/* translators: %s: Preset name. */
								__(
									'Apply the %s preset',
									'chatlume-ai-chatbot'
								),
								preset.name
							) }
							onClick={ () => {
								onApply( result );
								setOpen( false );
							} }
						>
							<Thumbnail theme={ result } />
							{ preset.name }
						</button>
					) ) }
				</div>
				<p className="chatlume:mt-3 chatlume:text-xs chatlume:text-fg-muted">
					{ __(
						'Presets change colors and styles. Your texts and images stay. Undo with Ctrl+Z.',
						'chatlume-ai-chatbot'
					) }
				</p>

				<div className="chatlume:mt-4 chatlume:border-t chatlume:border-border chatlume:pt-4">
					<p className="chatlume:mb-3 chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
						{ __( 'My presets', 'chatlume-ai-chatbot' ) }
					</p>
					{ saved.length > 0 && (
						<div className="chatlume:mb-3 chatlume:grid chatlume:grid-cols-2 chatlume:gap-3 chatlume:sm:grid-cols-5">
							{ saved.map( ( preset ) => (
								<div
									key={ preset.id }
									className="chatlume:relative"
								>
									<button
										type="button"
										className="chatlume:flex chatlume:w-full chatlume:flex-col chatlume:gap-1.5 chatlume:rounded-control chatlume:p-1 chatlume:text-start chatlume:text-xs chatlume:font-medium chatlume:text-fg chatlume:hover:bg-surface-muted chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
										aria-label={ sprintf(
											/* translators: %s: Preset name. */
											__(
												'Apply my preset %s',
												'chatlume-ai-chatbot'
											),
											preset.name
										) }
										onClick={ () => {
											onApply( preset.theme );
											setOpen( false );
										} }
									>
										<Thumbnail theme={ preset.theme } />
										<span className="chatlume:truncate">
											{ preset.name }
										</span>
									</button>
									<button
										type="button"
										className="chatlume:absolute chatlume:end-0 chatlume:top-0 chatlume:rounded-full chatlume:bg-surface chatlume:p-1 chatlume:text-fg-muted chatlume:shadow-card chatlume:hover:text-danger chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
										aria-label={ sprintf(
											/* translators: %s: Preset name. */
											__(
												'Delete my preset %s',
												'chatlume-ai-chatbot'
											),
											preset.name
										) }
										onClick={ () => onDelete( preset.id ) }
									>
										<X
											aria-hidden="true"
											className="chatlume:size-3"
										/>
									</button>
								</div>
							) ) }
						</div>
					) }
					<form
						className="chatlume:flex chatlume:items-end chatlume:gap-2"
						onSubmit={ async ( event ) => {
							event.preventDefault();
							if (
								name.trim() &&
								( await onSave( name.trim() ) )
							) {
								setName( '' );
							}
						} }
					>
						<div className="chatlume:flex chatlume:flex-1 chatlume:flex-col chatlume:gap-1">
							<label
								htmlFor={ nameId }
								className="chatlume:text-xs chatlume:text-fg-muted"
							>
								{ __(
									'Save the current look as a preset',
									'chatlume-ai-chatbot'
								) }
							</label>
							<Input
								id={ nameId }
								value={ name }
								maxLength={ 40 }
								placeholder={ __(
									'Preset name',
									'chatlume-ai-chatbot'
								) }
								onChange={ ( event ) =>
									setName( event.target.value )
								}
							/>
						</div>
						<Button
							type="submit"
							size="sm"
							disabled={ ! name.trim() }
						>
							{ __( 'Save preset', 'chatlume-ai-chatbot' ) }
						</Button>
					</form>
				</div>
			</PopoverContent>
		</Popover>
	);
}

/**
 * Published versions with restore.
 *
 * @param props           Props.
 * @param props.versions  Versions, newest first.
 * @param props.busy      An action is running.
 * @param props.onRestore Loads a version into the draft.
 */
export function VersionsMenu( {
	versions,
	busy,
	onRestore,
}: {
	versions: AppearanceVersion[];
	busy: boolean;
	onRestore: ( id: string ) => void;
} ) {
	const [ open, setOpen ] = useState( false );
	return (
		<Popover open={ open } onOpenChange={ setOpen }>
			<PopoverTrigger asChild>
				<Button
					type="button"
					size="icon"
					variant="ghost"
					aria-label={ __(
						'Published versions',
						'chatlume-ai-chatbot'
					) }
					title={ __( 'Published versions', 'chatlume-ai-chatbot' ) }
				>
					<History aria-hidden="true" />
				</Button>
			</PopoverTrigger>
			<PopoverContent align="end" className="chatlume:w-80 chatlume:p-3">
				<p className="chatlume:mb-2 chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
					{ __( 'Published versions', 'chatlume-ai-chatbot' ) }
				</p>
				{ versions.length === 0 ? (
					<p className="chatlume:text-[13px] chatlume:text-fg-muted">
						{ __(
							'Nothing published yet. Your last ten published versions appear here.',
							'chatlume-ai-chatbot'
						) }
					</p>
				) : (
					<ul className="chatlume:flex chatlume:flex-col chatlume:gap-1">
						{ versions.map( ( version ) => (
							<li
								key={ version.id }
								className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2 chatlume:rounded-control chatlume:px-2 chatlume:py-1.5 chatlume:text-[13px] chatlume:hover:bg-surface-muted"
							>
								<span className="chatlume:min-w-0">
									<span className="chatlume:block chatlume:text-fg">
										{ formatDateTime(
											version.published_at
										) }
									</span>
									{ version.author && (
										<span className="chatlume:block chatlume:text-xs chatlume:text-fg-muted">
											{ version.author }
										</span>
									) }
								</span>
								{ version.current ? (
									<span className="chatlume:flex chatlume:items-center chatlume:gap-1 chatlume:text-xs chatlume:text-success">
										<Check
											aria-hidden="true"
											className="chatlume:size-3.5"
										/>
										{ __( 'Live', 'chatlume-ai-chatbot' ) }
									</span>
								) : (
									<Button
										type="button"
										size="sm"
										variant="ghost"
										disabled={ busy }
										onClick={ () => {
											onRestore( version.id );
											setOpen( false );
										} }
									>
										{ __(
											'Restore',
											'chatlume-ai-chatbot'
										) }
									</Button>
								) }
							</li>
						) ) }
					</ul>
				) }
				<p className="chatlume:mt-2 chatlume:text-xs chatlume:text-fg-muted">
					{ __(
						'Restoring loads a version into your draft. Publish to show it to visitors.',
						'chatlume-ai-chatbot'
					) }
				</p>
			</PopoverContent>
		</Popover>
	);
}
