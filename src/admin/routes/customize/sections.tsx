/**
 * The Customizer's settings sections.
 */
import { __, sprintf } from '@wordpress/i18n';
import { Wand2 } from 'lucide-react';
import { useState } from 'react';

import { Button } from '../../components/ui/button';
import { Select } from '../../components/ui/select';
import { contrast } from '../../lib/theme/color';
import {
	contrastIssues,
	fixAll,
	fixIssue,
	pairLabel,
} from '../../lib/theme/contrast';
import { brandColor, withBrandColor } from '../../lib/theme/presets';
import {
	COLOR_TOKENS,
	ILLUSTRATIONS,
	LAUNCHER_ICONS,
	RANGES,
	SHAPE_PRESETS,
	type ColorToken,
	type Theme,
} from '../../lib/theme/types';
import type { AppearanceState } from '../../lib/appearance';
import { cn } from '../../lib/utils';
import { LAUNCHER_ICONS as ICON_PATHS } from '../../../widget/launcher/icons';
import {
	ColorField,
	ImageControl,
	Row,
	Segmented,
	Slider,
	TextControl,
	Toggle,
	type Change,
} from './controls';
import { FontSelect, FontUploads } from './FontPicker';

export interface SectionProps {
	theme: Theme;
	/** Sets one value by dotted path. */
	set: ( path: string, value: unknown, gesture?: string ) => void;
	/** Replaces the whole theme (palettes, fixes). */
	replace: ( theme: Theme, gesture?: string ) => void;
	advanced: boolean;
	/** Font catalog and uploads. */
	fonts: AppearanceState[ 'fonts' ];
	/** Receives the Customizer state after a font upload or deletion. */
	onServer: ( state: AppearanceState ) => void;
}

/**
 * Typed setter for one path.
 *
 * @param set  Setter.
 * @param path Path.
 * @return Change handler.
 */
function bind< T >( set: SectionProps[ 'set' ], path: string ): Change< T > {
	return ( value, gesture ) => set( path, value, gesture );
}

/**
 * Names of the color tokens.
 *
 * @return Labels.
 */
function tokenLabels(): Record< ColorToken, string > {
	return {
		primary: __( 'Primary (buttons)', 'chatlume-ai-chatbot' ),
		onPrimary: __( 'Text on primary', 'chatlume-ai-chatbot' ),
		accent: __( 'Accent', 'chatlume-ai-chatbot' ),
		background: __( 'Background', 'chatlume-ai-chatbot' ),
		surface: __( 'Surface', 'chatlume-ai-chatbot' ),
		headerBackground: __( 'Header background', 'chatlume-ai-chatbot' ),
		headerBackgroundEnd: __( 'Header gradient end', 'chatlume-ai-chatbot' ),
		headerText: __( 'Header text', 'chatlume-ai-chatbot' ),
		botBubble: __( 'Bot bubble', 'chatlume-ai-chatbot' ),
		botText: __( 'Bot text', 'chatlume-ai-chatbot' ),
		userBubble: __( 'Visitor bubble', 'chatlume-ai-chatbot' ),
		userText: __( 'Visitor text', 'chatlume-ai-chatbot' ),
		link: __( 'Links', 'chatlume-ai-chatbot' ),
		border: __( 'Borders', 'chatlume-ai-chatbot' ),
		inputBackground: __(
			'Message field background',
			'chatlume-ai-chatbot'
		),
		inputText: __( 'Message field text', 'chatlume-ai-chatbot' ),
		launcherBackground: __( 'Launcher background', 'chatlume-ai-chatbot' ),
		launcherIcon: __( 'Launcher icon', 'chatlume-ai-chatbot' ),
		sourceCard: __( 'Source cards', 'chatlume-ai-chatbot' ),
		muted: __( 'Secondary text', 'chatlume-ai-chatbot' ),
		error: __( 'Error', 'chatlume-ai-chatbot' ),
		success: __( 'Success', 'chatlume-ai-chatbot' ),
	};
}

const RECENT_KEY = 'chatlume-recent-colors';

function readRecent(): string[] {
	try {
		const stored = JSON.parse(
			window.localStorage.getItem( RECENT_KEY ) ?? '[]'
		);
		return Array.isArray( stored )
			? stored
					.filter( ( item ) => typeof item === 'string' )
					.slice( 0, 8 )
			: [];
	} catch {
		return [];
	}
}

function rememberColor( color: string ): string[] {
	const next = [
		color,
		...readRecent().filter( ( item ) => item !== color ),
	].slice( 0, 8 );
	try {
		window.localStorage.setItem( RECENT_KEY, JSON.stringify( next ) );
	} catch {
		// Storage is optional.
	}
	return next;
}

/**
 * Brand color: generates the whole palette.
 *
 * @param props         Props.
 * @param props.theme   Theme.
 * @param props.replace Replaces the theme.
 */
export function BrandColor( {
	theme,
	replace,
}: Pick< SectionProps, 'theme' | 'replace' > ) {
	const [ recent, setRecent ] = useState( readRecent );
	const apply = ( color: string, gesture?: string ) => {
		replace( withBrandColor( theme, color ), gesture );
		if ( ! gesture ) {
			setRecent( rememberColor( color ) );
		}
	};

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-2">
			<ColorField
				label={ __( 'Brand color', 'chatlume-ai-chatbot' ) }
				value={ brandColor( theme ) }
				onChange={ apply }
			/>
			<p className="chatlume:text-xs chatlume:text-fg-muted">
				{ __(
					'Builds matching light and dark colors that are easy to read.',
					'chatlume-ai-chatbot'
				) }
			</p>
			{ recent.length > 0 && (
				<div className="chatlume:flex chatlume:flex-wrap chatlume:gap-1.5">
					{ recent.map( ( color ) => (
						<button
							key={ color }
							type="button"
							className="chatlume:size-6 chatlume:rounded-full chatlume:border chatlume:border-border chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
							style={ { background: color } }
							aria-label={ sprintf(
								/* translators: %s: Hex color. */
								__(
									'Use recent color %s',
									'chatlume-ai-chatbot'
								),
								color
							) }
							onClick={ () => apply( color ) }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

/**
 * Contrast warnings with automatic fixes.
 *
 * @param props         Props.
 * @param props.theme   Theme.
 * @param props.replace Replaces the theme.
 */
export function ContrastWarnings( {
	theme,
	replace,
}: Pick< SectionProps, 'theme' | 'replace' > ) {
	const issues = contrastIssues( theme );
	if ( ! issues.length ) {
		return (
			<p className="chatlume:rounded-control chatlume:bg-success-soft chatlume:px-3 chatlume:py-2 chatlume:text-xs chatlume:text-success">
				{ __(
					'All text is easy to read in light and dark mode (WCAG AA).',
					'chatlume-ai-chatbot'
				) }
			</p>
		);
	}
	return (
		<div
			role="status"
			className="chatlume:flex chatlume:flex-col chatlume:gap-2 chatlume:rounded-control chatlume:bg-warning-soft chatlume:p-3"
		>
			<div className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2">
				<p className="chatlume:text-xs chatlume:font-medium chatlume:text-warning">
					{ sprintf(
						/* translators: %d: Number of color pairs. */
						__(
							'%d color pairs are hard to read.',
							'chatlume-ai-chatbot'
						),
						issues.length
					) }
				</p>
				<Button
					type="button"
					size="sm"
					onClick={ () => replace( fixAll( theme ) ) }
				>
					<Wand2 aria-hidden="true" />
					{ __( 'Fix all automatically', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
			<ul className="chatlume:flex chatlume:flex-col chatlume:gap-1">
				{ issues.map( ( issue ) => (
					<li
						key={ `${ issue.mode }-${ issue.id }` }
						className="chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2 chatlume:text-xs chatlume:text-fg"
					>
						<span>
							{ sprintf(
								/* translators: 1: What is hard to read, 2: Light or dark, 3: Contrast ratio, 4: Required ratio. */
								__(
									'%1$s (%2$s): %3$s:1, needs %4$s:1',
									'chatlume-ai-chatbot'
								),
								pairLabel( issue.id ),
								issue.mode === 'light'
									? __( 'light', 'chatlume-ai-chatbot' )
									: __( 'dark', 'chatlume-ai-chatbot' ),
								issue.ratio.toFixed( 2 ),
								String( issue.minimum )
							) }
						</span>
						<button
							type="button"
							className="chatlume:shrink-0 chatlume:font-medium chatlume:text-accent chatlume:hover:underline"
							onClick={ () =>
								replace( {
									...theme,
									colors: {
										...theme.colors,
										[ issue.mode ]: fixIssue(
											theme.colors[ issue.mode ],
											issue
										),
									},
								} )
							}
						>
							{ __( 'Fix', 'chatlume-ai-chatbot' ) }
						</button>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Colors: brand color, color mode, header style, every token (Advanced).
 *
 * @param props Props.
 */
export function ColorsSection( props: SectionProps ) {
	const { theme, set, replace, advanced } = props;
	const [ mode, setMode ] = useState< 'light' | 'dark' >( 'light' );
	const labels = tokenLabels();
	const colors = theme.colors[ mode ];

	return (
		<>
			<BrandColor theme={ theme } replace={ replace } />
			<Segmented
				label={ __( 'Color mode', 'chatlume-ai-chatbot' ) }
				value={ theme.mode }
				onChange={ bind( set, 'mode' ) }
				options={ [
					{
						id: 'light',
						label: __( 'Light', 'chatlume-ai-chatbot' ),
					},
					{ id: 'dark', label: __( 'Dark', 'chatlume-ai-chatbot' ) },
					{
						id: 'auto',
						label: __( 'Follow visitor', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			<Segmented
				label={ __( 'Header', 'chatlume-ai-chatbot' ) }
				value={ theme.header.style }
				onChange={ bind( set, 'header.style' ) }
				options={ [
					{
						id: 'solid',
						label: __( 'Solid', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'gradient',
						label: __( 'Gradient', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'minimal',
						label: __( 'Minimal', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ theme.header.style === 'gradient' && (
				<Slider
					label={ __( 'Gradient angle', 'chatlume-ai-chatbot' ) }
					value={ theme.header.gradientAngle }
					min={ RANGES.gradientAngle[ 0 ] }
					max={ RANGES.gradientAngle[ 1 ] }
					unit="°"
					onChange={ bind( set, 'header.gradientAngle' ) }
				/>
			) }
			<ContrastWarnings theme={ theme } replace={ replace } />
			{ advanced && (
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-3">
					<Segmented
						label={ __( 'Edit colors for', 'chatlume-ai-chatbot' ) }
						value={ mode }
						onChange={ ( next ) => setMode( next ) }
						options={ [
							{
								id: 'light',
								label: __(
									'Light mode',
									'chatlume-ai-chatbot'
								),
							},
							{
								id: 'dark',
								label: __( 'Dark mode', 'chatlume-ai-chatbot' ),
							},
						] }
					/>
					{ COLOR_TOKENS.filter(
						( token ) =>
							token !== 'headerBackgroundEnd' ||
							theme.header.style === 'gradient'
					).map( ( token ) => (
						<ColorField
							key={ `${ mode }-${ token }` }
							label={ labels[ token ] }
							value={ colors[ token ] }
							onChange={ bind(
								set,
								`colors.${ mode }.${ token }`
							) }
						/>
					) ) }
				</div>
			) }
		</>
	);
}

/**
 * Brand: names, avatar, logo, status.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.advanced
 */
export function BrandSection( { theme, set, advanced }: SectionProps ) {
	const brand = theme.brand;
	const illustrations: Record< string, string > = {
		bubbles: __( 'Bubbles', 'chatlume-ai-chatbot' ),
		robot: __( 'Robot', 'chatlume-ai-chatbot' ),
		spark: __( 'Spark', 'chatlume-ai-chatbot' ),
		leaf: __( 'Leaf', 'chatlume-ai-chatbot' ),
		wave: __( 'Wave', 'chatlume-ai-chatbot' ),
		planet: __( 'Planet', 'chatlume-ai-chatbot' ),
	};

	return (
		<>
			<Segmented
				label={ __( 'Avatar', 'chatlume-ai-chatbot' ) }
				value={ brand.avatarType }
				onChange={ bind( set, 'brand.avatarType' ) }
				options={ [
					{
						id: 'initials',
						label: __( 'Initials', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'image',
						label: __( 'Image', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'illustration',
						label: __( 'Illustration', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ brand.avatarType === 'image' && (
				<ImageControl
					label={ __( 'Avatar image', 'chatlume-ai-chatbot' ) }
					hint={ __(
						'A square PNG, JPG, or WebP of at least 96 pixels.',
						'chatlume-ai-chatbot'
					) }
					value={ brand.avatarUrl }
					onChange={ bind( set, 'brand.avatarUrl' ) }
				/>
			) }
			{ brand.avatarType === 'illustration' && (
				<Row label={ __( 'Illustration', 'chatlume-ai-chatbot' ) }>
					<Select
						aria-label={ __(
							'Illustration',
							'chatlume-ai-chatbot'
						) }
						value={ brand.illustration }
						onChange={ ( event ) =>
							set( 'brand.illustration', event.target.value )
						}
					>
						{ ILLUSTRATIONS.map( ( item ) => (
							<option key={ item } value={ item }>
								{ illustrations[ item ] }
							</option>
						) ) }
					</Select>
				</Row>
			) }
			<ImageControl
				label={ __(
					'Company logo in the header',
					'chatlume-ai-chatbot'
				) }
				value={ brand.logoUrl }
				onChange={ ( value ) => {
					set( 'brand.logoUrl', value );
					set( 'brand.showLogo', value !== '' );
				} }
			/>
			{ advanced && (
				<>
					<TextControl
						label={ __( 'Header title', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'Leave empty to use the bot name.',
							'chatlume-ai-chatbot'
						) }
						value={ brand.headerTitle }
						maxLength={ 60 }
						onChange={ bind( set, 'brand.headerTitle' ) }
					/>
					<TextControl
						label={ __( 'Header subtitle', 'chatlume-ai-chatbot' ) }
						hint={ __(
							'Replaces the online status when set.',
							'chatlume-ai-chatbot'
						) }
						value={ brand.headerSubtitle }
						maxLength={ 80 }
						onChange={ bind( set, 'brand.headerSubtitle' ) }
					/>
					<TextControl
						label={ __( 'Status text', 'chatlume-ai-chatbot' ) }
						placeholder={ __( 'Online', 'chatlume-ai-chatbot' ) }
						value={ brand.statusText }
						maxLength={ 40 }
						onChange={ bind( set, 'brand.statusText' ) }
					/>
					<Toggle
						label={ __(
							'Show the avatar in the header',
							'chatlume-ai-chatbot'
						) }
						checked={ brand.showAvatar }
						onChange={ bind( set, 'brand.showAvatar' ) }
					/>
					<Toggle
						label={ __(
							'Show the status dot',
							'chatlume-ai-chatbot'
						) }
						checked={ brand.showStatus }
						onChange={ bind( set, 'brand.showStatus' ) }
					/>
				</>
			) }
			<Toggle
				label={ __(
					'Show “Powered by Chatlume”',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Off by default. Turn it on if you would like to credit us.',
					'chatlume-ai-chatbot'
				) }
				checked={ brand.poweredBy }
				onChange={ bind( set, 'brand.poweredBy' ) }
			/>
		</>
	);
}

/**
 * Typography: fonts and sizes.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.advanced
 * @param props.fonts
 * @param props.onServer
 */
export function TypographySection( {
	theme,
	set,
	advanced,
	fonts,
	onServer,
}: SectionProps ) {
	const type = theme.typography;
	return (
		<>
			<FontSelect
				label={ __( 'Font', 'chatlume-ai-chatbot' ) }
				value={ type.bodyFont }
				options={ fonts.options }
				siteScript={ fonts.site_script }
				onChange={ ( key ) => set( 'typography.bodyFont', key ) }
			/>
			{ advanced && (
				<FontSelect
					label={ __( 'Heading font', 'chatlume-ai-chatbot' ) }
					value={ type.headingFont }
					options={ fonts.options }
					siteScript={ fonts.site_script }
					same
					onChange={ ( key ) => set( 'typography.headingFont', key ) }
				/>
			) }
			{ advanced && (
				<FontUploads fonts={ fonts } onChange={ onServer } />
			) }
			<Slider
				label={ __( 'Text size', 'chatlume-ai-chatbot' ) }
				value={ type.baseSize }
				min={ RANGES.baseSize[ 0 ] }
				max={ RANGES.baseSize[ 1 ] }
				unit="px"
				onChange={ bind( set, 'typography.baseSize' ) }
			/>
			{ advanced && (
				<>
					<Slider
						label={ __( 'Line height', 'chatlume-ai-chatbot' ) }
						value={ type.lineHeight }
						min={ RANGES.lineHeight[ 0 ] }
						max={ RANGES.lineHeight[ 1 ] }
						step={ 0.05 }
						onChange={ bind( set, 'typography.lineHeight' ) }
					/>
					<Slider
						label={ __( 'Title weight', 'chatlume-ai-chatbot' ) }
						value={ type.titleWeight }
						min={ RANGES.titleWeight[ 0 ] }
						max={ RANGES.titleWeight[ 1 ] }
						step={ 100 }
						onChange={ bind( set, 'typography.titleWeight' ) }
					/>
					<Slider
						label={ __( 'Message weight', 'chatlume-ai-chatbot' ) }
						value={ type.messageWeight }
						min={ RANGES.messageWeight[ 0 ] }
						max={ RANGES.messageWeight[ 1 ] }
						step={ 100 }
						onChange={ bind( set, 'typography.messageWeight' ) }
					/>
					<Slider
						label={ __( 'Letter spacing', 'chatlume-ai-chatbot' ) }
						value={ type.letterSpacing }
						min={ RANGES.letterSpacing[ 0 ] }
						max={ RANGES.letterSpacing[ 1 ] }
						step={ 0.005 }
						unit="em"
						onChange={ bind( set, 'typography.letterSpacing' ) }
					/>
				</>
			) }
			<p className="chatlume:text-xs chatlume:text-fg-muted">
				{ __(
					'The message field is always at least 16 px on phones, so they do not zoom in when typing.',
					'chatlume-ai-chatbot'
				) }
			</p>
		</>
	);
}

/**
 * Layout: widget style, position, size.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.advanced
 */
export function LayoutSection( { theme, set, advanced }: SectionProps ) {
	const layout = theme.layout;
	return (
		<>
			<Segmented
				label={ __( 'Style', 'chatlume-ai-chatbot' ) }
				value={ layout.style }
				onChange={ bind( set, 'layout.style' ) }
				options={ [
					{
						id: 'floating',
						label: __( 'Floating', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'panel',
						label: __( 'Side panel', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'modal',
						label: __( 'Centered', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			<Segmented
				label={ __( 'Position', 'chatlume-ai-chatbot' ) }
				value={ layout.position }
				onChange={ bind( set, 'layout.position' ) }
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
			{ advanced && (
				<>
					<Slider
						label={ __( 'Width', 'chatlume-ai-chatbot' ) }
						value={ layout.width }
						min={ RANGES.width[ 0 ] }
						max={ RANGES.width[ 1 ] }
						step={ 10 }
						unit="px"
						onChange={ bind( set, 'layout.width' ) }
					/>
					<Slider
						label={ __( 'Height', 'chatlume-ai-chatbot' ) }
						value={ layout.height }
						min={ RANGES.height[ 0 ] }
						max={ RANGES.height[ 1 ] }
						step={ 10 }
						unit="px"
						onChange={ bind( set, 'layout.height' ) }
					/>
					<Slider
						label={ __(
							'Distance from the side',
							'chatlume-ai-chatbot'
						) }
						value={ layout.offsetX }
						min={ RANGES.offset[ 0 ] }
						max={ RANGES.offset[ 1 ] }
						unit="px"
						onChange={ bind( set, 'layout.offsetX' ) }
					/>
					<Slider
						label={ __(
							'Distance from the bottom',
							'chatlume-ai-chatbot'
						) }
						value={ layout.offsetY }
						min={ RANGES.offset[ 0 ] }
						max={ RANGES.offset[ 1 ] }
						unit="px"
						onChange={ bind( set, 'layout.offsetY' ) }
					/>
					<Slider
						label={ __(
							'Distance from the side on phones',
							'chatlume-ai-chatbot'
						) }
						value={ layout.mobileOffsetX }
						min={ RANGES.mobileOffset[ 0 ] }
						max={ RANGES.mobileOffset[ 1 ] }
						unit="px"
						onChange={ bind( set, 'layout.mobileOffsetX' ) }
					/>
					<Slider
						label={ __(
							'Distance from the bottom on phones',
							'chatlume-ai-chatbot'
						) }
						value={ layout.mobileOffsetY }
						min={ RANGES.mobileOffset[ 0 ] }
						max={ RANGES.mobileOffset[ 1 ] }
						unit="px"
						onChange={ bind( set, 'layout.mobileOffsetY' ) }
					/>
					<Toggle
						label={ __(
							'Full screen on phones',
							'chatlume-ai-chatbot'
						) }
						checked={ layout.mobileFullscreen }
						onChange={ bind( set, 'layout.mobileFullscreen' ) }
					/>
					{ layout.mobileFullscreen && (
						<Slider
							label={ __(
								'Full screen below',
								'chatlume-ai-chatbot'
							) }
							value={ layout.fullscreenBelow }
							min={ RANGES.fullscreenBelow[ 0 ] }
							max={ RANGES.fullscreenBelow[ 1 ] }
							step={ 10 }
							unit="px"
							onChange={ bind( set, 'layout.fullscreenBelow' ) }
						/>
					) }
					<TextControl
						label={ __(
							'Stacking order (z-index)',
							'chatlume-ai-chatbot'
						) }
						hint={ __(
							'Raise it if another element covers the chat.',
							'chatlume-ai-chatbot'
						) }
						value={ String( layout.zIndex ) }
						maxLength={ 10 }
						onChange={ ( value, gesture ) => {
							const number = Number.parseInt( value, 10 );
							if ( Number.isFinite( number ) ) {
								set(
									'layout.zIndex',
									Math.min(
										2147483647,
										Math.max( 1, number )
									),
									gesture
								);
							}
						} }
					/>
				</>
			) }
		</>
	);
}

/**
 * Shape: corners, shadow, density.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.replace
 * @param props.advanced
 */
export function ShapeSection( {
	theme,
	set,
	replace,
	advanced,
}: SectionProps ) {
	const shape = theme.shape;
	const control = ( value: number ) => ( value >= 999 ? 41 : value );
	const fromControl = ( value: number ) => ( value > 40 ? 999 : value );
	const full = ( value: number ) =>
		value > 40 ? __( 'Full', 'chatlume-ai-chatbot' ) : `${ value }px`;

	return (
		<>
			<Segmented
				label={ __( 'Corners', 'chatlume-ai-chatbot' ) }
				value={ shape.preset }
				onChange={ ( preset ) =>
					replace( {
						...theme,
						shape: {
							...shape,
							preset,
							radii: { ...SHAPE_PRESETS[ preset ] },
						},
					} )
				}
				options={ [
					{
						id: 'sharp',
						label: __( 'Sharp', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'rounded',
						label: __( 'Rounded', 'chatlume-ai-chatbot' ),
					},
					{ id: 'soft', label: __( 'Soft', 'chatlume-ai-chatbot' ) },
					{ id: 'pill', label: __( 'Pill', 'chatlume-ai-chatbot' ) },
				] }
			/>
			<Segmented
				label={ __( 'Shadow', 'chatlume-ai-chatbot' ) }
				value={ shape.shadow }
				onChange={ bind( set, 'shape.shadow' ) }
				options={ [
					{ id: 'none', label: __( 'None', 'chatlume-ai-chatbot' ) },
					{ id: 'soft', label: __( 'Soft', 'chatlume-ai-chatbot' ) },
					{
						id: 'medium',
						label: __( 'Medium', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'strong',
						label: __( 'Strong', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			<Segmented
				label={ __( 'Spacing', 'chatlume-ai-chatbot' ) }
				value={ shape.density }
				onChange={ bind( set, 'shape.density' ) }
				options={ [
					{
						id: 'compact',
						label: __( 'Compact', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'comfortable',
						label: __( 'Comfortable', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'spacious',
						label: __( 'Spacious', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ advanced && (
				<>
					<Slider
						label={ __( 'Window corners', 'chatlume-ai-chatbot' ) }
						value={ shape.radii.window }
						min={ RANGES.radiusWindow[ 0 ] }
						max={ RANGES.radiusWindow[ 1 ] }
						unit="px"
						onChange={ bind( set, 'shape.radii.window' ) }
					/>
					<Slider
						label={ __( 'Message corners', 'chatlume-ai-chatbot' ) }
						value={ shape.radii.bubble }
						min={ RANGES.radiusBubble[ 0 ] }
						max={ RANGES.radiusBubble[ 1 ] }
						unit="px"
						onChange={ bind( set, 'shape.radii.bubble' ) }
					/>
					<Slider
						label={ __( 'Card corners', 'chatlume-ai-chatbot' ) }
						value={ shape.radii.card }
						min={ RANGES.radiusCard[ 0 ] }
						max={ RANGES.radiusCard[ 1 ] }
						unit="px"
						onChange={ bind( set, 'shape.radii.card' ) }
					/>
					<Slider
						label={ __( 'Button corners', 'chatlume-ai-chatbot' ) }
						value={ control( shape.radii.button ) }
						min={ 0 }
						max={ 41 }
						format={ full }
						onChange={ ( value, gesture ) =>
							set(
								'shape.radii.button',
								fromControl( value ),
								gesture
							)
						}
					/>
					<Slider
						label={ __(
							'Message field corners',
							'chatlume-ai-chatbot'
						) }
						value={ control( shape.radii.input ) }
						min={ 0 }
						max={ 41 }
						format={ full }
						onChange={ ( value, gesture ) =>
							set(
								'shape.radii.input',
								fromControl( value ),
								gesture
							)
						}
					/>
				</>
			) }
		</>
	);
}

/**
 * Conversation: messages, background, composer.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.advanced
 */
export function ConversationSection( { theme, set, advanced }: SectionProps ) {
	return (
		<>
			<Segmented
				label={ __( 'Messages', 'chatlume-ai-chatbot' ) }
				value={ theme.messages.style }
				onChange={ bind( set, 'messages.style' ) }
				options={ [
					{
						id: 'bubbles',
						label: __( 'Bubbles', 'chatlume-ai-chatbot' ),
					},
					{ id: 'flat', label: __( 'Flat', 'chatlume-ai-chatbot' ) },
					{
						id: 'outlined',
						label: __( 'Outlined', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			<Segmented
				label={ __( 'Background', 'chatlume-ai-chatbot' ) }
				value={ theme.background.type }
				onChange={ bind( set, 'background.type' ) }
				options={ [
					{
						id: 'solid',
						label: __( 'Solid', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'pattern',
						label: __( 'Pattern', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'gradient',
						label: __( 'Gradient', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ theme.background.type === 'pattern' && (
				<Segmented
					label={ __( 'Pattern', 'chatlume-ai-chatbot' ) }
					value={ theme.background.pattern }
					onChange={ bind( set, 'background.pattern' ) }
					options={ [
						{
							id: 'dots',
							label: __( 'Dots', 'chatlume-ai-chatbot' ),
						},
						{
							id: 'grid',
							label: __( 'Grid', 'chatlume-ai-chatbot' ),
						},
						{
							id: 'waves',
							label: __( 'Waves', 'chatlume-ai-chatbot' ),
						},
						{
							id: 'diagonal',
							label: __( 'Lines', 'chatlume-ai-chatbot' ),
						},
					] }
				/>
			) }
			{ advanced && (
				<>
					<Toggle
						label={ __( 'Glass effect', 'chatlume-ai-chatbot' ) }
						description={ __(
							'Blurs the page behind the window. Browsers without support show a solid background.',
							'chatlume-ai-chatbot'
						) }
						checked={ theme.background.glass }
						onChange={ bind( set, 'background.glass' ) }
					/>
					<Toggle
						label={ __(
							'Bot avatar next to each message',
							'chatlume-ai-chatbot'
						) }
						checked={ theme.messages.avatar }
						onChange={ bind( set, 'messages.avatar' ) }
					/>
					<Segmented
						label={ __( 'Times', 'chatlume-ai-chatbot' ) }
						value={ theme.messages.timestamps }
						onChange={ bind( set, 'messages.timestamps' ) }
						options={ [
							{
								id: 'hover',
								label: __( 'On hover', 'chatlume-ai-chatbot' ),
							},
							{
								id: 'always',
								label: __( 'Always', 'chatlume-ai-chatbot' ),
							},
							{
								id: 'never',
								label: __( 'Never', 'chatlume-ai-chatbot' ),
							},
						] }
					/>
					<Segmented
						label={ __( 'Message field', 'chatlume-ai-chatbot' ) }
						value={ theme.composer.style }
						onChange={ bind( set, 'composer.style' ) }
						options={ [
							{
								id: 'rounded',
								label: __( 'Rounded', 'chatlume-ai-chatbot' ),
							},
							{
								id: 'bar',
								label: __(
									'Full-width bar',
									'chatlume-ai-chatbot'
								),
							},
						] }
					/>
					<Segmented
						label={ __( 'Send button', 'chatlume-ai-chatbot' ) }
						value={ theme.composer.sendButton }
						onChange={ bind( set, 'composer.sendButton' ) }
						options={ [
							{
								id: 'icon',
								label: __( 'Icon', 'chatlume-ai-chatbot' ),
							},
							{
								id: 'text',
								label: __( 'Text', 'chatlume-ai-chatbot' ),
							},
						] }
					/>
				</>
			) }
		</>
	);
}

/**
 * Launcher: icon, shape, size, label, attention, teaser.
 *
 * @param props          Props.
 * @param props.theme
 * @param props.set
 * @param props.advanced
 */
export function LauncherSection( { theme, set, advanced }: SectionProps ) {
	const launcher = theme.launcher;
	const colors = theme.colors.light;
	const iconContrast = contrast(
		colors.launcherIcon,
		colors.launcherBackground
	);

	return (
		<>
			<Segmented
				label={ __( 'Button shows', 'chatlume-ai-chatbot' ) }
				value={ launcher.type }
				onChange={ bind( set, 'launcher.type' ) }
				options={ [
					{ id: 'icon', label: __( 'Icon', 'chatlume-ai-chatbot' ) },
					{
						id: 'avatar',
						label: __( 'Avatar', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'image',
						label: __( 'Image', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ launcher.type === 'icon' && (
				<div
					role="radiogroup"
					aria-label={ __( 'Icon', 'chatlume-ai-chatbot' ) }
					className="chatlume:grid chatlume:grid-cols-6 chatlume:gap-1.5"
				>
					{ LAUNCHER_ICONS.map( ( icon ) => (
						<button
							key={ icon }
							type="button"
							role="radio"
							aria-checked={ launcher.icon === icon }
							aria-label={ icon }
							title={ icon }
							className={ cn(
								'chatlume:flex chatlume:aspect-square chatlume:items-center chatlume:justify-center chatlume:rounded-control chatlume:border chatlume:transition-colors chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
								launcher.icon === icon
									? 'chatlume:border-accent chatlume:bg-accent-soft chatlume:text-accent'
									: 'chatlume:border-border chatlume:text-fg-muted chatlume:hover:text-fg'
							) }
							onClick={ () => set( 'launcher.icon', icon ) }
						>
							<svg
								viewBox="0 0 24 24"
								fill="none"
								stroke="currentColor"
								strokeWidth="2"
								strokeLinecap="round"
								strokeLinejoin="round"
								aria-hidden="true"
								className="chatlume:size-5"
							>
								<path d={ ICON_PATHS[ icon ] } />
							</svg>
						</button>
					) ) }
				</div>
			) }
			{ launcher.type === 'image' && (
				<ImageControl
					label={ __( 'Launcher image', 'chatlume-ai-chatbot' ) }
					value={ launcher.image }
					onChange={ bind( set, 'launcher.image' ) }
				/>
			) }
			<Segmented
				label={ __( 'Shape', 'chatlume-ai-chatbot' ) }
				value={ launcher.shape }
				onChange={ bind( set, 'launcher.shape' ) }
				options={ [
					{
						id: 'circle',
						label: __( 'Circle', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'square',
						label: __( 'Rounded square', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'pill',
						label: __( 'Pill with text', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			{ launcher.shape === 'pill' && (
				<TextControl
					label={ __( 'Button text', 'chatlume-ai-chatbot' ) }
					placeholder={ __( 'Chat with us', 'chatlume-ai-chatbot' ) }
					value={ launcher.label }
					maxLength={ 40 }
					onChange={ bind( set, 'launcher.label' ) }
				/>
			) }
			<Segmented
				label={ __( 'Size', 'chatlume-ai-chatbot' ) }
				value={ launcher.size }
				onChange={ bind( set, 'launcher.size' ) }
				options={ [
					{ id: 's', label: __( 'Small', 'chatlume-ai-chatbot' ) },
					{ id: 'm', label: __( 'Medium', 'chatlume-ai-chatbot' ) },
					{ id: 'l', label: __( 'Large', 'chatlume-ai-chatbot' ) },
				] }
			/>
			{ advanced && (
				<>
					<ColorField
						label={ __(
							'Launcher background',
							'chatlume-ai-chatbot'
						) }
						value={ colors.launcherBackground }
						onChange={ bind(
							set,
							'colors.light.launcherBackground'
						) }
					/>
					<ColorField
						label={ __( 'Icon color', 'chatlume-ai-chatbot' ) }
						value={ colors.launcherIcon }
						onChange={ bind( set, 'colors.light.launcherIcon' ) }
						warning={
							iconContrast < 3
								? __(
										'The icon is hard to see on this background.',
										'chatlume-ai-chatbot'
									)
								: undefined
						}
					/>
				</>
			) }
			<Segmented
				label={ __( 'Attention effect', 'chatlume-ai-chatbot' ) }
				value={ launcher.attention }
				onChange={ bind( set, 'launcher.attention' ) }
				options={ [
					{ id: 'none', label: __( 'None', 'chatlume-ai-chatbot' ) },
					{
						id: 'pulse',
						label: __( 'Pulse', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'bounce',
						label: __( 'Bounce', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'badge',
						label: __( 'Badge', 'chatlume-ai-chatbot' ),
					},
				] }
			/>
			<TextControl
				label={ __( 'Teaser bubble', 'chatlume-ai-chatbot' ) }
				hint={ __(
					'A short message next to the closed button, e.g. “Questions? Ask me!”. Leave empty for none.',
					'chatlume-ai-chatbot'
				) }
				value={ launcher.teaser }
				maxLength={ 140 }
				onChange={ bind( set, 'launcher.teaser' ) }
			/>
		</>
	);
}

/**
 * Motion: open animation and speed.
 *
 * @param props       Props.
 * @param props.theme
 * @param props.set
 */
export function MotionSection( { theme, set }: SectionProps ) {
	return (
		<>
			<Segmented
				label={ __( 'Opening animation', 'chatlume-ai-chatbot' ) }
				value={ theme.motion.open }
				onChange={ bind( set, 'motion.open' ) }
				options={ [
					{
						id: 'scale',
						label: __( 'Scale and fade', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'slide',
						label: __( 'Slide up', 'chatlume-ai-chatbot' ),
					},
					{ id: 'none', label: __( 'None', 'chatlume-ai-chatbot' ) },
				] }
			/>
			<Segmented
				label={ __( 'Speed', 'chatlume-ai-chatbot' ) }
				value={ theme.motion.speed }
				onChange={ bind( set, 'motion.speed' ) }
				options={ [
					{ id: 'fast', label: __( 'Fast', 'chatlume-ai-chatbot' ) },
					{
						id: 'normal',
						label: __( 'Normal', 'chatlume-ai-chatbot' ),
					},
					{ id: 'slow', label: __( 'Slow', 'chatlume-ai-chatbot' ) },
				] }
			/>
			<Toggle
				label={ __( 'Animate new messages', 'chatlume-ai-chatbot' ) }
				checked={ theme.messages.animate }
				onChange={ bind( set, 'messages.animate' ) }
			/>
			<p className="chatlume:text-xs chatlume:text-fg-muted">
				{ __(
					'Visitors who ask their device for reduced motion never see animations.',
					'chatlume-ai-chatbot'
				) }
			</p>
		</>
	);
}
