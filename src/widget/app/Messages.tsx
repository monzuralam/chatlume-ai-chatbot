/**
 * The conversation: welcome, starters and quick actions, messages.
 */
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

import { markdownText, parseRichText } from '../lib/markdown';
import {
	canSpeak,
	onSpeaking,
	speak,
	speakingId,
	stopSpeaking,
} from '../lib/voice';
import type {
	BehaviorConfig,
	ChatMessage,
	QuickAction,
	Source,
	ThemeUi,
	WidgetStrings,
} from '../types';
import { Avatar } from './Avatar';
import { copyText } from './copy';
import { Icon, type IconName } from './icons';
import { InlineNodes, Markdown } from './Markdown';
import type { Chat } from './useChat';

const timeFormat = ( () => {
	try {
		return new Intl.DateTimeFormat(
			document.documentElement.lang || undefined,
			{
				hour: 'numeric',
				minute: '2-digit',
			}
		);
	} catch {
		return new Intl.DateTimeFormat( undefined, {
			hour: 'numeric',
			minute: '2-digit',
		} );
	}
} )();

/**
 * Host name of a URL.
 *
 * @param url URL.
 * @return Host.
 */
function domain( url: string ): string {
	try {
		return new URL( url ).hostname.replace( /^www\./, '' );
	} catch {
		return '';
	}
}

/**
 * Source link cards.
 *
 * @param props         Props.
 * @param props.sources Sources.
 * @param props.strings Widget strings.
 * @return Cards.
 */
function SourceCards( {
	sources,
	strings,
}: {
	sources: Source[];
	strings: WidgetStrings;
} ) {
	const safe = sources
		.filter( ( source ) => /^https?:\/\//i.test( source.url ) )
		.slice( 0, 3 );
	if ( ! safe.length ) {
		return null;
	}
	return (
		<ul className="cl-sources" aria-label={ strings.sources }>
			{ safe.map( ( source ) => (
				<li key={ source.url }>
					<a
						className="cl-source"
						part="source-card"
						href={ source.url }
						target="_blank"
						rel="noopener noreferrer"
					>
						<span className="cl-source-title">
							{ source.title || source.url }
						</span>
						<span className="cl-source-domain">
							<Icon name="external" />
							{ domain( source.url ) }
						</span>
						<span className="cl-sr"> { strings.externalLink }</span>
					</a>
				</li>
			) ) }
		</ul>
	);
}

/**
 * Small icon button.
 *
 * @param props         Props.
 * @param props.icon    Icon.
 * @param props.label   Accessible label.
 * @param props.onClick Click handler.
 * @param props.pressed Toggle state.
 * @return Button.
 */
function ActionButton( {
	icon,
	label,
	onClick,
	pressed,
}: {
	icon: IconName;
	label: string;
	onClick: () => void;
	pressed?: boolean;
} ) {
	return (
		<button
			type="button"
			className="cl-action"
			aria-label={ label }
			title={ label }
			aria-pressed={ pressed === undefined ? undefined : pressed }
			onClick={ onClick }
		>
			<Icon name={ icon } />
		</button>
	);
}

/**
 * One message.
 *
 * @param props         Props.
 * @param props.message Message.
 * @param props.chat    Chat actions.
 * @param props.ui      Theme options.
 * @param props.strings Widget strings.
 * @param props.botName Bot name.
 * @return Message.
 */
function MessageItem( {
	message,
	chat,
	ui,
	strings,
	botName,
}: {
	message: ChatMessage;
	chat: Chat;
	ui: ThemeUi;
	strings: WidgetStrings;
	botName: string;
} ) {
	const [ copied, setCopied ] = useState( false );
	const [ thanked, setThanked ] = useState( false );
	const [ reading, setReading ] = useState( speakingId() === message.id );
	const bot = message.role === 'bot';
	const readAloud = bot && chat.behavior.read_aloud !== false && canSpeak();

	useEffect( () => {
		if ( ! readAloud ) {
			return undefined;
		}
		return onSpeaking( ( id ) => setReading( id === message.id ) );
	}, [ readAloud, message.id ] );
	const timestamps = ui.messages.timestamps;
	const finished = message.status === 'done' || message.status === 'stopped';
	const time = timeFormat.format( new Date( message.time ) );

	return (
		<li
			className={ `cl-msg cl-msg-${ message.role } cl-status-${ message.status }` }
			part={ bot ? 'message-bot' : 'message-user' }
		>
			{ bot && ui.messages.avatar && (
				<Avatar brand={ ui.brand } name={ botName } size="sm" />
			) }
			<div className="cl-msg-body">
				<span className="cl-sr">{ bot ? botName : strings.you }: </span>
				{ ( message.text || message.status === 'pending' ) && (
					<div
						className="cl-bubble"
						title={
							timestamps === 'hover' && finished
								? time
								: undefined
						}
					>
						{ message.status === 'pending' && ! message.text && (
							<span
								className="cl-typing"
								role="status"
								aria-label={ strings.typing }
							>
								<span />
								<span />
								<span />
							</span>
						) }
						{ bot && message.text && (
							<Markdown
								text={ message.text }
								strings={ strings }
							/>
						) }
						{ ! bot && (
							<span className="cl-user-text">
								{ message.text }
							</span>
						) }
					</div>
				) }

				{ bot && message.status === 'stopped' && (
					<p className="cl-note">{ strings.stopped }</p>
				) }

				{ bot && message.status === 'error' && (
					<div className="cl-error" role="alert">
						<p>{ message.error || strings.error }</p>
						{ message.question && (
							<button
								type="button"
								className="cl-chip"
								onClick={ () => chat.retry( message.id ) }
							>
								<Icon name="retry" />
								{ strings.retry }
							</button>
						) }
					</div>
				) }

				{ bot &&
					message.sources &&
					message.sources.length > 0 &&
					message.status !== 'pending' && (
						<SourceCards
							sources={ message.sources }
							strings={ strings }
						/>
					) }

				{ bot &&
				message.status === 'done' &&
				message.source === 'quick_answer' &&
				message.quickAnswerId ? (
					<button
						type="button"
						className="cl-chip cl-ask-ai"
						disabled={ chat.busy }
						onClick={ () => chat.askAi( message.id ) }
					>
						<Icon name="sparkle" />
						{ strings.askAi }
					</button>
				) : null }

				{ ( ( bot && finished && message.text ) ||
					( timestamps === 'always' && finished ) ) && (
					<div className={ `cl-meta cl-meta-${ timestamps }` }>
						{ timestamps !== 'never' && finished && (
							<time
								dateTime={ new Date(
									message.time
								).toISOString() }
							>
								{ time }
							</time>
						) }
						{ bot && finished && message.text && (
							<span className="cl-actions">
								<ActionButton
									icon={ copied ? 'check' : 'copy' }
									label={
										copied ? strings.copied : strings.copy
									}
									onClick={ () =>
										copyText(
											markdownText( message.text )
										).then( ( ok ) => {
											setCopied( ok );
											window.setTimeout(
												() => setCopied( false ),
												1600
											);
										} )
									}
								/>
								{ readAloud && (
									<ActionButton
										icon={ reading ? 'stop' : 'speaker' }
										label={
											reading
												? strings.stopReading
												: strings.readAloud
										}
										pressed={ reading }
										onClick={ () =>
											reading
												? stopSpeaking()
												: speak(
														message.id,
														markdownText(
															message.text
														)
													)
										}
									/>
								) }
								{ !! message.serverId && (
									<>
										<ActionButton
											icon="up"
											label={ strings.helpful }
											pressed={ message.feedback === 1 }
											onClick={ () => {
												chat.rate( message.id, 1 );
												setThanked(
													message.feedback !== 1
												);
											} }
										/>
										<ActionButton
											icon="down"
											label={ strings.notHelpful }
											pressed={ message.feedback === -1 }
											onClick={ () => {
												chat.rate( message.id, -1 );
												setThanked(
													message.feedback !== -1
												);
											} }
										/>
									</>
								) }
							</span>
						) }
						{ thanked && (
							<span className="cl-thanks" role="status">
								{ strings.thanks }
							</span>
						) }
					</div>
				) }
			</div>
		</li>
	);
}

/**
 * Icon for a quick action.
 *
 * @param type Action type.
 * @return Icon name.
 */
function actionIcon( type: QuickAction[ 'type' ] ): IconName {
	switch ( type ) {
		case 'email':
			return 'mail';
		case 'phone':
		case 'whatsapp':
			return 'phone';
		case 'message':
			return 'chat';
		default:
			return 'link';
	}
}

/**
 * A quick action: a link (URL, email, phone, WhatsApp) or a preset message.
 *
 * @param props          Props.
 * @param props.action   Action.
 * @param props.disabled Whether sending is blocked.
 * @param props.onSend   Sends a message.
 * @return Chip.
 */
function QuickActionChip( {
	action,
	disabled,
	onSend,
}: {
	action: QuickAction;
	disabled: boolean;
	onSend: ( text: string ) => boolean;
} ) {
	if ( action.type === 'message' ) {
		return (
			<button
				type="button"
				className="cl-chip cl-chip-action"
				disabled={ disabled }
				onClick={ () => onSend( action.message ?? action.label ) }
			>
				<Icon name={ actionIcon( action.type ) } />
				{ action.label }
			</button>
		);
	}
	// Links are built by the server; only known-safe schemes are rendered.
	if ( ! /^(https?:|mailto:|tel:)/i.test( action.href ?? '' ) ) {
		return null;
	}
	const external = action.type === 'url' || action.type === 'whatsapp';
	return (
		<a
			className="cl-chip cl-chip-action"
			href={ action.href }
			target={ external ? '_blank' : undefined }
			rel="noopener noreferrer"
		>
			<Icon name={ actionIcon( action.type ) } />
			{ action.label }
		</a>
	);
}

/**
 * The scrolling conversation area.
 *
 * @param props          Props.
 * @param props.chat     Chat state.
 * @param props.behavior Behavior settings.
 * @param props.ui       Theme options.
 * @param props.strings  Widget strings.
 * @param props.botName  Bot name.
 * @param props.closed
 * @return Conversation.
 */
export function Messages( {
	chat,
	behavior,
	ui,
	strings,
	botName,
	closed = false,
}: {
	chat: Chat;
	behavior: BehaviorConfig;
	ui: ThemeUi;
	strings: WidgetStrings;
	botName: string;
	closed?: boolean;
} ) {
	const scroller = useRef< HTMLDivElement >( null );
	const pinned = useRef( true );
	const welcome = useMemo(
		() => parseRichText( behavior.welcome_message ),
		[ behavior.welcome_message ]
	);
	const empty = chat.messages.length === 0;
	const disabled = chat.busy || chat.otherTabBusy;

	// Keeps the newest message in view unless the visitor scrolled up.
	useLayoutEffect( () => {
		const node = scroller.current;
		if ( node && pinned.current ) {
			node.scrollTop = node.scrollHeight;
		}
	}, [ chat.messages ] );

	return (
		<div
			className="cl-scroll"
			ref={ scroller }
			onScroll={ () => {
				const node = scroller.current;
				if ( node ) {
					pinned.current =
						node.scrollHeight - node.scrollTop - node.clientHeight <
						80;
				}
			} }
		>
			<ol className="cl-messages" aria-label={ strings.conversation }>
				{ welcome.length > 0 && (
					<li
						className="cl-msg cl-msg-bot cl-welcome"
						part="message-bot"
					>
						{ ui.messages.avatar && (
							<Avatar
								brand={ ui.brand }
								name={ botName }
								size="sm"
							/>
						) }
						<div className="cl-msg-body">
							<div className="cl-bubble">
								<div className="cl-md">
									<p>
										<InlineNodes
											nodes={ welcome }
											strings={ strings }
										/>
									</p>
								</div>
							</div>
						</div>
					</li>
				) }
				{ chat.messages.map( ( message ) => (
					<MessageItem
						key={ message.id }
						message={ message }
						chat={ chat }
						ui={ ui }
						strings={ strings }
						botName={ botName }
					/>
				) ) }
				{ closed && (
					<li
						className="cl-msg cl-msg-bot cl-offline"
						part="message-bot"
						role="status"
					>
						{ ui.messages.avatar && (
							<Avatar
								brand={ ui.brand }
								name={ botName }
								size="sm"
							/>
						) }
						<div className="cl-msg-body">
							<div className="cl-bubble">
								<div className="cl-md">
									<p>{ strings.offline }</p>
								</div>
							</div>
						</div>
					</li>
				) }
			</ol>

			{ empty &&
				( ( ! closed && behavior.starter_questions.length > 0 ) ||
					behavior.quick_actions.length > 0 ) && (
					<div className="cl-starters" part="starters">
						{ ( closed ? [] : behavior.starter_questions ).map(
							( question ) => (
								<button
									key={ question }
									type="button"
									className="cl-chip"
									disabled={ disabled }
									onClick={ () => chat.send( question ) }
								>
									{ question }
								</button>
							)
						) }
						{ behavior.quick_actions.map( ( action ) => (
							<QuickActionChip
								key={ action.label }
								action={ action }
								disabled={ disabled || closed }
								onSend={ chat.send }
							/>
						) ) }
					</div>
				) }

			{ chat.otherTabBusy && ! chat.busy && (
				<p className="cl-note cl-note-center" role="status">
					{ strings.busyTab }
				</p>
			) }
		</div>
	);
}
