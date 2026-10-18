/**
 * Dashboard: key numbers for today / 7 / 30 days, a daily chart,
 * connection health, unanswered questions, recent conversations, the setup
 * checklist, and cards registered by add-ons.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from 'react';
import { Link } from 'react-router';

import { BarChart } from '../../components/charts/BarChart';
import { PageHeader } from '../../components/layout/PageHeader';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { buttonVariants } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { dashboardCardRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { useSettings } from '../../hooks/useSettings';
import { useStats } from '../../hooks/useStats';
import { getBootstrap } from '../../lib/bootstrap';
import type { Period, StatsResponse } from '../../lib/stats';
import { cn } from '../../lib/utils';
import { formatCost } from '../conversations/format';
import {
	ChecklistCard,
	HealthCard,
	RecentCard,
	UnansweredCard,
} from './DashboardCards';

const PERIODS: Array< { days: Period; label: () => string } > = [
	{ days: 1, label: () => __( 'Today', 'chatlume-ai-chatbot' ) },
	{ days: 7, label: () => __( '7 days', 'chatlume-ai-chatbot' ) },
	{ days: 30, label: () => __( '30 days', 'chatlume-ai-chatbot' ) },
];

const CARD_SIZE = {
	sm: 'chatlume:lg:col-span-1',
	md: 'chatlume:lg:col-span-1',
	lg: 'chatlume:lg:col-span-2',
};

function Stat( {
	label,
	value,
	detail,
	loading,
}: {
	label: string;
	value: string;
	detail?: string;
	loading: boolean;
} ) {
	return (
		<Card className="chatlume:p-5">
			<p className="chatlume:text-[13px] chatlume:text-fg-muted">
				{ label }
			</p>
			{ loading ? (
				<Skeleton className="chatlume:mt-2 chatlume:h-8 chatlume:w-20" />
			) : (
				<p className="chatlume:mt-1 chatlume:text-2xl chatlume:font-semibold chatlume:tabular-nums chatlume:tracking-tight chatlume:text-fg">
					{ value }
				</p>
			) }
			{ detail && ! loading && (
				<p className="chatlume:mt-1 chatlume:text-xs chatlume:text-fg-muted">
					{ detail }
				</p>
			) }
		</Card>
	);
}

/**
 * Short day label for the chart.
 *
 * @param date Y-m-d.
 * @param days Period.
 * @return Label.
 */
function dayLabel( date: string, days: Period ): string {
	const value = new Date( `${ date }T12:00:00` );
	return new Intl.DateTimeFormat(
		undefined,
		days <= 7 ? { weekday: 'short' } : { day: 'numeric', month: 'short' }
	).format( value );
}

function ActivityChart( { stats }: { stats?: StatsResponse } ) {
	const data = ( stats?.series ?? [] ).map( ( day ) => ( {
		key: day.date,
		label: dayLabel( day.date, stats?.days ?? 7 ),
		title: new Intl.DateTimeFormat( undefined, {
			dateStyle: 'medium',
		} ).format( new Date( `${ day.date }T12:00:00` ) ),
		value: day.questions,
		detail: sprintf(
			/* translators: 1: Number of questions, 2: Number of conversations, 3: Cost. */
			__(
				'%1$d questions · %2$d conversations · %3$s',
				'chatlume-ai-chatbot'
			),
			day.questions,
			day.conversations,
			formatCost( day.cost )
		),
	} ) );

	return (
		<Card className="chatlume:lg:col-span-2">
			<CardHeader>
				<CardTitle>
					{ __( 'Questions per day', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'Messages visitors sent to your bot.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				{ ! stats ? (
					<Skeleton className="chatlume:h-48 chatlume:w-full" />
				) : (
					<BarChart
						data={ data }
						label={ __(
							'Questions per day',
							'chatlume-ai-chatbot'
						) }
						columns={ [
							__( 'Day', 'chatlume-ai-chatbot' ),
							__( 'Activity', 'chatlume-ai-chatbot' ),
						] }
					/>
				) }
			</CardContent>
		</Card>
	);
}

/**
 * The Dashboard.
 */
export default function HomeRoute() {
	const { user } = getBootstrap();
	const [ days, setDays ] = useState< Period >( 7 );
	const stats = useStats( days );
	const settings = useSettings();
	const cards = useRegistry( dashboardCardRegistry );
	const totals = stats.data?.totals;
	const loading = ! totals;

	return (
		<>
			<PageHeader
				title={
					user.displayName
						? sprintf(
								/* translators: %s: The current user's display name. */
								__( 'Welcome back, %s', 'chatlume-ai-chatbot' ),
								user.displayName
							)
						: __( 'Dashboard', 'chatlume-ai-chatbot' )
				}
				description={ __(
					'How your chatbot is doing.',
					'chatlume-ai-chatbot'
				) }
				actions={
					<div
						role="group"
						aria-label={ __( 'Period', 'chatlume-ai-chatbot' ) }
						className="chatlume:inline-flex chatlume:rounded-control chatlume:border chatlume:border-border chatlume:bg-surface chatlume:p-0.5"
					>
						{ PERIODS.map( ( period ) => (
							<button
								key={ period.days }
								type="button"
								aria-pressed={ days === period.days }
								onClick={ () => setDays( period.days ) }
								className={ cn(
									'chatlume:rounded-[8px] chatlume:px-3 chatlume:py-1.5 chatlume:text-sm chatlume:font-medium chatlume:transition-colors chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
									days === period.days
										? 'chatlume:bg-accent chatlume:text-accent-fg'
										: 'chatlume:text-fg-muted chatlume:hover:text-fg'
								) }
							>
								{ period.label() }
							</button>
						) ) }
					</div>
				}
			/>

			{ settings.data && ! settings.data.setup.completed && (
				<div className="chatlume:mb-6 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-card chatlume:bg-accent-soft chatlume:px-5 chatlume:py-4">
					<div>
						<p className="chatlume:text-sm chatlume:font-semibold chatlume:text-fg">
							{ __(
								'Finish setting up your chatbot',
								'chatlume-ai-chatbot'
							) }
						</p>
						<p className="chatlume:text-[13px] chatlume:text-fg-muted">
							{ __(
								'Connect an AI and pick your colors in a few quick steps.',
								'chatlume-ai-chatbot'
							) }
						</p>
					</div>
					<Link
						to="/setup"
						className={ buttonVariants( { variant: 'primary' } ) }
					>
						{ __( 'Start setup', 'chatlume-ai-chatbot' ) }
					</Link>
				</div>
			) }

			{ stats.isError && (
				<p
					role="alert"
					className="chatlume:mb-6 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
				>
					{ stats.error.message }
				</p>
			) }

			<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
				{ stats.data && (
					<ChecklistCard steps={ stats.data.checklist } />
				) }

				<div
					className={ cn(
						'chatlume:grid chatlume:gap-4 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-5',
						stats.isPlaceholderData && 'chatlume:opacity-60'
					) }
				>
					<Stat
						loading={ loading }
						label={ __( 'Conversations', 'chatlume-ai-chatbot' ) }
						value={ (
							totals?.conversations ?? 0
						).toLocaleString() }
					/>
					<Stat
						loading={ loading }
						label={ __( 'Questions', 'chatlume-ai-chatbot' ) }
						value={ ( totals?.questions ?? 0 ).toLocaleString() }
					/>
					<Stat
						loading={ loading }
						label={ __( 'Tokens used', 'chatlume-ai-chatbot' ) }
						value={ ( totals?.tokens ?? 0 ).toLocaleString() }
					/>
					<Stat
						loading={ loading }
						label={ __( 'Estimated cost', 'chatlume-ai-chatbot' ) }
						value={ formatCost( totals?.cost ?? 0 ) }
					/>
					<Stat
						loading={ loading }
						label={ __( 'Saved', 'chatlume-ai-chatbot' ) }
						value={ formatCost( totals?.saved ?? 0 ) }
						detail={ sprintf(
							/* translators: %d: Number of answers. */
							_n(
								'%d free answer (quick answers and cache)',
								'%d free answers (quick answers and cache)',
								totals?.free_answers ?? 0,
								'chatlume-ai-chatbot'
							),
							totals?.free_answers ?? 0
						) }
					/>
				</div>

				<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:lg:grid-cols-3">
					<ActivityChart stats={ stats.data } />
					<HealthCard stats={ stats.data } />
				</div>

				<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:lg:grid-cols-2">
					<UnansweredCard stats={ stats.data } />
					<RecentCard stats={ stats.data } />

					{ cards.map( ( card ) => {
						const CardComponent = card.component;
						return (
							<Card
								key={ card.id }
								className={ cn(
									CARD_SIZE[ card.size ?? 'md' ]
								) }
							>
								{ card.title && (
									<CardHeader>
										<CardTitle>{ card.title }</CardTitle>
									</CardHeader>
								) }
								<CardContent
									className={
										card.title ? '' : 'chatlume:pt-6'
									}
								>
									<CardComponent />
								</CardContent>
							</Card>
						);
					} ) }
				</div>
			</div>
		</>
	);
}
