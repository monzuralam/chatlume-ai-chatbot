/**
 * Extension registry for add-on plugins.
 *
 * Exposed as `window.chatlume.admin` before the app mounts, so an add-on
 * script that declares `chatlume-admin` as a dependency can register pages,
 * settings sections, and dashboard cards without modifying Chatlume:
 *
 *     window.chatlume.admin.registerPage( {
 *         id: 'my-addon',
 *         title: 'My add-on',
 *         path: '/my-addon',
 *         component: MyAddonPage,
 *     } );
 *
 * Every register function returns an unregister function. Registrations made
 * after the app has rendered appear immediately.
 */
import type { ComponentType } from 'react';

export interface IconProps {
	className?: string;
	'aria-hidden'?: boolean | 'true' | 'false';
}

export interface PageRegistration {
	/** Unique id: lowercase letters, numbers, and dashes, optionally namespaced ("vendor/page"). */
	id: string;
	/** Navigation label (already translated by the add-on). */
	title: string;
	/** Route path, e.g. "/my-addon". Sub-paths ("/my-addon/123") render the same page. */
	path: string;
	component: ComponentType;
	icon?: ComponentType< IconProps >;
	/** Sort order in the sidebar. Built-in pages use 0–1000. */
	order?: number;
	/** Use the full width of the screen (editors such as the Customizer). */
	wide?: boolean;
	/** Reachable by address only, not listed in the sidebar (the setup wizard). */
	hidden?: boolean;
}

export interface SettingsSectionRegistration {
	id: string;
	title: string;
	description?: string;
	component: ComponentType;
	order?: number;
}

export interface DashboardCardRegistration {
	id: string;
	title?: string;
	component: ComponentType;
	/** Grid width on wide screens. */
	size?: 'sm' | 'md' | 'lg';
	order?: number;
}

interface Orderable {
	id: string;
	order?: number;
}

export interface Registry< T extends Orderable > {
	register: ( item: T ) => () => void;
	getAll: () => readonly T[];
	subscribe: ( listener: () => void ) => () => void;
	clear: () => void;
}

const ID_PATTERN = /^[a-z0-9][a-z0-9-]*(\/[a-z0-9][a-z0-9-]*)?$/;
const PATH_PATTERN = /^\/([a-z0-9][a-z0-9\-/]*)?$/;

function isComponent( value: unknown ): boolean {
	return (
		typeof value === 'function' ||
		( typeof value === 'object' && value !== null && '$$typeof' in value )
	);
}

/**
 * Creates a registry with validation, stable ordering, and change events.
 *
 * @param kind     Name used in error messages.
 * @param validate Extra validation; throw to reject an item.
 */
export function createRegistry< T extends Orderable >(
	kind: string,
	validate: ( item: T, existing: readonly T[] ) => void = () => {}
): Registry< T > {
	let items: T[] = [];
	let snapshot: readonly T[] = [];
	const listeners = new Set< () => void >();

	const emit = () => {
		// Stable sort: by order, then by registration sequence.
		snapshot = Object.freeze(
			items
				.map( ( item, index ) => ( { item, index } ) )
				.sort(
					( a, b ) =>
						( a.item.order ?? 500 ) - ( b.item.order ?? 500 ) ||
						a.index - b.index
				)
				.map( ( { item } ) => item )
		);
		listeners.forEach( ( listener ) => listener() );
	};

	return {
		register( item ) {
			if ( ! item || typeof item !== 'object' ) {
				throw new TypeError( `Chatlume: ${ kind } must be an object.` );
			}
			if ( typeof item.id !== 'string' || ! ID_PATTERN.test( item.id ) ) {
				throw new TypeError(
					`Chatlume: ${ kind } id "${ String(
						item.id
					) }" is invalid. Use lowercase letters, numbers, and dashes.`
				);
			}
			if ( items.some( ( existing ) => existing.id === item.id ) ) {
				throw new Error(
					`Chatlume: a ${ kind } with id "${ item.id }" is already registered.`
				);
			}
			if (
				'component' in item &&
				! isComponent( ( item as { component?: unknown } ).component )
			) {
				throw new TypeError(
					`Chatlume: ${ kind } "${ item.id }" needs a React component.`
				);
			}
			validate( item, items );

			const entry = Object.freeze( { ...item } );
			items = [ ...items, entry ];
			emit();

			return () => {
				const before = items.length;
				items = items.filter( ( existing ) => existing !== entry );
				if ( items.length !== before ) {
					emit();
				}
			};
		},
		getAll() {
			return snapshot;
		},
		subscribe( listener ) {
			listeners.add( listener );
			return () => {
				listeners.delete( listener );
			};
		},
		clear() {
			items = [];
			emit();
		},
	};
}

/**
 * Normalizes a route path: lowercase, no trailing slash (except "/").
 *
 * @param path Path.
 */
export function normalizePath( path: string ): string {
	const trimmed = path.trim().toLowerCase().replace( /\/+$/, '' );
	return trimmed === '' ? '/' : trimmed;
}

/**
 * Finds the page whose path matches a location: exact match first,
 * otherwise the longest path prefix ("/a" matches "/a/b", not "/ab").
 *
 * @param pages    Registered pages.
 * @param pathname Current location path.
 */
export function matchPage< P extends Pick< PageRegistration, 'path' > >(
	pages: readonly P[],
	pathname: string
): P | undefined {
	const current = normalizePath( pathname );
	let best: P | undefined;

	for ( const page of pages ) {
		const path = normalizePath( page.path );
		const matches =
			path === current ||
			( path !== '/' && current.startsWith( `${ path }/` ) );

		if (
			matches &&
			( ! best || path.length > normalizePath( best.path ).length )
		) {
			best = page;
		}
	}

	return best;
}

export const pageRegistry = createRegistry< PageRegistration >(
	'page',
	( page, existing ) => {
		if ( typeof page.title !== 'string' || page.title.trim() === '' ) {
			throw new TypeError(
				`Chatlume: page "${ page.id }" needs a title.`
			);
		}
		if (
			typeof page.path !== 'string' ||
			! PATH_PATTERN.test( page.path )
		) {
			throw new TypeError(
				`Chatlume: page "${ page.id }" has an invalid path. Paths look like "/my-page".`
			);
		}
		const path = normalizePath( page.path );
		if (
			existing.some( ( other ) => normalizePath( other.path ) === path )
		) {
			throw new Error(
				`Chatlume: the path "${ path }" is already used by another page.`
			);
		}
	}
);

export const settingsSectionRegistry =
	createRegistry< SettingsSectionRegistration >(
		'settings section',
		( section ) => {
			if (
				typeof section.title !== 'string' ||
				section.title.trim() === ''
			) {
				throw new TypeError(
					`Chatlume: settings section "${ section.id }" needs a title.`
				);
			}
		}
	);

export const dashboardCardRegistry =
	createRegistry< DashboardCardRegistration >( 'dashboard card' );

export interface ChatlumeAdminApi {
	registerPage: ( page: PageRegistration ) => () => void;
	registerSettingsSection: (
		section: SettingsSectionRegistration
	) => () => void;
	registerDashboardCard: ( card: DashboardCardRegistration ) => () => void;
}

export const adminApi: ChatlumeAdminApi = Object.freeze( {
	registerPage: ( page: PageRegistration ) => pageRegistry.register( page ),
	registerSettingsSection: ( section: SettingsSectionRegistration ) =>
		settingsSectionRegistry.register( section ),
	registerDashboardCard: ( card: DashboardCardRegistration ) =>
		dashboardCardRegistry.register( card ),
} );

/**
 * Publishes the registry API on `window.chatlume.admin`.
 */
export function exposeAdminApi(): void {
	window.chatlume = window.chatlume ?? {};
	window.chatlume.admin = adminApi;
}
