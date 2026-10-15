/**
 * A live widget preview inside the admin: the real widget, mounted in
 * preview mode (pinned open, no server calls, sample replies), updated
 * through the origin-checked preview protocol.
 */
import { __ } from '@wordpress/i18n';
import {
	useCallback,
	useEffect,
	useRef,
	useState,
	type CSSProperties,
} from 'react';

import { getBootstrap } from '../../lib/bootstrap';
import { cn } from '../../lib/utils';
import { usePreviewMessages, usePreviewSync, type PreviewInputs } from './sync';

/**
 * Sends a message to the preview widget in this window.
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

interface WidgetPreviewProps extends PreviewInputs {
	className?: string;
	style?: CSSProperties;
}

/**
 * Live preview.
 *
 * @param props Props (see PreviewInputs), plus className and style for the frame.
 */
export function WidgetPreview( props: WidgetPreviewProps ) {
	const { className, style, onSelect } = props;
	const box = useRef< HTMLDivElement >( null );
	const latest = useRef( props );
	latest.current = props;
	const [ failed, setFailed ] = useState( false );
	const [ ready, setReady ] = useState( 0 );
	const config = getBootstrap().widget;

	// Mount once; later changes travel as preview messages.
	useEffect( () => {
		const element = box.current;
		if ( ! element || ! config ) {
			setFailed( ! config );
			return undefined;
		}
		let cancelled = false;
		widgetReady().then( ( available ) => {
			if ( cancelled ) {
				return;
			}
			if ( ! available || ! window.Chatlume ) {
				setFailed( true );
				return;
			}
			const initial = latest.current;
			window.Chatlume.mountPreview( element, {
				...config,
				theme: initial.theme ?? config.theme,
				preview: {
					origin: window.location.origin,
					behavior: initial.behavior,
					samples: initial.samples,
					state: initial.state,
					live: initial.live ?? null,
				},
			} ).catch( () => setFailed( true ) );
		} );
		return () => {
			cancelled = true;
			setReady( 0 );
			element.replaceChildren();
		};
	}, [ config ] );

	usePreviewSync( postToPreview, ready, props );
	usePreviewMessages(
		useCallback( () => window, [] ),
		useCallback( () => setReady( ( count ) => count + 1 ), [] ),
		onSelect
	);

	if ( failed ) {
		return (
			<div
				className={ cn(
					'chatlume:flex chatlume:items-center chatlume:justify-center chatlume:rounded-card chatlume:border chatlume:border-dashed chatlume:border-border chatlume:p-6 chatlume:text-center chatlume:text-sm chatlume:text-fg-muted',
					className
				) }
				style={ style }
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
				'chatlume:relative chatlume:overflow-hidden chatlume:rounded-card chatlume:border chatlume:border-border',
				! style?.background && 'chatlume:bg-surface-muted',
				className
			) }
			style={ style }
		/>
	);
}
