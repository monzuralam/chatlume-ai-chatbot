import { __ } from '@wordpress/i18n';
import { useState } from 'react';

import { EmptyState } from '../../components/layout/EmptyState';
import { PageHeader } from '../../components/layout/PageHeader';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { useProviders } from '../../hooks/useProviders';
import { ProviderCard } from './ProviderCard';
import { ProviderSheet } from './ProviderSheet';
import { RoutingCard } from './RoutingCard';

/**
 * Providers: routing roles and a grid of providers with a slide-out
 * configuration panel each.
 */
export default function ProvidersRoute() {
	const providers = useProviders();
	const [ openId, setOpenId ] = useState< string | null >( null );

	const list = providers.data?.providers ?? [];
	const roles = providers.data?.roles;
	const open = list.find( ( provider ) => provider.id === openId ) ?? null;

	const roleLabels = ( id: string ): string[] => {
		const labels: string[] = [];
		if ( roles?.primary.provider === id ) {
			labels.push( __( 'Primary', 'chatlume-ai-chatbot' ) );
		}
		if ( roles?.fallback.provider === id ) {
			labels.push( __( 'Fallback', 'chatlume-ai-chatbot' ) );
		}
		const embeddings =
			roles?.embeddings.provider || roles?.primary.provider;
		if ( embeddings === id && roles?.embeddings.model ) {
			labels.push( __( 'Embeddings', 'chatlume-ai-chatbot' ) );
		}
		return labels;
	};

	return (
		<>
			<PageHeader
				title={ __( 'Providers', 'chatlume-ai-chatbot' ) }
				description={ __(
					'Connect the AI services that power your bot. Without a provider, the bot still answers with quick answers and site search.',
					'chatlume-ai-chatbot'
				) }
			/>

			{ providers.isPending && (
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-6">
					<Skeleton className="chatlume:h-64 chatlume:rounded-card" />
					<div className="chatlume:grid chatlume:gap-4 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-3">
						{ Array.from( { length: 6 } ).map( ( _, index ) => (
							<Skeleton
								key={ index }
								className="chatlume:h-44 chatlume:rounded-card"
							/>
						) ) }
					</div>
				</div>
			) }

			{ providers.isError && (
				<EmptyState
					title={ __(
						'Providers could not be loaded',
						'chatlume-ai-chatbot'
					) }
					description={ providers.error.message }
					action={
						<Button
							variant="primary"
							onClick={ () => providers.refetch() }
						>
							{ __( 'Try again', 'chatlume-ai-chatbot' ) }
						</Button>
					}
				/>
			) }

			{ providers.isSuccess && (
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-8">
					<RoutingCard providers={ list } />

					<section aria-labelledby="chatlume-providers-heading">
						<h2
							id="chatlume-providers-heading"
							className="chatlume:mb-4 chatlume:text-base chatlume:font-semibold chatlume:text-fg"
						>
							{ __( 'All providers', 'chatlume-ai-chatbot' ) }
						</h2>
						<div className="chatlume:grid chatlume:gap-4 chatlume:sm:grid-cols-2 chatlume:xl:grid-cols-3">
							{ list.map( ( provider ) => (
								<ProviderCard
									key={ provider.id }
									provider={ provider }
									roles={ roleLabels( provider.id ) }
									onConfigure={ () =>
										setOpenId( provider.id )
									}
								/>
							) ) }
						</div>
					</section>
				</div>
			) }

			<ProviderSheet
				provider={ open }
				isPrimary={ !! open && roles?.primary.provider === open.id }
				isFallback={ !! open && roles?.fallback.provider === open.id }
				onOpenChange={ ( next ) => ! next && setOpenId( null ) }
			/>
		</>
	);
}
