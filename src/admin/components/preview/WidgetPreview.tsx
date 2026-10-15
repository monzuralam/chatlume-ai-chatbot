/**
 * A live widget preview inside the admin: the real widget, mounted in
 * preview mode (pinned open, no server calls, sample replies), updated
 * through the origin-checked preview protocol.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useRef, useState } from 'react';

import type {
	BehaviorConfig,
	PreviewSamples,
	PreviewState,
} from '../../../widget/types';
import { getBootstrap } from '../../lib/bootstrap';
import { cn } from '../../lib/utils';

/**
 * Sends a message to the preview widget.
 *
 * @param type    Message type.
 * @param payload Payload.
 */
export function postToPreview( type: string, payload: unknown ): void {
	window.postMessage(
		{ source: 'chatlume-customizer', type, payload },
		window.location.origin
	);
}

/**
 * Resolves once the widget script has defined window.Chatlume.
 *
 * @return Whether the widget is available.
 */
function widgetReady(): Promise< boolean > {
	if ( window.Chatlume?.mountPreview ) {
		return Promise.resolve( true );
	}
	return new Promise( ( resolve ) => {
		const done = () => resolve( !! window.Chatlume?.mountPreview );
		if ( document.readyState === 'complete' ) {
			done();
		} else {
			window.addEventListener( 'load', done, { once: true } );
		}
	} );
}

interface WidgetPreviewProps {
	behavior: BehaviorConfig;
	samples: Partial< PreviewSamples >;
	state?: PreviewState;
	className?: string;
}

/**
 * Live preview.
 *
 * @param props           Props.
 * @param props.behavior  Behavior settings to show.
 * @param props.samples   Sample conversation texts.
 * @param props.state     Forced state.
 * @param props.className Extra classes for the frame.
 */
export function WidgetPreview( {
	behavior,
	samples,
	state,
	className,
}: WidgetPreviewProps ) {
	const box = useRef< HTMLDivElement >( null );
	const latest = useRef( { behavior, samples, state } );
	latest.current = { behavior, samples, state };
	const [ failed, setFailed ] = useState( false );
	const config = getBootstrap().widget;

	// Mount once; later changes travel as preview messages.
	useEffect( () => {
		const element = box.current;
		if ( ! element || ! config ) {
			setFailed( ! config );
			return undefined;
		}
		let cancelled = false;
		widgetReady().then( ( ready ) => {
			if ( cancelled ) {
				return;
			}
			if ( ! ready || ! window.Chatlume ) {
				setFailed( true );
				return;
			}
			window.Chatlume.mountPreview( element, {
				...config,
				preview: {
					origin: window.location.origin,
					behavior: latest.current.behavior,
					samples: latest.current.samples,
					state: latest.current.state,
				},
			} ).catch( () => setFailed( true ) );
		} );
		return () => {
			cancelled = true;
			element.replaceChildren();
		};
	}, [ config ] );

	const behaviorKey = JSON.stringify( behavior );
	useEffect( () => {
		postToPreview( 'behavior', JSON.parse( behaviorKey ) );
	}, [ behaviorKey ] );

	useEffect( () => {
		if ( state ) {
			postToPreview( 'state', state );
		}
	}, [ state ] );

	if ( failed ) {
		return (
			<div
				className={ cn(
					'chatlume:flex chatlume:items-center chatlume:justify-center chatlume:rounded-card chatlume:border chatlume:border-dashed chatlume:border-border chatlume:p-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted',
					className
				) }
			>
				{ __(
					'The preview is not available because the widget files are missing. Rebuild the plugin with npm run build.',
					'chatlume-ai-chatbot'
				) }
			</div>
		);
	}

	return (
		<div
			ref={ box }
			role="region"
			aria-label={ __( 'Widget preview', 'chatlume-ai-chatbot' ) }
			className={ cn(
				'chatlume:relative chatlume:overflow-hidden chatlume:rounded-card chatlume:border chatlume:border-border chatlume:bg-surface-muted',
				className
			) }
		/>
	);
}
