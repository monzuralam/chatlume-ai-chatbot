/**
 * Chat app (chatlume-app.js, loaded on first open): renders the window into the widget's
 * shadow root and returns the imperative API the launcher uses.
 */
import { createRoot } from 'react-dom/client';

import { track } from '../lib/analytics';
import { stopSpeaking } from '../lib/voice';
import appCss from '../styles/app.css?inline';
import type { ChatApp, WidgetHost } from '../types';
import { App, type AppApi } from './App';

/**
 * Mounts the chat app.
 *
 * @param host Widget host.
 * @return App API.
 */
export function mount( host: WidgetHost ): ChatApp {
	const style = document.createElement( 'style' );
	style.textContent = appCss;
	host.root.append( style );

	let api: AppApi | null = null;
	const queue: Array< ( current: AppApi ) => void > = [];
	const call = ( action: ( current: AppApi ) => void ) => {
		if ( api ) {
			action( api );
		} else {
			queue.push( action );
		}
	};

	// "Chat opened" for the site's analytics: the first open loaded this app, later ones are watched.
	const opened = () => {
		if ( ! host.preview ) {
			track( host.config.analytics, 'chat_open' );
		}
	};
	if ( host.isOpen() ) {
		opened();
	}

	// Reading aloud stops when the window closes.
	const unwatch = host.onToggle( ( open ) => {
		if ( open ) {
			opened();
		} else {
			stopSpeaking();
		}
	} );

	const root = createRoot( host.mount );
	root.render(
		<App
			host={ host }
			register={ ( next ) => {
				api = next;
				queue.splice( 0 ).forEach( ( action ) => action( next ) );
			} }
		/>
	);

	return {
		send: ( text ) => call( ( current ) => current.send( text ) ),
		focus: () => call( ( current ) => current.focus() ),
		update: ( config ) => call( ( current ) => current.update( config ) ),
		setState: ( state ) => call( ( current ) => current.setState( state ) ),
		unmount: () => {
			unwatch();
			stopSpeaking();
			root.unmount();
			style.remove();
		},
	};
}
