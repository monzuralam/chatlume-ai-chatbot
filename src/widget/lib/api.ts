/**
 * Calls to Chatlume's public REST routes.
 *
 * No nonce is sent (pages stay cacheable); the server checks the signed
 * session token, the origin, and rate limits.
 */
import type { BehaviorConfig, Source, ThemeConfig } from '../types';

export class ApiError extends Error {
	code: string;
	status: number;
	retryAfter: number;

	constructor( code: string, message: string, status = 0, retryAfter = 0 ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
		this.retryAfter = retryAfter;
	}
}

export interface SessionResponse {
	token: string;
	expires: number;
	config: BehaviorConfig;
	theme?: ThemeConfig;
}

export interface Reply {
	text: string;
	source: string;
	sources: Source[];
	quick_answer_id: number | null;
	interrupted?: string;
}

export interface ChatResponse {
	conversation_id: string;
	message_id: number;
	reply: Reply;
}

export interface ChatRequest {
	token: string;
	message: string;
	conversation_id: string;
	page_url: string;
	page_title: string;
	language: string;
	skip_quick_answers?: boolean;
	quick_answer_id?: number;
}

/**
 * Error from a failed response.
 *
 * @param response Response.
 * @param fallback Message when the body has none.
 * @return Error.
 */
export async function errorFrom(
	response: Response,
	fallback: string
): Promise< ApiError > {
	let body: {
		code?: string;
		message?: string;
		data?: { status?: number; retry_after?: number };
	} = {};
	try {
		body = await response.json();
	} catch {
		// Not JSON (for example an HTML error page from a proxy).
	}
	return new ApiError(
		String( body.code ?? 'http_' + response.status ),
		String( body.message || fallback ),
		Number( body.data?.status ?? response.status ),
		Number( body.data?.retry_after ?? 0 )
	);
}

/**
 * Starts a POST request.
 *
 * @param base   REST base URL (ends with a slash).
 * @param path   Route path.
 * @param body   JSON body.
 * @param signal Abort signal.
 * @param accept Accept header.
 * @return Response.
 */
export function request(
	base: string,
	path: string,
	body: unknown,
	signal?: AbortSignal,
	accept = 'application/json'
): Promise< Response > {
	return fetch( base + path, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			Accept: accept,
		},
		body: JSON.stringify( body ),
		credentials: 'same-origin',
		cache: 'no-store',
		signal,
	} );
}

/**
 * POST returning JSON.
 *
 * @param base     REST base URL.
 * @param path     Route path.
 * @param body     JSON body.
 * @param fallback Error message when the server gives none.
 * @param signal   Abort signal.
 * @return Parsed body.
 */
export async function postJson< T >(
	base: string,
	path: string,
	body: unknown,
	fallback: string,
	signal?: AbortSignal
): Promise< T > {
	const response = await request( base, path, body, signal );
	if ( ! response.ok ) {
		throw await errorFrom( response, fallback );
	}
	return ( await response.json() ) as T;
}

/**
 * Whether a response is an event stream.
 *
 * @param response Response.
 * @return Whether it is.
 */
export function isEventStream( response: Response ): boolean {
	return (
		response.ok &&
		!! response.body &&
		( response.headers.get( 'content-type' ) ?? '' ).includes(
			'text/event-stream'
		)
	);
}
