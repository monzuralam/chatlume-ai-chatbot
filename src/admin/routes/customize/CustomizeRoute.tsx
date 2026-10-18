/**
 * The widget Customizer: settings on the left, a live preview that never
 * needs saving on the right. Edits autosave as a private draft; visitors see
 * them only after Publish.
 */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	Download,
	ExternalLink,
	Eye,
	EyeOff,
	FileUp,
	MessageCircle,
	Monitor,
	Redo2,
	RotateCcw,
	Smartphone,
	Tablet,
	Undo2,
	Upload,
	Wrench,
} from 'lucide-react';
import {
	useCallback,
	useEffect,
	useMemo,
	useReducer,
	useRef,
	useState,
	type ReactElement,
} from 'react';
import { toast } from 'sonner';

import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Card } from '../../components/ui/card';
import { Input } from '../../components/ui/input';
import {
	Popover,
	PopoverContent,
	PopoverTrigger,
} from '../../components/ui/popover';
import { Select } from '../../components/ui/select';
import { Switch } from '../../components/ui/switch';
import { useSettings } from '../../hooks/useSettings';
import type { Settings } from '../../lib/settingsSchema';
import { useUnsavedChangesWarning } from '../../hooks/useUnsavedChangesWarning';
import type { ApiError } from '../../lib/api';
import {
	deletePreset,
	discardDraft,
	fetchAppearance,
	fetchPreviewToken,
	parseThemeFile,
	publishDraft,
	record,
	redo,
	restoreVersion,
	sameTheme,
	saveDraft,
	savePreset,
	themeFile,
	undo,
	type AppearanceState,
	type History,
} from '../../lib/appearance';
import { downloadFile } from '../../lib/conversations';
import { getBootstrap } from '../../lib/bootstrap';
import {
	fetchChatbotInfo,
	previewBehavior,
	previewSamples,
	type ChatbotInfo,
} from '../../lib/chatbot';
import { resetSection, setPath } from '../../lib/theme/edit';
import { resolveTheme } from '../../lib/theme/resolve';
import type { Theme } from '../../lib/theme/types';
import { cn } from '../../lib/utils';
import type { PreviewState } from '../../../widget/types';
import { ImageControl, Section, Segmented } from './controls';
import { MatchSite } from './MatchSite';
import { PresetPicker, VersionsMenu } from './PresetPicker';
import { PreviewStage, type Backdrop, type Device } from './PreviewStage';
import {
	BrandColor,
	BrandSection,
	ColorsSection,
	ConversationSection,
	DeveloperSection,
	LauncherSection,
	LayoutSection,
	MotionSection,
	ShapeSection,
	TypographySection,
	type SectionProps,
} from './sections';

const APPEARANCE_KEY = [ 'chatlume', 'appearance' ] as const;
const MODE_KEY = 'chatlume-customizer-mode';

/** What the preview shows (and outlines) while a section is being edited. */
const SECTION_CONTEXT: Record< string, { state: PreviewState; part: string } > =
	{
		colors: { state: 'conversation', part: 'window' },
		brand: { state: 'conversation', part: 'header' },
		typography: { state: 'conversation', part: 'message-bot' },
		layout: { state: 'conversation', part: 'window' },
		shape: { state: 'conversation', part: 'message-user' },
		conversation: { state: 'conversation', part: 'message-bot' },
		launcher: { state: 'launcher', part: 'launcher' },
		motion: { state: 'conversation', part: 'window' },
		developer: { state: 'conversation', part: 'window' },
	};

/** The section that edits an element clicked in the preview. */
const PART_SECTION: Record< string, string > = {
	launcher: 'launcher',
	teaser: 'launcher',
	window: 'layout',
	header: 'brand',
	'message-bot': 'conversation',
	'message-user': 'colors',
	composer: 'conversation',
	'source-card': 'colors',
	starters: 'conversation',
};

type Action =
	| { type: 'change'; theme: Theme; gesture?: string }
	| { type: 'undo' }
	| { type: 'redo' }
	| { type: 'load'; theme: Theme };

interface EditorState {
	history: History;
	gesture?: string;
	at: number;
}

function reducer( state: EditorState, action: Action ): EditorState {
	switch ( action.type ) {
		case 'change': {
			// One drag of a slider or color picker is one undo step.
			const merge =
				!! action.gesture &&
				action.gesture === state.gesture &&
				Date.now() - state.at < 1500;
			return {
				history: record( state.history, action.theme, merge ),
				gesture: action.gesture,
				at: Date.now(),
			};
		}
		case 'undo':
			return { history: undo( state.history ), at: 0 };
		case 'redo':
			return { history: redo( state.history ), at: 0 };
		case 'load':
			return {
				history: { past: [], present: action.theme, future: [] },
				at: 0,
			};
	}
}

function readMode(): boolean {
	try {
		return window.localStorage.getItem( MODE_KEY ) === 'advanced';
	} catch {
		return false;
	}
}

function previewStates(): Array< { id: PreviewState; label: string } > {
	return [
		{
			id: 'conversation',
			label: __( 'Conversation', 'chatlume-ai-chatbot' ),
		},
		{ id: 'empty', label: __( 'Empty chat', 'chatlume-ai-chatbot' ) },
		{
			id: 'launcher',
			label: __( 'Closed launcher', 'chatlume-ai-chatbot' ),
		},
		{
			id: 'streaming',
			label: __( 'Writing a reply', 'chatlume-ai-chatbot' ),
		},
		{ id: 'typing', label: __( 'Typing', 'chatlume-ai-chatbot' ) },
		{ id: 'error', label: __( 'Error', 'chatlume-ai-chatbot' ) },
		{ id: 'offline', label: __( 'Offline', 'chatlume-ai-chatbot' ) },
		{ id: 'lead', label: __( 'Lead form', 'chatlume-ai-chatbot' ) },
	];
}

function Customizer( {
	appearance,
	info,
	chatbot,
}: {
	appearance: AppearanceState;
	info: ChatbotInfo;
	chatbot: Settings[ 'chatbot' ];
} ) {
	const queryClient = useQueryClient();
	const [ editor, dispatch ] = useReducer( reducer, {
		history: { past: [], present: appearance.draft, future: [] },
		at: 0,
	} );
	const theme = editor.history.present;
	const [ server, setServer ] = useState( appearance );
	const saved = useRef< Theme >( appearance.draft );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ advanced, setAdvanced ] = useState( readMode );
	const [ open, setOpen ] = useState< string[] >( [ 'colors', 'brand' ] );
	const [ state, setState ] = useState< PreviewState >( 'conversation' );
	const [ previewMode, setPreviewMode ] = useState<
		'theme' | 'light' | 'dark'
	>( 'theme' );
	const [ rtl, setRtl ] = useState( false );
	const [ showPreview, setShowPreview ] = useState( false );
	const [ confirm, setConfirm ] = useState< 'discard' | 'defaults' | null >(
		null
	);
	const bootstrap = getBootstrap();
	const [ device, setDevice ] = useState< Device >( 'desktop' );
	const [ backdrop, setBackdrop ] = useState< Backdrop >( 'site' );
	const [ backdropColor, setBackdropColor ] = useState( '#e8ecf3' );
	const [ pageUrl, setPageUrl ] = useState( bootstrap.siteUrl );
	const [ pageInput, setPageInput ] = useState( bootstrap.siteUrl );
	const [ compare, setCompare ] = useState( false );
	const [ liveReplies, setLiveReplies ] = useState( false );
	const [ autoSwitch, setAutoSwitch ] = useState( true );
	const [ highlight, setHighlight ] = useState< {
		part: string;
		at: number;
	} | null >( null );
	const context = useRef( '' );
	const importInput = useRef< HTMLInputElement >( null );

	const dirty = ! sameTheme( theme, saved.current );
	useUnsavedChangesWarning( dirty || saving );

	const apply = useCallback(
		( next: AppearanceState ) => {
			setServer( next );
			queryClient.setQueryData( APPEARANCE_KEY, next );
		},
		[ queryClient ]
	);

	const persist = useCallback(
		async ( next: Theme ) => {
			setSaving( true );
			try {
				const result = await saveDraft( next );
				saved.current = next;
				setSaveError( false );
				apply( result );
				return true;
			} catch ( error ) {
				setSaveError( true );
				toast.error( ( error as ApiError ).message );
				return false;
			} finally {
				setSaving( false );
			}
		},
		[ apply ]
	);

	// Autosave the draft shortly after the last change.
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const timer = window.setTimeout( () => persist( theme ), 800 );
		return () => window.clearTimeout( timer );
	}, [ theme, dirty, persist ] );

	const change = ( next: Theme, gesture?: string ) =>
		dispatch( { type: 'change', theme: next, gesture } );

	const sectionProps: SectionProps = {
		theme,
		advanced,
		set: ( path, value, gesture ) =>
			change( setPath( theme, path, value ), gesture ),
		replace: change,
		fonts: server.fonts,
		onServer: apply,
	};

	// Undo, redo, and save shortcuts (text fields keep their own undo).
	useEffect( () => {
		const onKeyDown = ( event: KeyboardEvent ) => {
			if ( ! ( event.metaKey || event.ctrlKey ) ) {
				return;
			}
			const target = event.target as HTMLElement | null;
			const typing =
				!! target &&
				( target.tagName === 'TEXTAREA' ||
					( target.tagName === 'INPUT' &&
						[ 'text', 'search', 'url', 'email', '' ].includes(
							( target as HTMLInputElement ).type
						) ) );
			const key = event.key.toLowerCase();
			if ( key === 's' ) {
				event.preventDefault();
				persist( theme );
			} else if ( ! typing && key === 'z' ) {
				event.preventDefault();
				dispatch( { type: event.shiftKey ? 'redo' : 'undo' } );
			} else if ( ! typing && key === 'y' ) {
				event.preventDefault();
				dispatch( { type: 'redo' } );
			}
		};
		document.addEventListener( 'keydown', onKeyDown );
		return () => document.removeEventListener( 'keydown', onKeyDown );
	}, [ persist, theme ] );

	const run = async ( task: () => Promise< void > ) => {
		setBusy( true );
		try {
			await task();
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	const publish = () =>
		run( async () => {
			if ( dirty && ! ( await persist( theme ) ) ) {
				return;
			}
			apply( await publishDraft() );
			toast.success(
				__(
					'Published. Visitors now see the new look.',
					'chatlume-ai-chatbot'
				)
			);
		} );

	const discard = () =>
		run( async () => {
			const result = await discardDraft();
			saved.current = result.draft;
			dispatch( { type: 'load', theme: result.draft } );
			apply( result );
			toast.success(
				__( 'Unpublished changes discarded.', 'chatlume-ai-chatbot' )
			);
		} );

	const restore = ( id: string ) =>
		run( async () => {
			const result = await restoreVersion( id );
			saved.current = result.draft;
			change( result.draft );
			apply( result );
			toast.success(
				__(
					'Version restored to your draft. Publish to make it live.',
					'chatlume-ai-chatbot'
				)
			);
		} );

	const toggleAdvanced = ( next: boolean ) => {
		setAdvanced( next );
		try {
			window.localStorage.setItem(
				MODE_KEY,
				next ? 'advanced' : 'simple'
			);
		} catch {
			// Storage is optional.
		}
	};

	// "Compare" shows what visitors see now.
	const config = useMemo( () => {
		const resolved = resolveTheme(
			compare ? server.published : theme,
			server.fonts.options
		);
		return previewMode === 'theme'
			? resolved
			: { ...resolved, ui: { ...resolved.ui, mode: previewMode } };
	}, [
		theme,
		compare,
		server.published,
		previewMode,
		server.fonts.options,
	] );

	const live = useMemo(
		() =>
			liveReplies && bootstrap.widget
				? {
						url: `${ bootstrap.widget.rest }playground/chat`,
						nonce: bootstrap.restNonce,
					}
				: null,
		[ liveReplies, bootstrap.widget, bootstrap.restNonce ]
	);

	const partLabels = useMemo(
		() => ( {
			launcher: __( 'Launcher – click to edit', 'chatlume-ai-chatbot' ),
			teaser: __( 'Teaser – click to edit', 'chatlume-ai-chatbot' ),
			window: __( 'Window – click to edit', 'chatlume-ai-chatbot' ),
			header: __( 'Header – click to edit', 'chatlume-ai-chatbot' ),
			'message-bot': __(
				'Bot message – click to edit',
				'chatlume-ai-chatbot'
			),
			'message-user': __(
				'Visitor message – click to edit',
				'chatlume-ai-chatbot'
			),
			composer: __(
				'Message field – click to edit',
				'chatlume-ai-chatbot'
			),
			'source-card': __(
				'Source link – click to edit',
				'chatlume-ai-chatbot'
			),
			starters: __(
				'Starter questions – click to edit',
				'chatlume-ai-chatbot'
			),
		} ),
		[]
	);

	// Context-aware preview: show and outline what the section edits.
	const enterSection = ( id: string ) => {
		if ( ! autoSwitch || context.current === id ) {
			return;
		}
		context.current = id;
		const target = SECTION_CONTEXT[ id ];
		if ( target ) {
			setState( target.state );
			setHighlight( { part: target.part, at: Date.now() } );
		}
	};

	// Click to edit: open the section for the clicked element.
	const selectPart = useCallback(
		( part: string ) => {
			const id = PART_SECTION[ part ];
			if ( ! id ) {
				return;
			}
			if ( ! advanced ) {
				toggleAdvanced( true );
			}
			setOpen( ( current ) =>
				current.includes( id ) ? current : [ ...current, id ]
			);
			window.requestAnimationFrame( () => {
				const element = document.querySelector< HTMLElement >(
					`#chatlume-admin [data-section="${ id }"]`
				);
				element?.scrollIntoView( {
					block: 'start',
					behavior: 'smooth',
				} );
				element
					?.querySelector< HTMLElement >(
						'input, select, textarea, [role="radio"]'
					)
					?.focus( { preventScroll: true } );
			} );
		},

		[ advanced ]
	);

	const showPage = () => {
		try {
			const next = new URL( pageInput, bootstrap.siteUrl );
			if ( next.origin !== new URL( bootstrap.siteUrl ).origin ) {
				throw new Error( 'origin' );
			}
			setPageUrl( next.href );
			setPageInput( next.href );
		} catch {
			toast.error(
				__(
					'Enter an address on this site, for example /contact/.',
					'chatlume-ai-chatbot'
				)
			);
		}
	};

	const previewOnSite = () =>
		run( async () => {
			if ( dirty && ! ( await persist( theme ) ) ) {
				return;
			}
			const token = await fetchPreviewToken( 'site' );
			window.open(
				addQueryArgs( pageUrl, { [ token.param ]: token.token } ),
				'_blank',
				'noopener'
			);
		} );

	const exportTheme = () =>
		downloadFile(
			themeFile( theme ),
			`chatlume-theme-${ new Date().toISOString().slice( 0, 10 ) }.json`,
			'application/json'
		);

	const importTheme = async ( file: File ) => {
		let imported: Theme;
		try {
			imported = parseThemeFile( await file.text() );
		} catch {
			toast.error(
				__(
					'This file is not a Chatlume theme. Export one from the Customizer first.',
					'chatlume-ai-chatbot'
				)
			);
			return;
		}
		run( async () => {
			// The server validates and clamps every value.
			const result = await saveDraft( imported );
			saved.current = result.draft;
			change( result.draft );
			apply( result );
			toast.success(
				__(
					'Theme imported into your draft. Publish to make it live.',
					'chatlume-ai-chatbot'
				)
			);
		} );
	};

	const savePresetAs = async ( name: string ) => {
		try {
			apply( await savePreset( name, theme ) );
			toast.success( __( 'Preset saved.', 'chatlume-ai-chatbot' ) );
			return true;
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
			return false;
		}
	};

	const removePreset = ( id: string ) =>
		run( async () => {
			apply( await deletePreset( id ) );
		} );

	const behavior = useMemo(
		() => previewBehavior( chatbot, info.defaults ),
		[ chatbot, info.defaults ]
	);

	const samples = useMemo(
		() => previewSamples( true, getBootstrap().siteUrl ),
		[]
	);

	const hasChanges = dirty || server.has_changes;
	const status = ( () => {
		if ( saving ) {
			return __( 'Saving draft…', 'chatlume-ai-chatbot' );
		}
		if ( saveError ) {
			return __( 'Draft not saved', 'chatlume-ai-chatbot' );
		}
		if ( dirty ) {
			return __( 'Editing…', 'chatlume-ai-chatbot' );
		}
		return hasChanges
			? __( 'Draft saved', 'chatlume-ai-chatbot' )
			: __( 'Up to date', 'chatlume-ai-chatbot' );
	} )();

	const section = (
		id: string,
		title: string,
		Body: ( props: SectionProps ) => ReactElement
	) => (
		<Section
			key={ id }
			id={ id }
			title={ title }
			open={ open.includes( id ) }
			onToggle={ () =>
				setOpen( ( current ) =>
					current.includes( id )
						? current.filter( ( item ) => item !== id )
						: [ ...current, id ]
				)
			}
			onReset={ () =>
				change( resetSection( theme, appearance.defaults, id ) )
			}
			onFocus={ () => enterSection( id ) }
		>
			<Body { ...sectionProps } />
		</Section>
	);

	return (
		<div className="chatlume:-mt-2">
			<div className="chatlume:mb-4 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3">
				<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2">
					<h1 className="chatlume:me-2 chatlume:text-2xl chatlume:font-semibold chatlume:tracking-tight chatlume:text-fg">
						{ __( 'Customize', 'chatlume-ai-chatbot' ) }
					</h1>
					<PresetPicker
						theme={ theme }
						onApply={ change }
						saved={ server.presets }
						onSave={ savePresetAs }
						onDelete={ removePreset }
					/>
					<MatchSite theme={ theme } onApply={ change } />
					<Button
						type="button"
						size="icon"
						variant="ghost"
						disabled={ ! editor.history.past.length }
						aria-label={ __( 'Undo', 'chatlume-ai-chatbot' ) }
						title={ __( 'Undo (Ctrl+Z)', 'chatlume-ai-chatbot' ) }
						onClick={ () => dispatch( { type: 'undo' } ) }
					>
						<Undo2 aria-hidden="true" />
					</Button>
					<Button
						type="button"
						size="icon"
						variant="ghost"
						disabled={ ! editor.history.future.length }
						aria-label={ __( 'Redo', 'chatlume-ai-chatbot' ) }
						title={ __(
							'Redo (Shift+Ctrl+Z)',
							'chatlume-ai-chatbot'
						) }
						onClick={ () => dispatch( { type: 'redo' } ) }
					>
						<Redo2 aria-hidden="true" />
					</Button>
					<div className="chatlume:w-44">
						<Segmented
							label={ __( 'Options', 'chatlume-ai-chatbot' ) }
							hideLabel
							value={ advanced ? 'advanced' : 'simple' }
							onChange={ ( value ) =>
								toggleAdvanced( value === 'advanced' )
							}
							options={ [
								{
									id: 'simple',
									label: __(
										'Simple',
										'chatlume-ai-chatbot'
									),
								},
								{
									id: 'advanced',
									label: __(
										'Advanced',
										'chatlume-ai-chatbot'
									),
								},
							] }
						/>
					</div>
				</div>
				<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2">
					<span
						className="chatlume:text-[13px] chatlume:text-fg-muted"
						role="status"
					>
						{ status }
					</span>
					{ hasChanges && (
						<Badge tone="warning">
							{ __(
								'Unpublished changes',
								'chatlume-ai-chatbot'
							) }
						</Badge>
					) }
					<VersionsMenu
						versions={ server.versions }
						busy={ busy }
						onRestore={ restore }
					/>
					<Popover>
						<PopoverTrigger asChild>
							<Button
								type="button"
								size="icon"
								variant="ghost"
								aria-label={ __(
									'Theme tools',
									'chatlume-ai-chatbot'
								) }
								title={ __(
									'Theme tools',
									'chatlume-ai-chatbot'
								) }
							>
								<Wrench aria-hidden="true" />
							</Button>
						</PopoverTrigger>
						<PopoverContent
							align="end"
							className="chatlume:flex chatlume:w-64 chatlume:flex-col chatlume:gap-1 chatlume:p-2"
						>
							<Button
								type="button"
								size="sm"
								variant="ghost"
								className="chatlume:justify-start"
								onClick={ exportTheme }
							>
								<Download aria-hidden="true" />
								{ __(
									'Export theme (JSON)',
									'chatlume-ai-chatbot'
								) }
							</Button>
							<Button
								type="button"
								size="sm"
								variant="ghost"
								className="chatlume:justify-start"
								disabled={ busy }
								onClick={ () => importInput.current?.click() }
							>
								<FileUp aria-hidden="true" />
								{ __( 'Import theme…', 'chatlume-ai-chatbot' ) }
							</Button>
							<input
								ref={ importInput }
								type="file"
								accept="application/json,.json"
								className="chatlume:hidden"
								aria-label={ __(
									'Theme file',
									'chatlume-ai-chatbot'
								) }
								onChange={ ( event ) => {
									const file = event.target.files?.[ 0 ];
									event.target.value = '';
									if ( file ) {
										importTheme( file );
									}
								} }
							/>
						</PopoverContent>
					</Popover>
					<Button
						type="button"
						size="sm"
						variant="ghost"
						disabled={ busy }
						onClick={ previewOnSite }
					>
						<ExternalLink aria-hidden="true" />
						{ __( 'Preview on my site', 'chatlume-ai-chatbot' ) }
					</Button>
					<Button
						type="button"
						size="sm"
						variant="ghost"
						disabled={ busy }
						onClick={ () =>
							setConfirm( hasChanges ? 'discard' : 'defaults' )
						}
					>
						<RotateCcw aria-hidden="true" />
						{ hasChanges
							? __( 'Discard changes', 'chatlume-ai-chatbot' )
							: __( 'Reset', 'chatlume-ai-chatbot' ) }
					</Button>
					<Button
						type="button"
						size="sm"
						variant="primary"
						disabled={ busy || ! hasChanges }
						onClick={ publish }
					>
						<Upload aria-hidden="true" />
						{ __( 'Publish', 'chatlume-ai-chatbot' ) }
					</Button>
					<Button
						type="button"
						size="sm"
						className="chatlume:lg:hidden"
						onClick={ () =>
							setShowPreview( ( current ) => ! current )
						}
					>
						{ showPreview ? (
							<EyeOff aria-hidden="true" />
						) : (
							<Eye aria-hidden="true" />
						) }
						{ showPreview
							? __( 'Hide preview', 'chatlume-ai-chatbot' )
							: __( 'Show preview', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
			</div>

			<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:lg:grid-cols-[320px_minmax(0,1fr)] chatlume:xl:grid-cols-[380px_minmax(0,1fr)]">
				<Card
					className="chatlume:overflow-hidden chatlume:lg:sticky chatlume:lg:top-12 chatlume:lg:max-h-[calc(100vh-8rem)] chatlume:lg:overflow-y-auto"
					aria-label={ __(
						'Appearance settings',
						'chatlume-ai-chatbot'
					) }
				>
					{ advanced ? (
						[
							section(
								'colors',
								__( 'Colors', 'chatlume-ai-chatbot' ),
								ColorsSection
							),
							section(
								'brand',
								__( 'Brand', 'chatlume-ai-chatbot' ),
								BrandSection
							),
							section(
								'typography',
								__( 'Typography', 'chatlume-ai-chatbot' ),
								TypographySection
							),
							section(
								'layout',
								__( 'Layout', 'chatlume-ai-chatbot' ),
								LayoutSection
							),
							section(
								'shape',
								__( 'Shape', 'chatlume-ai-chatbot' ),
								ShapeSection
							),
							section(
								'conversation',
								__(
									'Messages and background',
									'chatlume-ai-chatbot'
								),
								ConversationSection
							),
							section(
								'launcher',
								__( 'Launcher', 'chatlume-ai-chatbot' ),
								LauncherSection
							),
							section(
								'motion',
								__( 'Motion', 'chatlume-ai-chatbot' ),
								MotionSection
							),
							section(
								'developer',
								__(
									'Developer styling',
									'chatlume-ai-chatbot'
								),
								DeveloperSection
							),
						]
					) : (
						<div className="chatlume:flex chatlume:flex-col chatlume:gap-5 chatlume:p-4">
							<BrandColor theme={ theme } replace={ change } />
							<ImageControl
								label={ __( 'Avatar', 'chatlume-ai-chatbot' ) }
								hint={ __(
									'Leave empty to show the bot’s initials.',
									'chatlume-ai-chatbot'
								) }
								value={
									theme.brand.avatarType === 'image'
										? theme.brand.avatarUrl
										: ''
								}
								onChange={ ( url ) =>
									change( {
										...theme,
										brand: {
											...theme.brand,
											avatarUrl: url,
											avatarType: url
												? 'image'
												: 'initials',
										},
									} )
								}
							/>
							<ImageControl
								label={ __(
									'Company logo',
									'chatlume-ai-chatbot'
								) }
								value={ theme.brand.logoUrl }
								onChange={ ( url ) =>
									change( {
										...theme,
										brand: {
											...theme.brand,
											logoUrl: url,
											showLogo: url !== '',
										},
									} )
								}
							/>
							<TypographySection { ...sectionProps } />
							<Segmented
								label={ __(
									'Position',
									'chatlume-ai-chatbot'
								) }
								value={ theme.layout.position }
								onChange={ ( value ) =>
									sectionProps.set( 'layout.position', value )
								}
								options={ [
									{
										id: 'left',
										label: __(
											'Bottom left',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'right',
										label: __(
											'Bottom right',
											'chatlume-ai-chatbot'
										),
									},
								] }
							/>
							<p className="chatlume:text-xs chatlume:text-fg-muted">
								{ __(
									'Switch to Advanced for every option: colors one by one, shapes, layout, launcher, and motion.',
									'chatlume-ai-chatbot'
								) }
							</p>
						</div>
					) }
				</Card>

				<div
					className={ cn(
						'chatlume:flex-col chatlume:gap-3 chatlume:lg:sticky chatlume:lg:top-12 chatlume:lg:flex chatlume:lg:h-[calc(100vh-8rem)] chatlume:lg:min-h-[640px]',
						showPreview
							? 'chatlume:fixed chatlume:inset-x-3 chatlume:bottom-3 chatlume:top-24 chatlume:z-[100000] chatlume:flex chatlume:rounded-card chatlume:bg-surface chatlume:p-3 chatlume:shadow-card chatlume:lg:static chatlume:lg:z-auto chatlume:lg:bg-transparent chatlume:lg:p-0 chatlume:lg:shadow-none'
							: 'chatlume:hidden'
					) }
				>
					<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2">
						<div className="chatlume:w-48">
							<Select
								aria-label={ __(
									'Preview state',
									'chatlume-ai-chatbot'
								) }
								className="chatlume:h-8 chatlume:text-[13px]"
								value={ state }
								onChange={ ( event ) =>
									setState(
										event.target.value as PreviewState
									)
								}
							>
								{ previewStates().map( ( option ) => (
									<option
										key={ option.id }
										value={ option.id }
									>
										{ option.label }
									</option>
								) ) }
							</Select>
						</div>
						<div className="chatlume:w-56">
							<Segmented
								label={ __(
									'Preview colors',
									'chatlume-ai-chatbot'
								) }
								hideLabel
								value={ previewMode }
								onChange={ setPreviewMode }
								options={ [
									{
										id: 'theme',
										label: __(
											'As set',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'light',
										label: __(
											'Light',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'dark',
										label: __(
											'Dark',
											'chatlume-ai-chatbot'
										),
									},
								] }
							/>
						</div>
						<Button
							type="button"
							size="sm"
							variant={ rtl ? 'primary' : 'secondary' }
							aria-pressed={ rtl }
							onClick={ () => setRtl( ( current ) => ! current ) }
						>
							{ __( 'Right to left', 'chatlume-ai-chatbot' ) }
						</Button>
						<Button
							type="button"
							size="sm"
							variant="ghost"
							onClick={ () =>
								setState(
									state === 'launcher'
										? 'conversation'
										: 'launcher'
								)
							}
						>
							<MessageCircle aria-hidden="true" />
							{ state === 'launcher'
								? __( 'Open the chat', 'chatlume-ai-chatbot' )
								: __( 'Show launcher', 'chatlume-ai-chatbot' ) }
						</Button>
					</div>
					<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-2">
						<div className="chatlume:w-44">
							<Segmented
								label={ __( 'Device', 'chatlume-ai-chatbot' ) }
								hideLabel
								value={ device }
								onChange={ setDevice }
								options={ [
									{
										id: 'desktop',
										label: __(
											'Desktop',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'tablet',
										label: __(
											'Tablet',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'mobile',
										label: __(
											'Phone',
											'chatlume-ai-chatbot'
										),
									},
								] }
							/>
						</div>
						<span
							aria-hidden="true"
							className="chatlume:text-fg-muted"
						>
							{ device === 'desktop' && (
								<Monitor className="chatlume:size-4" />
							) }
							{ device === 'tablet' && (
								<Tablet className="chatlume:size-4" />
							) }
							{ device === 'mobile' && (
								<Smartphone className="chatlume:size-4" />
							) }
						</span>
						<div className="chatlume:w-40">
							<Select
								aria-label={ __(
									'Backdrop',
									'chatlume-ai-chatbot'
								) }
								className="chatlume:h-8 chatlume:text-[13px]"
								value={ backdrop }
								onChange={ ( event ) =>
									setBackdrop(
										event.target.value as Backdrop
									)
								}
							>
								<option value="site">
									{ __( 'Your site', 'chatlume-ai-chatbot' ) }
								</option>
								<option value="light">
									{ __(
										'Light background',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="dark">
									{ __(
										'Dark background',
										'chatlume-ai-chatbot'
									) }
								</option>
								<option value="pattern">
									{ __( 'Pattern', 'chatlume-ai-chatbot' ) }
								</option>
								<option value="custom">
									{ __(
										'Custom color',
										'chatlume-ai-chatbot'
									) }
								</option>
							</Select>
						</div>
						{ backdrop === 'custom' && (
							<input
								type="color"
								value={ backdropColor }
								aria-label={ __(
									'Backdrop color',
									'chatlume-ai-chatbot'
								) }
								className="chatlume:size-8 chatlume:cursor-pointer chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-0.5"
								onChange={ ( event ) =>
									setBackdropColor( event.target.value )
								}
							/>
						) }
						{ backdrop === 'site' && (
							<form
								className="chatlume:flex chatlume:min-w-48 chatlume:flex-1 chatlume:gap-1"
								onSubmit={ ( event ) => {
									event.preventDefault();
									showPage();
								} }
							>
								<Input
									value={ pageInput }
									aria-label={ __(
										'Page to preview on',
										'chatlume-ai-chatbot'
									) }
									className="chatlume:h-8 chatlume:text-[13px]"
									onChange={ ( event ) =>
										setPageInput( event.target.value )
									}
								/>
								<Button type="submit" size="sm">
									{ __( 'Go', 'chatlume-ai-chatbot' ) }
								</Button>
							</form>
						) }
					</div>
					<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-x-4 chatlume:gap-y-2">
						<Button
							type="button"
							size="sm"
							variant={ compare ? 'primary' : 'secondary' }
							aria-pressed={ compare }
							title={ __(
								'Show what visitors see now',
								'chatlume-ai-chatbot'
							) }
							onClick={ () =>
								setCompare( ( current ) => ! current )
							}
						>
							{ compare
								? __(
										'Showing published',
										'chatlume-ai-chatbot'
									)
								: __(
										'Compare with published',
										'chatlume-ai-chatbot'
									) }
						</Button>
						<div className="chatlume:w-52">
							<Segmented
								label={ __(
									'Preview replies',
									'chatlume-ai-chatbot'
								) }
								hideLabel
								value={ liveReplies ? 'live' : 'samples' }
								onChange={ ( value ) =>
									setLiveReplies( value === 'live' )
								}
								options={ [
									{
										id: 'samples',
										label: __(
											'Sample replies',
											'chatlume-ai-chatbot'
										),
									},
									{
										id: 'live',
										label: __(
											'Live AI replies',
											'chatlume-ai-chatbot'
										),
									},
								] }
							/>
						</div>
						<span className="chatlume:flex chatlume:items-center chatlume:gap-2">
							<Switch
								id="chatlume-auto-switch"
								checked={ autoSwitch }
								onCheckedChange={ setAutoSwitch }
							/>
							<label
								htmlFor="chatlume-auto-switch"
								className="chatlume:text-[13px] chatlume:text-fg"
							>
								{ __(
									'Auto-switch preview',
									'chatlume-ai-chatbot'
								) }
							</label>
						</span>
					</div>
					{ liveReplies && (
						<p className="chatlume:text-xs chatlume:text-fg-muted">
							{ __(
								'Messages you type in the preview get real answers from your bot (test chats: not in Conversations or statistics, but AI costs apply).',
								'chatlume-ai-chatbot'
							) }
						</p>
					) }
					<PreviewStage
						inputs={ {
							behavior,
							samples,
							state,
							theme: config,
							direction: rtl ? 'rtl' : 'ltr',
							live,
							highlight,
							onSelect: selectPart,
							labels: partLabels,
						} }
						device={ device }
						backdrop={ backdrop }
						color={ backdropColor }
						url={ pageUrl }
					/>
				</div>
			</div>

			<ConfirmDialog
				open={ confirm === 'discard' }
				title={ __(
					'Discard unpublished changes?',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Your draft goes back to what visitors see now.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Discard changes', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Keep editing', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					setConfirm( null );
					discard();
				} }
				onCancel={ () => setConfirm( null ) }
			/>
			<ConfirmDialog
				open={ confirm === 'defaults' }
				title={ __(
					'Reset everything to the defaults?',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'Every appearance option returns to Chatlume’s default look in your draft. You can undo this, and nothing changes for visitors until you publish.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Reset', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					setConfirm( null );
					change( appearance.defaults );
				} }
				onCancel={ () => setConfirm( null ) }
			/>
		</div>
	);
}

/**
 * The Customize page.
 */
export default function CustomizeRoute() {
	const appearance = useQuery< AppearanceState, ApiError >( {
		queryKey: APPEARANCE_KEY,
		queryFn: fetchAppearance,
		staleTime: Infinity,
	} );
	const info = useQuery< ChatbotInfo, ApiError >( {
		queryKey: [ 'chatlume', 'chatbot-info' ],
		queryFn: fetchChatbotInfo,
	} );
	const settings = useSettings();

	if ( appearance.isError || info.isError || settings.isError ) {
		return (
			<div
				role="alert"
				className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
			>
				<span>
					{
						( appearance.error ?? info.error ?? settings.error )
							?.message
					}
				</span>
				<Button
					size="sm"
					onClick={ () => {
						appearance.refetch();
						info.refetch();
						settings.refetch();
					} }
				>
					{ __( 'Try again', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		);
	}

	if ( ! appearance.data || ! info.data || ! settings.data ) {
		return <PageSkeleton />;
	}

	return (
		<Customizer
			appearance={ appearance.data }
			info={ info.data }
			chatbot={ settings.data.chatbot }
		/>
	);
}
