import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { getBootstrap } from './bootstrap';

/**
 * Error thrown by every Chatlume REST call, with the server's error code,
 * HTTP status, and a message that is safe to show the admin.
 */
export class ApiError extends Error {
	readonly code: string;
	readonly status: number;

	constructor( message: string, code: string, status: number ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
	}
}

interface WpErrorShape {
	code?: string;
	message?: string;
	data?: { status?: number; params?: Record< string, string > };
}

/**
 * Converts anything apiFetch throws into an ApiError.
 *
 * @param error Thrown value.
 */
export function toApiError( error: unknown ): ApiError {
	if ( error instanceof ApiError ) {
		return error;
	}

	const shape = ( error ?? {} ) as WpErrorShape;

	// For invalid parameters, WordPress puts the specific explanation per
	// parameter in data.params; it is more useful than the generic message.
	const paramMessage = shape.data?.params
		? Object.values( shape.data.params ).find(
				( message ) => typeof message === 'string' && message !== ''
			)
		: undefined;

	return new ApiError(
		paramMessage ||
			shape.message ||
			__(
				'Something went wrong while talking to your site. Please try again.',
				'chatlume-ai-chatbot'
			),
		shape.code || 'unknown_error',
		shape.data?.status ?? 0
	);
}

interface RequestOptions {
	method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
	data?: unknown;
}

/**
 * Calls a WordPress REST endpoint through core's apiFetch (which adds the
 * REST nonce automatically).
 *
 * @param path    Full REST path, e.g. '/wp/v2/users/me'.
 * @param options Method and body.
 */
export async function wpRequest< T >(
	path: string,
	options: RequestOptions = {}
): Promise< T > {
	try {
		return await apiFetch< T >( {
			path,
			method: options.method ?? 'GET',
			data: options.data as Record< string, unknown > | undefined,
		} );
	} catch ( error ) {
		throw toApiError( error );
	}
}

/**
 * A page of a Chatlume list endpoint with its X-WP-Total count.
 *
 * @param route Route inside the chatlume/v1 namespace, with query args.
 * @return Items and the total across pages.
 */
export async function requestPage< T >(
	route: string
): Promise< { items: T[]; total: number } > {
	let response: Response;
	try {
		response = ( await apiFetch( {
			path: `/${ getBootstrap().restNamespace }${ route }`,
			parse: false,
		} ) ) as Response;
	} catch ( error ) {
		throw toApiError(
			error instanceof Response
				? await error.json().catch( () => ( {} ) )
				: error
		);
	}

	const items = ( await response.json() ) as T[];

	return {
		items: Array.isArray( items ) ? items : [],
		total: Number( response.headers.get( 'X-WP-Total' ) ?? 0 ) || 0,
	};
}

/**
 * Uploads a file to a Chatlume endpoint as multipart form data.
 *
 * @param route  Route inside the chatlume/v1 namespace.
 * @param fields Form fields (files and text).
 * @return Parsed response.
 */
export async function upload< T >(
	route: string,
	fields: Record< string, string | Blob >
): Promise< T > {
	const body = new FormData();
	for ( const [ key, value ] of Object.entries( fields ) ) {
		body.append( key, value );
	}
	try {
		return await apiFetch< T >( {
			path: `/${ getBootstrap().restNamespace }${ route }`,
			method: 'POST',
			body,
		} );
	} catch ( error ) {
		throw toApiError( error );
	}
}

/**
 * Calls a Chatlume REST endpoint.
 *
 * @param route   Route inside the chatlume/v1 namespace, e.g. '/settings'.
 * @param options Method and body.
 */
export function request< T >(
	route: string,
	options: RequestOptions = {}
): Promise< T > {
	return wpRequest< T >(
		`/${ getBootstrap().restNamespace }${ route }`,
		options
	);
}
