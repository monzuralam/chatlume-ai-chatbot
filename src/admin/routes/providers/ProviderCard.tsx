import { __ } from '@wordpress/i18n';
import { Settings2 } from 'lucide-react';

import {
	ProviderAvatar,
	StateBadge,
} from '../../components/providers/ProviderBits';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Card } from '../../components/ui/card';
import type { ProviderSummary } from '../../lib/providers';

interface ProviderCardProps {
	provider: ProviderSummary;
	roles: string[];
	onConfigure: () => void;
}

/**
 * Card in the provider grid.
 *
 * @param props             Props.
 * @param props.provider    Provider.
 * @param props.roles       Role labels this provider holds (Primary, Fallback, Embeddings).
 * @param props.onConfigure Opens the configuration panel.
 */
export function ProviderCard( {
	provider,
	roles,
	onConfigure,
}: ProviderCardProps ) {
	return (
		<Card className="chatlume:flex chatlume:flex-col chatlume:p-5">
			<div className="chatlume:flex chatlume:items-start chatlume:gap-3">
				<ProviderAvatar initials={ provider.initials } />
				<div className="chatlume:min-w-0 chatlume:flex-1">
					<h3 className="chatlume:truncate chatlume:text-[15px] chatlume:font-semibold chatlume:text-fg">
						{ provider.label }
					</h3>
					<div className="chatlume:mt-1 chatlume:flex chatlume:flex-wrap chatlume:gap-1.5">
						{ roles.map( ( role ) => (
							<Badge key={ role } tone="accent">
								{ role }
							</Badge>
						) ) }
					</div>
				</div>
			</div>

			<p className="chatlume:mt-3 chatlume:line-clamp-2 chatlume:flex-1 chatlume:text-[13px] chatlume:text-fg-muted">
				{ provider.description }
			</p>

			<div className="chatlume:mt-4 chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-2">
				<div className="chatlume:flex chatlume:flex-col chatlume:gap-1">
					<StateBadge provider={ provider } />
					{ provider.key.source === 'constant' && (
						<span className="chatlume:text-xs chatlume:text-fg-muted">
							{ __(
								'Key defined in wp-config.php',
								'chatlume-ai-chatbot'
							) }
						</span>
					) }
				</div>
				<Button
					size="sm"
					onClick={ onConfigure }
					aria-label={ `${ __( 'Configure', 'chatlume-ai-chatbot' ) } ${ provider.label }` }
				>
					<Settings2 aria-hidden="true" />
					{ __( 'Configure', 'chatlume-ai-chatbot' ) }
				</Button>
			</div>
		</Card>
	);
}
