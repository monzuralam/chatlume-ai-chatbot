/**
 * The widget previewed on the real site: a same-origin frame of a page
 * (signed, admin-only, 30-minute preview address) where the live widget is
 * hidden and a preview widget takes updates from the Customizer.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { useCallback, useEffect, useRef, useState } from 'react';

import { cn } from '../../lib/utils';
import { usePreviewMessages, usePreviewSync, type PreviewInputs } from './sync';

export type FrameFailure = 'blocked' | 'timeout' | 'origin';

interface FramePreviewProps extends PreviewInputs {
	/** Page to show (same site). */
	url: string;
	/** Preview token and its query parameter. */
	token: { param: string; token: string } | null;
	/** The page cannot show the preview. */
	onFail: ( reason: FrameFailure ) => void;
	/** Inner size of the frame (device), or null to fill. */
	size: { width: number; height: number } | null;
	className?: string;
}

/** How long a page may take before the neutral backdrop is used. */
const TIMEOUT = 15000;

/**
 * Site preview frame.
 *
 * @param props Props.
 */
export function FramePreview( props: FramePreviewProps ) {
	const { url, token, onFail, size, className, onSelect } = props;
	const frame = useRef< HTMLIFrameElement >( null );
	const [ ready, setReady ] = useState( 0 );
	const [ loading, setLoading ] = useState( true );
	const failRef = useRef( onFail );
	failRef.current = onFail;
	const readyRef = useRef( false );
	readyRef.current = ready > 0;

	const src = token
		? addQueryArgs( url, { [ token.param ]: token.token } )
		: '';
	let origin = '';
	try {
		origin = src ? new URL( src, window.location.href ).origin : '';
	} catch {
		origin = '';
	}

	useEffect( () => {
		if ( src && origin !== window.location.origin ) {
			failRef.current( 'origin' );
		}
	}, [ src, origin ] );

	// A new page: wait for its preview widget to say it is listening.
	useEffect( () => {
		if ( ! src ) {
			return undefined;
		}
		setReady( 0 );
		setLoading( true );
		const timer = window.setTimeout(
			() => ! readyRef.current && failRef.current( 'timeout' ),
			TIMEOUT
		);
		return () => window.clearTimeout( timer );
	}, [ src ] );

	const post = useCallback( ( type: string, payload: unknown ) => {
		frame.current?.contentWindow?.postMessage(
			{ source: 'chatlume-customizer', type, payload },
			window.location.origin
		);
	}, [] );

	usePreviewSync( post, ready, props );
	usePreviewMessages(
		useCallback( () => frame.current?.contentWindow ?? null, [] ),
		useCallback( () => {
			setReady( ( count ) => count + 1 );
			setLoading( false );
		}, [] ),
		onSelect
	);

	const onLoad = () => {
		try {
			// Throws when the page is not same-origin (a redirect elsewhere).
			void frame.current?.contentWindow?.location.href;
		} catch {
			failRef.current( 'blocked' );
		}
	};

	return (
		<div
			className={ cn(
				'chatlume:relative chatlume:overflow-hidden chatlume:rounded-card chatlume:border chatlume:border-border chatlume:bg-surface',
				className
			) }
			style={
				size
					? {
							width: size.width,
							height: size.height,
							// The page inside gets exactly the device width.
							boxSizing: 'content-box',
						}
					: undefined
			}
		>
			{ loading && (
				<div
					aria-hidden="true"
					className="chatlume:absolute chatlume:inset-0 chatlume:animate-pulse chatlume:bg-surface-muted"
				/>
			) }
			{ src && (
				<iframe
					ref={ frame }
					src={ src }
					title={ __(
						'Your site with the widget preview',
						'chatlume-ai-chatbot'
					) }
					className="chatlume:absolute chatlume:inset-0 chatlume:size-full chatlume:border-0"
					onLoad={ onLoad }
					onError={ () => failRef.current( 'blocked' ) }
				/>
			) }
		</div>
	);
}
