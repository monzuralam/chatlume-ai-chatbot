/**
 * The Customizer's message after publishing.
 */
import { publishedMessage } from '../../src/admin/lib/appearance';

describe( 'publishedMessage', () => {
	it( 'confirms the publish when no cache was involved', () => {
		expect( publishedMessage() ).toEqual( {
			title: 'Published. Visitors now see the new look.',
		} );
		expect( publishedMessage( { purged: [], cdn: '' } ) ).toEqual( {
			title: 'Published. Visitors now see the new look.',
		} );
	} );

	it( 'names the page caches that were cleared', () => {
		expect(
			publishedMessage( {
				purged: [ 'WP Rocket', 'LiteSpeed Cache' ],
				cdn: '',
			} ).title
		).toBe(
			'Published. Visitors now see the new look, and the page cache of WP Rocket, LiteSpeed Cache was cleared.'
		);
	} );

	it( 'suggests purging a detected CDN', () => {
		const message = publishedMessage( { purged: [], cdn: 'Cloudflare' } );
		expect( message.description ).toContain( 'Your site uses Cloudflare.' );
	} );
} );
