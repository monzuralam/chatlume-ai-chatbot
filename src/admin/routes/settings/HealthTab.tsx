/**
 * Settings > Health check.
 */
import { __, sprintf } from '@wordpress/i18n';
import {
	CircleAlert,
	CircleCheck,
	Info,
	LoaderCircle,
	RefreshCw,
	TriangleAlert,
	Zap,
} from 'lucide-react';

import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { Skeleton } from '../../components/ui/skeleton';
import { useHealth, useStreamTest } from '../../hooks/useHealth';
import type { CheckStatus, HealthCheckItem } from '../../lib/health';
import { cn } from '../../lib/utils';

const ORDER: CheckStatus[] = [ 'critical', 'recommended', 'good', 'info' ];

const ICONS = {
	critical: CircleAlert,
	recommended: TriangleAlert,
	good: CircleCheck,
	info: Info,
};

const TONES = {
	critical: 'chatlume:text-danger',
	recommended: 'chatlume:text-warning',
	good: 'chatlume:text-success',
	info: 'chatlume:text-fg-muted',
};

/**
 * Label of a status.
 *
 * @param status Status.
 * @return Label.
 */
function statusLabel( status: CheckStatus ): string {
	switch ( status ) {
		case 'critical':
			return __( 'Needs attention', 'chatlume-ai-chatbot' );
		case 'recommended':
			return __( 'Recommended', 'chatlume-ai-chatbot' );
		case 'good':
			return __( 'Passed', 'chatlume-ai-chatbot' );
		default:
			return __( 'Information', 'chatlume-ai-chatbot' );
	}
}

function CheckRow( { check }: { check: HealthCheckItem } ) {
	const Icon = ICONS[ check.status ];
	return (
		<li className="chatlume:flex chatlume:gap-3 chatlume:py-4 chatlume:first:pt-0 chatlume:last:pb-0">
			<Icon
				aria-hidden="true"
				className={ cn(
					'chatlume:mt-0.5 chatlume:size-5 chatlume:shrink-0',
					TONES[ check.status ]
				) }
			/>
			<div className="chatlume:min-w-0 chatlume:flex-1">
				<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
					{ check.label }
					<span className="chatlume:sr-only">
						{ ' — ' + statusLabel( check.status ) }
					</span>
				</p>
				<p className="chatlume:mt-0.5 chatlume:text-[13px] chatlume:text-fg-muted">
					{ check.detail }
				</p>
				{ check.fix && (
					<p className="chatlume:mt-2 chatlume:rounded-control chatlume:bg-surface-muted chatlume:px-3 chatlume:py-2 chatlume:text-[13px] chatlume:text-fg">
						<strong className="chatlume:font-semibold">
							{ __( 'How to fix:', 'chatlume-ai-chatbot' ) }
						</strong>{ ' ' }
						{ check.fix }
					</p>
				) }
			</div>
		</li>
	);
}

function StreamTestCard() {
	const test = useStreamTest();
	const result = test.data;

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Live streaming test', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Checks whether your server and any proxy or CDN in front of it deliver replies word by word. Takes about two seconds and does not contact any AI provider.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-4">
				<div>
					<Button
						type="button"
						onClick={ () => test.mutate() }
						disabled={ test.isPending }
					>
						{ test.isPending ? (
							<LoaderCircle
								aria-hidden="true"
								className="chatlume:animate-spin"
							/>
						) : (
							<Zap aria-hidden="true" />
						) }
						{ test.isPending
							? __( 'Testing…', 'chatlume-ai-chatbot' )
							: __(
									'Run streaming test',
									'chatlume-ai-chatbot'
								) }
					</Button>
				</div>
				<div aria-live="polite">
					{ test.isError && (
						<p className="chatlume:text-sm chatlume:text-danger">
							{ test.error.message }
						</p>
					) }
					{ result && result.streaming && (
						<p className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:text-sm chatlume:text-success">
							<CircleCheck
								aria-hidden="true"
								className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
							/>
							{ sprintf(
								/* translators: 1: Number of events, 2: Milliseconds. */
								__(
									'Streaming works: %1$d updates arrived one by one over %2$d ms.',
									'chatlume-ai-chatbot'
								),
								result.events,
								result.spreadMs
							) }
						</p>
					) }
					{ result && ! result.streaming && (
						<div className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:text-sm chatlume:text-warning">
							<TriangleAlert
								aria-hidden="true"
								className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
							/>
							<div>
								<p>
									{ __(
										'Replies arrived all at once, so something between your server and the browser holds them back. The chat still works: the widget notices this and shows each reply when it is complete.',
										'chatlume-ai-chatbot'
									) }
								</p>
								<p className="chatlume:mt-1 chatlume:text-fg">
									<strong>
										{ __(
											'How to fix:',
											'chatlume-ai-chatbot'
										) }
									</strong>{ ' ' }
									{ __(
										'Ask your host to turn off response buffering and compression for /wp-json/chatlume/v1/chat/stream (for nginx: proxy_buffering off; for Apache: no-gzip for that path).',
										'chatlume-ai-chatbot'
									) }
								</p>
							</div>
						</div>
					) }
				</div>
			</CardContent>
		</Card>
	);
}

/**
 * The Health check tab.
 */
export function HealthTab() {
	const health = useHealth();
	const checks = [ ...( health.data?.checks ?? [] ) ].sort(
		( a, b ) => ORDER.indexOf( a.status ) - ORDER.indexOf( b.status )
	);
	const problems = checks.filter(
		( check ) =>
			check.status === 'critical' || check.status === 'recommended'
	).length;

	return (
		<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
			<Card>
				<CardHeader className="chatlume:flex-row chatlume:items-start chatlume:justify-between chatlume:gap-4">
					<div>
						<CardTitle className="chatlume:flex chatlume:items-center chatlume:gap-2">
							{ __( 'Health check', 'chatlume-ai-chatbot' ) }
							{ health.isSuccess && (
								<Badge
									tone={ problems ? 'warning' : 'success' }
								>
									{ problems
										? sprintf(
												/* translators: %d: Number of problems. */
												__(
													'%d to review',
													'chatlume-ai-chatbot'
												),
												problems
											)
										: __(
												'All good',
												'chatlume-ai-chatbot'
											) }
								</Badge>
							) }
						</CardTitle>
						<CardDescription>
							{ __(
								'Checks the things Chatlume depends on and explains how to fix anything that is not right.',
								'chatlume-ai-chatbot'
							) }
						</CardDescription>
					</div>
					<Button
						type="button"
						size="sm"
						onClick={ () => health.refetch() }
						disabled={ health.isFetching }
					>
						<RefreshCw
							aria-hidden="true"
							className={ cn(
								health.isFetching && 'chatlume:animate-spin'
							) }
						/>
						{ __( 'Run checks again', 'chatlume-ai-chatbot' ) }
					</Button>
				</CardHeader>
				<CardContent>
					{ health.isPending && (
						<div className="chatlume:flex chatlume:flex-col chatlume:gap-4">
							{ [ 0, 1, 2, 3 ].map( ( index ) => (
								<div
									key={ index }
									className="chatlume:flex chatlume:gap-3"
								>
									<Skeleton className="chatlume:size-5 chatlume:rounded-full" />
									<div className="chatlume:flex-1">
										<Skeleton className="chatlume:h-4 chatlume:w-48" />
										<Skeleton className="chatlume:mt-2 chatlume:h-3 chatlume:w-96 chatlume:max-w-full" />
									</div>
								</div>
							) ) }
						</div>
					) }
					{ health.isError && (
						<p
							role="alert"
							className="chatlume:text-sm chatlume:text-danger"
						>
							{ health.error.message }
						</p>
					) }
					{ health.isSuccess && (
						<ul className="chatlume:divide-y chatlume:divide-border">
							{ checks.map( ( check ) => (
								<CheckRow key={ check.id } check={ check } />
							) ) }
						</ul>
					) }
				</CardContent>
			</Card>

			<StreamTestCard />
		</div>
	);
}
