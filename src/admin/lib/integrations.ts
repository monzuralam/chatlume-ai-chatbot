/**
 * The Integrations page: webhooks, email notifications, analytics events,
 * and Cloudflare Turnstile.
 */
import { __ } from '@wordpress/i18n';
import { z } from 'zod';

import { request } from './api';
import {
	integrationsSettingsSchema,
	WEBHOOK_EVENTS,
	type Settings,
} from './settingsSchema';

export type WebhookEvent = ( typeof WEBHOOK_EVENTS )[ number ];

export interface Delivery {
	id: string;
	event: string;
	host: string;
	status: number;
	ok: boolean;
	error: string;
	attempt: number;
	ms: number;
	at: string;
}

export interface KeyStatus {
	source: 'constant' | 'settings' | 'none';
	masked: string;
	readable: boolean;
	constant: string;
}

export interface IntegrationsInfo {
	secret: string;
	deliveries: Delivery[];
	turnstile: KeyStatus;
	team_email: string;
	admin_email: string;
}

export interface TestResult {
	ok: boolean;
	status: number;
	error: string;
	ms: number;
	deliveries: Delivery[];
}

export const fetchIntegrations = () =>
	request< IntegrationsInfo >( '/integrations' );

export const regenerateSecret = () =>
	request< { secret: string } >( '/integrations/secret', { method: 'POST' } );

export const clearDeliveries = () =>
	request< { deliveries: Delivery[] } >( '/integrations/deliveries', {
		method: 'DELETE',
	} );

export const testWebhook = ( url: string ) =>
	request< TestResult >( '/integrations/test-webhook', {
		method: 'POST',
		data: { url },
	} );

export const testEmail = () =>
	request< { sent_to: string } >( '/integrations/test-email', {
		method: 'POST',
	} );

export const saveTurnstileKey = ( secret: string ) =>
	request< { turnstile: KeyStatus } >( '/integrations/turnstile-key', {
		method: 'POST',
		data: { secret },
	} );

export const deleteTurnstileKey = () =>
	request< { turnstile: KeyStatus } >( '/integrations/turnstile-key', {
		method: 'DELETE',
	} );

/**
 * Readable names of webhook events.
 *
 * @return Labels by event.
 */
export function eventLabels(): Record< WebhookEvent | 'test', string > {
	return {
		lead_captured: __( 'New lead', 'chatlume-ai-chatbot' ),
		conversation_ended: __( 'Conversation ended', 'chatlume-ai-chatbot' ),
		negative_feedback: __(
			'Answer rated not helpful',
			'chatlume-ai-chatbot'
		),
		handoff_requested: __(
			'Visitor asked for a person',
			'chatlume-ai-chatbot'
		),
		test: __( 'Test', 'chatlume-ai-chatbot' ),
	};
}

const optionalEmail = z.union( [ z.literal( '' ), z.email().max( 100 ) ] );

/**
 * Form values: the integrations section plus the email settings shown on
 * this page (they live in other sections).
 */
export const integrationsFormSchema = z
	.object( {
		integrations: integrationsSettingsSchema,
		team_email: optionalEmail,
		notify: z.boolean(),
		alert_email: optionalEmail,
	} )
	.superRefine( ( values, context ) => {
		values.integrations.webhooks.forEach( ( webhook, index ) => {
			if ( webhook.url.trim() === '' ) {
				context.addIssue( {
					code: 'custom',
					path: [ 'integrations', 'webhooks', index, 'url' ],
					message: 'action:url',
				} );
			}
			if ( webhook.events.length === 0 ) {
				context.addIssue( {
					code: 'custom',
					path: [ 'integrations', 'webhooks', index, 'events' ],
					message: 'events',
				} );
			}
		} );
		if (
			values.integrations.turnstile &&
			values.integrations.turnstile_site_key.trim() === ''
		) {
			context.addIssue( {
				code: 'custom',
				path: [ 'integrations', 'turnstile_site_key' ],
				message: 'site_key',
			} );
		}
	} );

export type IntegrationsForm = z.infer< typeof integrationsFormSchema >;

/**
 * Form values from settings.
 *
 * @param settings Settings.
 * @return Values.
 */
export function toIntegrationsForm( settings: Settings ): IntegrationsForm {
	return {
		integrations: settings.integrations,
		team_email: settings.handoff.team_email,
		notify: settings.leads.notify,
		alert_email: settings.costs.alert_email,
	};
}

/**
 * Settings update from form values.
 *
 * @param values Values.
 * @return Update.
 */
export function fromIntegrationsForm( values: IntegrationsForm ) {
	return {
		integrations: {
			...values.integrations,
			turnstile_site_key: values.integrations.turnstile_site_key.trim(),
			webhooks: values.integrations.webhooks.map( ( webhook ) => ( {
				url: webhook.url.trim(),
				events: webhook.events,
			} ) ),
		},
		handoff: { team_email: values.team_email.trim() },
		leads: { notify: values.notify },
		costs: { alert_email: values.alert_email.trim() },
	};
}

/**
 * Messages for this page's own validation codes.
 *
 * @param message Code.
 * @return Text, or undefined.
 */
export function integrationsMessage( message: unknown ): string | undefined {
	switch ( message ) {
		case 'events':
			return __( 'Choose at least one event.', 'chatlume-ai-chatbot' );
		case 'site_key':
			return __(
				'Enter your Turnstile site key, or turn Turnstile off.',
				'chatlume-ai-chatbot'
			);
		default:
			return undefined;
	}
}

/**
 * How a receiving server checks a signature, for the help text (PHP).
 *
 * @return Snippet.
 */
export function verifySnippet(): string {
	return [
		"$body     = file_get_contents( 'php://input' );",
		"$expected = 'sha256=' . hash_hmac( 'sha256', $body, 'YOUR_SECRET' );",
		"$valid    = hash_equals( $expected, $_SERVER['HTTP_X_CHATLUME_SIGNATURE'] ?? '' );",
	].join( '\n' );
}
