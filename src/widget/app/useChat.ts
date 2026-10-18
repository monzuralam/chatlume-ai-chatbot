/**
 * Chat state and the conversation with the server.
 *
 * - The server is the source of truth: only the new message, the session
 *   token, and the conversation ID are sent.
 * - Replies stream over SSE when possible and fall back to the normal route
 *   when streaming is off, the server is busy, or something buffers the
 *   response.
 * - localStorage only restores the visible conversation after a reload.
 * - Tabs stay in sync and only one tab streams at a time.
 */
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import {
	ApiError,
	errorFrom,
	isEventStream,
	postJson,
	request,
	type ChatResponse,
	type SessionResponse,
} from '../lib/api';
import { markdownText } from '../lib/markdown';
import { read, write } from '../lib/storage';
import { readEvents, wasBuffered } from '../lib/stream';
import { createTabSync, type TabSync } from '../lib/tabs';
import { turnstileToken } from '../lib/turnstile';
import { track } from '../lib/analytics';
import type {
	BehaviorConfig,
	ChatMessage,
	PreviewState,
	Source,
	WidgetHost,
} from '../types';
import { sampleMessages, withDefaults } from './samples';

const MAX_STORED = 60;
const BUFFERED_TTL = 24 * 60 * 60 * 1000;

export const DEFAULT_BEHAVIOR: BehaviorConfig = {
	bot_name: '',
	welcome_message: '',
	starter_questions: [],
	quick_actions: [],
	max_message_length: 1000,
	store_conversations: true,
};

interface Stored {
	conversationId: string;
	messages: ChatMessage[];
}

type Extra = { skip_quick_answers?: boolean; quick_answer_id?: number };

/**
 * New message ID.
 *
 * @return ID.
 */
function newId(): string {
	return (
		Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 )
	);
}

/**
 * Restores stored messages, dropping anything malformed. Replies that were
 * still arriving when the page closed are marked as stopped.
 *
 * @param value Stored value.
 * @return Messages.
 */
export function restoreMessages( value: unknown ): ChatMessage[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return value
		.filter(
			( item ): item is ChatMessage =>
				!! item &&
				typeof item === 'object' &&
				typeof item.id === 'string' &&
				( item.role === 'user' || item.role === 'bot' ) &&
				typeof item.text === 'string'
		)
		.map( ( item ) =>
			item.status === 'pending' || item.status === 'streaming'
				? { ...item, status: 'stopped' as const }
				: item
		)
		.slice( -MAX_STORED );
}

/**
 * The page language for the server (e.g. "en-US").
 *
 * @return Language.
 */
/**
 * The element a Turnstile challenge renders into (inside the chat window;
 * it stays empty unless the visitor has to act).
 *
 * @param host Widget host.
 * @return Container.
 */
function turnstileBox( host: WidgetHost ): HTMLElement {
	// Outside React's container, so re-renders never remove it.
	const parent = host.mount.parentElement ?? host.mount;
	const existing = parent.querySelector< HTMLElement >(
		':scope > .cl-turnstile'
	);
	if ( existing ) {
		return existing;
	}
	const box = document.createElement( 'div' );
	box.className = 'cl-turnstile';
	parent.append( box );
	return box;
}

function pageLanguage(): string {
	return ( document.documentElement.lang || '' ).slice( 0, 20 );
}

export interface Chat {
	messages: ChatMessage[];
	behavior: BehaviorConfig;
	busy: boolean;
	otherTabBusy: boolean;
	announcement: string;
	send: ( text: string ) => boolean;
	stop: () => void;
	retry: ( id: string ) => void;
	askAi: ( id: string ) => void;
	rate: ( id: string, rating: -1 | 1 ) => void;
	reset: () => void;
	setBehavior: ( behavior: Partial< BehaviorConfig > ) => void;
	force: ( state: PreviewState ) => void;
	/** Calls a public route with the session token and conversation ID. */
	post: < T >(
		path: string,
		body: Record< string, unknown >
	) => Promise< T >;
	/** The current conversation ID ('' before the first message). */
	conversationId: () => string;
}

/**
 * The chat hook.
 *
 * @param host Widget host.
 * @return Chat state and actions.
 */
export function useChat( host: WidgetHost ): Chat {
	const { config, preview } = host;
	const strings = config.strings;

	const stored = useMemo(
		() =>
			preview
				? { conversationId: '', messages: [] }
				: read< Stored >( 'chat', {
						conversationId: '',
						messages: [],
					} ),
		[ preview ]
	);

	const [ messages, setMessages ] = useState< ChatMessage[] >( () =>
		restoreMessages( stored.messages )
	);
	const [ behavior, setBehaviorState ] = useState< BehaviorConfig >( () => ( {
		...DEFAULT_BEHAVIOR,
		...( preview
			? config.preview?.behavior
			: read< Partial< BehaviorConfig > >( 'behavior', {} ) ),
	} ) );
	const [ busy, setBusy ] = useState( false );
	const [ otherTabBusy, setOtherTabBusy ] = useState( false );
	const [ announcement, setAnnouncement ] = useState( '' );

	const messagesRef = useRef( messages );
	const conversationRef = useRef(
		typeof stored.conversationId === 'string' ? stored.conversationId : ''
	);
	const sessionRef = useRef< Promise< string > | null >( null );
	const abortRef = useRef<
		( ( reason: 'user' | 'timeout' ) => void ) | null
	>( null );
	const busyRef = useRef( false );
	const streamRef = useRef( { id: '', token: '' } );
	/** Set after "Stop": later tokens are ignored while the server wraps up. */
	const stoppingRef = useRef( 0 );

	/**
	 * Asks the server to stop the running stream. Some servers never notice
	 * a closed connection, so aborting the request alone may not stop the AI.
	 */
	const sendStop = useCallback( () => {
		const { id, token } = streamRef.current;
		if ( ! id || ! token ) {
			return;
		}
		streamRef.current = { id: '', token: '' };
		const body = new URLSearchParams( { token, stream_id: id } );
		const url = config.rest + 'chat/stop';
		try {
			if ( ! window.navigator.sendBeacon?.( url, body ) ) {
				fetch( url, {
					method: 'POST',
					body,
					credentials: 'same-origin',
					keepalive: true,
				} ).catch( () => undefined );
			}
		} catch {
			// Best effort: the stream still ends at its time limit.
		}
	}, [ config.rest ] );
	const tabsRef = useRef< TabSync | null >( null );
	const pendingText = useRef< Record< string, string > >( {} );
	const frameRef = useRef( 0 );
	const noStream = useRef(
		read< number >( 'buffered', 0 ) > Date.now() - BUFFERED_TTL
	);

	const commit = useCallback( ( next: ChatMessage[] ) => {
		messagesRef.current = next;
		setMessages( next );
	}, [] );

	const patch = useCallback(
		( id: string, changes: Partial< ChatMessage > ) => {
			commit(
				messagesRef.current.map( ( message ) =>
					message.id === id ? { ...message, ...changes } : message
				)
			);
		},
		[ commit ]
	);

	/** Applies streamed text gathered since the last frame. */
	const flushText = useCallback( () => {
		window.cancelAnimationFrame( frameRef.current );
		frameRef.current = 0;
		const pending = pendingText.current;
		if ( ! Object.keys( pending ).length ) {
			return;
		}
		pendingText.current = {};
		commit(
			messagesRef.current.map( ( message ) =>
				pending[ message.id ] !== undefined
					? {
							...message,
							text: message.text + pending[ message.id ],
							status: 'streaming',
						}
					: message
			)
		);
	}, [ commit ] );

	const appendText = useCallback(
		( id: string, text: string ) => {
			pendingText.current[ id ] =
				( pendingText.current[ id ] ?? '' ) + text;
			if ( ! frameRef.current ) {
				frameRef.current = window.requestAnimationFrame( flushText );
			}
		},
		[ flushText ]
	);

	const save = useCallback( () => {
		if ( preview ) {
			return;
		}
		write( 'chat', {
			conversationId: conversationRef.current,
			messages: messagesRef.current
				.filter(
					( message ) =>
						message.status !== 'pending' &&
						message.status !== 'streaming'
				)
				.slice( -MAX_STORED ),
		} );
		tabsRef.current?.post( { type: 'state' } );
	}, [ preview ] );

	const ensureSession = useCallback(
		( fresh = false ): Promise< string > => {
			if ( ! fresh && sessionRef.current ) {
				return sessionRef.current;
			}
			const previous = read< {
				token?: string;
				expires?: number;
			} | null >( 'session', null );
			// A new session passes Cloudflare Turnstile first, when it is on.
			const needsCheck =
				!! config.turnstile &&
				! preview &&
				( ! previous?.token ||
					Number( previous.expires ?? 0 ) * 1000 <= Date.now() );
			const check = needsCheck
				? turnstileToken(
						config.turnstile as string,
						turnstileBox( host )
					).catch( () => {
						throw new ApiError( 'turnstile', strings.connection );
					} )
				: Promise.resolve( '' );
			const promise = check
				.then( ( turnstile ) =>
					postJson< SessionResponse >(
						config.rest,
						'session',
						{
							token: needsCheck ? '' : ( previous?.token ?? '' ),
							language: pageLanguage(),
							v: config.theme.v,
							turnstile,
						},
						strings.connection
					)
				)
				.then( ( data ) => {
					write( 'session', {
						token: data.token,
						expires: data.expires,
					} );
					if ( data.config ) {
						write( 'behavior', data.config );
						setBehaviorState( {
							...DEFAULT_BEHAVIOR,
							...data.config,
						} );
					}
					if ( data.theme ) {
						host.setTheme( data.theme );
					}
					return data.token;
				} );
			promise.catch( () => {
				sessionRef.current = null;
			} );
			sessionRef.current = promise;
			return promise;
		},
		[
			config.rest,
			config.theme.v,
			config.turnstile,
			preview,
			host,
			strings.connection,
		]
	);

	// Session and tab sync.
	useEffect( () => {
		if ( preview ) {
			return undefined;
		}
		ensureSession().catch( () => undefined );

		const tabs = createTabSync( ( message ) => {
			if ( message.type === 'busy' ) {
				setOtherTabBusy( message.active );
			} else if ( message.type === 'state' && ! busyRef.current ) {
				const latest = read< Stored >( 'chat', {
					conversationId: '',
					messages: [],
				} );
				conversationRef.current =
					typeof latest.conversationId === 'string'
						? latest.conversationId
						: '';
				commit( restoreMessages( latest.messages ) );
			}
		} );
		tabsRef.current = tabs;
		setOtherTabBusy( tabs.otherBusy() );

		const onHide = () => {
			// Closing or leaving the page mid-reply stops the AI too.
			if ( busyRef.current ) {
				sendStop();
			}
			tabs.release();
		};
		window.addEventListener( 'pagehide', onHide );
		return () => {
			window.removeEventListener( 'pagehide', onHide );
			tabs.close();
			tabsRef.current = null;
		};
	}, [ preview, ensureSession, commit, sendStop ] );

	const finalize = useCallback(
		( id: string, result: ChatResponse ) => {
			flushText();
			conversationRef.current = String( result.conversation_id ?? '' );
			const reply = result.reply;
			const current = messagesRef.current.find(
				( item ) => item.id === id
			);
			const text = reply.text || current?.text || '';
			const interrupted = reply.interrupted === 'error';
			let status: ChatMessage[ 'status' ] = 'done';
			if ( interrupted ) {
				status = 'error';
			} else if ( reply.interrupted === 'stopped' ) {
				status = 'stopped';
			}
			patch( id, {
				text,
				status,
				error: interrupted ? strings.error : undefined,
				source: reply.source,
				sources: Array.isArray( reply.sources ) ? reply.sources : [],
				quickAnswerId: reply.quick_answer_id ?? null,
				serverId: Number( result.message_id ) || 0,
				time: Date.now(),
			} );
			setAnnouncement( markdownText( text ) );
			host.notify();
		},
		[ flushText, patch, host, strings.error ]
	);

	/** Streams a reply. Resolves with the final reply. */
	const streamReply = useCallback(
		async (
			body: Record< string, unknown >,
			id: string,
			signal: AbortSignal,
			onFirstToken: () => void
		): Promise< ChatResponse > => {
			const response = await request(
				config.rest,
				'chat/stream',
				body,
				signal,
				'text/event-stream'
			);
			if ( ! isEventStream( response ) ) {
				if ( ! response.ok ) {
					throw await errorFrom( response, strings.error );
				}
				// Something rewrote the response: use the normal route from now on.
				throw new ApiError( 'chatlume_streaming_disabled', '' );
			}

			let done: ( ChatResponse & { streamed?: boolean } ) | null = null;
			let failure: ApiError | null = null;

			const stats = await readEvents(
				response.body as ReadableStream< Uint8Array >,
				( event ) => {
					let data: Record< string, unknown >;
					try {
						data = JSON.parse( event.data );
					} catch {
						return;
					}
					switch ( event.event ) {
						case 'start':
							conversationRef.current = String(
								data.conversation_id ?? conversationRef.current
							);
							streamRef.current = {
								id: String( data.stream_id ?? '' ),
								token: String( body.token ?? '' ),
							};
							break;
						case 'token':
							onFirstToken();
							if ( ! stoppingRef.current ) {
								appendText( id, String( data.t ?? '' ) );
							}
							break;
						case 'sources':
							patch( id, {
								sources: ( data.sources as Source[] ) ?? [],
							} );
							break;
						case 'done':
							done = data as unknown as ChatResponse;
							break;
						case 'error':
							failure = new ApiError(
								String( data.code ?? 'error' ),
								String( data.message ?? strings.error ),
								Number( data.status ?? 0 ),
								Number( data.retry_after ?? 0 )
							);
							break;
					}
				}
			);

			if ( failure ) {
				throw failure;
			}
			if ( ! done ) {
				throw new ApiError( 'incomplete', strings.connection );
			}
			const finished = done as ChatResponse & { streamed?: boolean };
			if ( wasBuffered( stats, !! finished.streamed ) ) {
				noStream.current = true;
				write( 'buffered', Date.now() );
			}
			return finished;
		},
		[ config.rest, appendText, patch, strings.connection, strings.error ]
	);

	/**
	 * Preview reply: a sample typed out (no server, no cost), or, with "Live
	 * AI replies" in the Customizer, a real answer from the admin-only
	 * Playground endpoint (a test chat). Options are read when the message is
	 * sent, so Customizer changes apply right away.
	 */
	const previewReply = useCallback(
		( id: string, question: string ) => {
			const live = host.config.preview?.live;
			if ( live ) {
				const controller = new AbortController();
				setBusy( true );
				busyRef.current = true;
				abortRef.current = () => controller.abort();
				const done = () => {
					abortRef.current = null;
					setBusy( false );
					busyRef.current = false;
				};
				window
					.fetch( live.url, {
						method: 'POST',
						credentials: 'same-origin',
						signal: controller.signal,
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': live.nonce,
						},
						body: JSON.stringify( {
							message: question,
							conversation_id: conversationRef.current,
						} ),
					} )
					.then( async ( response ) => {
						const data = await response.json();
						if ( ! response.ok ) {
							throw new Error(
								typeof data?.message === 'string'
									? data.message
									: strings.error
							);
						}
						done();
						finalize( id, data );
					} )
					.catch( ( error: Error ) => {
						done();
						patch(
							id,
							error.name === 'AbortError'
								? { status: 'stopped' }
								: {
										status: 'error',
										error: error.message || strings.error,
									}
						);
					} );
				return;
			}

			const text = withDefaults( host.config.preview?.samples ).reply;
			const words = text.split( /(\s+)/ );
			let index = 0;
			setBusy( true );
			busyRef.current = true;
			const timer = window.setInterval( () => {
				if ( index >= words.length ) {
					window.clearInterval( timer );
					abortRef.current = null;
					setBusy( false );
					busyRef.current = false;
					finalize( id, {
						conversation_id: '',
						message_id: 0,
						reply: {
							text,
							source: 'ai',
							sources: [],
							quick_answer_id: null,
						},
					} );
					return;
				}
				appendText( id, words[ index++ ] );
			}, 45 );
			abortRef.current = () => {
				window.clearInterval( timer );
				flushText();
				patch( id, { status: 'stopped' } );
				abortRef.current = null;
				setBusy( false );
				busyRef.current = false;
			};
		},
		[ host, appendText, finalize, flushText, patch, strings.error ]
	);

	/** Gets a reply for the bot message `id`. */
	const perform = useCallback(
		async ( id: string, question: string, extra: Extra ) => {
			if ( preview ) {
				previewReply( id, question );
				return;
			}

			const tabs = tabsRef.current;
			if ( tabs && ! tabs.acquire() ) {
				patch( id, { status: 'error', error: strings.busyTab } );
				return;
			}

			const controller = new AbortController();
			let reason: 'user' | 'timeout' | null = null;
			let timer = 0;
			const stopWith = ( why: 'user' | 'timeout' ) => {
				reason = why;
				controller.abort();
			};
			abortRef.current = stopWith;
			busyRef.current = true;
			setBusy( true );

			const firstToken = Math.max(
				5,
				Number( config.streaming.firstTokenTimeout ) || 20
			);

			try {
				let token = await ensureSession();
				let renewed = false;
				for (;;) {
					const body = {
						token,
						message: question,
						conversation_id: conversationRef.current,
						page_url: window.location.href.slice( 0, 2048 ),
						page_title: document.title.slice( 0, 300 ),
						language: pageLanguage(),
						...extra,
					};
					const streaming =
						config.streaming.enabled && ! noStream.current;
					timer = window.setTimeout(
						() => stopWith( 'timeout' ),
						( streaming
							? firstToken
							: Math.max( 60, firstToken * 3 ) ) * 1000
					);
					try {
						const result = streaming
							? await streamReply(
									body,
									id,
									controller.signal,
									() => window.clearTimeout( timer )
								)
							: await postJson< ChatResponse >(
									config.rest,
									'chat',
									body,
									strings.error,
									controller.signal
								);
						finalize( id, result );
						break;
					} catch ( error ) {
						window.clearTimeout( timer );
						if (
							error instanceof ApiError &&
							error.code === 'chatlume_streaming_disabled'
						) {
							noStream.current = true;
							continue;
						}
						if (
							error instanceof ApiError &&
							error.code === 'chatlume_invalid_session' &&
							! renewed
						) {
							renewed = true;
							token = await ensureSession( true );
							continue;
						}
						throw error;
					}
				}
			} catch ( error ) {
				flushText();
				if ( reason === 'user' ) {
					patch( id, { status: 'stopped' } );
				} else if ( reason === 'timeout' ) {
					patch( id, { status: 'error', error: strings.timeout } );
				} else if ( error instanceof ApiError && error.message ) {
					patch( id, { status: 'error', error: error.message } );
				} else {
					patch( id, { status: 'error', error: strings.connection } );
				}
			} finally {
				window.clearTimeout( timer );
				window.clearTimeout( stoppingRef.current );
				stoppingRef.current = 0;
				streamRef.current = { id: '', token: '' };
				abortRef.current = null;
				busyRef.current = false;
				setBusy( false );
				tabs?.release();
				save();
			}
		},
		[
			preview,
			previewReply,
			patch,
			strings,
			config.streaming,
			config.rest,
			ensureSession,
			streamReply,
			finalize,
			flushText,
			save,
		]
	);

	const send = useCallback(
		( text: string ): boolean => {
			const message = text.trim();
			if ( ! message || busyRef.current || otherTabBusy ) {
				return false;
			}
			const botId = newId();
			commit( [
				...messagesRef.current,
				{
					id: newId(),
					role: 'user',
					text: message,
					time: Date.now(),
					status: 'done',
				},
				{
					id: botId,
					role: 'bot',
					text: '',
					time: Date.now(),
					status: 'pending',
					question: message,
				},
			] );
			setAnnouncement( '' );
			perform( botId, message, {} );
			if ( ! preview ) {
				track( config.analytics, 'message_sent' );
			}
			return true;
		},
		[ commit, perform, otherTabBusy, preview, config.analytics ]
	);

	const again = useCallback(
		( id: string, extra: Extra ) => {
			const message = messagesRef.current.find(
				( item ) => item.id === id
			);
			if ( ! message || ! message.question || busyRef.current ) {
				return;
			}
			patch( id, {
				text: '',
				status: 'pending',
				error: undefined,
				sources: [],
				source: undefined,
				quickAnswerId: null,
				serverId: undefined,
				feedback: 0,
			} );
			perform( id, message.question, extra );
		},
		[ patch, perform ]
	);

	const retry = useCallback( ( id: string ) => again( id, {} ), [ again ] );

	const askAi = useCallback(
		( id: string ) => {
			const message = messagesRef.current.find(
				( item ) => item.id === id
			);
			again( id, {
				skip_quick_answers: true,
				quick_answer_id: Number( message?.quickAnswerId ) || 0,
			} );
		},
		[ again ]
	);

	const rate = useCallback(
		( id: string, rating: -1 | 1 ) => {
			const message = messagesRef.current.find(
				( item ) => item.id === id
			);
			if ( ! message || ! message.serverId ) {
				return;
			}
			const next = message.feedback === rating ? 0 : rating;
			patch( id, { feedback: next } );
			save();
			if ( preview ) {
				return;
			}
			ensureSession()
				.then( ( token ) =>
					postJson(
						config.rest,
						'feedback',
						{
							token,
							conversation_id: conversationRef.current,
							message_id: message.serverId,
							rating: next,
						},
						strings.error
					)
				)
				.catch( () => undefined );
		},
		[ patch, save, preview, ensureSession, config.rest, strings.error ]
	);

	const post = useCallback(
		async < T >( path: string, body: Record< string, unknown > ) => {
			const token = await ensureSession();
			return postJson< T >(
				config.rest,
				path,
				{
					token,
					conversation_id: conversationRef.current,
					...body,
				},
				strings.error
			);
		},
		[ ensureSession, config.rest, strings.error ]
	);

	const conversationId = useCallback( () => conversationRef.current, [] );

	const stop = useCallback( () => {
		if ( ! streamRef.current.id || stoppingRef.current ) {
			abortRef.current?.( 'user' );
			return;
		}
		// Ask the server to stop and keep listening: it ends the AI request,
		// saves the partial reply, and closes the stream within a second.
		// The connection is cut only if that does not happen in time.
		sendStop();
		flushText();
		const active = messagesRef.current.find(
			( message ) =>
				message.status === 'streaming' || message.status === 'pending'
		);
		if ( active ) {
			patch( active.id, { status: 'stopped' } );
		}
		stoppingRef.current = window.setTimeout(
			() => abortRef.current?.( 'user' ),
			3000
		);
	}, [ sendStop, flushText, patch ] );

	const reset = useCallback( () => {
		abortRef.current?.( 'user' );
		conversationRef.current = '';
		commit( [] );
		setAnnouncement( '' );
		save();
	}, [ commit, save ] );

	const setBehavior = useCallback(
		( next: Partial< BehaviorConfig > ) =>
			setBehaviorState( ( current ) => ( { ...current, ...next } ) ),
		[]
	);

	const force = useCallback(
		( state: PreviewState ) => {
			abortRef.current?.( 'user' );
			const sample = sampleMessages(
				state,
				withDefaults( host.config.preview?.samples ),
				strings
			);
			if ( sample ) {
				commit( sample );
			}
		},
		[ commit, host, strings ]
	);

	useEffect(
		() => () => {
			window.cancelAnimationFrame( frameRef.current );
		},
		[]
	);

	return {
		messages,
		behavior,
		busy,
		otherTabBusy,
		announcement,
		send,
		stop,
		retry,
		askAi,
		rate,
		reset,
		setBehavior,
		force,
		post,
		conversationId,
	};
}
