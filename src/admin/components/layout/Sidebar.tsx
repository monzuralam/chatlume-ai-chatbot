import { __ } from '@wordpress/i18n';
import { Puzzle } from 'lucide-react';
import { NavLink } from 'react-router';

import { pageRegistry } from '../../extensions/registry';
import { useRegistry } from '../../extensions/useRegistry';
import { cn } from '../../lib/utils';
import { Tooltip } from '../ui/tooltip';

/**
 * Icon navigation built from the page registry (built-in and add-on pages).
 * Below 1024 px it collapses to icons only; labels stay available to
 * screen readers and as tooltips.
 */
export function Sidebar() {
	const pages = useRegistry( pageRegistry );

	return (
		<nav
			aria-label={ __( 'Chatlume', 'chatlume-ai-chatbot' ) }
			className="chatlume:sticky chatlume:top-[calc(var(--wp-admin--admin-bar--height,32px)+4rem)] chatlume:h-[calc(100vh-var(--wp-admin--admin-bar--height,32px)-4rem)] chatlume:w-16 chatlume:shrink-0 chatlume:border-e chatlume:border-border chatlume:bg-surface chatlume:p-3 chatlume:lg:w-60"
		>
			<ul className="chatlume:flex chatlume:flex-col chatlume:gap-1">
				{ pages
					.filter( ( page ) => ! page.hidden )
					.map( ( page ) => {
						const Icon = page.icon ?? Puzzle;
						return (
							<li key={ page.id }>
								{ /* The label is visible on wide screens, so the tooltip only shows when collapsed. */ }
								<Tooltip
									content={ page.title }
									side="right"
									className="chatlume:lg:hidden"
								>
									{ /*
									 * className must be a string: the tooltip trigger (Radix Slot) merges
									 * class names as strings, so the active state is styled through the
									 * aria-current="page" attribute NavLink sets on the active link.
									 */ }
									<NavLink
										to={ page.path }
										end={ page.path === '/' }
										className={ cn(
											'chatlume:flex chatlume:h-10 chatlume:items-center chatlume:gap-3 chatlume:rounded-control chatlume:px-3',
											'chatlume:text-sm chatlume:font-medium chatlume:transition-colors chatlume:duration-150',
											'chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring',
											'chatlume:max-lg:justify-center chatlume:max-lg:px-0',
											'chatlume:text-fg-muted chatlume:hover:bg-surface-muted chatlume:hover:text-fg',
											'chatlume:aria-[current=page]:bg-accent-soft chatlume:aria-[current=page]:text-accent'
										) }
									>
										<Icon
											aria-hidden="true"
											className="chatlume:size-[18px] chatlume:shrink-0"
										/>
										<span className="chatlume:truncate chatlume:max-lg:sr-only">
											{ page.title }
										</span>
									</NavLink>
								</Tooltip>
							</li>
						);
					} ) }
			</ul>
		</nav>
	);
}
