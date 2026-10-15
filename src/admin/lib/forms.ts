/**
 * Helpers for forms validated with Zod.
 */
import { __ } from '@wordpress/i18n';
import type { FieldError, FieldErrors, FieldValues } from 'react-hook-form';
import type { z } from 'zod';

/**
 * Converts Zod issues to react-hook-form errors (first problem per field).
 *
 * @param issues   Zod issues.
 * @param collapse Dotted paths whose item errors belong to the field itself
 *                 (lists edited in one text box, such as blocked words).
 * @return Field errors.
 */
export function issuesToErrors< T extends FieldValues >(
	issues: z.core.$ZodIssue[],
	collapse: string[] = []
): FieldErrors< T > {
	const errors: Record< string, unknown > = {};

	for ( const issue of issues ) {
		let path = issue.path.map( String );
		const dotted = path.join( '.' );
		const collapsed = collapse.find(
			( prefix ) => dotted === prefix || dotted.startsWith( prefix + '.' )
		);
		if ( collapsed ) {
			path = collapsed.split( '.' );
		}
		if ( ! path.length ) {
			continue;
		}

		let node = errors;
		for ( let index = 0; index < path.length - 1; index++ ) {
			const key = path[ index ];
			if ( typeof node[ key ] !== 'object' || node[ key ] === null ) {
				node[ key ] = /^\d+$/.test( path[ index + 1 ] ) ? [] : {};
			}
			node = node[ key ] as Record< string, unknown >;
		}
		const leaf = path[ path.length - 1 ];
		if ( ! node[ leaf ] ) {
			node[ leaf ] = {
				type: issue.code,
				message: issue.message,
			} satisfies FieldError;
		}
	}

	return errors as FieldErrors< T >;
}

/**
 * A translated message for a field error.
 *
 * @param error Field error.
 * @return Message, or undefined when there is no error.
 */
export function fieldMessage(
	error: { type?: unknown; message?: unknown } | undefined
): string | undefined {
	if ( ! error ) {
		return undefined;
	}
	if ( error.message === 'ip' ) {
		return __(
			'Enter one IP address or range per line, for example 203.0.113.0/24.',
			'chatlume-ai-chatbot'
		);
	}
	switch ( error.message ) {
		case 'label':
			return __( 'Enter the button text.', 'chatlume-ai-chatbot' );
		case 'action:url':
			return __(
				'Enter a web address starting with http:// or https://.',
				'chatlume-ai-chatbot'
			);
		case 'action:email':
			return __( 'Enter a valid email address.', 'chatlume-ai-chatbot' );
		case 'action:phone':
		case 'action:whatsapp':
			return __(
				'Enter a phone number with the country code, for example +44 20 7946 0000.',
				'chatlume-ai-chatbot'
			);
		case 'action:message':
			return __( 'Enter the message to send.', 'chatlume-ai-chatbot' );
	}
	switch ( error.type ) {
		case 'too_big':
			return __(
				'This is too long or too large.',
				'chatlume-ai-chatbot'
			);
		case 'too_small':
			return __(
				'This is too short or too small.',
				'chatlume-ai-chatbot'
			);
		case 'invalid_format':
			return __(
				'Please check the format of this value.',
				'chatlume-ai-chatbot'
			);
		default:
			return __( 'Please check this value.', 'chatlume-ai-chatbot' );
	}
}
