/**
 * Keeps a preview widget (in the admin or in a site frame) in step with the
 * Customizer: every change travels as one preview message, and everything
 * is sent again when a preview (re)connects.
 */
import { useEffect, useRef } from 'react';

import type {
	BehaviorConfig,
	PreviewSamples,
	PreviewState,
	ThemeConfig,
} from '../../../widget/types';

export type PreviewPost = ( type: string, payload: unknown ) => void;

export interface PreviewInputs {
	behavior: BehaviorConfig;
	samples: Partial< PreviewSamples >;
	state?: PreviewState;
	/** A theme to show instead of the published one (Customizer). */
	theme?: ThemeConfig;
	direction?: 'ltr' | 'rtl';
	/** Real AI replies (Customizer "Live AI replies"); null for samples. */
	live?: { url: string; nonce: string } | null;
	/** Briefly outlines a part; a new `at` repeats it. */
	highlight?: { part: string; at: number } | null;
	/** The admin clicked an element of the preview (click to edit). */
	onSelect?: ( part: string ) => void;
	/** Names of elements, shown when hovering them. */
	labels?: Record< string, string >;
}

/**
 * Sends inputs whenever they change while the preview is ready.
 *
 * @param post   Sends one message to the preview.
 * @param ready  Times the preview has said it is listening (0: not yet); each
 *               new connection sends everything again.
 * @param inputs Inputs.
 */
export function usePreviewSync(
	post: PreviewPost,
	ready: number,
	inputs: PreviewInputs
): void {
	const { behavior, samples, state, theme, direction, live, highlight } =
		inputs;
	const behaviorKey = JSON.stringify( behavior );
	const samplesKey = JSON.stringify( samples );
	const liveKey = JSON.stringify( live ?? null );

	useEffect( () => {
		if ( ready ) {
			post( 'behavior', JSON.parse( behaviorKey ) );
		}
	}, [ ready, post, behaviorKey ] );

	useEffect( () => {
		if ( ready ) {
			post( 'samples', JSON.parse( samplesKey ) );
		}
	}, [ ready, post, samplesKey ] );

	useEffect( () => {
		if ( ready ) {
			post( 'live', JSON.parse( liveKey ) );
		}
	}, [ ready, post, liveKey ] );

	useEffect( () => {
		if ( ready && theme ) {
			post( 'theme', theme );
		}
	}, [ ready, post, theme ] );

	useEffect( () => {
		if ( ready && direction ) {
			post( 'direction', direction );
		}
	}, [ ready, post, direction ] );

	useEffect( () => {
		if ( ready && state ) {
			post( 'state', state );
		}
	}, [ ready, post, state ] );

	useEffect( () => {
		if ( ready && highlight ) {
			post( 'highlight', { part: highlight.part } );
		}
	}, [ ready, post, highlight ] );

	const labelsKey = JSON.stringify( inputs.labels ?? null );
	useEffect( () => {
		if ( ready && labelsKey !== 'null' ) {
			post( 'labels', JSON.parse( labelsKey ) );
		}
	}, [ ready, post, labelsKey ] );
}

/**
 * Listens for messages from a preview widget.
 *
 * @param source   The preview's window (null: none yet).
 * @param onReady  Called when the preview says it is listening.
 * @param onSelect Called when the admin clicks an element.
 */
export function usePreviewMessages(
	source: () => Window | null,
	onReady: () => void,
	onSelect?: ( part: string ) => void
): void {
	const handlers = useRef( { onReady, onSelect, source } );
	handlers.current = { onReady, onSelect, source };

	useEffect( () => {
		const onMessage = ( event: MessageEvent ) => {
			const data = event.data as {
				source?: unknown;
				type?: unknown;
				payload?: { part?: unknown };
			} | null;
			if (
				event.origin !== window.location.origin ||
				! data ||
				data.source !== 'chatlume-preview' ||
				event.source !== handlers.current.source()
			) {
				return;
			}
			if ( data.type === 'ready' ) {
				handlers.current.onReady();
			} else if (
				data.type === 'select' &&
				typeof data.payload?.part === 'string'
			) {
				handlers.current.onSelect?.( data.payload.part );
			}
		};
		window.addEventListener( 'message', onMessage );
		return () => window.removeEventListener( 'message', onMessage );
	}, [] );
}
