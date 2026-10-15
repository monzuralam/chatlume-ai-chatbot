/**
 * Conversation log: types, requests, and export files.
 */
import { addQueryArgs } from '@wordpress/url';

import { request } from './api';

export type Rating = -1 | 1 | null;

export interface ConversationSummary {
	id: number;
	logged_in: boolean;
	page_url: string;
	language: string;
	provider: string;
	model: string;
	lead_name: string;
	lead_email: string;
	rating: Rating;
	message_count: number;
	total_cost: number;
	created_at: string;
	updated_at: string;
	first_question: string;
	sources: string[];
	is_test?: boolean;
}

export interface LoggedMessage {
	id: number;
	role: 'user' | 'assistant' | string;
	content: string;
	source: string;
	input_tokens: number;
	output_tokens: number;
	cost: number;
	latency_ms: number;
	feedback: Rating;
	sources: Array< { title?: string; url?: string } >;
	error_type: string;
	interrupted: string;
	created_at: string;
}

export interface ConversationFilters {
	search: string;
	date_from: string;
	date_to: string;
	rating: 'any' | 'up' | 'down' | 'none';
	source: string;
	lead: 'any' | 'yes' | 'no';
	language: string;
}

export const EMPTY_FILTERS: ConversationFilters = {
	search: '',
	date_from: '',
	date_to: '',
	rating: 'any',
	source: '',
	lead: 'any',
	language: '',
};

export interface ConversationPage {
	items: ConversationSummary[];
	total: number;
	page: number;
	per_page: number;
	languages: string[];
}

export interface Transcript {
	conversation: ConversationSummary;
	messages: LoggedMessage[];
}

export interface ExportData {
	conversations: Array< ConversationSummary & { messages: LoggedMessage[] } >;
	truncated: boolean;
}

/**
 * Only the filters that differ from "any" (keeps addresses short).
 *
 * @param filters Filters.
 * @return Query arguments.
 */
export function activeFilters(
	filters: ConversationFilters
): Partial< ConversationFilters > {
	const active: Partial< ConversationFilters > = {};
	( Object.keys( filters ) as Array< keyof ConversationFilters > ).forEach(
		( key ) => {
			if ( filters[ key ] !== EMPTY_FILTERS[ key ] ) {
				( active as Record< string, string > )[ key ] = filters[ key ];
			}
		}
	);
	return active;
}

export const fetchConversations = (
	filters: ConversationFilters,
	page: number,
	perPage: number
) =>
	request< ConversationPage >(
		addQueryArgs( '/conversations', {
			...activeFilters( filters ),
			page,
			per_page: perPage,
		} )
	);

export const fetchTranscript = ( id: number ) =>
	request< Transcript >( `/conversations/${ id }` );

export const deleteConversations = ( ids: number[] ) =>
	ids.length === 1
		? request< { deleted: number } >( `/conversations/${ ids[ 0 ] }`, {
				method: 'DELETE',
			} )
		: request< { deleted: number } >( '/conversations', {
				method: 'DELETE',
				data: { ids },
			} );

export const fetchExport = ( filters: ConversationFilters ) =>
	request< ExportData >(
		addQueryArgs( '/conversations/export', activeFilters( filters ) )
	);

/**
 * One CSV cell. Text that a spreadsheet would run as a formula is prefixed
 * with an apostrophe, because visitor messages end up in the file.
 *
 * @param value Value.
 * @return Escaped cell.
 */
export function csvCell( value: unknown ): string {
	let text = value === null || value === undefined ? '' : String( value );
	if ( /^[=+\-@\t\r]/.test( text ) ) {
		text = "'" + text;
	}
	return /[",\r\n]/.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
}

/**
 * CSV export: one row per message, with its conversation's details.
 *
 * @param data Export data.
 * @return CSV text (UTF-8 with a byte order mark so spreadsheets read it correctly).
 */
export function toCsv( data: ExportData ): string {
	const header = [
		'conversation_id',
		'conversation_started',
		'page_url',
		'language',
		'rating',
		'lead_name',
		'lead_email',
		'message_time',
		'role',
		'message',
		'source',
		'input_tokens',
		'output_tokens',
		'cost_usd',
		'latency_ms',
		'feedback',
		'source_links',
	];
	const rows = [ header.join( ',' ) ];

	for ( const conversation of data.conversations ) {
		for ( const message of conversation.messages ) {
			rows.push(
				[
					conversation.id,
					conversation.created_at,
					conversation.page_url,
					conversation.language,
					conversation.rating ?? '',
					conversation.lead_name,
					conversation.lead_email,
					message.created_at,
					message.role,
					message.content,
					message.source,
					message.input_tokens,
					message.output_tokens,
					message.cost,
					message.latency_ms,
					message.feedback ?? '',
					message.sources
						.map( ( source ) => source.url ?? '' )
						.filter( Boolean )
						.join( ' ' ),
				]
					.map( csvCell )
					.join( ',' )
			);
		}
	}

	return '\uFEFF' + rows.join( '\r\n' ) + '\r\n';
}

/**
 * Offers text as a file download.
 *
 * @param text     Contents.
 * @param filename File name.
 * @param type     MIME type.
 */
export function downloadFile(
	text: string,
	filename: string,
	type: string
): void {
	const url = URL.createObjectURL( new Blob( [ text ], { type } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	link.click();
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}
