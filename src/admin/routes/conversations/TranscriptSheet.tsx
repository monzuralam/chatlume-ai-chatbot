/**
 * A conversation transcript in a side panel: details, every message with
 * its source, tokens, cost, timing, and feedback.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	ExternalLink,
	ThumbsDown,
	ThumbsUp,
	Trash2,
	TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Sheet } from '../../components/ui/sheet';
import { Skeleton } from '../../components/ui/skeleton';
import {
	useDeleteConversations,
	useTranscript,
} from '../../hooks/useConversations';
import type { LoggedMessage } from '../../lib/conversations';
import { cn } from '../../lib/utils';
import { formatCost, formatDateTime, sourceLabel } from './format';

function MessageDetails( { message }: { message: LoggedMessage } ) {
	const parts: string[] = [];
	if ( message.source ) {
		parts.push( sourceLabel( message.source ) );
	}
	if ( message.input_tokens || message.output_tokens ) {
		parts.push(
			sprintf(
				/* translators: 1: Input tokens, 2: Output tokens. */
				__( '%1$d in / %2$d out tokens', 'chatlume-ai-chatbot' ),
				message.input_tokens,
				message.output_tokens
			)
		);
	}
	if ( message.cost > 0 ) {
		parts.push( formatCost( message.cost ) );
	}
	if ( message.latency_ms > 0 ) {
		parts.push(
			sprintf(
				/* translators: %s: Seconds. */
				__( '%s s', 'chatlume-ai-chatbot' ),
				( message.latency_ms / 1000 ).toFixed( 1 )
			)
		);
	}

	return (
		<div className="chatlume:mt-1.5 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:gap-x-2 chatlume:gap-y-1 chatlume:text-xs chatlume:text-fg-muted">
			<span>{ parts.join( ' · ' ) }</span>
			{ message.feedback === 1 && (
				<Badge tone="success">
					<ThumbsUp aria-hidden="true" />
					{ __( 'Helpful', 'chatlume-ai-chatbot' ) }
				</Badge>
			) }
			{ message.feedback === -1 && (
				<Badge tone="danger">
					<ThumbsDown aria-hidden="true" />
					{ __( 'Not helpful', 'chatlume-ai-chatbot' ) }
				</Badge>
			) }
			{ message.interrupted === 'stopped' && (
				<Badge>
					{ __( 'Stopped by the visitor', 'chatlume-ai-chatbot' ) }
				</Badge>
			) }
			{ message.error_type && (
				<Badge tone="warning">
					<TriangleAlert aria-hidden="true" />
					{ sprintf(
						/* translators: %s: Error type. */
						__( 'Provider error: %s', 'chatlume-ai-chatbot' ),
						message.error_type
					) }
				</Badge>
			) }
		</div>
	);
}

/**
 * Transcript panel.
 *
 * @param props         Props.
 * @param props.id      Conversation ID (0 = closed).
 * @param props.onClose Closes the panel.
 */
export function TranscriptSheet( {
	id,
	onClose,
}: {
	id: number;
	onClose: () => void;
} ) {
	const transcript = useTranscript( id );
	const remove = useDeleteConversations();
	const [ confirming, setConfirming ] = useState( false );
	const conversation = transcript.data?.conversation;

	return (
		<Sheet
			open={ id > 0 }
			onOpenChange={ ( open ) => ! open && onClose() }
			title={ sprintf(
				/* translators: %d: Conversation number. */
				__( 'Conversation #%d', 'chatlume-ai-chatbot' ),
				id
			) }
			description={
				conversation
					? formatDateTime( conversation.created_at )
					: undefined
			}
			footer={
				<Button
					type="button"
					variant="ghost"
					disabled={ ! conversation || remove.isPending }
					onClick={ () => setConfirming( true ) }
				>
					<Trash2 aria-hidden="true" />
					{ __( 'Delete conversation', 'chatlume-ai-chatbot' ) }
				</Button>
			}
		>
			{ transcript.isPending && (
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-3">
					<Skeleton className="chatlume:h-16 chatlume:w-full" />
					<Skeleton className="chatlume:h-10 chatlume:w-2/3" />
					<Skeleton className="chatlume:ms-auto chatlume:h-10 chatlume:w-1/2" />
				</div>
			) }
			{ transcript.isError && (
				<p
					role="alert"
					className="chatlume:text-sm chatlume:text-danger"
				>
					{ transcript.error.message }
				</p>
			) }
			{ conversation && (
				<>
					<dl className="chatlume:mb-6 chatlume:grid chatlume:grid-cols-[auto_1fr] chatlume:gap-x-4 chatlume:gap-y-1.5 chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-4 chatlume:text-[13px]">
						{ conversation.page_url && (
							<>
								<dt className="chatlume:text-fg-muted">
									{ __( 'Page', 'chatlume-ai-chatbot' ) }
								</dt>
								<dd className="chatlume:min-w-0">
									<a
										href={ conversation.page_url }
										target="_blank"
										rel="noopener noreferrer"
										className="chatlume:inline-flex chatlume:max-w-full chatlume:items-center chatlume:gap-1 chatlume:text-accent chatlume:hover:underline"
									>
										<span className="chatlume:truncate">
											{ conversation.page_url }
										</span>
										<ExternalLink
											aria-hidden="true"
											className="chatlume:size-3.5 chatlume:shrink-0"
										/>
									</a>
								</dd>
							</>
						) }
						<dt className="chatlume:text-fg-muted">
							{ __( 'Language', 'chatlume-ai-chatbot' ) }
						</dt>
						<dd>{ conversation.language || '—' }</dd>
						<dt className="chatlume:text-fg-muted">
							{ __( 'AI model', 'chatlume-ai-chatbot' ) }
						</dt>
						<dd>
							{ conversation.provider
								? `${ conversation.provider } / ${ conversation.model }`
								: __(
										'None (no AI answer)',
										'chatlume-ai-chatbot'
									) }
						</dd>
						<dt className="chatlume:text-fg-muted">
							{ __( 'Estimated cost', 'chatlume-ai-chatbot' ) }
						</dt>
						<dd>{ formatCost( conversation.total_cost ) }</dd>
						{ conversation.lead_email && (
							<>
								<dt className="chatlume:text-fg-muted">
									{ __( 'Lead', 'chatlume-ai-chatbot' ) }
								</dt>
								<dd>
									{ conversation.lead_name &&
										`${ conversation.lead_name } · ` }
									<a
										className="chatlume:text-accent chatlume:hover:underline"
										href={ `mailto:${ conversation.lead_email }` }
									>
										{ conversation.lead_email }
									</a>
								</dd>
							</>
						) }
						<dt className="chatlume:text-fg-muted">
							{ __( 'Visitor', 'chatlume-ai-chatbot' ) }
						</dt>
						<dd>
							{ conversation.logged_in
								? __( 'Logged-in user', 'chatlume-ai-chatbot' )
								: __( 'Guest', 'chatlume-ai-chatbot' ) }
						</dd>
					</dl>

					<ol
						className="chatlume:flex chatlume:flex-col chatlume:gap-4"
						aria-label={ __( 'Messages', 'chatlume-ai-chatbot' ) }
					>
						{ ( transcript.data?.messages ?? [] ).map(
							( message ) => {
								const visitor = message.role === 'user';
								return (
									<li
										key={ message.id }
										className={ cn(
											'chatlume:flex chatlume:flex-col',
											visitor
												? 'chatlume:items-end'
												: 'chatlume:items-start'
										) }
									>
										<span className="chatlume:mb-1 chatlume:text-xs chatlume:font-medium chatlume:text-fg-muted">
											{ visitor
												? __(
														'Visitor',
														'chatlume-ai-chatbot'
													)
												: __(
														'Bot',
														'chatlume-ai-chatbot'
													) }
											{ ' · ' }
											{ formatDateTime(
												message.created_at,
												true
											) }
										</span>
										<div
											className={ cn(
												'chatlume:max-w-[85%] chatlume:whitespace-pre-wrap chatlume:break-words chatlume:rounded-card chatlume:px-3.5 chatlume:py-2.5 chatlume:text-sm',
												visitor
													? 'chatlume:bg-accent chatlume:text-white'
													: 'chatlume:bg-surface-muted chatlume:text-fg'
											) }
										>
											{ message.content ||
												__(
													'(empty)',
													'chatlume-ai-chatbot'
												) }
										</div>
										{ ! visitor && (
											<MessageDetails
												message={ message }
											/>
										) }
										{ ! visitor &&
											message.sources.length > 0 && (
												<ul className="chatlume:mt-1.5 chatlume:flex chatlume:flex-col chatlume:gap-0.5 chatlume:text-xs">
													{ message.sources.map(
														( source, index ) => (
															<li key={ index }>
																<a
																	href={
																		source.url
																	}
																	target="_blank"
																	rel="noopener noreferrer"
																	className="chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:text-accent chatlume:hover:underline"
																>
																	{ source.title ||
																		source.url }
																	<ExternalLink
																		aria-hidden="true"
																		className="chatlume:size-3"
																	/>
																</a>
															</li>
														)
													) }
												</ul>
											) }
									</li>
								);
							}
						) }
					</ol>
				</>
			) }

			<ConfirmDialog
				open={ confirming }
				title={ __(
					'Delete this conversation?',
					'chatlume-ai-chatbot'
				) }
				description={ __(
					'The conversation and all its messages are removed permanently.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Delete', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					setConfirming( false );
					remove.mutate( [ id ], {
						onSuccess: () => {
							toast.success(
								__(
									'Conversation deleted.',
									'chatlume-ai-chatbot'
								)
							);
							onClose();
						},
						onError: ( error ) => toast.error( error.message ),
					} );
				} }
				onCancel={ () => setConfirming( false ) }
			/>
		</Sheet>
	);
}
