/**
 * Settings > Logs: provider errors and the optional debug log.
 */
import { __ } from '@wordpress/i18n';
import { ExternalLink, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';

import { EmptyState } from '../../components/layout/EmptyState';
import { ConfirmDialog } from '../../components/ui/alert-dialog';
import { Button } from '../../components/ui/button';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { Skeleton } from '../../components/ui/skeleton';
import { useClearLog, useHealth } from '../../hooks/useHealth';
import {
	describeDebugEntry,
	type DebugEntry,
	type ProviderErrorEntry,
} from '../../lib/health';

const dateFormat = new Intl.DateTimeFormat( undefined, {
	dateStyle: 'medium',
	timeStyle: 'short',
} );

/**
 * Formatted time of a log entry.
 *
 * @param seconds Unix time.
 * @return Text.
 */
function when( seconds: number ): string {
	return dateFormat.format( new Date( seconds * 1000 ) );
}

function ClearButton( {
	log,
	label,
	confirmTitle,
}: {
	log: 'errors' | 'debug';
	label: string;
	confirmTitle: string;
} ) {
	const clear = useClearLog( log );
	const [ asking, setAsking ] = useState( false );

	return (
		<>
			<Button
				type="button"
				size="sm"
				variant="ghost"
				disabled={ clear.isPending }
				onClick={ () => setAsking( true ) }
			>
				<Trash2 aria-hidden="true" />
				{ label }
			</Button>
			<ConfirmDialog
				open={ asking }
				title={ confirmTitle }
				description={ __(
					'This cannot be undone.',
					'chatlume-ai-chatbot'
				) }
				confirmLabel={ __( 'Clear', 'chatlume-ai-chatbot' ) }
				cancelLabel={ __( 'Cancel', 'chatlume-ai-chatbot' ) }
				tone="danger"
				onConfirm={ () => {
					setAsking( false );
					clear.mutate( undefined, {
						onSuccess: () =>
							toast.success(
								__( 'Log cleared.', 'chatlume-ai-chatbot' )
							),
						onError: ( error ) => toast.error( error.message ),
					} );
				} }
				onCancel={ () => setAsking( false ) }
			/>
		</>
	);
}

function ProviderErrors( { entries }: { entries: ProviderErrorEntry[] } ) {
	if ( ! entries.length ) {
		return (
			<p className="chatlume:text-sm chatlume:text-fg-muted">
				{ __(
					'No provider errors recorded. 🎉',
					'chatlume-ai-chatbot'
				) }
			</p>
		);
	}
	return (
		<ul className="chatlume:divide-y chatlume:divide-border">
			{ entries.map( ( entry, index ) => (
				<li
					key={ `${ entry.time }-${ index }` }
					className="chatlume:py-4 chatlume:first:pt-0 chatlume:last:pb-0"
				>
					<div className="chatlume:flex chatlume:flex-wrap chatlume:items-baseline chatlume:justify-between chatlume:gap-2">
						<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ entry.title }
						</p>
						<time
							dateTime={ new Date(
								entry.time * 1000
							).toISOString() }
							className="chatlume:text-xs chatlume:text-fg-muted"
						>
							{ when( entry.time ) }
						</time>
					</div>
					<p className="chatlume:mt-0.5 chatlume:text-xs chatlume:text-fg-muted">
						{ [
							entry.provider,
							entry.model,
							entry.status ? `HTTP ${ entry.status }` : '',
						]
							.filter( Boolean )
							.join( ' · ' ) }
					</p>
					<p className="chatlume:mt-2 chatlume:text-[13px] chatlume:text-fg">
						{ entry.explanation }
					</p>
					{ entry.detail && (
						<p className="chatlume:mt-1 chatlume:break-words chatlume:font-mono chatlume:text-xs chatlume:text-fg-muted">
							{ entry.detail }
						</p>
					) }
					{ entry.fix && (
						<a
							href={ entry.fix.url }
							className="chatlume:mt-2 chatlume:inline-flex chatlume:items-center chatlume:gap-1 chatlume:text-[13px] chatlume:font-medium chatlume:text-accent chatlume:hover:underline"
							{ ...( /^https?:/.test( entry.fix.url ) &&
							! entry.fix.url.includes( window.location.host )
								? {
										target: '_blank',
										rel: 'noopener noreferrer',
									}
								: {} ) }
						>
							{ entry.fix.label }
							<ExternalLink
								aria-hidden="true"
								className="chatlume:size-3.5"
							/>
						</a>
					) }
				</li>
			) ) }
		</ul>
	);
}

function DebugTable( { entries }: { entries: DebugEntry[] } ) {
	return (
		<div className="chatlume:overflow-x-auto">
			<table className="chatlume:w-full chatlume:text-left chatlume:text-[13px]">
				<thead>
					<tr className="chatlume:border-b chatlume:border-border chatlume:text-xs chatlume:text-fg-muted">
						<th
							scope="col"
							className="chatlume:py-2 chatlume:pe-4 chatlume:font-medium"
						>
							{ __( 'Time', 'chatlume-ai-chatbot' ) }
						</th>
						<th
							scope="col"
							className="chatlume:py-2 chatlume:pe-4 chatlume:font-medium"
						>
							{ __( 'Event', 'chatlume-ai-chatbot' ) }
						</th>
						<th
							scope="col"
							className="chatlume:py-2 chatlume:pe-4 chatlume:font-medium"
						>
							{ __( 'Details', 'chatlume-ai-chatbot' ) }
						</th>
						<th
							scope="col"
							className="chatlume:py-2 chatlume:text-end chatlume:font-medium"
						>
							{ __( 'Time taken', 'chatlume-ai-chatbot' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ entries.map( ( entry, index ) => (
						<tr
							key={ `${ entry.time }-${ index }` }
							className="chatlume:border-b chatlume:border-border chatlume:last:border-0"
						>
							<td className="chatlume:whitespace-nowrap chatlume:py-2 chatlume:pe-4 chatlume:text-fg-muted">
								{ when( entry.time ) }
							</td>
							<td className="chatlume:py-2 chatlume:pe-4">
								{ entry.event }
							</td>
							<td className="chatlume:py-2 chatlume:pe-4">
								{ describeDebugEntry( entry ) }
							</td>
							<td className="chatlume:whitespace-nowrap chatlume:py-2 chatlume:text-end chatlume:text-fg-muted">
								{ entry.latency_ms !== undefined
									? `${ entry.latency_ms } ms`
									: '' }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * The Logs tab.
 */
export function LogsTab() {
	const health = useHealth();

	if ( health.isPending ) {
		return (
			<Card>
				<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-3">
					<Skeleton className="chatlume:h-5 chatlume:w-48" />
					<Skeleton className="chatlume:h-16 chatlume:w-full" />
				</CardContent>
			</Card>
		);
	}

	if ( health.isError ) {
		return (
			<p role="alert" className="chatlume:text-sm chatlume:text-danger">
				{ health.error.message }
			</p>
		);
	}

	const { errors, debug } = health.data;

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
			<Card>
				<CardHeader className="chatlume:flex-row chatlume:items-start chatlume:justify-between chatlume:gap-4">
					<div>
						<CardTitle>
							{ __( 'Provider errors', 'chatlume-ai-chatbot' ) }
						</CardTitle>
						<CardDescription>
							{ __(
								'The latest 50 times an AI provider rejected or failed a request, with what it means and how to fix it.',
								'chatlume-ai-chatbot'
							) }
						</CardDescription>
					</div>
					{ errors.length > 0 && (
						<ClearButton
							log="errors"
							label={ __( 'Clear', 'chatlume-ai-chatbot' ) }
							confirmTitle={ __(
								'Clear the provider error log?',
								'chatlume-ai-chatbot'
							) }
						/>
					) }
				</CardHeader>
				<CardContent>
					<ProviderErrors entries={ errors } />
				</CardContent>
			</Card>

			<Card>
				<CardHeader className="chatlume:flex-row chatlume:items-start chatlume:justify-between chatlume:gap-4">
					<div>
						<CardTitle>
							{ __( 'Debug log', 'chatlume-ai-chatbot' ) }
						</CardTitle>
						<CardDescription>
							{ __(
								'A summary of recent chat events. Message text and visitor details are never recorded.',
								'chatlume-ai-chatbot'
							) }
						</CardDescription>
					</div>
					{ debug.entries.length > 0 && (
						<ClearButton
							log="debug"
							label={ __( 'Clear', 'chatlume-ai-chatbot' ) }
							confirmTitle={ __(
								'Clear the debug log?',
								'chatlume-ai-chatbot'
							) }
						/>
					) }
				</CardHeader>
				<CardContent>
					{ debug.entries.length > 0 ? (
						<DebugTable entries={ debug.entries } />
					) : (
						<EmptyState
							title={
								debug.enabled
									? __(
											'No events yet',
											'chatlume-ai-chatbot'
										)
									: __(
											'Debug logging is off',
											'chatlume-ai-chatbot'
										)
							}
							description={
								debug.enabled
									? __(
											'Chat with your bot and the events appear here.',
											'chatlume-ai-chatbot'
										)
									: __(
											'Turn it on under General > Troubleshooting when you need to investigate a problem.',
											'chatlume-ai-chatbot'
										)
							}
							action={
								debug.enabled ? undefined : (
									<Button asChild size="sm">
										<Link to="/settings?tab=general">
											{ __(
												'Open Troubleshooting',
												'chatlume-ai-chatbot'
											) }
										</Link>
									</Button>
								)
							}
						/>
					) }
				</CardContent>
			</Card>
		</div>
	);
}
