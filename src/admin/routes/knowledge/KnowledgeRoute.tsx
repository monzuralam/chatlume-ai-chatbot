/**
 * Knowledge: what the bot learns from (Sources), uploaded Documents, Quick
 * answers, and Index status. The tab is kept in the address
 * (#/knowledge?tab=documents), so other pages can link to it.
 */
import { __ } from '@wordpress/i18n';
import { useSearchParams } from 'react-router';

import { PageHeader } from '../../components/layout/PageHeader';
import { PageSkeleton } from '../../components/layout/PageSkeleton';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Tabs } from '../../components/ui/tabs';
import { useKnowledgeStatus } from '../../hooks/useKnowledge';
import { useSettings } from '../../hooks/useSettings';
import type { KnowledgeStatus } from '../../lib/knowledge';
import { DocumentsTab } from './DocumentsTab';
import { QuickAnswersTab } from './QuickAnswersTab';
import { SourcesTab } from './SourcesTab';
import { StatusTab } from './StatusTab';

const TABS = [ 'sources', 'documents', 'quick-answers', 'status' ] as const;
type TabId = ( typeof TABS )[ number ];

/**
 * Badge on the Index status tab: work in progress, or something to check.
 *
 * @param status Index status.
 * @return Badge, if any.
 */
function statusBadge( status: KnowledgeStatus ) {
	if ( status.queue.status === 'running' ) {
		return (
			<Badge tone="accent">
				{ __( 'Updating', 'chatlume-ai-chatbot' ) }
			</Badge>
		);
	}
	if ( status.size.level !== 'ok' || status.embeddings.status === 'error' ) {
		return (
			<Badge tone="warning">
				{ __( 'Check', 'chatlume-ai-chatbot' ) }
			</Badge>
		);
	}
	return undefined;
}

export default function KnowledgeRoute() {
	const [ params, setParams ] = useSearchParams();
	const requested = params.get( 'tab' );
	const tab: TabId = TABS.includes( requested as TabId )
		? ( requested as TabId )
		: 'sources';
	const settings = useSettings();
	const status = useKnowledgeStatus();

	const header = (
		<PageHeader
			title={ __( 'Knowledge', 'chatlume-ai-chatbot' ) }
			description={ __(
				'What your bot knows about your business, and how it finds the right answer.',
				'chatlume-ai-chatbot'
			) }
		/>
	);

	if ( settings.isError || status.isError ) {
		return (
			<>
				{ header }
				<div
					role="alert"
					className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-control chatlume:bg-danger-soft chatlume:px-4 chatlume:py-3 chatlume:text-sm chatlume:text-danger"
				>
					<span>{ ( settings.error ?? status.error )?.message }</span>
					<Button
						size="sm"
						onClick={ () => {
							settings.refetch();
							status.refetch();
						} }
					>
						{ __( 'Try again', 'chatlume-ai-chatbot' ) }
					</Button>
				</div>
			</>
		);
	}

	if ( ! settings.data || ! status.data ) {
		return <PageSkeleton />;
	}

	const knowledge = settings.data.knowledge;
	const data = status.data;

	return (
		<>
			{ header }
			<Tabs
				label={ __( 'Knowledge sections', 'chatlume-ai-chatbot' ) }
				value={ tab }
				onChange={ ( next ) =>
					setParams( next === 'sources' ? {} : { tab: next }, {
						replace: true,
					} )
				}
				tabs={ [
					{
						id: 'sources',
						label: __( 'Sources', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'documents',
						label: __( 'Documents', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'quick-answers',
						label: __( 'Quick answers', 'chatlume-ai-chatbot' ),
					},
					{
						id: 'status',
						label: __( 'Index status', 'chatlume-ai-chatbot' ),
						badge: statusBadge( data ),
					},
				] }
			>
				{ tab === 'sources' && (
					<SourcesTab knowledge={ knowledge } status={ data } />
				) }
				{ tab === 'documents' && (
					<DocumentsTab knowledge={ knowledge } status={ data } />
				) }
				{ tab === 'quick-answers' && <QuickAnswersTab /> }
				{ tab === 'status' && (
					<StatusTab knowledge={ knowledge } status={ data } />
				) }
			</Tabs>
		</>
	);
}
