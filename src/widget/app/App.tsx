/**
 * The chat window.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { track } from '../lib/analytics';
import { isClosed } from '../lib/display';
import { parseRichText } from '../lib/markdown';
import { createPreviewChannel, type PreviewBatch } from '../lib/preview';
import type {
	BehaviorConfig,
	PreviewSamples,
	PreviewState,
	ThemeConfig,
	WidgetConfig,
	WidgetHost,
	WidgetStrings,
} from '../types';
import { Composer } from './Composer';
import { HandoffCard, LeadForm } from './Engagement';
import { Header } from './Header';
import { InlineNodes } from './Markdown';
import { Messages } from './Messages';
import { useChat } from './useChat';

export interface AppApi {
	send: ( text: string ) => void;
	focus: () => void;
	update: ( config: Partial< WidgetConfig > ) => void;
	setState: ( state: PreviewState | null ) => void;
}

/**
 * Whether a flag is set for this browser session.
 *
 * @param key Key.
 * @return Set.
 */
function readSession( key: string ): boolean {
	try {
		return window.sessionStorage.getItem( `chatlume:${ key }` ) === '1';
	} catch {
		return false;
	}
}

/**
 * Sets a flag for this browser session.
 *
 * @param key Key.
 */
function writeSession( key: string ): void {
	try {
		window.sessionStorage.setItem( `chatlume:${ key }`, '1' );
	} catch {
		// Storage unavailable: the flag lasts until the page reloads.
	}
}

const FOCUSABLE =
	'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Chat window.
 *
 * @param props          Props.
 * @param props.host     Widget host.
 * @param props.register Receives the imperative API.
 * @return Window.
 */
export function App( {
	host,
	register,
}: {
	host: WidgetHost;
	register: ( api: AppApi ) => void;
} ) {
	const [ config, setConfig ] = useState< WidgetConfig >( host.config );
	const [ open, setOpen ] = useState( host.isOpen() );
	const chat = useChat( host );
	const dialog = useRef< HTMLDivElement >( null );
	const input = useRef< HTMLTextAreaElement >( null );
	// Names of elements for the preview's hover outline (sent by the Customizer).
	const labels = useRef< Record< string, string > >( {} );

	const ui = config.theme.ui;
	const strings = config.strings;
	const inline = host.inline;
	const fullscreen = (): boolean =>
		!! host.mount.closest( '[data-fullscreen]' );
	const trapFocus = ! inline && ( ui.style === 'modal' || fullscreen() );
	const title =
		ui.brand.headerTitle || chat.behavior.bot_name || strings.conversation;
	const titleId = 'cl-title';

	useEffect( () => host.onToggle( setOpen ), [ host ] );

	// Outside business hours the chat shows the offline message; checked every minute.
	const [ closed, setClosed ] = useState(
		() => ! host.preview && isClosed( config.display )
	);
	useEffect( () => {
		if ( host.preview || ! config.display?.hours.enabled ) {
			return undefined;
		}
		const timer = window.setInterval(
			() => setClosed( isClosed( config.display ) ),
			60_000
		);
		return () => window.clearInterval( timer );
	}, [ host.preview, config.display ] );

	useEffect( () => {
		if ( dialog.current ) {
			dialog.current.inert = ! open;
		}
	}, [ open ] );

	const focus = useCallback( () => {
		window.requestAnimationFrame( () => {
			const coarse =
				typeof window.matchMedia === 'function' &&
				window.matchMedia( '(pointer: coarse)' ).matches;
			// On touch screens focusing the input would open the keyboard over the conversation.
			if ( coarse || ! input.current ) {
				dialog.current?.focus();
			} else {
				input.current.focus();
			}
		} );
	}, [] );

	// Lead form and "Talk to a person".
	const lead = chat.behavior.lead ?? null;
	const handoff = chat.behavior.handoff ?? null;
	const [ leadSaved, setLeadSaved ] = useState( false );
	const [ leadSkipped, setLeadSkipped ] = useState( () =>
		readSession( 'lead-skip' )
	);
	const [ previewLead, setPreviewLead ] = useState( false );
	const [ handoffOpen, setHandoffOpen ] = useState( false );
	const [ notice, setNotice ] = useState( '' );
	const privacyNotice = useMemo(
		() => parseRichText( chat.behavior.privacy_notice ?? '' ),
		[ chat.behavior.privacy_notice ]
	);
	const leadGiven = !! lead?.given || leadSaved;
	const visitorMessages = chat.messages.filter(
		( message ) => message.role === 'user'
	).length;
	// Before chatting: the form replaces the message box until it is filled in (or skipped).
	const leadGate =
		previewLead ||
		( !! lead &&
			lead.timing === 'before' &&
			! leadGiven &&
			! leadSkipped &&
			visitorMessages === 0 &&
			! closed );
	const leadLater =
		!! lead &&
		lead.timing === 'after' &&
		! leadGiven &&
		! leadSkipped &&
		visitorMessages >= lead.after &&
		! chat.busy;

	const skipLead = () => {
		writeSession( 'lead-skip' );
		setLeadSkipped( true );
		setPreviewLead( false );
	};

	const saveLead = async ( details: {
		name: string;
		email: string;
		consent: boolean;
	} ) => {
		if ( host.preview ) {
			setPreviewLead( false );
			setLeadSaved( true );
			return;
		}
		await chat.post( 'lead', {
			...details,
			page_url: window.location.href,
		} );
		setLeadSaved( true );
		setNotice( strings.leadThanks );
		track( config.analytics, 'lead_captured' );
	};

	const emailMe = () => {
		setNotice( strings.sending );
		chat.post( 'transcript', {} )
			.then( () => setNotice( strings.emailMeOk ) )
			.catch( ( error: unknown ) =>
				setNotice(
					error instanceof Error && error.message
						? error.message
						: strings.error
				)
			);
	};

	useEffect( () => {
		if ( ! notice ) {
			return undefined;
		}
		const timer = window.setTimeout( () => setNotice( '' ), 6000 );
		return () => window.clearTimeout( timer );
	}, [ notice ] );

	const setState = useCallback(
		( state: PreviewState | null ) => {
			if ( state === 'launcher' ) {
				host.close();
				return;
			}
			host.open();
			setPreviewLead( state === 'lead' );
			if ( state ) {
				chat.force( state );
			}
		},
		[ host, chat ]
	);

	useEffect( () => {
		register( {
			send: ( text ) => {
				chat.send( text );
			},
			focus,
			update: ( next ) =>
				setConfig( ( current ) => ( { ...current, ...next } ) ),
			setState,
		} );
	} );

	// Full-screen windows follow the visible viewport (mobile keyboards).
	useEffect( () => {
		const viewport = window.visualViewport;
		if ( ! open || ! viewport ) {
			return undefined;
		}
		const sync = () =>
			dialog.current?.style.setProperty(
				'--cl-vh',
				`${ viewport.height }px`
			);
		sync();
		viewport.addEventListener( 'resize', sync );
		return () => viewport.removeEventListener( 'resize', sync );
	}, [ open ] );

	// Preview mode: Customizer messages, highlight, and click to edit.
	useEffect( () => {
		if ( ! host.preview || host.site ) {
			return undefined;
		}
		const origin = config.preview?.origin || window.location.origin;
		const target = window.parent !== window ? window.parent : window;

		const highlight = ( part: unknown ) => {
			if ( typeof part !== 'string' || ! /^[a-z-]+$/.test( part ) ) {
				return;
			}
			host.root
				.querySelectorAll( `[part~="${ part }"]` )
				.forEach( ( node ) => {
					node.classList.remove( 'cl-highlight' );
					// Restarts the animation.
					void ( node as HTMLElement ).offsetWidth;
					node.classList.add( 'cl-highlight' );
					window.setTimeout(
						() => node.classList.remove( 'cl-highlight' ),
						1400
					);
				} );
		};

		const channel = createPreviewChannel(
			origin,
			( batch: PreviewBatch ) => {
				if ( batch.theme ) {
					host.setTheme( batch.theme as ThemeConfig );
				}
				if ( batch.direction === 'rtl' || batch.direction === 'ltr' ) {
					host.setDirection( batch.direction );
				}
				// Read when a reply is produced, so they apply to the next message.
				if ( batch.samples && typeof batch.samples === 'object' ) {
					host.config.preview = {
						...host.config.preview,
						samples: batch.samples as Partial< PreviewSamples >,
					};
				}
				if ( batch.labels && typeof batch.labels === 'object' ) {
					labels.current = Object.fromEntries(
						Object.entries(
							batch.labels as Record< string, unknown >
						).filter( ( [ , value ] ) => typeof value === 'string' )
					) as Record< string, string >;
				}
				if ( 'live' in batch ) {
					const live = batch.live as {
						url?: unknown;
						nonce?: unknown;
					} | null;
					host.config.preview = {
						...host.config.preview,
						live:
							live &&
							typeof live.url === 'string' &&
							typeof live.nonce === 'string'
								? { url: live.url, nonce: live.nonce }
								: null,
					};
				}
				if ( batch.strings ) {
					setConfig( ( current ) => ( {
						...current,
						strings: {
							...current.strings,
							...( batch.strings as WidgetStrings ),
						},
					} ) );
				}
				if ( batch.behavior ) {
					chat.setBehavior(
						batch.behavior as Partial< BehaviorConfig >
					);
				}
				if ( batch.state ) {
					setState( batch.state as PreviewState );
				}
				if ( batch.highlight ) {
					highlight( ( batch.highlight as { part?: unknown } ).part );
				}
			},
			target
		);

		const partOf = ( event: Event ): Element | null =>
			( event
				.composedPath()
				.find(
					( node ) =>
						node instanceof Element && node.hasAttribute( 'part' )
				) as Element | undefined ) ?? null;

		let hovered: Element | null = null;
		const onOver = ( event: Event ) => {
			const part = partOf( event );
			if ( part !== hovered ) {
				hovered?.classList.remove( 'cl-hover' );
				part?.classList.add( 'cl-hover' );
				const name = ( part?.getAttribute( 'part' ) ?? '' ).split(
					' '
				)[ 0 ];
				if ( part && labels.current[ name ] ) {
					part.setAttribute( 'title', labels.current[ name ] );
				}
				hovered = part;
			}
		};
		const onLeave = () => {
			hovered?.classList.remove( 'cl-hover' );
			hovered = null;
		};
		const onClick = ( event: Event ) => {
			const part = partOf( event );
			if ( part ) {
				channel.post( 'select', {
					part: ( part.getAttribute( 'part' ) ?? '' ).split(
						' '
					)[ 0 ],
				} );
			}
		};

		const root = host.root;
		root.addEventListener( 'mouseover', onOver );
		root.addEventListener( 'mouseleave', onLeave, true );
		root.addEventListener( 'click', onClick, true );
		channel.post( 'ready' );
		if ( config.preview?.state ) {
			setState( config.preview.state );
		}

		return () => {
			channel.dispose();
			root.removeEventListener( 'mouseover', onOver );
			root.removeEventListener( 'mouseleave', onLeave, true );
			root.removeEventListener( 'click', onClick, true );
		};
		// The channel lives for the whole preview.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const onKeyDown = ( event: KeyboardEvent ) => {
		if ( event.key === 'Escape' && ! inline ) {
			event.stopPropagation();
			host.close();
			return;
		}
		if ( event.key !== 'Tab' || ! trapFocus || ! dialog.current ) {
			return;
		}
		const items = Array.from(
			dialog.current.querySelectorAll< HTMLElement >( FOCUSABLE )
		).filter( ( node ) => node.offsetParent !== null );
		if ( ! items.length ) {
			return;
		}
		const active = host.root.activeElement;
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		if (
			event.shiftKey &&
			( active === first || active === dialog.current )
		) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && active === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	const background = ui.background;

	return (
		<>
			{ ! inline && ui.style === 'modal' && (
				<div
					className="cl-backdrop"
					data-open={ open ? '' : undefined }
					onClick={ () => host.close() }
					aria-hidden="true"
				/>
			) }
			<div
				ref={ dialog }
				className="cl-window"
				part="window"
				role={ inline ? 'region' : 'dialog' }
				aria-modal={ trapFocus ? true : undefined }
				aria-labelledby={ titleId }
				tabIndex={ -1 }
				data-open={ open ? '' : undefined }
				data-messages={ ui.messages.style }
				data-timestamps={ ui.messages.timestamps }
				data-animate={ ui.messages.animate ? '' : undefined }
				data-bg={ background.type }
				data-pattern={
					background.type === 'pattern'
						? background.pattern
						: undefined
				}
				data-glass={ background.glass ? '' : undefined }
				data-motion={ ui.motion }
				onKeyDown={ ( event ) =>
					onKeyDown( event as unknown as KeyboardEvent )
				}
			>
				<Header
					ui={ ui }
					strings={ strings }
					title={ title }
					titleId={ titleId }
					closable={ ! inline }
					onClose={ () => host.close() }
					closed={ closed }
					onNewChat={ () => {
						chat.reset();
						setHandoffOpen( false );
						focus();
					} }
					onHandoff={
						handoff ? () => setHandoffOpen( true ) : undefined
					}
					onEmailMe={
						chat.behavior.transcript &&
						leadGiven &&
						! host.preview &&
						chat.messages.length > 0
							? emailMe
							: undefined
					}
				/>
				<Messages
					chat={ chat }
					behavior={ chat.behavior }
					ui={ ui }
					strings={ strings }
					botName={ chat.behavior.bot_name || title }
					closed={ closed }
					hideStarters={ leadGate }
					footer={
						<>
							{ ( leadGate || leadLater ) && (
								<LeadForm
									config={ lead }
									strings={ strings }
									title={
										leadGate
											? strings.leadBefore
											: strings.leadAfter
									}
									skippable={
										! lead?.required || previewLead
									}
									askName={ lead?.ask_name !== false }
									consent
									onSubmit={ saveLead }
									onSkip={ skipLead }
								/>
							) }
							{ handoffOpen && handoff && (
								<HandoffCard
									config={ handoff }
									strings={ strings }
									leadGiven={ leadGiven }
									canEmail={
										chat.messages.length > 0 &&
										! host.preview
									}
									leadConfig={ lead }
									onLink={ ( type ) => {
										track( config.analytics, 'handoff', {
											channel: type,
										} );
										if ( chat.conversationId() ) {
											chat.post( 'handoff', {
												channel: type,
												page_url: window.location.href,
											} ).catch( () => undefined );
										}
									} }
									onLead={ saveLead }
									onEmailTeam={ () =>
										chat
											.post( 'handoff', {
												channel: 'email_team',
												page_url: window.location.href,
											} )
											.then( () =>
												track(
													config.analytics,
													'handoff',
													{ channel: 'email_team' }
												)
											)
									}
									onClose={ () => setHandoffOpen( false ) }
								/>
							) }
						</>
					}
				/>
				{ notice && (
					<p className="cl-notice" role="status">
						{ notice }
					</p>
				) }
				{ ! leadGate && (
					<Composer
						ref={ input }
						chat={ chat }
						ui={ ui }
						strings={ strings }
						closed={ closed }
						voice={ chat.behavior.voice_input !== false }
						maxLength={ chat.behavior.max_message_length || 1000 }
					/>
				) }
				{ privacyNotice.length > 0 && ! leadGate && (
					<p className="cl-privacy">
						<InlineNodes
							nodes={ privacyNotice }
							strings={ strings }
						/>
					</p>
				) }
				{ ui.brand.poweredBy && (
					<p className="cl-powered">{ strings.poweredBy }</p>
				) }
				<div className="cl-sr" aria-live="polite" aria-atomic="true">
					{ chat.announcement }
				</div>
			</div>
		</>
	);
}
