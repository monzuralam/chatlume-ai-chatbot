import {
	adminApi,
	createRegistry,
	dashboardCardRegistry,
	exposeAdminApi,
	matchPage,
	normalizePath,
	pageRegistry,
	settingsSectionRegistry,
} from '../../src/admin/extensions/registry';

const Component = () => null;

afterEach( () => {
	pageRegistry.clear();
	settingsSectionRegistry.clear();
	dashboardCardRegistry.clear();
} );

describe( 'createRegistry', () => {
	it( 'sorts by order, then by registration sequence', () => {
		const registry = createRegistry< { id: string; order?: number } >(
			'item'
		);
		registry.register( { id: 'c', order: 10 } );
		registry.register( { id: 'a' } );
		registry.register( { id: 'b', order: 10 } );
		registry.register( { id: 'first', order: 1 } );

		expect( registry.getAll().map( ( item ) => item.id ) ).toEqual( [
			'first',
			'c',
			'b',
			'a',
		] );
	} );

	it( 'returns a stable snapshot between changes', () => {
		const registry = createRegistry< { id: string } >( 'item' );
		registry.register( { id: 'a' } );
		expect( registry.getAll() ).toBe( registry.getAll() );
	} );

	it( 'notifies subscribers and supports unregistering', () => {
		const registry = createRegistry< { id: string } >( 'item' );
		const listener = jest.fn();
		const unsubscribe = registry.subscribe( listener );

		const unregister = registry.register( { id: 'a' } );
		expect( listener ).toHaveBeenCalledTimes( 1 );

		unregister();
		expect( listener ).toHaveBeenCalledTimes( 2 );
		expect( registry.getAll() ).toHaveLength( 0 );

		// A second unregister is a no-op.
		unregister();
		expect( listener ).toHaveBeenCalledTimes( 2 );

		unsubscribe();
		registry.register( { id: 'b' } );
		expect( listener ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'rejects invalid and duplicate ids', () => {
		const registry = createRegistry< { id: string } >( 'item' );
		expect( () => registry.register( { id: 'Bad Id' } ) ).toThrow(
			TypeError
		);
		expect( () => registry.register( { id: '' } ) ).toThrow( TypeError );
		registry.register( { id: 'vendor/item-1' } );
		expect( () => registry.register( { id: 'vendor/item-1' } ) ).toThrow(
			/already registered/
		);
	} );

	it( 'freezes registered items', () => {
		const registry = createRegistry< { id: string } >( 'item' );
		registry.register( { id: 'a' } );
		expect( Object.isFrozen( registry.getAll()[ 0 ] ) ).toBe( true );
	} );
} );

describe( 'pageRegistry', () => {
	it( 'registers valid pages', () => {
		pageRegistry.register( {
			id: 'addon',
			title: 'Add-on',
			path: '/addon',
			component: Component,
		} );
		expect( pageRegistry.getAll()[ 0 ].id ).toBe( 'addon' );
	} );

	it( 'rejects pages without a component, title, or valid path', () => {
		expect( () =>
			pageRegistry.register( {
				id: 'a',
				title: 'A',
				path: '/a',
				component: 'nope' as unknown as typeof Component,
			} )
		).toThrow( /React component/ );
		expect( () =>
			pageRegistry.register( {
				id: 'b',
				title: ' ',
				path: '/b',
				component: Component,
			} )
		).toThrow( /title/ );
		expect( () =>
			pageRegistry.register( {
				id: 'c',
				title: 'C',
				path: 'no-slash',
				component: Component,
			} )
		).toThrow( /invalid path/ );
	} );

	it( 'rejects a path that is already taken', () => {
		pageRegistry.register( {
			id: 'one',
			title: 'One',
			path: '/same',
			component: Component,
		} );
		expect( () =>
			pageRegistry.register( {
				id: 'two',
				title: 'Two',
				path: '/same/',
				component: Component,
			} )
		).toThrow( /already used/ );
	} );
} );

describe( 'matchPage', () => {
	const pages = [
		{ id: 'home', path: '/' },
		{ id: 'a', path: '/a' },
		{ id: 'ab', path: '/a/b' },
	];

	it.each( [
		[ '/', 'home' ],
		[ '', 'home' ],
		[ '/a', 'a' ],
		[ '/a/', 'a' ],
		[ '/a/123', 'a' ],
		[ '/a/b/c', 'ab' ],
		[ '/A', 'a' ],
	] )( 'matches %s to %s', ( pathname, id ) => {
		expect( matchPage( pages, pathname )?.id ).toBe( id );
	} );

	it( 'does not match partial segments or unknown paths', () => {
		expect( matchPage( pages, '/ab' ) ).toBeUndefined();
		expect( matchPage( pages, '/missing' ) ).toBeUndefined();
	} );

	it( 'normalizes paths', () => {
		expect( normalizePath( '/Settings/' ) ).toBe( '/settings' );
		expect( normalizePath( '/' ) ).toBe( '/' );
	} );
} );

describe( 'window.chatlume.admin', () => {
	it( 'exposes the public API and keeps existing globals', () => {
		window.chatlume = { bootstrap: undefined };
		exposeAdminApi();

		expect( window.chatlume.admin ).toBe( adminApi );
		expect( Object.isFrozen( adminApi ) ).toBe( true );

		const unregister = window.chatlume.admin?.registerDashboardCard( {
			id: 'stats',
			component: Component,
		} );
		expect( dashboardCardRegistry.getAll() ).toHaveLength( 1 );
		unregister?.();
		expect( dashboardCardRegistry.getAll() ).toHaveLength( 0 );

		window.chatlume.admin?.registerSettingsSection( {
			id: 'addon-settings',
			title: 'Add-on',
			component: Component,
		} );
		expect( settingsSectionRegistry.getAll()[ 0 ].title ).toBe( 'Add-on' );
	} );
} );
