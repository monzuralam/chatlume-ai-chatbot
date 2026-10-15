/**
 * The Customizer's preview area: the widget on the real site (or a neutral
 * backdrop when the site cannot be shown), at desktop, tablet, or phone size.
 */
import { __ } from '@wordpress/i18n';
import { Info } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
	FramePreview,
	type FrameFailure,
} from '../../components/preview/FramePreview';
import type { PreviewInputs } from '../../components/preview/sync';
import { WidgetPreview } from '../../components/preview/WidgetPreview';
import { fetchPreviewToken, type PreviewToken } from '../../lib/appearance';

export type Device = 'desktop' | 'tablet' | 'mobile';
export type Backdrop = 'site' | 'light' | 'dark' | 'pattern' | 'custom';

const DEVICES: Record<
	Exclude< Device, 'desktop' >,
	{ width: number; height: number }
> = {
	tablet: { width: 768, height: 1024 },
	mobile: { width: 375, height: 812 },
};

/**
 * Background of a neutral backdrop.
 *
 * @param backdrop Backdrop.
 * @param color    Custom color.
 * @return CSS background.
 */
function backdropStyle( backdrop: Backdrop, color: string ): string {
	switch ( backdrop ) {
		case 'dark':
			return '#1f2330';
		case 'pattern':
			return 'radial-gradient(circle, rgba(100, 110, 130, 0.22) 1px, transparent 1.2px) 0 0 / 16px 16px, #f6f7f9';
		case 'custom':
			return color;
		default:
			return '#f6f7f9';
	}
}

/**
 * Why the site cannot be shown.
 *
 * @param reason Failure.
 * @return Explanation.
 */
function failureText( reason: FrameFailure ): string {
	switch ( reason ) {
		case 'origin':
			return __(
				'Your site uses a different address than the admin (for example http and https), so it cannot be shown here. The widget is shown on a plain background instead.',
				'chatlume-ai-chatbot'
			);
		case 'blocked':
			return __(
				'This page cannot be shown inside the admin (it redirects elsewhere or refuses to be framed). The widget is shown on a plain background instead.',
				'chatlume-ai-chatbot'
			);
		default:
			return __(
				'The page did not load the preview in time (its theme may not print the footer scripts). The widget is shown on a plain background instead.',
				'chatlume-ai-chatbot'
			);
	}
}

/**
 * Preview stage.
 *
 * @param props          Props.
 * @param props.inputs   Preview inputs.
 * @param props.device   Device size.
 * @param props.backdrop Backdrop.
 * @param props.color    Custom backdrop color.
 * @param props.url      Page shown behind the widget.
 */
export function PreviewStage( {
	inputs,
	device,
	backdrop,
	color,
	url,
}: {
	inputs: PreviewInputs;
	device: Device;
	backdrop: Backdrop;
	color: string;
	url: string;
} ) {
	const area = useRef< HTMLDivElement >( null );
	const [ space, setSpace ] = useState( { width: 0, height: 0 } );
	const [ token, setToken ] = useState< PreviewToken | null >( null );
	const [ failure, setFailure ] = useState< FrameFailure | null >( null );

	// The available space, for scaling tablet and phone frames to fit.
	useEffect( () => {
		const element = area.current;
		if ( ! element || typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const observer = new ResizeObserver( ( [ entry ] ) =>
			setSpace( {
				width: entry.contentRect.width,
				height: entry.contentRect.height,
			} )
		);
		observer.observe( element );
		return () => observer.disconnect();
	}, [] );

	const useSite = backdrop === 'site' && ! failure;

	// A preview address, renewed shortly before it expires.
	useEffect( () => {
		if ( ! useSite ) {
			return undefined;
		}
		let cancelled = false;
		let timer = 0;
		const renew = () =>
			fetchPreviewToken( 'frame' )
				.then( ( next ) => {
					if ( cancelled ) {
						return;
					}
					setToken( next );
					timer = window.setTimeout(
						renew,
						Math.max( 60, next.expires - Date.now() / 1000 - 120 ) *
							1000
					);
				} )
				.catch( () => ! cancelled && setFailure( 'blocked' ) );
		renew();
		return () => {
			cancelled = true;
			window.clearTimeout( timer );
		};
	}, [ useSite ] );

	// Another page or device: try the site again.
	useEffect( () => setFailure( null ), [ url, device ] );

	const onFail = useCallback(
		( reason: FrameFailure ) => setFailure( reason ),
		[]
	);

	const size = device === 'desktop' ? null : DEVICES[ device ];
	const scale = size
		? Math.min( 1, space.width / size.width, space.height / size.height ) ||
			1
		: 1;
	const neutral = backdrop === 'site' ? 'light' : backdrop;

	const content = useSite ? (
		<FramePreview
			{ ...inputs }
			url={ url }
			token={ token }
			size={ size }
			onFail={ onFail }
			className={ size ? undefined : 'chatlume:size-full' }
		/>
	) : (
		<WidgetPreview
			{ ...inputs }
			className={ size ? undefined : 'chatlume:size-full' }
			style={ {
				background: backdropStyle( neutral, color ),
				...( size ? { width: size.width, height: size.height } : {} ),
			} }
		/>
	);

	return (
		<div className="chatlume:flex chatlume:min-h-0 chatlume:flex-1 chatlume:flex-col chatlume:gap-2">
			{ failure && backdrop === 'site' && (
				<p
					role="status"
					className="chatlume:flex chatlume:items-start chatlume:gap-2 chatlume:rounded-control chatlume:bg-surface-muted chatlume:px-3 chatlume:py-2 chatlume:text-xs chatlume:text-fg-muted"
				>
					<Info
						aria-hidden="true"
						className="chatlume:mt-0.5 chatlume:size-3.5 chatlume:shrink-0"
					/>
					{ failureText( failure ) }
				</p>
			) }
			<div
				ref={ area }
				className="chatlume:relative chatlume:flex chatlume:min-h-0 chatlume:flex-1 chatlume:items-start chatlume:justify-center chatlume:overflow-hidden"
			>
				{ size ? (
					<div
						style={ {
							width: size.width * scale,
							height: size.height * scale,
						} }
					>
						<div
							style={ {
								width: size.width,
								height: size.height,
								transform: `scale(${ scale })`,
								transformOrigin: 'top left',
							} }
						>
							{ content }
						</div>
					</div>
				) : (
					<div className="chatlume:size-full">{ content }</div>
				) }
			</div>
		</div>
	);
}
