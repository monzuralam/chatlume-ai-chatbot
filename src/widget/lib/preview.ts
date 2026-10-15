/**
 * Preview protocol between the Customizer and a preview widget.
 *
 * Customizer → widget: `{ source: 'chatlume-customizer', type, payload }`
 *   - theme      ThemeConfig       New tokens and options.
 *   - strings    WidgetStrings     Visitor-facing strings.
 *   - behavior   BehaviorConfig    Bot name, welcome, starters, quick actions.
 *   - state      PreviewState      Forced preview state.
 *   - highlight  { part: string }  Briefly outlines an element.
 *   - direction  'ltr' | 'rtl'     Text direction.
 *   - samples    PreviewSamples    Sample conversation texts.
 *   - live       { url, nonce }    Real AI replies (null: samples).
 *   - labels     { part: name }    Names shown when hovering elements.
 *
 * Widget → Customizer: `{ source: 'chatlume-preview', type, payload }`
 *   - ready      {}                The preview is listening.
 *   - select     { part: string }  The admin clicked an element (click to edit).
 *
 * Messages from any other origin are ignored. Bursts (dragging a color
 * picker) are coalesced and applied once per animation frame.
 */

export const PREVIEW_TYPES = [
	'theme',
	'strings',
	'behavior',
	'state',
	'highlight',
	'direction',
	'samples',
	'live',
	'labels',
] as const;

export type PreviewType = ( typeof PREVIEW_TYPES )[ number ];
export type PreviewBatch = Partial< Record< PreviewType, unknown > >;

export interface PreviewChannel {
	post: ( type: 'ready' | 'select', payload?: unknown ) => void;
	dispose: () => void;
}

/**
 * Whether a message event is a valid Customizer message.
 *
 * @param event  Message event.
 * @param origin Allowed origin.
 * @return Whether it is accepted.
 */
export function isPreviewMessage(
	event: Pick< MessageEvent, 'origin' | 'data' >,
	origin: string
): boolean {
	const data = event.data as { source?: unknown; type?: unknown } | null;
	return (
		event.origin === origin &&
		!! data &&
		typeof data === 'object' &&
		data.source === 'chatlume-customizer' &&
		( PREVIEW_TYPES as readonly unknown[] ).includes( data.type )
	);
}

/**
 * Listens for Customizer messages.
 *
 * @param origin   Allowed origin.
 * @param apply    Receives each batch (latest payload per type).
 * @param target   Window that receives outgoing messages.
 * @param schedule Frame scheduler (tests).
 * @return Channel.
 */
export function createPreviewChannel(
	origin: string,
	apply: ( batch: PreviewBatch ) => void,
	target: Window = window.parent,
	schedule: ( callback: () => void ) => unknown = ( callback ) =>
		window.requestAnimationFrame( callback )
): PreviewChannel {
	let batch: PreviewBatch = {};
	let queued = false;

	const flush = () => {
		queued = false;
		const current = batch;
		batch = {};
		apply( current );
	};

	const onMessage = ( event: MessageEvent ) => {
		if ( ! isPreviewMessage( event, origin ) ) {
			return;
		}
		const { type, payload } = event.data as {
			type: PreviewType;
			payload: unknown;
		};
		batch[ type ] = payload;
		if ( ! queued ) {
			queued = true;
			schedule( flush );
		}
	};

	window.addEventListener( 'message', onMessage );

	return {
		post( type, payload = {} ) {
			target.postMessage(
				{ source: 'chatlume-preview', type, payload },
				origin
			);
		},
		dispose() {
			window.removeEventListener( 'message', onMessage );
		},
	};
}
