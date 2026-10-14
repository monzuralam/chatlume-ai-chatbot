/**
 * Chatlume admin app entry point.
 */
import './public-path';
import './styles/index.css';

import domReady from '@wordpress/dom-ready';
import { StrictMode } from 'react';
import { createRoot } from '@wordpress/element';

import { App } from './App';
import { exposeAdminApi } from './extensions/registry';
import { registerBuiltInPages } from './routes';

// Publish the extension API immediately, so add-on scripts that load after
// this one (dependency: 'chatlume-admin') can register before the app mounts.
exposeAdminApi();
registerBuiltInPages();

domReady( () => {
	const root = document.getElementById( 'chatlume-admin' );

	if ( ! root ) {
		return;
	}

	// Replace the server-rendered fallback content with the app.
	root.replaceChildren();

	createRoot( root ).render(
		<StrictMode>
			<App root={ root } />
		</StrictMode>
	);
} );
