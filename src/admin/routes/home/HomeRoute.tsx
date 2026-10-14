import { __, sprintf } from '@wordpress/i18n';
import {
	CircleCheck,
	CircleX,
	Command,
	Database,
	KeyRound,
	Network,
	Search,
	Server,
	Sparkles,
	TriangleAlert,
	Type,
	type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { PageHeader } from '../../components/layout/PageHeader';
import { Badge } from '../../components/ui/badge';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { dashboardCardRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { getBootstrap } from '../../lib/bootstrap';
import { cn } from '../../lib/utils';

type Tone = 'success' | 'warning' | 'danger' | 'neutral';

interface StatusRowProps {
	icon: LucideIcon;
	label: string;
	value: ReactNode;
	tone: Tone;
	statusLabel: string;
	help?: string;
}

const TONE_ICON: Record< Tone, LucideIcon > = {
	success: CircleCheck,
	warning: TriangleAlert,
	danger: CircleX,
	neutral: CircleCheck,
};

function StatusRow( {
	icon: Icon,
	label,
	value,
	tone,
	statusLabel,
	help,
}: StatusRowProps ) {
	const ToneIcon = TONE_ICON[ tone ];

	return (
		<li className="chatlume:flex chatlume:items-start chatlume:gap-3 chatlume:py-3">
			<span className="chatlume:mt-0.5 chatlume:flex chatlume:size-8 chatlume:shrink-0 chatlume:items-center chatlume:justify-center chatlume:rounded-lg chatlume:bg-surface-muted chatlume:text-fg-muted">
				<Icon aria-hidden="true" className="chatlume:size-4" />
			</span>
			<div className="chatlume:min-w-0 chatlume:flex-1">
				<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-2">
					<span className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
						{ label }
					</span>
					<Badge tone={ tone }>
						<ToneIcon aria-hidden="true" />
						{ statusLabel }
					</Badge>
				</div>
				<div className="chatlume:mt-0.5 chatlume:text-[13px] chatlume:text-fg-muted">
					{ value }
				</div>
				{ help && (
					<p className="chatlume:mt-1.5 chatlume:text-[13px] chatlume:text-fg">
						{ help }
					</p>
				) }
			</div>
		</li>
	);
}

function InstallationCard() {
	const { system, version } = getBootstrap();
	const dbReady = system.dbVersion >= system.dbVersionExpected;

	const encryption = {
		ok: {
			tone: 'success' as Tone,
			status: __( 'Working', 'chatlume-ai-chatbot' ),
			help: undefined,
		},
		key_changed: {
			tone: 'warning' as Tone,
			status: __( 'Re-enter keys', 'chatlume-ai-chatbot' ),
			help: __(
				"Your site's security keys (salts) changed, so previously saved API keys can no longer be read. Re-enter them, or define them in wp-config.php so they survive future changes.",
				'chatlume-ai-chatbot'
			),
		},
		unavailable: {
			tone: 'danger' as Tone,
			status: __( 'Unavailable', 'chatlume-ai-chatbot' ),
			help: __(
				'This server has no libsodium support, so API keys cannot be stored encrypted. Ask your host to enable the PHP sodium extension, or define API keys in wp-config.php.',
				'chatlume-ai-chatbot'
			),
		},
	}[ system.encryption ];

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'Installation', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ __(
						'The building blocks Chatlume needs on this site.',
						'chatlume-ai-chatbot'
					) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				<ul className="chatlume:divide-y chatlume:divide-border">
					<StatusRow
						icon={ Database }
						label={ __( 'Database tables', 'chatlume-ai-chatbot' ) }
						value={ sprintf(
							/* translators: 1: Plugin version, 2: Database schema version. */
							__(
								'Chatlume %1$s · schema version %2$d',
								'chatlume-ai-chatbot'
							),
							version,
							system.dbVersion
						) }
						tone={ dbReady ? 'success' : 'warning' }
						statusLabel={
							dbReady
								? __( 'Up to date', 'chatlume-ai-chatbot' )
								: __( 'Update pending', 'chatlume-ai-chatbot' )
						}
						help={
							dbReady
								? undefined
								: __(
										'The database update did not finish. Reload this page to run it again.',
										'chatlume-ai-chatbot'
									)
						}
					/>
					<StatusRow
						icon={ KeyRound }
						label={ __(
							'Secure key storage',
							'chatlume-ai-chatbot'
						) }
						value={ __(
							'API keys are encrypted with a key derived from your site’s security keys.',
							'chatlume-ai-chatbot'
						) }
						tone={ encryption.tone }
						statusLabel={ encryption.status }
						help={ encryption.help }
					/>
					<StatusRow
						icon={ Search }
						label={ __(
							'Keyword search index',
							'chatlume-ai-chatbot'
						) }
						value={
							system.fulltext
								? __(
										'Your database supports fast full-text search for the knowledge base.',
										'chatlume-ai-chatbot'
									)
								: __(
										'Your database has no full-text search, so Chatlume uses its built-in search instead. It works everywhere but is slower on very large sites.',
										'chatlume-ai-chatbot'
									)
						}
						tone={ system.fulltext ? 'success' : 'neutral' }
						statusLabel={
							system.fulltext
								? __( 'Available', 'chatlume-ai-chatbot' )
								: __( 'Using fallback', 'chatlume-ai-chatbot' )
						}
					/>
					<StatusRow
						icon={ Server }
						label={ __( 'Server', 'chatlume-ai-chatbot' ) }
						value={ sprintf(
							/* translators: 1: WordPress version, 2: PHP version. */
							__(
								'WordPress %1$s · PHP %2$s',
								'chatlume-ai-chatbot'
							),
							system.wpVersion,
							system.phpVersion
						) }
						tone="success"
						statusLabel={ __( 'Supported', 'chatlume-ai-chatbot' ) }
					/>
				</ul>
			</CardContent>
		</Card>
	);
}

function FeaturesCard() {
	const { features, isMultisite } = getBootstrap().system;

	const rows: Array< {
		icon: LucideIcon;
		label: string;
		description: string;
		available: boolean;
	} > = [
		{
			icon: Sparkles,
			label: __( 'AI services in WordPress', 'chatlume-ai-chatbot' ),
			description: __(
				'The AI Client and Settings > Connectors, added in WordPress 7.0.',
				'chatlume-ai-chatbot'
			),
			available: features.aiClient && features.connectors,
		},
		{
			icon: Network,
			label: __( 'Abilities API', 'chatlume-ai-chatbot' ),
			description: __(
				'A shared registry of site actions for AI agents, added in WordPress 6.9.',
				'chatlume-ai-chatbot'
			),
			available: features.abilitiesApi,
		},
		{
			icon: Type,
			label: __( 'Font Library', 'chatlume-ai-chatbot' ),
			description: __(
				'Site-wide font management, added in WordPress 6.5.',
				'chatlume-ai-chatbot'
			),
			available: features.fontLibrary,
		},
		{
			icon: Command,
			label: __( 'Command Palette', 'chatlume-ai-chatbot' ),
			description: __(
				'Quick navigation with Ctrl+K or ⌘K, added in WordPress 6.3.',
				'chatlume-ai-chatbot'
			),
			available: features.commandPalette,
		},
	];

	return (
		<Card>
			<CardHeader>
				<CardTitle>
					{ __( 'WordPress features', 'chatlume-ai-chatbot' ) }
				</CardTitle>
				<CardDescription>
					{ isMultisite
						? __(
								'Core features Chatlume detects on this site. Each site in your network keeps its own Chatlume settings and data.',
								'chatlume-ai-chatbot'
							)
						: __(
								'Core features Chatlume detects on this site and builds on when present.',
								'chatlume-ai-chatbot'
							) }
				</CardDescription>
			</CardHeader>
			<CardContent>
				<ul className="chatlume:divide-y chatlume:divide-border">
					{ rows.map( ( row ) => (
						<StatusRow
							key={ row.label }
							icon={ row.icon }
							label={ row.label }
							value={ row.description }
							tone={ row.available ? 'success' : 'neutral' }
							statusLabel={
								row.available
									? __( 'Available', 'chatlume-ai-chatbot' )
									: __(
											'Not available',
											'chatlume-ai-chatbot'
										)
							}
						/>
					) ) }
				</ul>
			</CardContent>
		</Card>
	);
}

const CARD_SIZE = {
	sm: 'chatlume:md:col-span-1',
	md: 'chatlume:md:col-span-1',
	lg: 'chatlume:md:col-span-2',
};

/**
 * Home: welcome, installation status, detected WordPress features, and
 * cards registered by add-ons.
 */
export default function HomeRoute() {
	const { user } = getBootstrap();
	const cards = useRegistry( dashboardCardRegistry );

	return (
		<>
			<PageHeader
				title={
					user.displayName
						? sprintf(
								/* translators: %s: The current user's display name. */
								__( 'Welcome, %s', 'chatlume-ai-chatbot' ),
								user.displayName
							)
						: __( 'Welcome to Chatlume', 'chatlume-ai-chatbot' )
				}
				description={ __(
					'Chatlume is installed. Here is the status of everything it relies on.',
					'chatlume-ai-chatbot'
				) }
			/>

			<div className="chatlume:grid chatlume:items-start chatlume:gap-6 chatlume:md:grid-cols-2">
				<InstallationCard />
				<FeaturesCard />

				{ cards.map( ( card ) => {
					const CardComponent = card.component;
					return (
						<Card
							key={ card.id }
							className={ cn( CARD_SIZE[ card.size ?? 'md' ] ) }
						>
							{ card.title && (
								<CardHeader>
									<CardTitle>{ card.title }</CardTitle>
								</CardHeader>
							) }
							<CardContent
								className={ card.title ? '' : 'chatlume:pt-6' }
							>
								<CardComponent />
							</CardContent>
						</Card>
					);
				} ) }
			</div>
		</>
	);
}
