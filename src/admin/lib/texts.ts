/**
 * The Widget texts page: interface labels and translations of the
 * admin's own texts, one language at a time.
 */
import { __, sprintf } from '@wordpress/i18n';

import { request } from './api';

export interface SiteLanguage {
	code: string;
	locale: string;
	name: string;
}

export interface ContentSource {
	/** Where the main-language text lives, e.g. "chatbot.welcome_message". */
	path: string;
	group: string;
	format: 'text' | 'textarea' | 'rich';
	max: number;
	main: string;
	value: string;
}

export interface TextsState {
	plugin: '' | 'polylang' | 'wpml';
	languages: SiteLanguage[];
	main: string;
	language: string;
	labels: Record< string, { default: string; value: string } >;
	content: ContentSource[];
}

export interface TextsForm {
	labels: Record< string, string >;
	/** In the order of `TextsState.content` (paths contain dots). */
	content: Array< { path: string; value: string } >;
}

export const fetchTexts = ( language: string ) =>
	request< TextsState >(
		`/texts?language=${ encodeURIComponent( language ) }`
	);

export const saveTexts = ( language: string, values: TextsForm ) =>
	request< TextsState >( '/texts', {
		method: 'POST',
		data: { language, ...fromTextsForm( values ) },
	} );

/**
 * Form values from the server state.
 *
 * @param state State.
 * @return Values.
 */
export function toTextsForm( state: TextsState ): TextsForm {
	return {
		labels: Object.fromEntries(
			Object.entries( state.labels ).map( ( [ key, label ] ) => [
				key,
				label.value,
			] )
		),
		content: state.content.map( ( source ) => ( {
			path: source.path,
			value: source.value,
		} ) ),
	};
}

/**
 * Request body from form values: trimmed, empty texts left out.
 *
 * @param values Values.
 * @return Body.
 */
export function fromTextsForm( values: TextsForm ): {
	labels: Record< string, string >;
	content: Record< string, string >;
} {
	const labels: Record< string, string > = {};
	for ( const [ key, value ] of Object.entries( values.labels ) ) {
		if ( value.trim() ) {
			labels[ key ] = value.trim();
		}
	}
	const content: Record< string, string > = {};
	for ( const item of values.content ) {
		if ( item.value.trim() ) {
			content[ item.path ] = item.value.trim();
		}
	}
	return { labels, content };
}

export interface LabelGroup {
	id: string;
	title: string;
	keys: string[];
}

/**
 * Interface labels by where visitors see them. Labels the server knows
 * but no group lists are shown under "Other".
 *
 * @param keys Labels the server sent.
 * @return Groups with at least one label.
 */
export function labelGroups( keys: string[] ): LabelGroup[] {
	const groups: LabelGroup[] = [
		{
			id: 'window',
			title: __( 'Chat window', 'chatlume-ai-chatbot' ),
			keys: [
				'open',
				'close',
				'menu',
				'newChat',
				'conversation',
				'online',
				'away',
				'closed',
				'newMessage',
				'busyTab',
				'poweredBy',
			],
		},
		{
			id: 'composer',
			title: __( 'Message box', 'chatlume-ai-chatbot' ),
			keys: [
				'placeholder',
				'send',
				'stop',
				'voiceStart',
				'voiceStop',
				'listening',
				'voiceBlocked',
				'voiceFailed',
			],
		},
		{
			id: 'messages',
			title: __( 'Messages', 'chatlume-ai-chatbot' ),
			keys: [
				'you',
				'typing',
				'retry',
				'copy',
				'copied',
				'copyCode',
				'helpful',
				'notHelpful',
				'thanks',
				'askAi',
				'sources',
				'readAloud',
				'stopReading',
				'externalLink',
				'stopped',
			],
		},
		{
			id: 'lead',
			title: __( 'Lead form', 'chatlume-ai-chatbot' ),
			keys: [
				'leadBefore',
				'leadAfter',
				'leadName',
				'leadEmail',
				'leadSubmit',
				'leadSkip',
				'leadThanks',
				'leadInvalid',
				'leadConsent',
			],
		},
		{
			id: 'handoff',
			title: __( 'Talk to a person', 'chatlume-ai-chatbot' ),
			keys: [
				'talkToPerson',
				'handoffTitle',
				'emailTeam',
				'emailNeeded',
				'emailTeamOk',
				'emailMe',
				'emailMeOk',
				'sending',
			],
		},
		{
			id: 'problems',
			title: __( 'Problems', 'chatlume-ai-chatbot' ),
			keys: [ 'timeout', 'connection' ],
		},
	];

	const known = new Set( keys );
	const listed = new Set( groups.flatMap( ( group ) => group.keys ) );
	const other = keys.filter( ( key ) => ! listed.has( key ) );
	if ( other.length ) {
		groups.push( {
			id: 'other',
			title: __( 'Other', 'chatlume-ai-chatbot' ),
			keys: other,
		} );
	}

	return groups
		.map( ( group ) => ( {
			...group,
			keys: group.keys.filter( ( key ) => known.has( key ) ),
		} ) )
		.filter( ( group ) => group.keys.length > 0 );
}

/**
 * Readable name of an admin text.
 *
 * @param path Where the main-language text lives.
 * @return Name.
 */
export function sourceLabel( path: string ): string {
	const starter = /^chatbot\.starter_questions\.(\d+)$/.exec( path );
	if ( starter ) {
		return sprintf(
			/* translators: %d: Starter question number. */
			__( 'Starter question %d', 'chatlume-ai-chatbot' ),
			Number( starter[ 1 ] ) + 1
		);
	}
	const action = /^chatbot\.quick_actions\.(\d+)\.(label|value)$/.exec(
		path
	);
	if ( action ) {
		const number = Number( action[ 1 ] ) + 1;
		return action[ 2 ] === 'label'
			? sprintf(
					/* translators: %d: Quick action number. */
					__( 'Quick action %d: button text', 'chatlume-ai-chatbot' ),
					number
				)
			: sprintf(
					/* translators: %d: Quick action number. */
					__(
						'Quick action %d: message sent',
						'chatlume-ai-chatbot'
					),
					number
				);
	}

	const names: Record< string, string > = {
		'chatbot.bot_name': __( 'Bot name', 'chatlume-ai-chatbot' ),
		'chatbot.welcome_message': __(
			'Welcome message',
			'chatlume-ai-chatbot'
		),
		'chatbot.refusal_message': __(
			'Off-topic reply',
			'chatlume-ai-chatbot'
		),
		'chatbot.offline_message': __(
			'Offline message',
			'chatlume-ai-chatbot'
		),
		'chatbot.error_message': __( 'Error message', 'chatlume-ai-chatbot' ),
		'chatbot.site_search_message': __(
			'Site search introduction',
			'chatlume-ai-chatbot'
		),
		'display.triggers.message': __(
			'Invitation message',
			'chatlume-ai-chatbot'
		),
		'leads.consent': __( 'Consent text', 'chatlume-ai-chatbot' ),
		'privacy.chat_notice': __( 'Privacy notice', 'chatlume-ai-chatbot' ),
		'theme.launcher.label': __( 'Chat button text', 'chatlume-ai-chatbot' ),
		'theme.launcher.teaser': __(
			'Chat button message bubble',
			'chatlume-ai-chatbot'
		),
		'theme.brand.headerTitle': __( 'Header title', 'chatlume-ai-chatbot' ),
		'theme.brand.headerSubtitle': __(
			'Header subtitle',
			'chatlume-ai-chatbot'
		),
		'theme.brand.statusText': __( 'Status text', 'chatlume-ai-chatbot' ),
	};

	return names[ path ] ?? path;
}

/**
 * Whether a label or text matches a search.
 *
 * @param search   Search text.
 * @param haystack Texts to look in.
 * @return Whether it matches.
 */
export function matchesSearch(
	search: string,
	...haystack: string[]
): boolean {
	const needle = search.trim().toLocaleLowerCase();
	return (
		! needle ||
		haystack.some( ( text ) => text.toLocaleLowerCase().includes( needle ) )
	);
}
