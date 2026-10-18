/**
 * The Knowledge page: what the bot learns from, uploaded documents, quick
 * answers, and the state of the index.
 */
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { request, requestPage, upload } from './api';
import type { ChosenPage } from './display';
import type { Settings } from './settingsSchema';

export type KnowledgeSettings = Settings[ 'knowledge' ];

export type SourceType = 'post' | 'business' | 'document' | 'product';

export interface KnowledgeStatus {
	index: {
		chunks: number;
		sources: number;
		bytes: number;
		embedded: number;
		stale: number;
		types: Partial< Record< SourceType, number > >;
		languages: Record< string, number >;
		updated_at: string;
	};
	size: {
		level: 'ok' | 'warn' | 'high';
		chunks_limit: number;
		bytes_limit: number;
	};
	queue: {
		status: 'idle' | 'running' | 'done';
		total: number;
		done: number;
		started: string;
		finished: string;
	};
	fulltext: boolean;
	embeddings: {
		switched_on: boolean;
		available: boolean;
		model: string;
		pending: number;
		status: 'idle' | 'running' | 'done' | 'error';
		error: string;
		message: string;
		retry_at: string;
	};
	post_types: Array< { name: string; label: string } >;
	woocommerce: boolean;
	chosen: { included: ChosenPage[]; excluded: ChosenPage[] };
}

export interface KnowledgeSource {
	type: SourceType;
	id: string;
	title: string;
	url: string;
	language: string;
	chunks: number;
	updated_at: string;
}

export interface KnowledgeDocument {
	id: string;
	name: string;
	type: string;
	size: number;
	chars: number;
	chunks: number;
	language: string;
	uploaded_at: string;
}

export interface DocumentsInfo {
	items: KnowledgeDocument[];
	max_bytes: number;
	server_bytes: number;
	types: string[];
	docx: boolean;
}

export interface QuickAnswer {
	id: number;
	question: string;
	answer: string;
	keywords: string;
	language: string;
	enabled: boolean;
	hit_count: number;
	miss_count: number;
	created_at: string;
	updated_at: string;
}

export type QuickAnswerFields = Pick<
	QuickAnswer,
	'question' | 'answer' | 'keywords' | 'language' | 'enabled'
>;

export interface ImportResult {
	created: number;
	updated: number;
	skipped: number;
	errors: Array< { row: number; message: string } >;
}

export const fetchKnowledgeStatus = () =>
	request< KnowledgeStatus >( '/knowledge/status' );

export const startReindex = () =>
	request< KnowledgeStatus >( '/knowledge/reindex', { method: 'POST' } );

export const fetchSources = ( args: {
	type: string;
	search: string;
	page: number;
} ) =>
	requestPage< KnowledgeSource >(
		addQueryArgs( '/knowledge/sources', { ...args, per_page: 20 } )
	);

export const fetchDocuments = () =>
	request< DocumentsInfo >( '/knowledge/documents' );

export const uploadDocument = ( file: File, language: string ) =>
	upload< KnowledgeDocument >( '/knowledge/documents', {
		file,
		language,
	} );

export const deleteDocument = ( id: string ) =>
	request< { deleted: boolean } >( `/knowledge/documents/${ id }`, {
		method: 'DELETE',
	} );

export const fetchQuickAnswers = ( args: { search: string; page: number } ) =>
	requestPage< QuickAnswer >(
		addQueryArgs( '/quick-answers', { ...args, per_page: 20 } )
	);

export const createQuickAnswer = ( fields: QuickAnswerFields ) =>
	request< QuickAnswer >( '/quick-answers', {
		method: 'POST',
		data: fields,
	} );

export const updateQuickAnswer = (
	id: number,
	fields: Partial< QuickAnswerFields >
) =>
	request< QuickAnswer >( `/quick-answers/${ id }`, {
		method: 'PATCH',
		data: fields,
	} );

export const deleteQuickAnswer = ( id: number ) =>
	request< { deleted: boolean } >( `/quick-answers/${ id }`, {
		method: 'DELETE',
	} );

export type ImportRow = Partial<
	Record<
		'question' | 'answer' | 'keywords' | 'language' | 'enabled',
		string
	>
>;

export const importQuickAnswers = ( rows: ImportRow[] ) =>
	request< ImportResult >( '/quick-answers/import', {
		method: 'POST',
		data: { rows },
	} );

/**
 * Every quick answer, a page at a time (for the CSV export).
 *
 * @return Quick answers.
 */
export async function fetchAllQuickAnswers(): Promise< QuickAnswer[] > {
	const all: QuickAnswer[] = [];
	for ( let page = 1; page <= 100; page++ ) {
		const result = await requestPage< QuickAnswer >(
			addQueryArgs( '/quick-answers', { page, per_page: 100 } )
		);
		all.push( ...result.items );
		if ( result.items.length < 100 || all.length >= result.total ) {
			break;
		}
	}
	return all;
}

/**
 * Readable name of a source type.
 *
 * @param type Source type.
 * @return Label.
 */
export function sourceTypeLabel( type: SourceType ): string {
	switch ( type ) {
		case 'business':
			return __( 'Business info', 'chatlume-ai-chatbot' );
		case 'document':
			return __( 'Document', 'chatlume-ai-chatbot' );
		case 'product':
			return __( 'Product', 'chatlume-ai-chatbot' );
		default:
			return __( 'Page or post', 'chatlume-ai-chatbot' );
	}
}

/**
 * Bytes as "1.2 MB".
 *
 * @param bytes Bytes.
 * @return Text.
 */
export function formatBytes( bytes: number ): string {
	if ( bytes < 1024 ) {
		return sprintf(
			/* translators: %d: Number of bytes. */
			__( '%d B', 'chatlume-ai-chatbot' ),
			bytes
		);
	}
	let value = bytes / 1024;
	let unit = 0;
	while ( value >= 1024 && unit < 2 ) {
		value /= 1024;
		unit++;
	}
	const amount = value.toLocaleString( undefined, {
		maximumFractionDigits: value < 10 ? 1 : 0,
	} );
	if ( unit === 0 ) {
		/* translators: %s: Size in kilobytes. */
		return sprintf( __( '%s KB', 'chatlume-ai-chatbot' ), amount );
	}
	if ( unit === 1 ) {
		/* translators: %s: Size in megabytes. */
		return sprintf( __( '%s MB', 'chatlume-ai-chatbot' ), amount );
	}
	/* translators: %s: Size in gigabytes. */
	return sprintf( __( '%s GB', 'chatlume-ai-chatbot' ), amount );
}

/**
 * Lower-case extension of a file name.
 *
 * @param name File name.
 * @return Extension without the dot.
 */
export function extensionOf( name: string ): string {
	const match = /\.([a-z0-9]+)$/i.exec( name );
	const extension = match ? match[ 1 ].toLowerCase() : '';
	return extension === 'markdown' ? 'md' : extension;
}

/**
 * Why a file cannot be uploaded, checked before sending it.
 *
 * @param file      File.
 * @param file.name File name.
 * @param file.size Size in bytes.
 * @param info      Accepted types and size limit.
 * @return Message, or '' when it can be uploaded.
 */
export function uploadProblem(
	file: { name: string; size: number },
	info: Pick< DocumentsInfo, 'types' | 'max_bytes' | 'docx' >
): string {
	const extension = extensionOf( file.name );
	if ( ! info.types.includes( extension ) ) {
		return __(
			'This file type is not supported. Upload a PDF, DOCX, TXT, Markdown, CSV, or JSON file.',
			'chatlume-ai-chatbot'
		);
	}
	if ( extension === 'docx' && ! info.docx ) {
		return __(
			'DOCX files need the PHP Zip extension, which this server does not have. Save the document as PDF or TXT instead.',
			'chatlume-ai-chatbot'
		);
	}
	if ( file.size > info.max_bytes ) {
		return sprintf(
			/* translators: %s: Maximum file size, for example "10 MB". */
			__(
				'The file is larger than %s. Choose a smaller file or raise the limit below.',
				'chatlume-ai-chatbot'
			),
			formatBytes( info.max_bytes )
		);
	}
	if ( file.size === 0 ) {
		return __( 'This file is empty.', 'chatlume-ai-chatbot' );
	}
	return '';
}

/**
 * Parses CSV text (comma, semicolon, or tab separated; quoted fields may
 * contain separators, quotes, and line breaks).
 *
 * @param text CSV text.
 * @return Rows of cells.
 */
export function parseCsv( text: string ): string[][] {
	// Spreadsheet apps often start UTF-8 files with a byte order mark.
	const source = text.charCodeAt( 0 ) === BOM ? text.slice( 1 ) : text;
	const firstLine = source.split( /\r?\n/, 1 )[ 0 ] ?? '';
	const counts = [ ',', ';', '\t' ].map( ( separator ) => ( {
		separator,
		count: firstLine.split( separator ).length,
	} ) );
	const separator = counts.reduce( ( best, item ) =>
		item.count > best.count ? item : best
	).separator;

	const rows: string[][] = [];
	let row: string[] = [];
	let cell = '';
	let quoted = false;

	for ( let i = 0; i < source.length; i++ ) {
		const char = source[ i ];
		if ( quoted ) {
			if ( char === '"' && source[ i + 1 ] === '"' ) {
				cell += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				cell += char;
			}
			continue;
		}
		if ( char === '"' && cell === '' ) {
			quoted = true;
		} else if ( char === separator ) {
			row.push( cell );
			cell = '';
		} else if ( char === '\n' || char === '\r' ) {
			if ( char === '\r' && source[ i + 1 ] === '\n' ) {
				i++;
			}
			row.push( cell );
			rows.push( row );
			row = [];
			cell = '';
		} else {
			cell += char;
		}
	}
	if ( cell !== '' || row.length > 0 ) {
		row.push( cell );
		rows.push( row );
	}

	return rows.filter( ( cells ) =>
		cells.some( ( value ) => value.trim() !== '' )
	);
}

/** Byte order mark (U+FEFF). */
const BOM = 0xfeff;

const CSV_COLUMNS = [
	'question',
	'answer',
	'keywords',
	'language',
	'enabled',
] as const;

/**
 * Quick answers from CSV: columns are matched by their header (question,
 * answer, keywords, language, enabled); without a header the first two
 * columns are the question and the answer.
 *
 * @param text CSV text.
 * @return Rows to import.
 */
export function quickAnswersFromCsv( text: string ): ImportRow[] {
	const rows = parseCsv( text );
	if ( rows.length === 0 ) {
		return [];
	}

	const header = rows[ 0 ].map( ( cell ) => cell.trim().toLowerCase() );
	const hasHeader =
		header.includes( 'question' ) && header.includes( 'answer' );
	const columns = hasHeader
		? header
		: [ 'question', 'answer', 'keywords', 'language', 'enabled' ];

	return ( hasHeader ? rows.slice( 1 ) : rows ).map( ( cells ) => {
		const item: ImportRow = {};
		columns.forEach( ( column, index ) => {
			if (
				( CSV_COLUMNS as readonly string[] ).includes( column ) &&
				cells[ index ] !== undefined
			) {
				// Undo the apostrophe our export adds before formula characters.
				item[ column as ( typeof CSV_COLUMNS )[ number ] ] = cells[
					index
				]
					.trim()
					.replace( /^'(?=[=+\-@])/, '' );
			}
		} );
		return item;
	} );
}

/**
 * Quick answers as CSV with a header row, UTF-8 with a byte order mark so
 * spreadsheet apps read every language correctly.
 *
 * @param answers Quick answers.
 * @return CSV text.
 */
export function quickAnswersToCsv(
	answers: Array< Pick< QuickAnswer, ( typeof CSV_COLUMNS )[ number ] > >
): string {
	const escape = ( value: string ) => {
		// Cells starting with these could run as formulas in spreadsheets.
		const safe = /^[=+\-@\t\r]/.test( value ) ? `'${ value }` : value;
		return /[",;\n\r\t]/.test( safe )
			? `"${ safe.replace( /"/g, '""' ) }"`
			: safe;
	};
	const lines = [ CSV_COLUMNS.join( ',' ) ];
	for ( const answer of answers ) {
		lines.push(
			[
				answer.question,
				answer.answer,
				answer.keywords,
				answer.language,
				answer.enabled ? 'yes' : 'no',
			]
				.map( escape )
				.join( ',' )
		);
	}
	return String.fromCharCode( BOM ) + lines.join( '\r\n' ) + '\r\n';
}

/**
 * Starts a download of text as a file.
 *
 * @param text     Contents.
 * @param filename File name.
 * @param type     MIME type.
 */
export function downloadText( text: string, filename: string, type: string ) {
	const url = URL.createObjectURL( new Blob( [ text ], { type } ) );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

/**
 * Share of the rebuild that is done, 0–100.
 *
 * @param queue Queue state.
 * @return Percent.
 */
export function progressPercent( queue: KnowledgeStatus[ 'queue' ] ): number {
	if ( queue.status !== 'running' ) {
		return queue.status === 'done' ? 100 : 0;
	}
	return queue.total > 0
		? Math.min( 99, Math.round( ( queue.done / queue.total ) * 100 ) )
		: 0;
}
