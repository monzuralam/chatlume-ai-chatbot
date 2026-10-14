import { __ } from '@wordpress/i18n';
import { Link } from 'react-router';

import { EmptyState } from '../components/layout/EmptyState';
import { Button } from '../components/ui/button';

/**
 * Shown for unknown in-app routes (for example, a page of a deactivated add-on).
 */
export default function NotFoundRoute() {
	return (
		<EmptyState
			title={ __( 'Page not found', 'chatlume-ai-chatbot' ) }
			description={ __(
				'This Chatlume page does not exist. It may belong to an add-on that is no longer active.',
				'chatlume-ai-chatbot'
			) }
			action={
				<Button variant="primary" asChild>
					<Link to="/">
						{ __( 'Go to Home', 'chatlume-ai-chatbot' ) }
					</Link>
				</Button>
			}
		/>
	);
}
