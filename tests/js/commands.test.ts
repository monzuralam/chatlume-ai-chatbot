/**
 * Command Palette commands.
 */
import { chatlumeCommands, commandTarget } from '../../src/commands/list';

const admin = 'https://example.test/wp-admin/admin.php?page=chatlume';

describe( 'chatlumeCommands', () => {
	it( 'covers the main screens with unique names', () => {
		const commands = chatlumeCommands();
		const names = commands.map( ( command ) => command.name );
		expect( new Set( names ).size ).toBe( names.length );
		expect( commands.map( ( command ) => command.label ) ).toEqual(
			expect.arrayContaining( [
				'Chatlume: Conversations',
				'Chatlume: Test your bot',
				'Chatlume: Customize widget',
			] )
		);
		for ( const command of commands ) {
			expect( command.label.startsWith( 'Chatlume: ' ) ).toBe( true );
			expect( command.route.startsWith( '/' ) ).toBe( true );
		}
	} );
} );

describe( 'commandTarget', () => {
	it( 'changes the route without reloading on the Chatlume screen', () => {
		expect(
			commandTarget( '/conversations', admin, {
				href: `${ admin }#/settings`,
			} )
		).toEqual( { hash: '#/conversations' } );
	} );

	it( 'opens the Chatlume screen from anywhere else', () => {
		expect(
			commandTarget( '/customize', admin, {
				href: 'https://example.test/wp-admin/edit.php?post_type=page',
			} )
		).toEqual( { url: `${ admin }#/customize` } );
		expect(
			commandTarget( '/', admin, {
				href: 'https://example.test/wp-admin/admin.php?page=other',
			} )
		).toEqual( { url: `${ admin }#/` } );
	} );
} );
