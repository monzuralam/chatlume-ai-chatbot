import { __, sprintf } from '@wordpress/i18n';
import { BookOpen, Monitor, Moon, Sun } from 'lucide-react';

import { getBootstrap } from '../../lib/bootstrap';
import { nextColorMode } from '../../lib/colorMode';
import { cn } from '../../lib/utils';
import { Logo } from '../brand/Logo';
import { useColorModeContext } from '../ColorModeProvider';
import { Button } from '../ui/button';
import { Tooltip } from '../ui/tooltip';
import { useSystemHealth } from './useSystemHealth';

function ColorModeToggle() {
	const { mode, setMode } = useColorModeContext();

	const labels = {
		system: __( 'Color mode: match system', 'chatlume-ai-chatbot' ),
		light: __( 'Color mode: light', 'chatlume-ai-chatbot' ),
		dark: __( 'Color mode: dark', 'chatlume-ai-chatbot' ),
	};
	const Icon = { system: Monitor, light: Sun, dark: Moon }[ mode ];

	return (
		<Tooltip content={ labels[ mode ] } side="bottom">
			<Button
				variant="ghost"
				size="icon"
				aria-label={ labels[ mode ] }
				onClick={ () => setMode( nextColorMode( mode ) ) }
			>
				<Icon aria-hidden="true" />
			</Button>
		</Tooltip>
	);
}

/**
 * App header: brand, version, overall status, docs link, color mode.
 */
export function Header() {
	const { version, docsUrl } = getBootstrap();
	const health = useSystemHealth();

	return (
		<header className="chatlume:sticky chatlume:top-[var(--wp-admin--admin-bar--height,32px)] chatlume:z-20 chatlume:flex chatlume:h-16 chatlume:items-center chatlume:gap-3 chatlume:border-b chatlume:border-border chatlume:bg-surface/85 chatlume:px-4 chatlume:backdrop-blur-md chatlume:md:px-6">
			<Logo />
			<div className="chatlume:flex chatlume:min-w-0 chatlume:items-baseline chatlume:gap-2">
				<span className="chatlume:text-[15px] chatlume:font-semibold chatlume:tracking-tight">
					{ __( 'Chatlume', 'chatlume-ai-chatbot' ) }
				</span>
				{ version && (
					<span className="chatlume:text-xs chatlume:text-fg-muted">
						{ sprintf(
							/* translators: %s: Plugin version number. */
							__( 'v%s', 'chatlume-ai-chatbot' ),
							version
						) }
					</span>
				) }
			</div>

			<Tooltip content={ health.detail } side="bottom">
				<span
					// Focusable so keyboard users can reach the explanation tooltip.
					tabIndex={ 0 }
					role="status"
					className="chatlume:ms-2 chatlume:inline-flex chatlume:items-center chatlume:gap-2 chatlume:rounded-full chatlume:border chatlume:border-border chatlume:px-2.5 chatlume:py-1 chatlume:text-xs chatlume:font-medium chatlume:text-fg-muted chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
				>
					<span
						aria-hidden="true"
						className={ cn(
							'chatlume:size-2 chatlume:rounded-full',
							health.ok
								? 'chatlume:bg-success'
								: 'chatlume:bg-warning'
						) }
					/>
					<span className="chatlume:max-sm:sr-only">
						{ health.label }
					</span>
				</span>
			</Tooltip>

			<div className="chatlume:ms-auto chatlume:flex chatlume:items-center chatlume:gap-1">
				{ docsUrl && (
					<Button variant="ghost" size="sm" asChild>
						<a
							href={ docsUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							<BookOpen aria-hidden="true" />
							<span className="chatlume:max-sm:sr-only">
								{ __( 'Help', 'chatlume-ai-chatbot' ) }
							</span>
							<span className="screen-reader-text">
								{ __(
									'(opens in a new tab)',
									'chatlume-ai-chatbot'
								) }
							</span>
						</a>
					</Button>
				) }
				<ColorModeToggle />
			</div>
		</header>
	);
}
