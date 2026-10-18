/**
 * Lead form and "Talk to a person".
 */
import { useId, useMemo, useState } from 'react';

import { parseRichText } from '../lib/markdown';
import type { HandoffConfig, LeadConfig, WidgetStrings } from '../types';
import { Icon, type IconName } from './icons';
import { InlineNodes } from './Markdown';

/**
 * Whether text looks like an email address (the server checks again).
 *
 * @param value Text.
 * @return Valid.
 */
export function looksLikeEmail( value: string ): boolean {
	return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( value.trim() );
}

/**
 * The visitor's name and email.
 *
 * @param props             Props.
 * @param props.config      Lead settings.
 * @param props.strings     Widget strings.
 * @param props.title       Heading.
 * @param props.skippable   Whether "Skip" is offered.
 * @param props.askName     Ask for a name.
 * @param props.consent     Show the consent checkbox.
 * @param props.submitLabel Submit button text.
 * @param props.onSubmit    Sends the details; resolves when saved.
 * @param props.onSkip      Skip handler.
 */
export function LeadForm( {
	config,
	strings,
	title,
	skippable,
	askName,
	consent,
	submitLabel,
	onSubmit,
	onSkip,
}: {
	config: LeadConfig | null;
	strings: WidgetStrings;
	title: string;
	skippable: boolean;
	askName: boolean;
	consent: boolean;
	submitLabel?: string;
	onSubmit: ( details: {
		name: string;
		email: string;
		consent: boolean;
	} ) => Promise< void >;
	onSkip?: () => void;
} ) {
	const id = useId();
	const [ name, setName ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ agreed, setAgreed ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const consentNodes = useMemo(
		() =>
			consent && config?.consent ? parseRichText( config.consent ) : [],
		[ consent, config?.consent ]
	);

	const submit = async () => {
		if ( ! looksLikeEmail( email ) ) {
			setError( strings.leadInvalid );
			return;
		}
		if ( consentNodes.length > 0 && ! agreed ) {
			setError( strings.leadConsent );
			return;
		}
		setError( '' );
		setSending( true );
		try {
			await onSubmit( {
				name: name.trim(),
				email: email.trim(),
				consent: agreed,
			} );
		} catch ( caught ) {
			setError(
				caught instanceof Error && caught.message
					? caught.message
					: strings.error
			);
		} finally {
			setSending( false );
		}
	};

	return (
		<form
			className="cl-card cl-lead"
			part="lead-form"
			noValidate
			aria-labelledby={ `${ id }-title` }
			onSubmit={ ( event ) => {
				event.preventDefault();
				submit();
			} }
		>
			<p className="cl-card-title" id={ `${ id }-title` }>
				{ title }
			</p>
			{ askName && (
				<label className="cl-field" htmlFor={ `${ id }-name` }>
					<span>{ strings.leadName }</span>
					<input
						id={ `${ id }-name` }
						type="text"
						autoComplete="name"
						maxLength={ 100 }
						value={ name }
						onInput={ ( event ) =>
							setName(
								( event.target as HTMLInputElement ).value
							)
						}
					/>
				</label>
			) }
			<label className="cl-field" htmlFor={ `${ id }-email` }>
				<span>{ strings.leadEmail }</span>
				<input
					id={ `${ id }-email` }
					type="email"
					autoComplete="email"
					inputMode="email"
					required
					maxLength={ 100 }
					value={ email }
					aria-invalid={ error === strings.leadInvalid }
					aria-describedby={ error ? `${ id }-error` : undefined }
					onInput={ ( event ) =>
						setEmail( ( event.target as HTMLInputElement ).value )
					}
				/>
			</label>
			{ consentNodes.length > 0 && (
				<label className="cl-consent" htmlFor={ `${ id }-consent` }>
					<input
						id={ `${ id }-consent` }
						type="checkbox"
						checked={ agreed }
						onChange={ ( event ) =>
							setAgreed(
								( event.target as HTMLInputElement ).checked
							)
						}
					/>
					<span>
						<InlineNodes
							nodes={ consentNodes }
							strings={ strings }
						/>
					</span>
				</label>
			) }
			{ error && (
				<p
					className="cl-field-error"
					id={ `${ id }-error` }
					role="alert"
				>
					{ error }
				</p>
			) }
			<div className="cl-card-actions">
				<button
					type="submit"
					className="cl-button"
					disabled={ sending }
				>
					{ sending
						? strings.sending
						: ( submitLabel ?? strings.leadSubmit ) }
				</button>
				{ skippable && onSkip && (
					<button
						type="button"
						className="cl-button cl-button-quiet"
						onClick={ onSkip }
					>
						{ strings.leadSkip }
					</button>
				) }
			</div>
		</form>
	);
}

const ACTION_ICONS: Record< string, IconName > = {
	contact: 'link',
	email: 'mail',
	phone: 'phone',
	whatsapp: 'chat',
};

/**
 * Ways to reach a person.
 *
 * @param props             Props.
 * @param props.config      Handoff settings.
 * @param props.strings     Widget strings.
 * @param props.leadGiven   Whether the visitor gave an email.
 * @param props.canEmail    Whether there is a conversation to send.
 * @param props.leadConfig  Lead settings (for the consent text).
 * @param props.onLink      Records a link click.
 * @param props.onLead      Saves the visitor's details.
 * @param props.onEmailTeam Sends the conversation to the team.
 * @param props.onClose     Hides the card.
 */
export function HandoffCard( {
	config,
	strings,
	leadGiven,
	canEmail,
	leadConfig,
	onLink,
	onLead,
	onEmailTeam,
	onClose,
}: {
	config: HandoffConfig;
	strings: WidgetStrings;
	leadGiven: boolean;
	canEmail: boolean;
	leadConfig: LeadConfig | null;
	onLink: ( type: string ) => void;
	onLead: ( details: {
		name: string;
		email: string;
		consent: boolean;
	} ) => Promise< void >;
	onEmailTeam: () => Promise< void >;
	onClose: () => void;
} ) {
	const id = useId();
	const [ asking, setAsking ] = useState( false );
	const [ state, setState ] = useState< 'idle' | 'sending' | 'sent' >(
		'idle'
	);
	const [ error, setError ] = useState( '' );

	const emailTeam = async () => {
		setError( '' );
		setState( 'sending' );
		try {
			await onEmailTeam();
			setState( 'sent' );
		} catch ( caught ) {
			setState( 'idle' );
			setError(
				caught instanceof Error && caught.message
					? caught.message
					: strings.error
			);
		}
	};

	return (
		<section
			className="cl-card cl-handoff"
			part="handoff"
			aria-labelledby={ `${ id }-title` }
		>
			<div className="cl-card-head">
				<p className="cl-card-title" id={ `${ id }-title` }>
					{ strings.handoffTitle }
				</p>
				<button
					type="button"
					className="cl-icon-button"
					aria-label={ strings.close }
					onClick={ onClose }
				>
					<Icon name="close" />
				</button>
			</div>
			{ config.actions.length > 0 && (
				<div className="cl-handoff-links">
					{ config.actions.map( ( action ) => (
						<a
							key={ action.type }
							className="cl-chip"
							href={ action.href }
							target="_blank"
							rel="noopener noreferrer"
							onClick={ () => onLink( action.type ) }
						>
							<Icon
								name={ ACTION_ICONS[ action.type ] ?? 'link' }
							/>
							{ action.label }
							<span className="cl-sr">
								{ strings.externalLink }
							</span>
						</a>
					) ) }
				</div>
			) }
			{ config.email_team && canEmail && state !== 'sent' && (
				<>
					{ asking && ! leadGiven ? (
						<LeadForm
							config={ leadConfig }
							strings={ strings }
							title={ strings.emailNeeded }
							skippable={ false }
							askName
							consent={ !! leadConfig?.consent }
							submitLabel={ strings.emailTeam }
							onSubmit={ async ( details ) => {
								await onLead( details );
								await emailTeam();
							} }
						/>
					) : (
						<button
							type="button"
							className="cl-button"
							disabled={ state === 'sending' }
							onClick={ () =>
								leadGiven ? emailTeam() : setAsking( true )
							}
						>
							<Icon name="mail" />
							{ state === 'sending'
								? strings.sending
								: strings.emailTeam }
						</button>
					) }
				</>
			) }
			{ state === 'sent' && (
				<p className="cl-card-note" role="status">
					{ strings.emailTeamOk }
				</p>
			) }
			{ error && (
				<p className="cl-field-error" role="alert">
					{ error }
				</p>
			) }
		</section>
	);
}
