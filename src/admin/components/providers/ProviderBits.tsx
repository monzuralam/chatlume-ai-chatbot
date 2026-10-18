import { __ } from '@wordpress/i18n';
import {
	CircleCheck,
	CircleDashed,
	CircleX,
	KeyRound,
	TriangleAlert,
} from 'lucide-react';

import type { ProviderState, ProviderSummary } from '../../lib/providers';
import { cn } from '../../lib/utils';
import { Badge } from '../ui/badge';

/**
 * Neutral initials avatar (no third-party logos are bundled).
 *
 * @param props           Props.
 * @param props.initials  Two letters.
 * @param props.className Extra classes.
 */
export function ProviderAvatar( {
	initials,
	className,
}: {
	initials: string;
	className?: string;
} ) {
	return (
		<span
			aria-hidden="true"
			className={ cn(
				'chatlume:inline-flex chatlume:size-10 chatlume:shrink-0 chatlume:items-center chatlume:justify-center chatlume:rounded-xl',
				'chatlume:border chatlume:border-border chatlume:bg-surface-muted chatlume:text-sm chatlume:font-semibold chatlume:tracking-wide chatlume:text-fg',
				className
			) }
		>
			{ initials }
		</span>
	);
}

/**
 * Human-readable state.
 *
 * @param state Provider state.
 */
export function stateLabel( state: ProviderState ): string {
	switch ( state ) {
		case 'ready':
			return __( 'Connected', 'chatlume-ai-chatbot' );
		case 'untested':
			return __( 'Not tested yet', 'chatlume-ai-chatbot' );
		case 'error':
			return __( 'Last test failed', 'chatlume-ai-chatbot' );
		case 'needs_key':
			return __( 'Needs an API key', 'chatlume-ai-chatbot' );
		case 'needs_url':
			return __( 'Needs a base URL', 'chatlume-ai-chatbot' );
		case 'needs_connector':
			return __(
				'Needs a key in Settings > Connectors',
				'chatlume-ai-chatbot'
			);
		case 'key_unreadable':
			return __( 'Please re-enter your API key', 'chatlume-ai-chatbot' );
	}
}

/**
 * Status badge for a provider.
 *
 * @param props          Props.
 * @param props.provider Provider.
 */
export function StateBadge( { provider }: { provider: ProviderSummary } ) {
	const tone = {
		ready: 'success',
		untested: 'neutral',
		error: 'danger',
		needs_key: 'neutral',
		needs_url: 'neutral',
		needs_connector: 'neutral',
		key_unreadable: 'warning',
	} as const;
	const Icon = {
		ready: CircleCheck,
		untested: CircleDashed,
		error: CircleX,
		needs_key: KeyRound,
		needs_url: CircleDashed,
		needs_connector: KeyRound,
		key_unreadable: TriangleAlert,
	}[ provider.state ];

	return (
		<Badge tone={ tone[ provider.state ] }>
			<Icon aria-hidden="true" />
			{ stateLabel( provider.state ) }
		</Badge>
	);
}
