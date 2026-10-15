/**
 * Health check, logs, and maintenance calls.
 */
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { request, toApiError } from './api';
import { getBootstrap } from './bootstrap';

export type CheckStatus = 'critical' | 'recommended' | 'good' | 'info';

export interface HealthCheckItem {
	id: string;
	status: CheckStatus;
	label: string;
	detail: string;
	fix: string;
}

export interface ProviderErrorEntry {
	time: number;
	provider: string;
	model: string;
	type: string;
	status: number;
	detail: string;
	title: string;
	explanation: string;
	fix: { label: string; url: string } | null;
}

export interface DebugEntry {
	time: number;
	event: string;
	source?: string;
	provider?: string;
	model?: string;
	input_tokens?: number;
	output_tokens?: number;
	cost?: number;
	latency_ms?: number;
	error_type?: string;
	interrupted?: string;
	streamed?: boolean;
	code?: string;
	status?: number;
}

export interface HealthResponse {
	checks: HealthCheckItem[];
	errors: ProviderErrorEntry[];
	debug: { enabled: boolean; entries: DebugEntry[] };
}

export const fetchHealth = () => request< HealthResponse >( '/health' );

export const clearProviderErrors = () =>
	request< { cleared: boolean } >( '/health/errors', { method: 'DELETE' } );

export const clearDebugLog = () =>
	request< { cleared: boolean } >( '/health/debug-log', {
		method: 'DELETE',
	} );

export const clearResponseCache = () =>
	request< { cleared: boolean } >( '/cache/clear', { method: 'POST' } );

export interface StreamTestResult {
	/** Events received (the server sends 6, a quarter second apart). */
	events: number;
	/** Milliseconds between the first and last event. */
	spreadMs: number;
	/** Whether events arrived one by one. */
	streaming: boolean;
}

/**
 * Judges a streaming test from the arrival times of its events: when the
 * server and every proxy pass the stream through, the six events arrive over
 * about 1.25 seconds; when something buffers, they arrive together.
 *
 * @param arrivals Arrival times in milliseconds.
 * @return Result.
 */
export function judgeStreamTest( arrivals: number[] ): StreamTestResult {
	const spreadMs =
		arrivals.length > 1
			? Math.round( arrivals[ arrivals.length - 1 ] - arrivals[ 0 ] )
			: 0;
	return {
		events: arrivals.length,
		spreadMs,
		streaming: arrivals.length >= 4 && spreadMs >= 600,
	};
}

/**
 * Runs the live streaming test.
 *
 * @return Result.
 */
export async function runStreamTest(): Promise< StreamTestResult > {
	let response: Response;
	try {
		response = ( await apiFetch( {
			path: `/${ getBootstrap().restNamespace }/health/stream-test`,
			method: 'POST',
			parse: false,
		} ) ) as Response;
	} catch ( error ) {
		throw toApiError(
			error instanceof Response
				? await error.json().catch( () => ( {} ) )
				: error
		);
	}

	const arrivals: number[] = [];
	const reader = (
		response.body as ReadableStream< Uint8Array >
	 ).getReader();
	const decoder = new TextDecoder();
	let buffer = '';

	for (;;) {
		const { done, value } = await reader.read();
		if ( done ) {
			break;
		}
		buffer += decoder.decode( value, { stream: true } );
		const ticks = buffer.split( 'event: tick' ).length - 1;
		const now = window.performance.now();
		while ( arrivals.length < ticks ) {
			arrivals.push( now );
		}
	}

	return judgeStreamTest( arrivals );
}

/**
 * A short description of a debug entry.
 *
 * @param entry Entry.
 * @return Text.
 */
export function describeDebugEntry( entry: DebugEntry ): string {
	if ( entry.event === 'rejected' ) {
		return sprintf(
			/* translators: 1: Error code, 2: HTTP status. */
			__( 'Request rejected: %1$s (HTTP %2$d)', 'chatlume-ai-chatbot' ),
			entry.code ?? '',
			entry.status ?? 0
		);
	}
	const parts = [ entry.source ?? '' ];
	if ( entry.provider ) {
		parts.push(
			`${ entry.provider }${ entry.model ? ` / ${ entry.model }` : '' }`
		);
	}
	if ( entry.input_tokens || entry.output_tokens ) {
		parts.push(
			sprintf(
				/* translators: 1: Input tokens, 2: Output tokens. */
				__( '%1$d in / %2$d out tokens', 'chatlume-ai-chatbot' ),
				entry.input_tokens ?? 0,
				entry.output_tokens ?? 0
			)
		);
	}
	if ( entry.streamed ) {
		parts.push( __( 'streamed', 'chatlume-ai-chatbot' ) );
	}
	if ( entry.interrupted ) {
		parts.push(
			sprintf(
				/* translators: %s: Reason, e.g. "stopped". */
				__( 'interrupted (%s)', 'chatlume-ai-chatbot' ),
				entry.interrupted
			)
		);
	}
	if ( entry.error_type ) {
		parts.push(
			sprintf(
				/* translators: %s: Error type. */
				__( 'provider error: %s', 'chatlume-ai-chatbot' ),
				entry.error_type
			)
		);
	}
	return parts.filter( Boolean ).join( ' · ' );
}
