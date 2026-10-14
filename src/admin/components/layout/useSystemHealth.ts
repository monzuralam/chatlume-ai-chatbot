import { __ } from '@wordpress/i18n';

import { getBootstrap, type SystemStatus } from '../../lib/bootstrap';

export interface SystemHealth {
	ok: boolean;
	label: string;
	detail: string;
}

/**
 * Summarizes the installation status into one indicator.
 *
 * @param system System status from boot data.
 */
export function summarizeHealth( system: SystemStatus ): SystemHealth {
	if ( system.encryption === 'key_changed' ) {
		return {
			ok: false,
			label: __( 'Needs attention', 'chatlume-ai-chatbot' ),
			detail: __(
				"Your site's security keys changed, so saved API keys must be re-entered.",
				'chatlume-ai-chatbot'
			),
		};
	}

	if ( system.encryption === 'unavailable' ) {
		return {
			ok: false,
			label: __( 'Needs attention', 'chatlume-ai-chatbot' ),
			detail: __(
				'Secure key storage is unavailable on this server. Define API keys in wp-config.php instead.',
				'chatlume-ai-chatbot'
			),
		};
	}

	if ( system.dbVersion < system.dbVersionExpected ) {
		return {
			ok: false,
			label: __( 'Needs attention', 'chatlume-ai-chatbot' ),
			detail: __(
				'The database update did not finish. Reload this page to try again.',
				'chatlume-ai-chatbot'
			),
		};
	}

	return {
		ok: true,
		label: __( 'All systems ready', 'chatlume-ai-chatbot' ),
		detail: __(
			'Database, settings, and secure key storage are working.',
			'chatlume-ai-chatbot'
		),
	};
}

export function useSystemHealth(): SystemHealth {
	return summarizeHealth( getBootstrap().system );
}
