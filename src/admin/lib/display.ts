/**
 * Display & business hours: where the floating widget appears and when the
 * bot is available.
 */
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { z } from 'zod';

import { request, wpRequest } from './api';
import {
	DAY_KEYS,
	displaySettingsSchema,
	type DayKey,
	type Settings,
} from './settingsSchema';

export type DisplaySettings = Settings[ 'display' ];

export interface ChosenPage {
	id: number;
	title: string;
	type: string;
}

export interface DisplayInfo {
	post_types: Array< { name: string; label: string } >;
	roles: Array< { slug: string; name: string } >;
	pages: ChosenPage[];
	timezone: { name: string; label: string };
	now: { day: DayKey; time: string; open: boolean };
	shortcode: string;
}

export const fetchDisplayInfo = () => request< DisplayInfo >( '/display' );

interface SearchResult {
	id: number;
	title: string;
	subtype: string;
}

/**
 * Searches posts and pages by title (core search endpoint).
 *
 * @param term Search text.
 * @return Matches.
 */
export async function searchContent( term: string ): Promise< ChosenPage[] > {
	const results = await wpRequest< SearchResult[] >(
		addQueryArgs( '/wp/v2/search', {
			search: term,
			type: 'post',
			subtype: 'any',
			per_page: 10,
		} )
	);
	return results.map( ( result ) => ( {
		id: result.id,
		title: result.title,
		type: result.subtype,
	} ) );
}

/**
 * Form values: address patterns are edited as text, one per line, and the
 * offline message lives in the chatbot section.
 */
export interface DisplayFormValues {
	display: Omit< DisplaySettings, 'url_patterns' >;
	patterns: string;
	offline_message: string;
}

/**
 * Lines of a text box, trimmed, without blanks.
 *
 * @param text Text.
 * @return Lines.
 */
export function lines( text: string ): string[] {
	return text
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );
}

/**
 * Stored settings to form values.
 *
 * @param settings Settings.
 * @return Form values.
 */
export function toDisplayForm( settings: Settings ): DisplayFormValues {
	const { url_patterns: patterns, ...display } = settings.display;
	return {
		display,
		patterns: patterns.join( '\n' ),
		offline_message: settings.chatbot.offline_message,
	};
}

/**
 * Form values to a settings update.
 *
 * @param values Form values.
 * @return Update.
 */
export function fromDisplayForm( values: DisplayFormValues ): {
	display: DisplaySettings;
	chatbot: { offline_message: string };
} {
	return {
		display: { ...values.display, url_patterns: lines( values.patterns ) },
		chatbot: { offline_message: values.offline_message },
	};
}

/**
 * A page address pattern: a path ("/shop/*"), a wildcard ("*checkout*"),
 * or a full address on this site.
 */
const PATTERN = /^(https?:\/\/\S+|[^\s]+)$/i;

export const displayFormSchema = z
	.object( {
		display: displaySettingsSchema.extend( {
			url_patterns: z
				.array(
					z
						.string()
						.max( 200 )
						.regex( PATTERN, { message: 'pattern' } )
				)
				.max( 50 ),
		} ),
		chatbot: z.object( { offline_message: z.string().max( 500 ) } ),
	} )
	.superRefine( ( value, context ) => {
		const display = value.display;
		if (
			display.pages_mode !== 'all' &&
			! display.page_ids.length &&
			! display.post_types.length &&
			! display.url_patterns.length
		) {
			context.addIssue( {
				code: 'custom',
				path: [ 'display', 'pages_mode' ],
				message: 'rules:empty',
			} );
		}
		if (
			display.hours.enabled &&
			! DAY_KEYS.some( ( day ) => display.hours.days[ day ].open )
		) {
			context.addIssue( {
				code: 'custom',
				path: [ 'display', 'hours', 'enabled' ],
				message: 'hours:none',
			} );
		}
	} );

/**
 * Validation messages specific to this page.
 *
 * @param message Message code.
 * @return Text, or undefined for other codes.
 */
export function displayMessage( message: unknown ): string | undefined {
	switch ( message ) {
		case 'rules:empty':
			return __(
				'Choose at least one page, content type, or address.',
				'chatlume-ai-chatbot'
			);
		case 'hours:none':
			return __(
				'Open at least one day, or turn business hours off.',
				'chatlume-ai-chatbot'
			);
		case 'pattern':
			return __(
				'Enter one address per line without spaces, for example /shop/*.',
				'chatlume-ai-chatbot'
			);
		default:
			return undefined;
	}
}

/**
 * Day names.
 *
 * @return Names by key.
 */
export function dayNames(): Record< DayKey, string > {
	return {
		mon: __( 'Monday', 'chatlume-ai-chatbot' ),
		tue: __( 'Tuesday', 'chatlume-ai-chatbot' ),
		wed: __( 'Wednesday', 'chatlume-ai-chatbot' ),
		thu: __( 'Thursday', 'chatlume-ai-chatbot' ),
		fri: __( 'Friday', 'chatlume-ai-chatbot' ),
		sat: __( 'Saturday', 'chatlume-ai-chatbot' ),
		sun: __( 'Sunday', 'chatlume-ai-chatbot' ),
	};
}

/**
 * How a day's hours read, e.g. "09:00 – 17:00", "Open all day",
 * "22:00 – 02:00 (next day)", or "Closed".
 *
 * @param day Hours.
 * @return Text.
 */
export function describeDay(
	day: DisplaySettings[ 'hours' ][ 'days' ][ DayKey ]
): string {
	if ( ! day.open ) {
		return __( 'Closed', 'chatlume-ai-chatbot' );
	}
	if ( day.start === day.end ) {
		return __( 'Open all day', 'chatlume-ai-chatbot' );
	}
	if ( day.end < day.start ) {
		return sprintf(
			/* translators: 1: Opening time, 2: Closing time on the next day. */
			__( '%1$s – %2$s (next day)', 'chatlume-ai-chatbot' ),
			day.start,
			day.end
		);
	}
	return `${ day.start } – ${ day.end }`;
}

/**
 * Copies one day's hours to the weekdays (Monday to Friday) or to every day.
 *
 * @param days   Current hours.
 * @param from   Day to copy.
 * @param target Which days receive it.
 * @return New hours.
 */
export function copyHours(
	days: DisplaySettings[ 'hours' ][ 'days' ],
	from: DayKey,
	target: 'weekdays' | 'all'
): DisplaySettings[ 'hours' ][ 'days' ] {
	const keys: DayKey[] =
		target === 'weekdays'
			? [ 'mon', 'tue', 'wed', 'thu', 'fri' ]
			: [ ...DAY_KEYS ];
	const next = { ...days };
	for ( const key of keys ) {
		next[ key ] = { ...days[ from ] };
	}
	return next;
}
