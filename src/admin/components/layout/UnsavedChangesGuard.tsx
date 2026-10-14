import { __ } from '@wordpress/i18n';
import { useBlocker } from 'react-router';

import { ConfirmDialog } from '../ui/alert-dialog';

/**
 * Asks before leaving a page with unsaved changes through in-app navigation.
 *
 * Render it only while there are unsaved changes: a mounted router blocker
 * affects every navigation, so it must not exist when nothing needs guarding.
 */
export function UnsavedChangesGuard() {
	const blocker = useBlocker(
		( { currentLocation, nextLocation } ) =>
			currentLocation.pathname !== nextLocation.pathname
	);

	return (
		<ConfirmDialog
			open={ blocker.state === 'blocked' }
			title={ __( 'Leave without saving?', 'chatlume-ai-chatbot' ) }
			description={ __(
				'You have unsaved changes on this page. If you leave now, they will be lost.',
				'chatlume-ai-chatbot'
			) }
			confirmLabel={ __( 'Leave page', 'chatlume-ai-chatbot' ) }
			cancelLabel={ __( 'Stay', 'chatlume-ai-chatbot' ) }
			tone="danger"
			onConfirm={ () => blocker.proceed?.() }
			onCancel={ () => blocker.reset?.() }
		/>
	);
}
