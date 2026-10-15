/**
 * Dashboard cards: connection health, unanswered questions, recent
 * conversations, and the setup checklist.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	CircleCheck,
	Circle,
	MessageSquarePlus,
	ThumbsDown,
	ThumbsUp,
	TriangleAlert,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router';

import {
	ProviderAvatar,
	StateBadge,
} from '../../components/providers/ProviderBits';
import { QuickAnswerSheet } from '../../components/QuickAnswerSheet';
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
import { pageRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { useProviders } from '../../hooks/useProviders';
import { getBootstrap } from '../../lib/bootstrap';
import {
	budgetShare,
	reasonLabel,
	type ChecklistStep,
	type StatsResponse,
	type UnansweredQuestion,
} from '../../lib/stats';
import { cn } from '../../lib/utils';
import { formatCost, formatDateTime } from '../conversations/format';

/**
 * Paths of registered pages (links point only to pages that exist).
 *
 * @return Paths.
 */
function useRegisteredPaths(): Set< string > {
	return new Set( useRegistry( pageRegistry ).map( ( page ) => page.path ) );
}

/**
 * Bar color for a share of a budget.
 *
 * @param share Percentage used.
 * @return Class.
 */
function barTone( share: number ): string {
	if ( share >= 100 ) {
		return 'chatlume:bg-danger';
	}
	return share >= 80 ? 'chatlume:bg-warning' : 'chatlume:bg-accent';
}

function UsageBar( {
	label,
	used,
	budget,
	format,
}: {
	label: string;
	used: number;
	budget: number;
	format: ( value: number ) => string;
} ) {
	const share = budgetShare( used, budget );
	if ( share === null ) {
		return null;
	}
	return (
		<div>
			<div className="chatlume:mb-1 chatlume:flex chatlume:justify-between chatlume:text-xs chatlume:text-fg-muted">
				<span>{ label }</span>
				<span>
					{ sprintf(
						/* translators: 1: Amount used, 2: Budget. */
						__( '%1$s of %2$s', 'chatlume-ai-chatbot' ),
						format( used ),
						format( budget )
					) }
				</span>
			</div>
			<div
				className="chatlume:h-2 chatlume:overflow-hidden chatlume:rounded-full chatlume:bg-surface-muted"
				role="progressbar"
				aria-label={ label }
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ Math.min( 100, share ) }
			>
				<div
					className={ cn(
						'chatlume:h-full chatlume:rounded-full',
						barTone( share )
					) }
					style={ { width: `${ Math.min( 100, share ) }%` } }
				/>
			</div>
		</div>
	);
}

/**
 * Provider connection, budgets, and installation warnings.
 *
 * @param props       Props.
 * @param props.stats Statistics.
 */
export function HealthCard( { stats }: { stats?: StatsResponse } ) {
	const providers = useProviders();
	const { system } = getBootstrap();
	const roles = providers.data?.roles;
	const find = ( id?: string ) =>
		providers.data?.providers.find( ( provider ) => provider.id === id );
	const primary = find( roles?.primary.provider );
	const fallback = find( roles?.fallback.provider );
	const budget = stats?.budget;
	const tokens = budget?.unit === 'tokens';
	const format = ( value: number ) =>
		tokens ? value.toLocaleString() : formatCost( value );

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Connection', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'The AI behind your bot and how much of your budget is used.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent className="chatlume:flex chatlume:flex-col chatlume:gap-4">
				{ providers.isPending && (
					<Skeleton className="chatlume:h-12 chatlume:w-full" />
				) }
				{ providers.isSuccess && ! primary && (
					<div className="chatlume:flex chatlume:flex-col chatlume:items-start chatlume:gap-3 chatlume:rounded-control chatlume:bg-surface-muted chatlume:p-3 chatlume:text-[13px]">
						<p>
							{ __(
								'No AI provider yet. Your bot already answers with quick answers and site search; connect a provider for full AI answers.',
								'chatlume-ai-chatbot'
							) }
						</p>
						<Button asChild size="sm" variant="primary">
							<Link to="/providers">
								{ __(
									'Connect a provider',
									'chatlume-ai-chatbot'
								) }
							</Link>
						</Button>
					</div>
				) }
				{ primary && (
					<Link
						to="/providers"
						className="chatlume:flex chatlume:items-center chatlume:gap-3 chatlume:rounded-control chatlume:p-1 chatlume:hover:bg-surface-muted chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
					>
						<ProviderAvatar initials={ primary.initials } />
						<div className="chatlume:min-w-0 chatlume:flex-1">
							<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
								{ primary.label }
							</p>
							<p className="chatlume:truncate chatlume:text-xs chatlume:text-fg-muted">
								{ roles?.primary.model ||
									__(
										'Default model',
										'chatlume-ai-chatbot'
									) }
								{ fallback &&
									' · ' +
										sprintf(
											/* translators: %s: Provider name. */
											__(
												'fallback: %s',
												'chatlume-ai-chatbot'
											),
											fallback.label
										) }
							</p>
						</div>
						<StateBadge provider={ primary } />
					</Link>
				) }

				{ budget && (
					<>
						<UsageBar
							label={ __( 'Today', 'chatlume-ai-chatbot' ) }
							used={
								tokens ? budget.day_tokens : budget.day_cost
							}
							budget={ budget.daily }
							format={ format }
						/>
						<UsageBar
							label={ __( 'This month', 'chatlume-ai-chatbot' ) }
							used={
								tokens ? budget.month_tokens : budget.month_cost
							}
							budget={ budget.monthly }
							format={ format }
						/>
						{ ! budget.daily && ! budget.monthly && (
							<p className="chatlume:text-[13px] chatlume:text-fg-muted">
								{ __(
									'No budget limits set.',
									'chatlume-ai-chatbot'
								) }{ ' ' }
								<Link
									to="/chatbot"
									className="chatlume:text-accent chatlume:hover:underline"
								>
									{ __(
										'Set a budget',
										'chatlume-ai-chatbot'
									) }
								</Link>
							</p>
						) }
					</>
				) }

				{ ( system.encryption !== 'ok' ||
					system.dbVersion < system.dbVersionExpected ) && (
					<p className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:rounded-control chatlume:bg-warning-soft chatlume:px-3 chatlume:py-2 chatlume:text-[13px] chatlume:text-warning">
						<TriangleAlert
							aria-hidden="true"
							className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0"
						/>
						<span>
							{ __(
								'Something needs attention.',
								'chatlume-ai-chatbot'
							) }{ ' ' }
							<Link
								to="/settings?tab=health"
								className="chatlume:font-medium chatlume:underline"
							>
								{ __(
									'Open the health check',
									'chatlume-ai-chatbot'
								) }
							</Link>
						</span>
					</p>
				) }
			</CardContent>
		</Card>
	);
}

/**
 * Questions the bot could not answer well, with "Create quick answer".
 *
 * @param props       Props.
 * @param props.stats Statistics.
 */
export function UnansweredCard( { stats }: { stats?: StatsResponse } ) {
	const [ chosen, setChosen ] = useState< UnansweredQuestion | null >( null );
	const list = stats?.unanswered ?? [];
	const draft = useMemo(
		() =>
			chosen && {
				question: chosen.question,
				answer: '',
				language: chosen.language,
			},
		[ chosen ]
	);

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __(
						'Questions your bot couldn’t answer',
						'chatlume-ai-chatbot'
					) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Turn them into quick answers, or add the missing information to your website.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ ! stats && (
					<Skeleton className="chatlume:h-24 chatlume:w-full" />
				) }
				{ stats && list.length === 0 && (
					<p className="chatlume:text-sm chatlume:text-fg-muted">
						{ __(
							'Nothing here for this period. Your bot answered everything it was asked.',
							'chatlume-ai-chatbot'
						) }
					</p>
				) }
				{ list.length > 0 && (
					<ul className="chatlume:divide-y chatlume:divide-border">
						{ list.map( ( item ) => (
							<li
								key={ item.question }
								className="chatlume:flex chatlume:items-start chatlume:gap-3 chatlume:py-3 chatlume:first:pt-0 chatlume:last:pb-0"
							>
								<div className="chatlume:min-w-0 chatlume:flex-1">
									<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
										{ item.question }
										{ item.count > 1 && (
											<span className="chatlume:ms-2 chatlume:text-xs chatlume:font-normal chatlume:text-fg-muted">
												{ sprintf(
													/* translators: %d: Number of times asked. */
													_n(
														'asked %d time',
														'asked %d times',
														item.count,
														'chatlume-ai-chatbot'
													),
													item.count
												) }
											</span>
										) }
									</p>
									<div className="chatlume:mt-1 chatlume:flex chatlume:flex-wrap chatlume:gap-1">
										{ item.reasons.map( ( reason ) => (
											<Badge
												key={ reason }
												tone={
													reason === 'not_helpful'
														? 'danger'
														: 'neutral'
												}
											>
												{ reasonLabel( reason ) }
											</Badge>
										) ) }
									</div>
								</div>
								<Button
									type="button"
									size="sm"
									onClick={ () => setChosen( item ) }
								>
									<MessageSquarePlus aria-hidden="true" />
									{ __(
										'Create quick answer',
										'chatlume-ai-chatbot'
									) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
			</CardContent>
			<QuickAnswerSheet
				draft={ draft }
				onClose={ () => setChosen( null ) }
			/>
		</Card>
	);
}

/**
 * The five newest conversations.
 *
 * @param props       Props.
 * @param props.stats Statistics.
 */
export function RecentCard( { stats }: { stats?: StatsResponse } ) {
	const recent = stats?.recent ?? [];

	return (
		<Card>
			<CardHeader className="chatlume:flex-row chatlume:items-start chatlume:justify-between chatlume:gap-4">
				<div>
					<CardTitle>
						{ __( 'Recent conversations', 'chatlume-ai-chatbot' ) }
					</CardTitle>
					<CardDescription>
						{ __(
							'The latest chats on your site.',
							'chatlume-ai-chatbot'
						) }
					</CardDescription>
				</div>
				{ recent.length > 0 && (
					<Button asChild size="sm" variant="ghost">
						<Link to="/conversations">
							{ __( 'View all', 'chatlume-ai-chatbot' ) }
						</Link>
					</Button>
				) }
			</CardHeader>
			<CardContent>
				{ ! stats && (
					<Skeleton className="chatlume:h-24 chatlume:w-full" />
				) }
				{ stats && recent.length === 0 && (
					<p className="chatlume:text-sm chatlume:text-fg-muted">
						{ __( 'No conversations yet.', 'chatlume-ai-chatbot' ) }
					</p>
				) }
				{ recent.length > 0 && (
					<ul className="chatlume:divide-y chatlume:divide-border">
						{ recent.map( ( item ) => (
							<li key={ item.id }>
								<Link
									to={ `/conversations?id=${ item.id }` }
									className="chatlume:flex chatlume:items-center chatlume:gap-3 chatlume:py-2.5 chatlume:hover:text-accent chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring chatlume:rounded-sm"
								>
									<span className="chatlume:min-w-0 chatlume:flex-1 chatlume:truncate chatlume:text-sm chatlume:font-medium">
										{ item.first_question ||
											__(
												'(no question)',
												'chatlume-ai-chatbot'
											) }
									</span>
									{ item.rating === 1 && (
										<ThumbsUp
											className="chatlume:size-4 chatlume:text-success"
											aria-label={ __(
												'Helpful',
												'chatlume-ai-chatbot'
											) }
										/>
									) }
									{ item.rating === -1 && (
										<ThumbsDown
											className="chatlume:size-4 chatlume:text-danger"
											aria-label={ __(
												'Not helpful',
												'chatlume-ai-chatbot'
											) }
										/>
									) }
									<span className="chatlume:whitespace-nowrap chatlume:text-xs chatlume:text-fg-muted">
										{ formatDateTime( item.updated_at ) }
									</span>
								</Link>
							</li>
						) ) }
					</ul>
				) }
			</CardContent>
		</Card>
	);
}

/**
 * Label of a setup step.
 *
 * @param id Step ID.
 * @return Label.
 */
function stepLabel( id: ChecklistStep[ 'id' ] ): string {
	switch ( id ) {
		case 'provider':
			return __( 'Connect an AI provider', 'chatlume-ai-chatbot' );
		case 'personalize':
			return __(
				'Give your bot a name and personality',
				'chatlume-ai-chatbot'
			);
		case 'widget':
			return __( 'Show the chat on your site', 'chatlume-ai-chatbot' );
		case 'quick_answers':
			return __(
				'Add quick answers to common questions',
				'chatlume-ai-chatbot'
			);
		case 'first_chat':
			return __( 'Get your first conversation', 'chatlume-ai-chatbot' );
	}
	return id;
}

/**
 * Setup checklist (hidden once everything is done).
 *
 * @param props       Props.
 * @param props.steps Steps.
 */
export function ChecklistCard( { steps }: { steps: ChecklistStep[] } ) {
	const paths = useRegisteredPaths();
	const done = steps.filter( ( step ) => step.done ).length;

	if ( ! steps.length || done === steps.length ) {
		return null;
	}

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Get set up', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ sprintf(
						/* translators: 1: Steps done, 2: Total steps. */
						__( '%1$d of %2$d done', 'chatlume-ai-chatbot' ),
						done,
						steps.length
					) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				<ol className="chatlume:grid chatlume:gap-2 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-3">
					{ steps.map( ( step ) => {
						const Icon = step.done ? CircleCheck : Circle;
						const content = (
							<>
								<Icon
									aria-hidden="true"
									className={ cn(
										'chatlume:size-5 chatlume:shrink-0',
										step.done
											? 'chatlume:text-success'
											: 'chatlume:text-fg-muted'
									) }
								/>
								<span
									className={ cn(
										step.done &&
											'chatlume:text-fg-muted chatlume:line-through'
									) }
								>
									{ stepLabel( step.id ) }
								</span>
								<span className="chatlume:sr-only">
									{ step.done
										? __( '(done)', 'chatlume-ai-chatbot' )
										: __(
												'(to do)',
												'chatlume-ai-chatbot'
											) }
								</span>
							</>
						);
						const classes =
							'chatlume:flex chatlume:items-center chatlume:gap-2.5 chatlume:rounded-control chatlume:border chatlume:border-border chatlume:px-3 chatlume:py-2.5 chatlume:text-sm';
						return (
							<li key={ step.id }>
								{ ! step.done && paths.has( step.route ) ? (
									<Link
										to={ step.route }
										className={ cn(
											classes,
											'chatlume:hover:border-accent chatlume:hover:text-accent chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring'
										) }
									>
										{ content }
									</Link>
								) : (
									<div className={ classes }>{ content }</div>
								) }
							</li>
						);
					} ) }
				</ol>
			</CardContent>
		</Card>
	);
}
