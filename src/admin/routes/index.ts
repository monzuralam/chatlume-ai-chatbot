import { __ } from '@wordpress/i18n';
import { Bot, House, MessagesSquare, Plug, Settings } from 'lucide-react';
import { lazy } from 'react';

import { pageRegistry } from '../extensions/registry';

// Each route is its own chunk, loaded only when visited.
const HomeRoute = lazy(
	() => import( /* webpackChunkName: "route-home" */ './home/HomeRoute' )
);
const ProvidersRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-providers" */ './providers/ProvidersRoute'
		)
);
const ChatbotRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-chatbot" */ './chatbot/ChatbotRoute'
		)
);
const ConversationsRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-conversations" */ './conversations/ConversationsRoute'
		)
);
const SettingsRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-settings" */ './settings/SettingsRoute'
		)
);

/**
 * Registers the built-in pages in the same registry add-ons use, so the
 * sidebar and router treat every page alike. Later phases add their pages here.
 */
export function registerBuiltInPages(): void {
	pageRegistry.register( {
		id: 'home',
		title: __( 'Home', 'chatlume-ai-chatbot' ),
		path: '/',
		icon: House,
		component: HomeRoute,
		order: 0,
	} );

	pageRegistry.register( {
		id: 'providers',
		title: __( 'Providers', 'chatlume-ai-chatbot' ),
		path: '/providers',
		icon: Plug,
		component: ProvidersRoute,
		order: 100,
	} );

	pageRegistry.register( {
		id: 'chatbot',
		title: __( 'Chatbot', 'chatlume-ai-chatbot' ),
		path: '/chatbot',
		icon: Bot,
		component: ChatbotRoute,
		order: 200,
	} );

	pageRegistry.register( {
		id: 'conversations',
		title: __( 'Conversations', 'chatlume-ai-chatbot' ),
		path: '/conversations',
		icon: MessagesSquare,
		component: ConversationsRoute,
		order: 300,
	} );

	pageRegistry.register( {
		id: 'settings',
		title: __( 'Settings', 'chatlume-ai-chatbot' ),
		path: '/settings',
		icon: Settings,
		component: SettingsRoute,
		order: 1000,
	} );
}
