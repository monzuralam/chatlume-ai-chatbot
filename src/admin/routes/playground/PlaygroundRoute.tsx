import { useMutation } from '@tanstack/react-query';
import { __ } from '@wordpress/i18n';
import {
	ExternalLink,
	Info,
	MessageSquarePlus,
	PencilLine,
	RotateCcw,
	SendHorizontal,
	Sparkles,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { PageHeader } from '../../components/layout/PageHeader';
import {
	QuickAnswerSheet,
	type QuickAnswerDraft,
} from '../../components/QuickAnswerSheet';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { ReplyMarkdown } from '../../components/ReplyMarkdown';
import { Card } from '../../components/ui/card';
import { inputClasses } from '../../components/ui/input';
import type { ApiError } from '../../lib/api';
import {
	canAskAi,
	sendPlaygroundMessage,
	type PlaygroundInput,
	type PlaygroundReply,
	type PlaygroundTurn,
} from '../../lib/playground';
import { cn } from '../../lib/utils';
import { sourceLabel } from '../conversations/format';
import { DebugPanel } from './DebugPanel';

let turnCounter = 0;
const nextId = () => `turn-${ ++turnCounter }`;

interface SheetState {
	draft: QuickAnswerDraft;
	title: string;
}

/**
 * Playground: chat with the real bot as a visitor and see why each reply
 * was given. Test chats never appear in Conversations or statistics.
 */
export default function PlaygroundRoute() {
	const [ turns, setTurns ] = useState< PlaygroundTurn[] >( [] );
	const [ conversationId, setConversationId ] = useState( '' );
	const [ selectedId, setSelectedId ] = useState( '' );
	const [ text, setText ] = useState( '' );
	const [ sheet, setSheet ] = useState< SheetState | null >( null );
	const listRef = useRef< HTMLDivElement >( null );
	const inputRef = useRef< HTMLTextAreaElement >( null );

	const send = useMutation< PlaygroundReply, ApiError, PlaygroundInput >( {
		mutationFn: sendPlaygroundMessage,
	} );

	const selected = useMemo( () => {
		const turn = turns.find( ( item ) => item.id === selectedId );
		return turn?.role === 'assistant' ? turn : undefined;
	}, [ turns, selectedId ] );

	// Keep the newest message in view.
	useEffect( () => {
		const list = listRef.current;
		if ( list ) {
			list.scrollTop = list.scrollHeight;
		}
	}, [ turns, send.isPending ] );

	const ask = (
		question: string,
		extra: Partial< PlaygroundInput > = {}
	) => {
		const message = question.trim();
		if ( ! message || send.isPending ) {
			return;
		}
		if ( ! extra.skip_quick_answers ) {
			setTurns( ( current ) => [
				...current,
				{ id: nextId(), role: 'user', text: message },
			] );
		}
		send.mutate(
			{ message, conversation_id: conversationId, ...extra },
			{
				onSuccess: ( data ) => {
					const id = nextId();
					setConversationId( data.conversation_id );
					setTurns( ( current ) => [
						...current,
						{ id, role: 'assistant', question: message, data },
					] );
					setSelectedId( id );
				},
				onError: ( error ) =>
					setTurns( ( current ) => [
						...current,
						{
							id: nextId(),
							role: 'error',
							question: message,
							text: error.message,
						},
					] ),
			}
		);
	};

	const submit = () => {
		ask( text );
		setText( '' );
		inputRef.current?.focus();
	};

	const reset = () => {
		setTurns( [] );
		setConversationId( '' );
		setSelectedId( '' );
		send.reset();
		inputRef.current?.focus();
	};

	const openSheet = ( question: string, answer: string, title: string ) =>
		setSheet( {
			draft: {
				question,
				answer,
				language: '',
			},
			title,
		} );

	return (
		<>
			<PageHeader
				title={ __( 'Test your bot', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Chat with your bot as a visitor would and see exactly why each answer was given. Test chats do not appear in Conversations or statistics.',
					'chatlume-ai-chatbot'
				) }
				actions={
					<Button
						type="button"
						onClick={ reset }
						disabled={ turns.length === 0 || send.isPending }
					>
						<RotateCcw aria-hidden="true" />
						{ __( 'New conversation', 'chatlume-ai-chatbot' ) }
					</Button>
				}
			/>

			<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:lg:grid-cols-[minmax(0,1fr)_minmax(0,420px)]">
				<Card className="chatlume:flex chatlume:h-[640px] chatlume:max-h-[75vh] chatlume:flex-col chatlume:overflow-hidden">
					<div
						ref={ listRef }
						className="chatlume:flex-1 chatlume:overflow-y-auto chatlume:p-5"
						role="log"
						aria-live="polite"
						aria-label={ __(
							'Test conversation',
							'chatlume-ai-chatbot'
						) }
					>
						{ turns.length === 0 && ! send.isPending && (
							<div className="chatlume:flex chatlume:h-full chatlume:flex-col chatlume:items-center chatlume:justify-center chatlume:gap-2 chatlume:text-center">
								<Sparkles
									aria-hidden="true"
									className="chatlume:size-8 chatlume:text-accent"
								/>
								<p className="chatlume:font-medium chatlume:text-fg">
									{ __(
										'Ask anything a visitor might ask.',
										'chatlume-ai-chatbot'
									) }
								</p>
								<p className="chatlume:max-w-sm chatlume:text-sm chatlume:text-fg-muted">
									{ __(
										'Replies use your real settings, quick answers, and AI provider. AI replies cost the same as for visitors.',
										'chatlume-ai-chatbot'
									) }
								</p>
							</div>
						) }

						<ul className="chatlume:flex chatlume:flex-col chatlume:gap-4">
							{ turns.map( ( turn ) => (
								<li
									key={ turn.id }
									className={ cn(
										'chatlume:flex chatlume:flex-col',
										turn.role === 'user'
											? 'chatlume:items-end'
											: 'chatlume:items-start'
									) }
								>
									{ turn.role === 'user' && (
										<div className="chatlume:max-w-[85%] chatlume:whitespace-pre-wrap chatlume:break-words chatlume:rounded-card chatlume:bg-accent chatlume:px-3.5 chatlume:py-2.5 chatlume:text-sm chatlume:text-white">
											{ turn.text }
										</div>
									) }

									{ turn.role === 'error' && (
										<div
											role="alert"
											className="chatlume:max-w-[85%] chatlume:rounded-card chatlume:bg-danger-soft chatlume:px-3.5 chatlume:py-2.5 chatlume:text-sm chatlume:text-danger"
										>
											{ turn.text }
										</div>
									) }

									{ turn.role === 'assistant' && (
										<AssistantTurn
											turn={ turn }
											selected={ turn.id === selectedId }
											busy={ send.isPending }
											onSelect={ () =>
												setSelectedId( turn.id )
											}
											onAskAi={ () =>
												ask( turn.question, {
													skip_quick_answers: true,
													quick_answer_id:
														turn.data.reply
															.quick_answer_id ??
														0,
												} )
											}
											onSave={ () =>
												openSheet(
													turn.question,
													turn.data.reply.text,
													__(
														'Save as quick answer',
														'chatlume-ai-chatbot'
													)
												)
											}
											onImprove={ () =>
												openSheet(
													turn.question,
													turn.data.reply.text,
													__(
														'Improve this answer',
														'chatlume-ai-chatbot'
													)
												)
											}
										/>
									) }
								</li>
							) ) }
							{ send.isPending && (
								<li
									className="chatlume:text-sm chatlume:text-fg-muted"
									aria-live="polite"
								>
									{ __(
										'Your bot is typing…',
										'chatlume-ai-chatbot'
									) }
								</li>
							) }
						</ul>
					</div>

					<form
						className="chatlume:flex chatlume:items-end chatlume:gap-2 chatlume:border-t chatlume:border-border chatlume:p-4"
						onSubmit={ ( event ) => {
							event.preventDefault();
							submit();
						} }
					>
						<label
							htmlFor="chatlume-playground-message"
							className="chatlume:sr-only"
						>
							{ __( 'Your message', 'chatlume-ai-chatbot' ) }
						</label>
						<textarea
							id="chatlume-playground-message"
							ref={ inputRef }
							rows={ 2 }
							maxLength={ 4000 }
							placeholder={ __(
								'Type a question and press Enter…',
								'chatlume-ai-chatbot'
							) }
							className={ cn(
								inputClasses,
								'chatlume:h-auto chatlume:flex-1 chatlume:resize-none chatlume:py-2'
							) }
							value={ text }
							onChange={ ( event ) =>
								setText( event.target.value )
							}
							onKeyDown={ ( event ) => {
								if (
									event.key === 'Enter' &&
									! event.shiftKey &&
									! event.nativeEvent.isComposing
								) {
									event.preventDefault();
									submit();
								}
							} }
						/>
						<Button
							type="submit"
							variant="primary"
							disabled={ ! text.trim() || send.isPending }
						>
							<SendHorizontal aria-hidden="true" />
							{ __( 'Send', 'chatlume-ai-chatbot' ) }
						</Button>
					</form>
				</Card>

				<DebugPanel turn={ selected } />
			</div>

			<QuickAnswerSheet
				draft={ sheet?.draft ?? null }
				title={ sheet?.title }
				onClose={ () => setSheet( null ) }
			/>
		</>
	);
}

/**
 * A bot reply with its source and actions.
 *
 * @param props           Props.
 * @param props.turn      The reply.
 * @param props.selected  Whether its details are shown.
 * @param props.busy      A message is being sent.
 * @param props.onSelect  Show its details.
 * @param props.onAskAi   Ask the AI instead of the quick answer.
 * @param props.onSave    Save as a quick answer.
 * @param props.onImprove Edit into a quick answer.
 */
function AssistantTurn( {
	turn,
	selected,
	busy,
	onSelect,
	onAskAi,
	onSave,
	onImprove,
}: {
	turn: Extract< PlaygroundTurn, { role: 'assistant' } >;
	selected: boolean;
	busy: boolean;
	onSelect: () => void;
	onAskAi: () => void;
	onSave: () => void;
	onImprove: () => void;
} ) {
	const { reply } = turn.data;

	return (
		<>
			<div
				className={ cn(
					'chatlume:max-w-[85%] chatlume:break-words chatlume:rounded-card chatlume:bg-surface-muted chatlume:px-3.5 chatlume:py-2.5 chatlume:text-sm chatlume:text-fg',
					selected && 'chatlume:ring-2 chatlume:ring-accent'
				) }
			>
				<ReplyMarkdown text={ reply.text } />
			</div>
			{ reply.sources.length > 0 && (
				<ul className="chatlume:mt-1.5 chatlume:flex chatlume:flex-col chatlume:gap-0.5 chatlume:text-xs">
					{ reply.sources.map( ( source ) => (
						<li key={ source.url }>
							<a
								href={ source.url }
								target="_blank"
								rel="noopener noreferrer"
								className="chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:text-accent chatlume:hover:underline"
							>
								{ source.title || source.url }
								<ExternalLink
									aria-hidden="true"
									className="chatlume:size-3"
								/>
							</a>
						</li>
					) ) }
				</ul>
			) }
			<div className="chatlume:mt-1.5 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-1">
				<Badge tone={ reply.source === 'ai' ? 'accent' : 'neutral' }>
					{ sourceLabel( reply.source ) }
				</Badge>
				<Button
					type="button"
					size="sm"
					variant="ghost"
					aria-pressed={ selected }
					className={ cn( selected && 'chatlume:text-accent' ) }
					onClick={ onSelect }
				>
					<Info aria-hidden="true" />
					{ __( 'Why this answer', 'chatlume-ai-chatbot' ) }
				</Button>
				{ canAskAi( turn.data ) && (
					<Button
						type="button"
						size="sm"
						variant="ghost"
						disabled={ busy }
						onClick={ onAskAi }
					>
						<Sparkles aria-hidden="true" />
						{ __( 'Ask AI instead', 'chatlume-ai-chatbot' ) }
					</Button>
				) }
				{ reply.source !== 'quick_answer' && (
					<Button
						type="button"
						size="sm"
						variant="ghost"
						onClick={ onSave }
					>
						<MessageSquarePlus aria-hidden="true" />
						{ __( 'Save as quick answer', 'chatlume-ai-chatbot' ) }
					</Button>
				) }
				<Button
					type="button"
					size="sm"
					variant="ghost"
					onClick={ onImprove }
				>
					<PencilLine aria-hidden="true" />
					{ __( 'Improve this answer', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		</>
	);
}
