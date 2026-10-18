import { __ } from '@wordpress/i18n';
import {
	BookOpen,
	Bot,
	FlaskConical,
	LayoutDashboard,
	MessagesSquare,
	MonitorSmartphone,
	Paintbrush,
	Plug,
	Settings,
	Type,
	Webhook,
} from 'lucide-react';
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
const SetupRoute = lazy(
	() => import( /* webpackChunkName: "route-setup" */ './setup/SetupRoute' )
);
const KnowledgeRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-knowledge" */ './knowledge/KnowledgeRoute'
		)
);
const CustomizeRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-customize" */ './customize/CustomizeRoute'
		)
);
const DisplayRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-display" */ './display/DisplayRoute'
		)
);
const PlaygroundRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-playground" */ './playground/PlaygroundRoute'
		)
);
const TextsRoute = lazy(
	() => import( /* webpackChunkName: "route-texts" */ './texts/TextsRoute' )
);
const IntegrationsRoute = lazy(
	() =>
		import(
			/* webpackChunkName: "route-integrations" */ './integrations/IntegrationsRoute'
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
		title: __( 'Dashboard', 'chatlume-ai-chatbot' ),
		path: '/',
		icon: LayoutDashboard,
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
		id: 'knowledge',
		title: __( 'Knowledge', 'chatlume-ai-chatbot' ),
		path: '/knowledge',
		icon: BookOpen,
		component: KnowledgeRoute,
		order: 210,
	} );

	pageRegistry.register( {
		id: 'setup',
		title: __( 'Setup', 'chatlume-ai-chatbot' ),
		path: '/setup',
		component: SetupRoute,
		order: 5,
		wide: true,
		hidden: true,
	} );

	pageRegistry.register( {
		id: 'texts',
		title: __( 'Widget texts', 'chatlume-ai-chatbot' ),
		path: '/texts',
		icon: Type,
		component: TextsRoute,
		order: 215,
	} );

	pageRegistry.register( {
		id: 'customize',
		title: __( 'Customize', 'chatlume-ai-chatbot' ),
		path: '/customize',
		icon: Paintbrush,
		component: CustomizeRoute,
		order: 220,
		wide: true,
	} );

	pageRegistry.register( {
		id: 'display',
		title: __( 'Display', 'chatlume-ai-chatbot' ),
		path: '/display',
		icon: MonitorSmartphone,
		component: DisplayRoute,
		order: 230,
	} );

	pageRegistry.register( {
		id: 'playground',
		title: __( 'Test your bot', 'chatlume-ai-chatbot' ),
		path: '/playground',
		icon: FlaskConical,
		component: PlaygroundRoute,
		order: 250,
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
		id: 'integrations',
		title: __( 'Integrations', 'chatlume-ai-chatbot' ),
		path: '/integrations',
		icon: Webhook,
		component: IntegrationsRoute,
		order: 900,
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
