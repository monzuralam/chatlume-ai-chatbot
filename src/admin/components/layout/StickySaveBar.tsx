import { __ } from '@wordpress/i18n';
import { LoaderCircle } from 'lucide-react';
import { AnimatePresence, m } from 'motion/react';

import { Button } from '../ui/button';

interface StickySaveBarProps {
	visible: boolean;
	saving: boolean;
	onSave: () => void;
	onDiscard: () => void;
}

/**
 * Bar pinned to the bottom of the page while there are unsaved changes.
 *
 * @param props           Props.
 * @param props.visible   Whether there are unsaved changes.
 * @param props.saving    Whether a save is in progress.
 * @param props.onSave    Save handler.
 * @param props.onDiscard Discard handler.
 */
export function StickySaveBar( {
	visible,
	saving,
	onSave,
	onDiscard,
}: StickySaveBarProps ) {
	const isMac =
		typeof navigator !== 'undefined' && /Mac/i.test( navigator.platform );

	return (
		<AnimatePresence>
			{ visible && (
				<m.div
					initial={ { opacity: 0, y: 16 } }
					animate={ { opacity: 1, y: 0 } }
					exit={ { opacity: 0, y: 16 } }
					transition={ { duration: 0.18, ease: 'easeOut' } }
					className="chatlume:sticky chatlume:bottom-4 chatlume:z-10 chatlume:mt-8"
				>
					<div
						role="region"
						aria-label={ __(
							'Unsaved changes',
							'chatlume-ai-chatbot'
						) }
						className="chatlume:flex chatlume:flex-wrap chatlume:items-center chatlume:justify-between chatlume:gap-3 chatlume:rounded-card chatlume:border chatlume:border-border chatlume:bg-surface chatlume:px-5 chatlume:py-3 chatlume:shadow-float"
					>
						<p className="chatlume:text-sm chatlume:font-medium chatlume:text-fg">
							{ __(
								'You have unsaved changes.',
								'chatlume-ai-chatbot'
							) }
							<span className="chatlume:ms-2 chatlume:text-fg-muted chatlume:max-sm:hidden">
								{ isMac
									? __(
											'Press ⌘S to save.',
											'chatlume-ai-chatbot'
										)
									: __(
											'Press Ctrl+S to save.',
											'chatlume-ai-chatbot'
										) }
							</span>
						</p>
						<div className="chatlume:flex chatlume:gap-2">
							<Button
								variant="ghost"
								onClick={ onDiscard }
								disabled={ saving }
							>
								{ __( 'Discard', 'chatlume-ai-chatbot' ) }
							</Button>
							<Button
								variant="primary"
								onClick={ onSave }
								disabled={ saving }
							>
								{ saving && (
									<LoaderCircle
										aria-hidden="true"
										className="chatlume:animate-spin"
									/>
								) }
								{ saving
									? __( 'Saving…', 'chatlume-ai-chatbot' )
									: __(
											'Save changes',
											'chatlume-ai-chatbot'
										) }
							</Button>
						</div>
					</div>
				</m.div>
			) }
		</AnimatePresence>
	);
}
