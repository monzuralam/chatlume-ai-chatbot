/**
 * The message composer: auto-growing textarea, Enter to send,
 * Shift+Enter for a new line, a counter near the limit, Send / Stop.
 */
import { forwardRef, useLayoutEffect, useRef, useState } from 'react';

import type { ThemeUi, WidgetStrings } from '../types';
import { Icon } from './icons';
import type { Chat } from './useChat';

interface Props {
	chat: Chat;
	ui: ThemeUi;
	strings: WidgetStrings;
	maxLength: number;
	/** Outside business hours: no input. */
	closed?: boolean;
}

/**
 * Replaces printf-style placeholders (%1$d, %2$d) in a translated string.
 *
 * @param template Template.
 * @param values   Values.
 * @return Text.
 */
export function format(
	template: string,
	...values: Array< string | number >
): string {
	let next = 0;
	return ( template ?? '' ).replace(
		/%(?:(\d+)\$)?[ds]/g,
		( _match, position ) => {
			const value = position
				? values[ Number( position ) - 1 ]
				: values[ next++ ];
			return value === undefined ? '' : String( value );
		}
	);
}

export const Composer = forwardRef< HTMLTextAreaElement, Props >(
	function ComposerField(
		{ chat, ui, strings, maxLength, closed = false },
		ref
	) {
		const [ text, setText ] = useState( '' );
		const area = useRef< HTMLTextAreaElement | null >( null );
		const blocked = chat.busy || chat.otherTabBusy || closed;
		const length = Array.from( text ).length;
		const near = length >= Math.floor( maxLength * 0.8 );

		useLayoutEffect( () => {
			const node = area.current;
			if ( node ) {
				node.style.height = 'auto';
				node.style.height = `${ Math.min( node.scrollHeight, 140 ) }px`;
			}
		}, [ text ] );

		const submit = () => {
			if ( ! closed && text.trim() && chat.send( text ) ) {
				setText( '' );
			}
		};

		return (
			<form
				className={ `cl-composer cl-composer-${ ui.composer.style }` }
				part="composer"
				onSubmit={ ( event ) => {
					event.preventDefault();
					submit();
				} }
			>
				<label className="cl-sr" htmlFor="cl-input">
					{ strings.placeholder }
				</label>
				<textarea
					id="cl-input"
					ref={ ( node ) => {
						area.current = node;
						if ( typeof ref === 'function' ) {
							ref( node );
						} else if ( ref ) {
							ref.current = node;
						}
					} }
					className="cl-input"
					rows={ 1 }
					value={ text }
					maxLength={ maxLength }
					placeholder={
						closed ? strings.closed : strings.placeholder
					}
					disabled={ closed }
					enterKeyHint="send"
					autoComplete="off"
					onInput={ ( event ) =>
						setText( ( event.target as HTMLTextAreaElement ).value )
					}
					onKeyDown={ ( event ) => {
						if (
							event.key === 'Enter' &&
							! event.shiftKey &&
							! ( event as unknown as KeyboardEvent ).isComposing
						) {
							event.preventDefault();
							if ( ! blocked ) {
								submit();
							}
						}
					} }
				/>
				{ near && (
					<span className="cl-count" aria-live="polite">
						{ format( strings.charCount, length, maxLength ) }
					</span>
				) }
				{ chat.busy ? (
					<button
						type="button"
						className="cl-send cl-stop"
						aria-label={ strings.stop }
						title={ strings.stop }
						onClick={ () => chat.stop() }
					>
						<Icon name="stop" />
					</button>
				) : (
					<button
						type="submit"
						className={ `cl-send cl-send-${ ui.composer.sendButton }` }
						aria-label={ strings.send }
						disabled={ blocked || ! text.trim() }
					>
						{ ui.composer.sendButton === 'text' ? (
							strings.send
						) : (
							<Icon name="send" />
						) }
					</button>
				) }
			</form>
		);
	}
);
