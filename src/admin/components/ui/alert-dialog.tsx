import * as AlertDialogPrimitive from '@radix-ui/react-alert-dialog';
import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';
import { usePortalContainer } from '../PortalContainer';
import { buttonVariants } from './button';

interface ConfirmDialogProps {
	open: boolean;
	title: string;
	description: ReactNode;
	confirmLabel: string;
	cancelLabel: string;
	tone?: 'primary' | 'danger';
	onConfirm: () => void;
	onCancel: () => void;
}

/**
 * Modal confirmation dialog rendered inside #chatlume-admin.
 *
 * @param props              Props.
 * @param props.open         Whether the dialog is open.
 * @param props.title        Title.
 * @param props.description  Explanation.
 * @param props.confirmLabel Confirm button label.
 * @param props.cancelLabel  Cancel button label.
 * @param props.tone         Confirm button style.
 * @param props.onConfirm    Confirm handler.
 * @param props.onCancel     Cancel handler (also Esc and outside click).
 */
export function ConfirmDialog( {
	open,
	title,
	description,
	confirmLabel,
	cancelLabel,
	tone = 'primary',
	onConfirm,
	onCancel,
}: ConfirmDialogProps ) {
	const container = usePortalContainer();

	return (
		<AlertDialogPrimitive.Root
			open={ open }
			onOpenChange={ ( next ) => ! next && onCancel() }
		>
			<AlertDialogPrimitive.Portal container={ container }>
				<AlertDialogPrimitive.Overlay className="chatlume:fixed chatlume:inset-0 chatlume:z-[100000] chatlume:bg-black/40 chatlume:backdrop-blur-[2px]" />
				<AlertDialogPrimitive.Content
					className={ cn(
						'chatlume:fixed chatlume:top-1/2 chatlume:left-1/2 chatlume:z-[100001] chatlume:w-[calc(100%-2rem)] chatlume:max-w-md',
						'chatlume:-translate-x-1/2 chatlume:-translate-y-1/2 chatlume:rounded-card chatlume:border chatlume:border-border',
						'chatlume:bg-surface chatlume:p-6 chatlume:text-fg chatlume:shadow-float'
					) }
				>
					<AlertDialogPrimitive.Title className="chatlume:text-base chatlume:font-semibold">
						{ title }
					</AlertDialogPrimitive.Title>
					<AlertDialogPrimitive.Description className="chatlume:mt-2 chatlume:text-sm chatlume:text-fg-muted">
						{ description }
					</AlertDialogPrimitive.Description>
					<div className="chatlume:mt-6 chatlume:flex chatlume:justify-end chatlume:gap-2">
						<AlertDialogPrimitive.Cancel
							className={ buttonVariants( {
								variant: 'secondary',
							} ) }
						>
							{ cancelLabel }
						</AlertDialogPrimitive.Cancel>
						<AlertDialogPrimitive.Action
							className={ buttonVariants( { variant: tone } ) }
							onClick={ onConfirm }
						>
							{ confirmLabel }
						</AlertDialogPrimitive.Action>
					</div>
				</AlertDialogPrimitive.Content>
			</AlertDialogPrimitive.Portal>
		</AlertDialogPrimitive.Root>
	);
}
