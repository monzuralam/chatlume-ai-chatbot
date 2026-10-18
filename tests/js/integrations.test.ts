/**
 * The Integrations page form and the widget's analytics events.
 */
import {
	eventLabels,
	fromIntegrationsForm,
	integrationsFormSchema,
	integrationsMessage,
	toIntegrationsForm,
	verifySnippet,
	type IntegrationsForm,
} from '../../src/admin/lib/integrations';
import {
	WEBHOOK_EVENTS,
	type Settings,
} from '../../src/admin/lib/settingsSchema';
import { track } from '../../src/widget/lib/analytics';

const form = (
	change: Partial< IntegrationsForm > = {}
): IntegrationsForm => ( {
	integrations: {
		webhooks: [],
		analytics: false,
		turnstile: false,
		turnstile_site_key: '',
	},
	team_email: '',
	notify: true,
	alert_email: '',
	...change,
} );

const issues = ( values: IntegrationsForm ) => {
	const result = integrationsFormSchema.safeParse( values );
	return result.success
		? []
		: result.error.issues.map( ( issue ) => [
				issue.path.join( '.' ),
				issue.message,
			] );
};

describe( 'integrationsFormSchema', () => {
	it( 'accepts the defaults', () => {
		expect( issues( form() ) ).toEqual( [] );
	} );

	it( 'needs an address and an event for every webhook', () => {
		expect(
			issues(
				form( {
					integrations: {
						...form().integrations,
						webhooks: [ { url: ' ', events: [] } ],
					},
				} )
			)
		).toEqual(
			expect.arrayContaining( [
				[ 'integrations.webhooks.0.url', 'action:url' ],
				[ 'integrations.webhooks.0.events', 'events' ],
			] )
		);
	} );

	it( 'rejects other schemes, more than five webhooks, and bad emails', () => {
		const hook = {
			url: 'https://hooks.example.test/a',
			events: [ 'lead_captured' as const ],
		};
		expect(
			issues(
				form( {
					integrations: {
						...form().integrations,
						webhooks: [ { ...hook, url: 'ftp://example.test/' } ],
					},
				} )
			).map( ( [ path ] ) => path )
		).toContain( 'integrations.webhooks.0.url' );
		expect(
			issues(
				form( {
					integrations: {
						...form().integrations,
						webhooks: Array( 6 ).fill( hook ),
					},
				} )
			).length
		).toBeGreaterThan( 0 );
		expect(
			issues( form( { team_email: 'nope' } ) ).map( ( [ path ] ) => path )
		).toEqual( [ 'team_email' ] );
	} );

	it( 'needs a site key while Turnstile is on', () => {
		expect(
			issues(
				form( {
					integrations: { ...form().integrations, turnstile: true },
				} )
			)
		).toEqual( [ [ 'integrations.turnstile_site_key', 'site_key' ] ] );
	} );
} );

describe( 'form conversion', () => {
	it( 'reads email settings from their sections and writes them back trimmed', () => {
		const settings = {
			integrations: form().integrations,
			handoff: { team_email: 'team@example.test' },
			leads: { notify: false },
			costs: { alert_email: 'bills@example.test' },
		} as unknown as Settings;

		const values = toIntegrationsForm( settings );
		expect( values.team_email ).toBe( 'team@example.test' );
		expect( values.notify ).toBe( false );
		expect( values.alert_email ).toBe( 'bills@example.test' );

		expect(
			fromIntegrationsForm( {
				...values,
				team_email: ' a@example.test ',
				integrations: {
					...values.integrations,
					turnstile_site_key: ' 0x4AAA ',
					webhooks: [
						{
							url: ' https://hooks.example.test/a ',
							events: [ 'handoff_requested' ],
						},
					],
				},
			} )
		).toEqual( {
			integrations: {
				webhooks: [
					{
						url: 'https://hooks.example.test/a',
						events: [ 'handoff_requested' ],
					},
				],
				analytics: false,
				turnstile: false,
				turnstile_site_key: '0x4AAA',
			},
			handoff: { team_email: 'a@example.test' },
			leads: { notify: false },
			costs: { alert_email: 'bills@example.test' },
		} );
	} );

	it( 'labels every event and explains its own codes', () => {
		const labels = eventLabels();
		for ( const event of WEBHOOK_EVENTS ) {
			expect( labels[ event ] ).toBeTruthy();
		}
		expect( integrationsMessage( 'events' ) ).toBeTruthy();
		expect( integrationsMessage( 'site_key' ) ).toBeTruthy();
		expect( integrationsMessage( 'other' ) ).toBeUndefined();
		expect( verifySnippet() ).toContain( 'hash_equals' );
	} );
} );

describe( 'track', () => {
	afterEach( () => {
		delete window.dataLayer;
	} );

	it( 'does nothing while analytics events are off', () => {
		window.dataLayer = [];
		const listener = jest.fn();
		document.addEventListener( 'chatlume:chat_open', listener );

		track( false, 'chat_open' );
		track( undefined, 'chat_open' );

		expect( window.dataLayer ).toEqual( [] );
		expect( listener ).not.toHaveBeenCalled();
		document.removeEventListener( 'chatlume:chat_open', listener );
	} );

	it( 'pushes to the data layer and dispatches a DOM event', () => {
		window.dataLayer = [];
		const listener = jest.fn();
		document.addEventListener( 'chatlume:message_sent', listener );

		track( true, 'message_sent', { count: 2 } );

		expect( window.dataLayer ).toEqual( [
			{ event: 'message_sent', event_source: 'chatlume', count: 2 },
		] );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect(
			( listener.mock.calls[ 0 ][ 0 ] as CustomEvent ).detail
		).toEqual( {
			event: 'message_sent',
			event_source: 'chatlume',
			count: 2,
		} );
		document.removeEventListener( 'chatlume:message_sent', listener );
	} );

	it( 'still dispatches without a data layer', () => {
		const listener = jest.fn();
		document.addEventListener( 'chatlume:handoff', listener );
		track( true, 'handoff' );
		expect( listener ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( 'chatlume:handoff', listener );
	} );
} );
