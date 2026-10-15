/**
 * "Match my website": proposes a theme from the site's icon, logo, colors,
 * and fonts, shown before and after so the admin can accept or keep editing.
 */
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { Check, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Button } from '../../components/ui/button';
import { Sheet } from '../../components/ui/sheet';
import type { ApiError } from '../../lib/api';
import { fetchBrand, fetchPreviewToken } from '../../lib/appearance';
import { getBootstrap } from '../../lib/bootstrap';
import {
	cssColorToHex,
	matchSite,
	type SampledStyles,
} from '../../lib/theme/match';
import type { Theme } from '../../lib/theme/types';
import { Thumbnail } from './PresetPicker';

/** How long the home page may take to load for sampling. */
const SAMPLE_TIMEOUT = 12000;

/**
 * Reads button, link, and text colors from the home page in a hidden,
 * same-origin frame (no admin bar, no widget).
 *
 * @return Styles, or null when the page cannot be read.
 */
async function sampleSite(): Promise< SampledStyles | null > {
	const token = await fetchPreviewToken( 'sample' );
	return new Promise( ( resolve ) => {
		const frame = document.createElement( 'iframe' );
		frame.setAttribute( 'aria-hidden', 'true' );
		frame.tabIndex = -1;
		frame.style.cssText =
			'position:fixed;left:-10000px;top:0;width:1280px;height:800px;border:0;visibility:hidden';
		const finish = ( result: SampledStyles | null ) => {
			window.clearTimeout( timer );
			frame.remove();
			resolve( result );
		};
		const timer = window.setTimeout( () => finish( null ), SAMPLE_TIMEOUT );
		frame.onload = () => {
			try {
				const doc = frame.contentDocument;
				const win = frame.contentWindow;
				if ( ! doc || ! win ) {
					finish( null );
					return;
				}
				const style = ( element: Element | null ) =>
					element ? win.getComputedStyle( element ) : null;
				let button = '';
				for ( const element of Array.from(
					doc.querySelectorAll(
						'.wp-element-button, .wp-block-button__link, button, .button, input[type="submit"]'
					)
				).slice( 0, 30 ) ) {
					button = cssColorToHex(
						style( element )?.backgroundColor ?? ''
					);
					if ( button ) {
						break;
					}
				}
				const link = doc.querySelector(
					'main a[href], .entry-content a[href], article a[href], #content a[href], a[href]'
				);
				finish( {
					button,
					link: cssColorToHex( style( link )?.color ?? '' ),
					text: cssColorToHex( style( doc.body )?.color ?? '' ),
				} );
			} catch {
				finish( null );
			}
		};
		frame.src = addQueryArgs( getBootstrap().siteUrl, {
			[ token.param ]: token.token,
		} );
		document.body.append( frame );
	} );
}

/**
 * The button and the before/after sheet.
 *
 * @param props         Props.
 * @param props.theme   Current theme.
 * @param props.onApply Applies the proposal.
 */
export function MatchSite( {
	theme,
	onApply,
}: {
	theme: Theme;
	onApply: ( theme: Theme ) => void;
} ) {
	const [ busy, setBusy ] = useState( false );
	const [ proposal, setProposal ] = useState< {
		theme: Theme;
		changes: string[];
		colorFound: boolean;
	} | null >( null );

	const start = async () => {
		setBusy( true );
		try {
			const [ brand, sampled ] = await Promise.all( [
				fetchBrand(),
				sampleSite().catch( () => null ),
			] );
			setProposal( matchSite( theme, brand, sampled ) );
		} catch ( error ) {
			toast.error( ( error as ApiError ).message );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<Button type="button" size="sm" disabled={ busy } onClick={ start }>
				<Sparkles aria-hidden="true" />
				{ busy
					? __( 'Reading your site…', 'chatlume-ai-chatbot' )
					: __( 'Match my website', 'chatlume-ai-chatbot' ) }
			</Button>
			<Sheet
				open={ proposal !== null }
				onOpenChange={ ( open ) => ! open && setProposal( null ) }
				title={ __( 'Match my website', 'chatlume-ai-chatbot' ) }
				description={ __(
					'A look built from your site. Apply it, then fine-tune anything you like.',
					'chatlume-ai-chatbot'
				) }
				footer={
					<>
						<Button
							type="button"
							variant="ghost"
							onClick={ () => setProposal( null ) }
						>
							{ __( 'Cancel', 'chatlume-ai-chatbot' ) }
						</Button>
						<Button
							type="button"
							variant="primary"
							onClick={ () => {
								if ( proposal ) {
									onApply( proposal.theme );
								}
								setProposal( null );
							} }
						>
							{ __( 'Apply', 'chatlume-ai-chatbot' ) }
						</Button>
					</>
				}
			>
				{ proposal && (
					<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
						<div className="chatlume:grid chatlume:grid-cols-2 chatlume:gap-4">
							<figure className="chatlume:m-0 chatlume:flex chatlume:flex-col chatlume:gap-1.5">
								<Thumbnail theme={ theme } />
								<figcaption className="chatlume:text-xs chatlume:text-fg-muted">
									{ __( 'Before', 'chatlume-ai-chatbot' ) }
								</figcaption>
							</figure>
							<figure className="chatlume:m-0 chatlume:flex chatlume:flex-col chatlume:gap-1.5">
								<Thumbnail theme={ proposal.theme } />
								<figcaption className="chatlume:text-xs chatlume:text-fg-muted">
									{ __( 'After', 'chatlume-ai-chatbot' ) }
								</figcaption>
							</figure>
						</div>
						<ul className="chatlume:flex chatlume:flex-col chatlume:gap-1.5 chatlume:text-sm chatlume:text-fg">
							{ proposal.changes.map( ( change ) => (
								<li
									key={ change }
									className="chatlume:flex chatlume:items-start chatlume:gap-2"
								>
									<Check
										aria-hidden="true"
										className="chatlume:mt-0.5 chatlume:size-4 chatlume:shrink-0 chatlume:text-success"
									/>
									{ change }
								</li>
							) ) }
						</ul>
						{ ! proposal.colorFound && (
							<p className="chatlume:rounded-control chatlume:bg-surface-muted chatlume:px-3 chatlume:py-2 chatlume:text-xs chatlume:text-fg-muted">
								{ __(
									'Your site has no clear brand color (its palette and buttons are neutral), so the colors stay as they are. Pick one under Colors.',
									'chatlume-ai-chatbot'
								) }
							</p>
						) }
						<p className="chatlume:text-xs chatlume:text-fg-muted">
							{ __(
								'Nothing is published until you click Publish, and Ctrl+Z undoes it.',
								'chatlume-ai-chatbot'
							) }
						</p>
					</div>
				) }
			</Sheet>
		</>
	);
}
