import * as DialogPrimitive from '@radix-ui/react-dialog';
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';
import { usePortalContainer } from '../PortalContainer';

interface SheetProps {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	title: ReactNode;
	description?: ReactNode;
	footer?: ReactNode;
	children: ReactNode;
}

/**
 * Slide-out panel from the end edge (right in LTR, left in RTL), rendered
 * inside #chatlume-admin. Focus is trapped while open and Esc closes it.
 *
 * @param props              Props.
 * @param props.open         Open state.
 * @param props.onOpenChange Open state handler.
 * @param props.title        Title.
 * @param props.description  Description.
 * @param props.footer       Sticky footer (actions).
 * @param props.children     Body.
 */
export function Sheet( {
	open,
	onOpenChange,
	title,
	description,
	footer,
	children,
}: SheetProps ) {
	const container = usePortalContainer();

	return (
		<DialogPrimitive.Root open={ open } onOpenChange={ onOpenChange }>
			<DialogPrimitive.Portal container={ container }>
				<DialogPrimitive.Overlay className="chatlume:fixed chatlume:inset-0 chatlume:z-[100000] chatlume:bg-black/30 chatlume:backdrop-blur-[1px]" />
				<DialogPrimitive.Content
					className={ cn(
						'chatlume:fixed chatlume:inset-y-0 chatlume:end-0 chatlume:z-[100001] chatlume:flex chatlume:w-full chatlume:max-w-xl chatlume:flex-col',
						'chatlume:border-s chatlume:border-border chatlume:bg-surface chatlume:text-fg chatlume:shadow-float',
						'chatlume:top-[var(--wp-admin--admin-bar--height,32px)]'
					) }
				>
					<div className="chatlume:flex chatlume:items-start chatlume:gap-4 chatlume:border-b chatlume:border-border chatlume:px-6 chatlume:py-5">
						<div className="chatlume:min-w-0 chatlume:flex-1">
							<DialogPrimitive.Title className="chatlume:text-lg chatlume:font-semibold chatlume:tracking-tight">
								{ title }
							</DialogPrimitive.Title>
							{ description ? (
								<DialogPrimitive.Description className="chatlume:mt-1 chatlume:text-sm chatlume:text-fg-muted">
									{ description }
								</DialogPrimitive.Description>
							) : (
								<DialogPrimitive.Description className="screen-reader-text">
									{ title }
								</DialogPrimitive.Description>
							) }
						</div>
						<DialogPrimitive.Close
							aria-label={ __( 'Close', 'chatlume-ai-chatbot' ) }
							className="chatlume:rounded-control chatlume:p-1.5 chatlume:text-fg-muted chatlume:hover:bg-surface-muted chatlume:hover:text-fg chatlume:focus-visible:outline-none chatlume:focus-visible:ring-[3px] chatlume:focus-visible:ring-ring"
						>
							<X aria-hidden="true" className="chatlume:size-5" />
						</DialogPrimitive.Close>
					</div>
					<div className="chatlume:flex-1 chatlume:overflow-y-auto chatlume:px-6 chatlume:py-6">
						{ children }
					</div>
					{ footer && (
						<div className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-end chatlume:gap-2 chatlume:border-t chatlume:border-border chatlume:bg-surface chatlume:px-6 chatlume:py-4">
							{ footer }
						</div>
					) }
				</DialogPrimitive.Content>
			</DialogPrimitive.Portal>
		</DialogPrimitive.Root>
	);
}
