/**
 * Cloudflare Turnstile, only when the admin turned it on: Cloudflare's
 * script is loaded on demand (when a new chat session starts), never with
 * the page, and the challenge usually completes without the visitor doing
 * anything.
 */

const SCRIPT =
	'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

interface TurnstileApi {
	render: (
		element: HTMLElement,
		options: {
			sitekey: string;
			appearance?: 'always' | 'execute' | 'interaction-only';
			callback: ( token: string ) => void;
			'error-callback': () => void;
			'expired-callback'?: () => void;
		}
	) => string;
	remove: ( id: string ) => void;
}

let loading: Promise< TurnstileApi > | null = null;

/**
 * Loads Cloudflare's script once.
 *
 * @return The Turnstile API.
 */
function load(): Promise< TurnstileApi > {
	const scope = window as unknown as { turnstile?: TurnstileApi };
	if ( scope.turnstile ) {
		return Promise.resolve( scope.turnstile );
	}
	if ( ! loading ) {
		loading = new Promise( ( resolve, reject ) => {
			const script = document.createElement( 'script' );
			script.src = SCRIPT;
			script.async = true;
			script.onload = () =>
				scope.turnstile
					? resolve( scope.turnstile )
					: reject( new Error( 'turnstile' ) );
			script.onerror = () => {
				loading = null;
				reject( new Error( 'turnstile' ) );
			};
			document.head.append( script );
		} );
	}
	return loading;
}

/**
 * Runs the challenge in a container and returns its token.
 *
 * @param siteKey   Public site key.
 * @param container Element the challenge renders into (shown only when the visitor must act).
 * @param timeout   Milliseconds before giving up.
 * @return Token.
 */
export async function turnstileToken(
	siteKey: string,
	container: HTMLElement,
	timeout = 30000
): Promise< string > {
	const api = await load();

	return new Promise( ( resolve, reject ) => {
		let id = '';
		const done = () => {
			window.clearTimeout( timer );
			try {
				api.remove( id );
			} catch {
				// Already removed.
			}
		};
		const timer = window.setTimeout( () => {
			done();
			reject( new Error( 'turnstile' ) );
		}, timeout );

		id = api.render( container, {
			sitekey: siteKey,
			appearance: 'interaction-only',
			callback: ( token ) => {
				done();
				resolve( token );
			},
			'error-callback': () => {
				done();
				reject( new Error( 'turnstile' ) );
			},
		} );
	} );
}
