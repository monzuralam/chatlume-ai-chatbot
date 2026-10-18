import { __ } from '@wordpress/i18n';
import { domAnimation, LazyMotion, m, MotionConfig } from 'motion/react';
import { Suspense, useEffect, useState, type ReactNode } from 'react';
import { useLocation, useNavigate } from 'react-router';
import { Toaster } from 'sonner';

import { matchPage, pageRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { getBootstrap } from '../../lib/bootstrap';
import NotFoundRoute from '../../routes/NotFoundRoute';
import { useColorModeContext } from '../ColorModeProvider';
import { PortalContainerContext } from '../PortalContainer';
import { TooltipProvider } from '../ui/tooltip';
import { ErrorBoundary } from './ErrorBoundary';
import { Header } from './Header';
import { PageSkeleton } from './PageSkeleton';
import { Sidebar } from './Sidebar';

/**
 * Renders the registered page that matches the current location.
 * Pages come from the registry, so add-on pages work like built-in ones.
 */
function PageOutlet() {
	const location = useLocation();
	const pages = useRegistry( pageRegistry );
	const page = matchPage( pages, location.pathname );
	const PageComponent = page?.component ?? NotFoundRoute;

	return (
		<ErrorBoundary resetKey={ location.pathname }>
			<Suspense fallback={ <PageSkeleton /> }>
				<m.div
					key={ page?.id ?? 'not-found' }
					initial={ { opacity: 0, y: 6 } }
					animate={ { opacity: 1, y: 0 } }
					transition={ { duration: 0.2, ease: 'easeOut' } }
				>
					<PageComponent />
				</m.div>
			</Suspense>
		</ErrorBoundary>
	);
}

let checkedSetup = false;

/**
 * Opens the setup wizard once per page load, when setup is not finished
 * (or skipped) and the admin arrives on the Dashboard.
 */
function SetupRedirect() {
	const location = useLocation();
	const navigate = useNavigate();

	useEffect( () => {
		if ( checkedSetup ) {
			return;
		}
		checkedSetup = true;
		if ( ! getBootstrap().setupCompleted && location.pathname === '/' ) {
			navigate( '/setup', { replace: true } );
		}
	}, [ location.pathname, navigate ] );

	return null;
}

/**
 * Content width: readable by default, full width for editors.
 *
 * @param props          Props.
 * @param props.children Page.
 */
function PageWidth( { children }: { children: ReactNode } ) {
	const location = useLocation();
	const pages = useRegistry( pageRegistry );
	const wide = !! matchPage( pages, location.pathname )?.wide;

	return (
		<div
			className={
				wide
					? 'chatlume:mx-auto chatlume:max-w-[1800px]'
					: 'chatlume:mx-auto chatlume:max-w-5xl'
			}
		>
			{ children }
		</div>
	);
}

/**
 * App frame: header, sidebar, page area, toasts, and the portal container.
 */
export function AppShell() {
	const [ portalContainer, setPortalContainer ] =
		useState< HTMLDivElement | null >( null );
	const { resolved } = useColorModeContext();

	return (
		<PortalContainerContext.Provider value={ portalContainer }>
			<MotionConfig reducedMotion="user">
				<LazyMotion features={ domAnimation } strict>
					<TooltipProvider delayDuration={ 300 }>
						<SetupRedirect />
						<Header />
						<div className="chatlume:flex">
							<Sidebar />
							<main
								className="chatlume:min-w-0 chatlume:flex-1 chatlume:px-4 chatlume:py-8 chatlume:md:px-8 chatlume:lg:px-10"
								aria-label={ __(
									'Chatlume',
									'chatlume-ai-chatbot'
								) }
							>
								<PageWidth>
									<PageOutlet />
								</PageWidth>
							</main>
						</div>
						<Toaster
							theme={ resolved }
							position="bottom-right"
							closeButton
							richColors
						/>
					</TooltipProvider>
				</LazyMotion>
			</MotionConfig>
			<div ref={ setPortalContainer } className="chatlume-portal-root" />
		</PortalContainerContext.Provider>
	);
}
