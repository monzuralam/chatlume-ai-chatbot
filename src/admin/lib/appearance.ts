/**
 * Customizer data: draft, published theme, and versions.
 */
import apiFetch from '@wordpress/api-fetch';

import { request, toApiError } from './api';
import { getBootstrap } from './bootstrap';
import type { FontOption, Theme } from './theme/types';

export interface AppearanceVersion {
	id: string;
	published_at: string;
	author: string;
	current: boolean;
}

export interface UploadedFont {
	id: string;
	name: string;
	url: string;
	weight: string;
	style: 'normal' | 'italic';
}

export interface AppearanceState {
	draft: Theme;
	published: Theme;
	has_changes: boolean;
	defaults: Theme;
	versions: AppearanceVersion[];
	fonts: {
		options: FontOption[];
		uploaded: UploadedFont[];
		/** Script of the site language, e.g. "bengali". */
		site_script: string;
	};
}

/**
 * Uploads a woff2 font.
 *
 * @param file   File.
 * @param name   Display name.
 * @param weight Weight ("400" … "700", or "100 900" for variable fonts).
 * @return New state.
 */
export async function uploadFont(
	file: File,
	name: string,
	weight: string
): Promise< AppearanceState > {
	const body = new FormData();
	body.append( 'font', file );
	body.append( 'name', name );
	body.append( 'weight', weight );
	try {
		return await apiFetch< AppearanceState >( {
			path: `/${ getBootstrap().restNamespace }/appearance/fonts`,
			method: 'POST',
			body,
		} );
	} catch ( error ) {
		throw toApiError( error );
	}
}

export const deleteFont = ( id: string ) =>
	request< AppearanceState >(
		`/appearance/fonts/${ encodeURIComponent( id ) }`,
		{ method: 'DELETE' }
	);

/**
 * Whether a font lacks the site's script (a note for the admin), or null
 * when unknown.
 *
 * @param option Font.
 * @param script Site script.
 * @return True when the script is missing.
 */
export function lacksScript(
	option: FontOption | undefined,
	script: string
): boolean {
	return !! option?.scripts && ! option.scripts.includes( script );
}

export const fetchAppearance = () =>
	request< AppearanceState >( '/appearance' );

export const saveDraft = ( theme: Theme ) =>
	request< AppearanceState >( '/appearance/draft', {
		method: 'POST',
		data: { theme },
	} );

export const discardDraft = () =>
	request< AppearanceState >( '/appearance/draft', { method: 'DELETE' } );

export const publishDraft = () =>
	request< AppearanceState >( '/appearance/publish', { method: 'POST' } );

export const restoreVersion = ( id: string ) =>
	request< AppearanceState >(
		`/appearance/versions/${ encodeURIComponent( id ) }/restore`,
		{ method: 'POST' }
	);

/**
 * Whether two themes are the same.
 *
 * @param a Theme.
 * @param b Theme.
 * @return True when equal.
 */
export function sameTheme( a: Theme, b: Theme ): boolean {
	return JSON.stringify( a ) === JSON.stringify( b );
}

/**
 * Undo and redo stacks for the theme being edited.
 */
export interface History {
	past: Theme[];
	present: Theme;
	future: Theme[];
}

const LIMIT = 100;

/**
 * Records a change. Changes within one gesture (dragging a slider or color
 * picker) share a group and become a single undo step.
 *
 * @param history History.
 * @param next    New theme.
 * @param merge   Replace the present instead of adding a step.
 * @return History.
 */
export function record(
	history: History,
	next: Theme,
	merge = false
): History {
	if ( sameTheme( next, history.present ) ) {
		return history;
	}
	if ( merge ) {
		return { ...history, present: next, future: [] };
	}
	return {
		past: [ ...history.past, history.present ].slice( -LIMIT ),
		present: next,
		future: [],
	};
}

/**
 * Steps back.
 *
 * @param history History.
 * @return History.
 */
export function undo( history: History ): History {
	if ( ! history.past.length ) {
		return history;
	}
	return {
		past: history.past.slice( 0, -1 ),
		present: history.past[ history.past.length - 1 ],
		future: [ history.present, ...history.future ],
	};
}

/**
 * Steps forward.
 *
 * @param history History.
 * @return History.
 */
export function redo( history: History ): History {
	if ( ! history.future.length ) {
		return history;
	}
	return {
		past: [ ...history.past, history.present ],
		present: history.future[ 0 ],
		future: history.future.slice( 1 ),
	};
}
