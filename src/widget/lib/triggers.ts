/**
 * Proactive invitations: after some time on the page, after scrolling, or
 * when the pointer heads out of the window (desktop), at most as often as
 * the admin allows. Runs in the launcher, so it costs nothing until it
 * fires and never loads the chat app by itself.
 */
import { read, write } from './storage';
import type { TriggerConfig } from '../types';

const DAY = 86400000;
const SESSION_KEY = 'chatlume:invited';

/**
 * Whether an invitation may show now.
 *
 * @param frequency   How often one visitor may see it.
 * @param lastShown   When it last showed (ms), or 0.
 * @param thisSession Whether it already showed in this browser session.
 * @param now         Current time (ms).
 * @return Whether it may show.
 */
export function isDue(
	frequency: TriggerConfig[ 'frequency' ],
	lastShown: number,
	thisSession: boolean,
	now: number
): boolean {
	switch ( frequency ) {
		case 'always':
			return true;
		case 'session':
			return ! thisSession;
		case 'week':
			return ! thisSession && now - lastShown >= 7 * DAY;
		default:
			return ! thisSession && now - lastShown >= DAY;
	}
}

/**
 * How far down the page the visitor has read, 0–100.
 *
 * @param scrollTop Pixels scrolled.
 * @param viewport  Window height.
 * @param height    Document height.
 * @return Percent.
 */
export function scrolledPercent(
	scrollTop: number,
	viewport: number,
	height: number
): number {
	if ( height <= viewport ) {
		return 100;
	}
	return Math.min( 100, ( ( scrollTop + viewport ) / height ) * 100 );
}

/**
 * Whether this browser session already saw an invitation.
 *
 * @return Seen.
 */
function seenThisSession(): boolean {
	try {
		return window.sessionStorage.getItem( SESSION_KEY ) === '1';
	} catch {
		return false;
	}
}

/**
 * Remembers that an invitation showed.
 */
export function recordInvitation(): void {
	write( 'invited', Date.now() );
	try {
		window.sessionStorage.setItem( SESSION_KEY, '1' );
	} catch {
		// Storage unavailable: the daily or weekly limit still applies.
	}
}

/**
 * Watches for the invitation's conditions and calls `fire` once, when the
 * first one is met. With no condition set, it fires shortly after loading.
 *
 * @param config Invitation settings.
 * @param fire   Called once.
 * @return Stops watching.
 */
export function watchTriggers(
	config: TriggerConfig,
	fire: () => void
): () => void {
	const stops: Array< () => void > = [];
	const stop = () => stops.splice( 0 ).forEach( ( undo ) => undo() );

	if (
		! isDue(
			config.frequency,
			Number( read( 'invited', 0 ) ) || 0,
			seenThisSession(),
			Date.now()
		) ||
		( config.skipReturning && read( 'seen', false ) )
	) {
		return stop;
	}

	const once = () => {
		stop();
		fire();
	};

	const anyCondition = config.delay > 0 || config.scroll > 0 || config.exit;
	if ( config.delay > 0 || ! anyCondition ) {
		const timer = window.setTimeout(
			once,
			anyCondition ? config.delay * 1000 : 1500
		);
		stops.push( () => window.clearTimeout( timer ) );
	}

	if ( config.scroll > 0 ) {
		const onScroll = () => {
			const root = document.documentElement;
			if (
				scrolledPercent(
					window.scrollY,
					window.innerHeight,
					root.scrollHeight
				) >= config.scroll
			) {
				once();
			}
		};
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		stops.push( () => window.removeEventListener( 'scroll', onScroll ) );
	}

	// Exit intent needs a mouse: the pointer leaving through the top edge.
	if (
		config.exit &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia( '(pointer: fine)' ).matches
	) {
		const onLeave = ( event: MouseEvent ) => {
			if ( ! event.relatedTarget && event.clientY <= 0 ) {
				once();
			}
		};
		document.addEventListener( 'mouseout', onLeave );
		stops.push( () => document.removeEventListener( 'mouseout', onLeave ) );
	}

	return stop;
}
