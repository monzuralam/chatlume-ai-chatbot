/**
 * Registers Chatlume's commands in WordPress's Command Palette.
 *
 * Loaded on every admin screen where the palette exists (WordPress 6.9+,
 * or the block editors before that), for users who can manage Chatlume.
 */
import { store as commandsStore } from '@wordpress/commands';
import { dispatch } from '@wordpress/data';

import { chatlumeCommands, commandTarget } from './list';

declare global {
	interface Window {
		chatlumeCommands?: { adminUrl: string };
	}
}

const adminUrl = window.chatlumeCommands?.adminUrl;

if ( adminUrl ) {
	const { registerCommand } = dispatch( commandsStore );

	for ( const command of chatlumeCommands() ) {
		registerCommand( {
			name: command.name,
			label: command.label,
			searchLabel: `${ command.label } ${ command.searchLabel }`,
			callback: ( { close }: { close: () => void } ) => {
				close();
				const target = commandTarget(
					command.route,
					adminUrl,
					window.location
				);
				if ( 'hash' in target ) {
					window.location.hash = target.hash;
				} else {
					window.location.assign( target.url );
				}
			},
		} );
	}
}
