import { __ } from '@wordpress/i18n';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { useCreateQuickAnswer } from '../hooks/useStats';
import { cn } from '../lib/utils';
import { Button } from './ui/button';
import { Field } from './ui/field';
import { Input, inputClasses } from './ui/input';
import { Sheet } from './ui/sheet';

/**
 * What the sheet starts with.
 */
export interface QuickAnswerDraft {
	question: string;
	answer: string;
	language: string;
}

/**
 * Creates a quick answer from a question (and, optionally, an answer to
 * start from). Used by the Dashboard and the Playground.
 *
 * @param props         Props.
 * @param props.draft   Starting values; null closes the sheet.
 * @param props.title   Sheet title.
 * @param props.onClose Called when the sheet closes.
 */
export function QuickAnswerSheet( {
	draft,
	title,
	onClose,
}: {
	draft: QuickAnswerDraft | null;
	title?: string;
	onClose: () => void;
} ) {
	const create = useCreateQuickAnswer();
	const [ question, setQuestion ] = useState( '' );
	const [ answer, setAnswer ] = useState( '' );

	// Fill the form when a draft is chosen.
	useEffect( () => {
		if ( draft ) {
			setQuestion( draft.question );
			setAnswer( draft.answer );
		}
	}, [ draft ] );

	const save = () => {
		if ( ! draft || ! question.trim() || ! answer.trim() ) {
			return;
		}
		create.mutate(
			{
				question: question.trim(),
				answer: answer.trim(),
				language: draft.language,
				enabled: true,
			},
			{
				onSuccess: () => {
					toast.success(
						__(
							'Quick answer created. Visitors asking this get your answer instantly.',
							'chatlume-ai-chatbot'
						)
					);
					onClose();
				},
				onError: ( error ) => toast.error( error.message ),
			}
		);
	};

	return (
		<Sheet
			open={ draft !== null }
			onOpenChange={ ( open ) => ! open && onClose() }
			title={
				title ?? __( 'Create a quick answer', 'chatlume-ai-chatbot' )
			}
			description={ __(
				'Your own answer, served instantly and for free whenever a visitor asks this.',
				'chatlume-ai-chatbot'
			) }
			footer={
				<>
					<Button type="button" variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'chatlume-ai-chatbot' ) }
					</Button>
					<Button
						type="button"
						variant="primary"
						disabled={
							! question.trim() ||
							! answer.trim() ||
							create.isPending
						}
						onClick={ save }
					>
						{ __( 'Create quick answer', 'chatlume-ai-chatbot' ) }
					</Button>
				</>
			}
		>
			<div className="chatlume:flex chatlume:flex-col chatlume:gap-5">
				<Field
					id="chatlume-qa-question"
					label={ __( 'Question', 'chatlume-ai-chatbot' ) }
				>
					<Input
						id="chatlume-qa-question"
						maxLength={ 500 }
						value={ question }
						onChange={ ( event ) =>
							setQuestion( event.target.value )
						}
					/>
				</Field>
				<Field
					id="chatlume-qa-answer"
					label={ __( 'Answer', 'chatlume-ai-chatbot' ) }
					hint={ __(
						'Short and complete. You can use **bold** and links.',
						'chatlume-ai-chatbot'
					) }
				>
					<textarea
						id="chatlume-qa-answer"
						rows={ 8 }
						maxLength={ 5000 }
						className={ cn(
							inputClasses,
							'chatlume:h-auto chatlume:py-2'
						) }
						aria-describedby="chatlume-qa-answer-hint"
						value={ answer }
						onChange={ ( event ) =>
							setAnswer( event.target.value )
						}
					/>
				</Field>
			</div>
		</Sheet>
	);
}
