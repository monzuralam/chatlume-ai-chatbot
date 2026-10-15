import { __ } from '@wordpress/i18n';
import { useSearchParams } from 'react-router';

import { PageHeader } from '../../components/layout/PageHeader';
import { Badge } from '../../components/ui/badge';
import {
	Card,
	CardContent,
	CardDescription,
	CardHeader,
	CardTitle,
} from '../../components/ui/card';
import { Tabs } from '../../components/ui/tabs';
import { settingsSectionRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { useHealth } from '../../hooks/useHealth';
import { GeneralTab } from './GeneralTab';
import { HealthTab } from './HealthTab';
import { LogsTab } from './LogsTab';

const TABS = [ 'general', 'health', 'logs' ] as const;
type TabId = ( typeof TABS )[ number ];

/**
 * Sections registered by add-ons (shown on the General tab).
 */
function RegisteredSections() {
	const sections = useRegistry( settingsSectionRegistry );

	return (
		<>
			{ sections.map( ( section ) => {
				const SectionComponent = section.component;
				return (
					<Card key={ section.id } className="chatlume:mt-6">
						<CardHeader>
							<CardTitle>{ section.title }</CardTitle>
							{ section.description && (
								<CardDescription>
									{ section.description }
								</CardDescription>
							) }
						</CardHeader>
						<CardContent>
							<SectionComponent />
						</CardContent>
					</Card>
				);
			} ) }
		</>
	);
}

/**
 * Settings: General, Health check, and Logs tabs. The tab is kept in the
 * address (#/settings?tab=health), so other pages can link to it.
 */
export default function SettingsRoute() {
	const [ params, setParams ] = useSearchParams();
	const requested = params.get( 'tab' );
	const tab: TabId = TABS.includes( requested as TabId )
		? ( requested as TabId )
		: 'general';
	const health = useHealth();
	const problems = ( health.data?.checks ?? [] ).filter(
		( check ) => check.status === 'critical'
	).length;

	return (
		<>
			<PageHeader
				title={ __( 'Settings', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Site-wide options, health check, and logs.',
					'chatlume-ai-chatbot'
				) }
			/>
			<Tabs
				label={ __( 'Settings sections', 'chatlume-ai-chatbot' ) }
				value={ tab }
				onChange={ ( next ) =>
					setParams( next === 'general' ? {} : { tab: next }, {
						replace: true,
					} )
				}
				tabs={ [
					{
						id: 'general',
						label: __( 'General', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'health',
						label: __( 'Health check', 'chatlume-ai-chatbot' ),
						badge:
							problems > 0 ? (
								<Badge tone="danger">{ problems }</Badge>
							) : undefined,
					},
					{ id: 'logs', label: __( 'Logs', 'chatlume-ai-chatbot' ) },
				] }
			>
				{ tab === 'general' && (
					<>
						<GeneralTab />
						<RegisteredSections />
					</>
				) }
				{ tab === 'health' && <HealthTab /> }
				{ tab === 'logs' && <LogsTab /> }
			</Tabs>
		</>
	);
}
