import { __ } from '@wordpress/i18n';

import { Skeleton } from '../ui/skeleton';

/**
 * Placeholder shown while a page's code chunk loads.
 */
export function PageSkeleton() {
	return (
		<div role="status" aria-live="polite">
			<span className="screen-reader-text">
				{ __( 'Loading…', 'chatlume-ai-chatbot' ) }
			</span>
			<Skeleton className="chatlume:h-8 chatlume:w-64" />
			<Skeleton className="chatlume:mt-3 chatlume:h-4 chatlume:w-96 chatlume:max-w-full" />
			<div className="chatlume:mt-8 chatlume:grid chatlume:gap-6 chatlume:md:grid-cols-2">
				<Skeleton className="chatlume:h-56 chatlume:rounded-card" />
				<Skeleton className="chatlume:h-56 chatlume:rounded-card" />
			</div>
		</div>
	);
}
