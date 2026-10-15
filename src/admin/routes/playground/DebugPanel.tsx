import { __, sprintf } from '@wordpress/i18n';
import { Info } from 'lucide-react';
import type { ReactNode } from 'react';

import { Badge } from '../../components/ui/badge';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import {
	cacheLabel,
	explainReply,
	promptText,
	type PlaygroundTurn,
} from '../../lib/playground';
import { formatCost, sourceLabel } from '../conversations/format';

function Row( { label, children }: { label: string; children: ReactNode } ) {
	return (
		<div className="chatlume:flex chatlume:justify-between chatlume:gap-4 chatlume:py-1.5 chatlume:text-sm">
			<dt className="chatlume:text-fg-muted">{ label }</dt>
			<dd className="chatlume:min-w-0 chatlume:break-words chatlume:text-right chatlume:font-medium chatlume:text-fg">
				{ children }
			</dd>
		</div>
	);
}

/**
 * Why the selected reply was given: route, provider, usage, knowledge, and
 * the final prompt sent to the AI.
 *
 * @param props      Props.
 * @param props.turn Selected reply.
 */
export function DebugPanel( {
	turn,
}: {
	turn?: Extract< PlaygroundTurn, { role: 'assistant' } >;
} ) {
	if ( ! turn ) {
		return (
			<Card>
				<CardContent className="chatlume:flex chatlume:items-start chatlume:gap-3 chatlume:pt-6 chatlume:text-sm chatlume:text-fg-muted">
					<Info
						aria-hidden="true"
						className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
					/>
					{ __(
						'Send a message, then select a reply to see how it was produced.',
						'chatlume-ai-chatbot'
					) }
				</CardContent>
			</Card>
		);
	}

	const { reply, details } = turn.data;
	const { trace } = details;
	const prompt = promptText( trace.prompt );
	const usedAi = details.provider !== '';

	return (
		<Card aria-label={ __( 'Reply details', 'chatlume-ai-chatbot' ) }>
			<CardHeader>
				<CardTitle>
					{ __( 'Why this answer', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>{ explainReply( turn.data ) }</CardDescription>
			</CardHeader>
			<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-5">
				<dl className="chatlume:divide-y chatlume:divide-border">
					<Row label={ __( 'Answered by', 'chatlume-ai-chatbot' ) }>
						{ sourceLabel( reply.source ) }
					</Row>
					{ usedAi && (
						<Row label={ __( 'Provider', 'chatlume-ai-chatbot' ) }>
							{ details.model
								? `${ details.provider } · ${ details.model }`
								: details.provider }
						</Row>
					) }
					{ usedAi && (
						<Row label={ __( 'Tokens', 'chatlume-ai-chatbot' ) }>
							{ sprintf(
								/* translators: 1: Input tokens, 2: Output tokens. */
								__(
									'%1$d in · %2$d out',
									'chatlume-ai-chatbot'
								),
								details.input_tokens,
								details.output_tokens
							) }
						</Row>
					) }
					<Row
						label={ __( 'Estimated cost', 'chatlume-ai-chatbot' ) }
					>
						{ formatCost( details.cost ) }
					</Row>
					<Row label={ __( 'Time', 'chatlume-ai-chatbot' ) }>
						{ sprintf(
							/* translators: %d: Milliseconds. */
							__( '%d ms', 'chatlume-ai-chatbot' ),
							details.latency_ms
						) }
					</Row>
					{ trace.quick_answer && (
						<Row
							label={ __(
								'Quick answer',
								'chatlume-ai-chatbot'
							) }
						>
							{ sprintf(
								/* translators: 1: Question, 2: Match score in percent. */
								__( '“%1$s” (%2$d%%)', 'chatlume-ai-chatbot' ),
								trace.quick_answer.question,
								Math.round( trace.quick_answer.score * 100 )
							) }
						</Row>
					) }
					<Row
						label={ __( 'Response cache', 'chatlume-ai-chatbot' ) }
					>
						{ cacheLabel( trace.cache ) }
					</Row>
					{ details.error_type && (
						<Row label={ __( 'Error', 'chatlume-ai-chatbot' ) }>
							<Badge tone="danger">{ details.error_type }</Badge>
						</Row>
					) }
					{ reply.interrupted && (
						<Row
							label={ __( 'Interrupted', 'chatlume-ai-chatbot' ) }
						>
							{ reply.interrupted }
						</Row>
					) }
				</dl>

				{ trace.knowledge.length > 0 && (
					<section>
						<h3 className="chatlume:mb-2 chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
							{ __( 'Knowledge used', 'chatlume-ai-chatbot' ) }
						</h3>
						<ol className="chatlume:flex chatlume:flex-col chatlume:gap-2">
							{ trace.knowledge.map( ( chunk, index ) => (
								<li
									key={ index }
									className="chatlume:rounded-control chatlume:border chatlume:border-border chatlume:p-3 chatlume:text-xs"
								>
									<div className="chatlume:mb-1 chatlume:flex chatlume:items-center chatlume:justify-between chatlume:gap-2">
										<span className="chatlume:min-w-0 chatlume:truncate chatlume:font-medium chatlume:text-fg">
											{ chunk.title ||
												chunk.url ||
												__(
													'Untitled',
													'chatlume-ai-chatbot'
												) }
										</span>
										{ chunk.score !== null && (
											<Badge>
												{ sprintf(
													/* translators: %s: Relevance score. */
													__(
														'score %s',
														'chatlume-ai-chatbot'
													),
													chunk.score.toFixed( 2 )
												) }
											</Badge>
										) }
									</div>
									<p className="chatlume:line-clamp-3 chatlume:text-fg-muted">
										{ chunk.excerpt }
									</p>
								</li>
							) ) }
						</ol>
					</section>
				) }

				{ prompt && (
					<details className="chatlume:group">
						<summary className="chatlume:cursor-pointer chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
							{ __(
								'Final prompt sent to the AI',
								'chatlume-ai-chatbot'
							) }
						</summary>
						<pre className="chatlume:mt-2 chatlume:max-h-96 chatlume:overflow-auto chatlume:whitespace-pre-wrap chatlume:break-words chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-3 chatlume:font-mono chatlume:text-xs chatlume:text-fg">
							{ prompt }
						</pre>
					</details>
				) }
			</CardContent>
		</Card>
	);
}
