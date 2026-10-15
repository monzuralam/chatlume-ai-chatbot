/**
 * Display rules in the browser and the Display page helpers.
 */
import {
	copyHours,
	describeDay,
	displayFormSchema,
	fromDisplayForm,
	lines,
	toDisplayForm,
} from '../../src/admin/lib/display';
import type { Settings } from '../../src/admin/lib/settingsSchema';
import {
	isClosed,
	isOpen,
	showFloating,
	zonedTime,
} from '../../src/widget/lib/display';
import type { DayHours, DisplayConfig } from '../../src/widget/types';

const DAYS = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];

function hours(
	open: Record< string, [ string, string ] >
): Record< string, DayHours > {
	return Object.fromEntries(
		DAYS.map( ( day ) => [
			day,
			{
				open: day in open,
				start: open[ day ]?.[ 0 ] ?? '09:00',
				end: open[ day ]?.[ 1 ] ?? '17:00',
			},
		] )
	);
}

// 2026-10-12 is a Monday.
const utc = ( time: string ) => new Date( `${ time }:00Z` );

describe( 'zonedTime', () => {
	it( 'reads the weekday and time in offsets and named timezones', () => {
		expect( zonedTime( utc( '2026-10-12T20:30' ), '+05:30' ) ).toEqual( {
			weekday: 2,
			minutes: 2 * 60,
		} );
		expect( zonedTime( utc( '2026-10-12T01:00' ), '-03:00' ) ).toEqual( {
			weekday: 0,
			minutes: 22 * 60,
		} );
		expect( zonedTime( utc( '2026-10-12T12:00' ), 'Asia/Dhaka' ) ).toEqual(
			{ weekday: 1, minutes: 18 * 60 }
		);
		expect( zonedTime( utc( '2026-10-12T12:00' ), 'Not/AZone' ) ).toEqual( {
			weekday: 1,
			minutes: 12 * 60,
		} );
	} );
} );

describe( 'isOpen', () => {
	it( 'matches the server rules', () => {
		const weekdays = hours( { mon: [ '09:00', '17:00' ] } );
		expect( isOpen( weekdays, utc( '2026-10-12T09:00' ), 'UTC' ) ).toBe(
			true
		);
		expect( isOpen( weekdays, utc( '2026-10-12T17:00' ), 'UTC' ) ).toBe(
			false
		);
		expect( isOpen( weekdays, utc( '2026-10-13T12:00' ), 'UTC' ) ).toBe(
			false
		);

		const late = hours( { fri: [ '22:00', '02:00' ] } );
		expect( isOpen( late, utc( '2026-10-16T23:30' ), 'UTC' ) ).toBe( true );
		expect( isOpen( late, utc( '2026-10-17T01:59' ), 'UTC' ) ).toBe( true );
		expect( isOpen( late, utc( '2026-10-17T02:00' ), 'UTC' ) ).toBe(
			false
		);

		const allDay = hours( { sun: [ '00:00', '00:00' ] } );
		expect( isOpen( allDay, utc( '2026-10-18T23:59' ), 'UTC' ) ).toBe(
			true
		);
	} );

	it( 'uses the site timezone, not the visitor’s', () => {
		const morning = hours( { mon: [ '09:00', '10:00' ] } );
		// 09:30 in Dhaka is 03:30 UTC.
		expect(
			isOpen( morning, utc( '2026-10-12T03:30' ), 'Asia/Dhaka' )
		).toBe( true );
		expect(
			isOpen( morning, utc( '2026-10-12T09:30' ), 'Asia/Dhaka' )
		).toBe( false );
	} );
} );

describe( 'showFloating', () => {
	const display = (
		devices: DisplayConfig[ 'devices' ],
		behavior: 'offline' | 'hide' = 'offline'
	): DisplayConfig => ( {
		devices,
		hours: { enabled: true, behavior, timezone: 'UTC', days: hours( {} ) },
	} );

	it( 'follows the device rule', () => {
		expect( showFloating( undefined, 390 ) ).toBe( true );
		expect( showFloating( display( 'mobile' ), 390 ) ).toBe( true );
		expect( showFloating( display( 'mobile' ), 1200 ) ).toBe( false );
		expect( showFloating( display( 'desktop' ), 390 ) ).toBe( false );
	} );

	it( 'hides the button when closed only if asked to', () => {
		expect( isClosed( display( 'all' ) ) ).toBe( true );
		expect( showFloating( display( 'all', 'offline' ), 1200 ) ).toBe(
			true
		);
		expect( showFloating( display( 'all', 'hide' ), 1200 ) ).toBe( false );
	} );
} );

describe( 'Display page helpers', () => {
	const settings = {
		display: {
			enabled: true,
			preload: false,
			pages_mode: 'include',
			page_ids: [ 4 ],
			post_types: [],
			url_patterns: [ '/shop/*', '/contact' ],
			devices: 'all',
			visitors: 'all',
			roles: [],
			hours: {
				enabled: true,
				behavior: 'offline',
				days: {
					mon: { open: true, start: '09:00', end: '17:00' },
					tue: { open: false, start: '09:00', end: '17:00' },
					wed: { open: false, start: '09:00', end: '17:00' },
					thu: { open: false, start: '09:00', end: '17:00' },
					fri: { open: false, start: '10:00', end: '12:00' },
					sat: { open: false, start: '09:00', end: '17:00' },
					sun: { open: false, start: '09:00', end: '17:00' },
				},
			},
		},
		chatbot: { offline_message: 'Away' },
	} as unknown as Settings;

	it( 'edits address patterns one per line', () => {
		const form = toDisplayForm( settings );
		expect( form.patterns ).toBe( '/shop/*\n/contact' );
		expect( lines( ' /a \n\n/b\r\n' ) ).toEqual( [ '/a', '/b' ] );

		const update = fromDisplayForm( { ...form, patterns: '/x\n\n /y ' } );
		expect( update.display.url_patterns ).toEqual( [ '/x', '/y' ] );
		expect( update.chatbot.offline_message ).toBe( 'Away' );
		expect( displayFormSchema.safeParse( update ).success ).toBe( true );
	} );

	it( 'needs something to match and at least one open day', () => {
		const form = toDisplayForm( settings );
		const empty = fromDisplayForm( {
			...form,
			display: { ...form.display, page_ids: [] },
			patterns: '',
		} );
		const result = displayFormSchema.safeParse( empty );
		expect( result.success ).toBe( false );
		expect( result.error?.issues[ 0 ].message ).toBe( 'rules:empty' );

		const closed = JSON.parse(
			JSON.stringify( fromDisplayForm( form ) )
		) as ReturnType< typeof fromDisplayForm >;
		closed.display.hours.days.mon.open = false;
		expect(
			displayFormSchema.safeParse( closed ).error?.issues[ 0 ].message
		).toBe( 'hours:none' );

		const spaced = fromDisplayForm( { ...form, patterns: '/a b' } );
		expect(
			displayFormSchema.safeParse( spaced ).error?.issues[ 0 ].message
		).toBe( 'pattern' );
	} );

	it( 'describes and copies hours', () => {
		const days = settings.display.hours.days;
		expect( describeDay( days.mon ) ).toBe( '09:00 – 17:00' );
		expect( describeDay( days.tue ) ).toBe( 'Closed' );
		expect(
			describeDay( { open: true, start: '22:00', end: '02:00' } )
		).toBe( '22:00 – 02:00 (next day)' );
		expect(
			describeDay( { open: true, start: '00:00', end: '00:00' } )
		).toBe( 'Open all day' );

		const weekdays = copyHours( days, 'mon', 'weekdays' );
		expect( weekdays.fri ).toEqual( days.mon );
		expect( weekdays.sat.open ).toBe( false );
		expect( copyHours( days, 'mon', 'all' ).sun.open ).toBe( true );
		expect( days.fri.start ).toBe( '10:00' );
	} );
} );
