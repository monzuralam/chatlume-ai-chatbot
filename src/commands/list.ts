/**
 * Chatlume commands for WordPress's own Command Palette (Ctrl/Cmd+K).
 * Chatlume does not build a palette of its own.
 */
import { __ } from '@wordpress/i18n';

export interface ChatlumeCommand {
	name: string;
	label: string;
	/** Extra words people may type. */
	searchLabel: string;
	/** Route inside the Chatlume app. */
	route: string;
}

/**
 * The commands, translated.
 *
 * @return Commands.
 */
export function chatlumeCommands(): ChatlumeCommand[] {
	return [
		{
			name: 'chatlume/dashboard',
			label: __( 'Chatlume: Dashboard', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot statistics overview',
				'chatlume-ai-chatbot'
			),
			route: '/',
		},
		{
			name: 'chatlume/conversations',
			label: __( 'Chatlume: Conversations', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot chats transcripts visitors',
				'chatlume-ai-chatbot'
			),
			route: '/conversations',
		},
		{
			name: 'chatlume/playground',
			label: __( 'Chatlume: Test your bot', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot playground try ask',
				'chatlume-ai-chatbot'
			),
			route: '/playground',
		},
		{
			name: 'chatlume/customize',
			label: __( 'Chatlume: Customize widget', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot appearance colors design style',
				'chatlume-ai-chatbot'
			),
			route: '/customize',
		},
		{
			name: 'chatlume/chatbot',
			label: __( 'Chatlume: Chatbot settings', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot prompt welcome message personality',
				'chatlume-ai-chatbot'
			),
			route: '/chatbot',
		},
		{
			name: 'chatlume/knowledge',
			label: __( 'Chatlume: Knowledge', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot knowledge base documents quick answers faq index',
				'chatlume-ai-chatbot'
			),
			route: '/knowledge',
		},
		{
			name: 'chatlume/providers',
			label: __( 'Chatlume: AI providers', 'chatlume-ai-chatbot' ),
			searchLabel: __( 'chatbot api key model', 'chatlume-ai-chatbot' ),
			route: '/providers',
		},
		{
			name: 'chatlume/display',
			label: __(
				'Chatlume: Display and business hours',
				'chatlume-ai-chatbot'
			),
			searchLabel: __(
				'chatbot pages devices hours offline',
				'chatlume-ai-chatbot'
			),
			route: '/display',
		},
		{
			name: 'chatlume/settings',
			label: __( 'Chatlume: Settings', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot rate limits privacy cache',
				'chatlume-ai-chatbot'
			),
			route: '/settings',
		},
		{
			name: 'chatlume/health',
			label: __( 'Chatlume: Health check', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot status problems diagnose',
				'chatlume-ai-chatbot'
			),
			route: '/settings?tab=health',
		},
		{
			name: 'chatlume/setup',
			label: __( 'Chatlume: Setup wizard', 'chatlume-ai-chatbot' ),
			searchLabel: __(
				'chatbot get started onboarding',
				'chatlume-ai-chatbot'
			),
			route: '/setup',
		},
	];
}

/**
 * Where a command goes: a route change when the Chatlume screen is already
 * open (no reload), otherwise the Chatlume screen at that route.
 *
 * @param route    Route.
 * @param adminUrl Address of the Chatlume screen.
 * @param current  Current location.
 * @return Hash to set, or a URL to load.
 */
export function commandTarget(
	route: string,
	adminUrl: string,
	current: Pick< Location, 'href' >
): { hash: string } | { url: string } {
	const here = new URL( current.href );
	const target = new URL( adminUrl, current.href );
	const sameScreen =
		here.origin === target.origin &&
		here.pathname === target.pathname &&
		here.searchParams.get( 'page' ) === target.searchParams.get( 'page' );

	return sameScreen
		? { hash: `#${ route }` }
		: { url: `${ target.href.split( '#' )[ 0 ] }#${ route }` };
}
