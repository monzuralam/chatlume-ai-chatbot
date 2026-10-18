/**
 * Chat events for the site's own analytics, when the admin turns them on:
 * pushed to `window.dataLayer` (Google Tag Manager and similar) if the page
 * has one, and dispatched as `chatlume:<event>` DOM events on `document`.
 * Nothing is sent anywhere by Chatlume, and events never contain message
 * text, names, or email addresses.
 */

export type ChatEvent =
	'chat_open' | 'message_sent' | 'lead_captured' | 'handoff';

/**
 * Reports an event.
 *
 * @param enabled Whether analytics events are on.
 * @param name    Event.
 * @param detail  Extra, non-personal details.
 */
export function track(
	enabled: boolean | undefined,
	name: ChatEvent,
	detail: Record< string, string | number > = {}
): void {
	if ( ! enabled ) {
		return;
	}
	const data = { event: name, event_source: 'chatlume', ...detail };
	if ( Array.isArray( window.dataLayer ) ) {
		window.dataLayer.push( data );
	}
	document.dispatchEvent(
		new CustomEvent( `chatlume:${ name }`, { detail: data } )
	);
}
